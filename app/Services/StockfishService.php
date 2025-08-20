<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class StockfishService
{
    private string $bin;
    private Process $proc;
    private InputStream $stdin;

    public function __construct()
    {
        $this->bin = env('STOCKFISH_BIN', 'stockfish');
        Log::info('StockfishService initializing', ['binary' => $this->bin]);
        
        $this->stdin = new InputStream();
        $this->proc = new Process([$this->bin]);
        $this->proc->setInput($this->stdin);
        $this->proc->setTimeout(null);
        
        try {
            $this->proc->start();
            Log::info('StockfishService process started', ['pid' => $this->proc->getPid()]);
        } catch (\Throwable $e) {
            Log::error('StockfishService failed to start', ['error' => $e->getMessage()]);
            throw $e;
        }

        $this->send('uci');
        $this->waitFor('/\buciok\b/');

        $this->send('setoption name Threads value ' . (int) env('SF_THREADS', 1));
        $this->send('setoption name Hash value ' . (int) env('SF_HASH_MB', 64));
        $this->send('setoption name Move Overhead value ' . (int) env('SF_MOVE_OVERHEAD_MS', 60));
        $this->send('setoption name Minimum Thinking Time value 0');
        $this->send('setoption name Ponder value false');

        $this->send('isready');
        $this->waitFor('/\breadyok\b/');
    }

    public function __destruct()
    {
        if (isset($this->stdin)) {
            $this->send('quit');
            $this->stdin->close();
        }
    }

    private function send(string $cmd): void
    {
        $this->stdin->write($cmd . "\n");
    }

    private function waitFor(string $pattern, int $timeoutMs = 2000): string
    {
        $deadline = microtime(true) + $timeoutMs / 1000;
        $buf = '';
        do {
            $buf .= $this->proc->getIncrementalOutput() . $this->proc->getIncrementalErrorOutput();
            if (preg_match($pattern, $buf)) return $buf;
            usleep(1000);
        } while (microtime(true) < $deadline);
        return $buf;
    }

    public function bestMoveFromClock(
        string $fenOrStart,
        int $wtimeMs,
        int $btimeMs,
        int $wincMs = 0,
        int $bincMs = 0,
        ?int $eloTarget = null,
        ?int $hardCapMs = null
    ): ?string {
        Log::info('StockfishService.bestMoveFromClock called', [
            'fen' => $fenOrStart,
            'wtime_ms' => $wtimeMs,
            'btime_ms' => $btimeMs,
            'winc_ms' => $wincMs,
            'binc_ms' => $bincMs,
            'elo_target' => $eloTarget,
            'hard_cap_ms' => $hardCapMs,
        ]);

        if (!$this->proc->isRunning()) {
            Log::error('Stockfish process is not running');
            return null;
        }

        $this->send('ucinewgame');
        $this->send('isready');
        $readyBuf = $this->waitFor('/\breadyok\b/');
        if (!preg_match('/\breadyok\b/', $readyBuf)) {
            Log::error('Stockfish did not respond readyok', ['buffer' => $readyBuf]);
            return null;
        }

        $this->send('setoption name MultiPV value 1');
        $this->send('setoption name Threads value 1');

        $pos = ($fenOrStart === 'startpos') ? 'position startpos' : 'position fen ' . $fenOrStart;
        $this->send($pos);

        $sideTotal = max($wtimeMs, $btimeMs);
        $derivedCap = (int) max(150, min(4000, ($sideTotal / 120)));
        $hardCapMs = $hardCapMs ?? $derivedCap;

        $target = (int) max(200, min(3000, $eloTarget ?? 800));

        if ($target >= 1320) {
            $this->send('setoption name UCI_LimitStrength value true');
            $this->send('setoption name UCI_Elo value ' . min(3190, $target));
            // If a hard cap is provided, respect it as the movetime budget; otherwise derive a small budget
            $perMoveMs = $hardCapMs !== null
                ? (int) $hardCapMs
                : (int) (300 + ($target - 1320) * 0.05);
            $go = sprintf(
                'go wtime %d btime %d winc %d binc %d movetime %d',
                max(0, $wtimeMs),
                max(0, $btimeMs),
                max(0, $wincMs),
                max(0, $bincMs),
                max(150, $perMoveMs)
            );
            Log::info('StockfishService.sending_go_command', ['command' => $go]);
            $this->send($go);
            
            $waitTimeMs = max(300, $perMoveMs + 800);
            Log::info('StockfishService.waiting_for_bestmove', ['wait_time_ms' => $waitTimeMs]);
            $buf = $this->waitFor('/\bbestmove\s+[a-h][1-8][a-h][1-8][qrbn]?\b/', $waitTimeMs);
            
            if (!preg_match('/bestmove\s+([a-h][1-8][a-h][1-8][qrbn]?)/', $buf)) {
                Log::info('StockfishService.no_bestmove_yet', ['buffer_length' => strlen($buf), 'buffer' => substr($buf, -200)]);
                $this->send('stop');
                $buf .= $this->waitFor('/\bbestmove\s+[a-h][1-8][a-h][1-8][qrbn]?\b/', 1500);
                Log::info('StockfishService.after_stop', ['buffer_length' => strlen($buf), 'buffer' => substr($buf, -200)]);
            }
            
            if (preg_match('/bestmove\s+([a-h][1-8][a-h][1-8][qrbn]?)/', $buf, $m)) {
                Log::info('StockfishService.found_bestmove', ['move' => $m[1]]);
                return $m[1];
            }
            
            Log::warning('StockfishService.no_bestmove_found', ['buffer' => $buf]);
            return null;
        }

        return $this->weakMove($fenOrStart, $target);
    }

    private function weakMove(string $fenOrStart, int $target): ?string
    {
        Log::info('StockfishService.weakMove called', ['fen' => $fenOrStart, 'target' => $target]);
        
        $this->send('setoption name UCI_LimitStrength value false');
        $this->send('setoption name Skill Level value 0');

        $weakness = max(0.0, min(1.0, (1200.0 - $target) / 1000.0));

        $k = 4 + (int) floor(26 * $weakness);
        $k = max(4, min(30, $k));
        $this->send('setoption name MultiPV value ' . $k);

        $base = ($fenOrStart === 'startpos') ? 'position startpos' : 'position fen ' . $fenOrStart;
        $this->send($base);

        $this->send('go depth 1');
        Log::info('StockfishService.weakMove waiting for depth 1');
        $buf = $this->waitFor('/\bbestmove\s+[a-h][1-8][a-h][1-8][qrbn]?\b/', 500);
        if (!preg_match('/bestmove\s+([a-h][1-8][a-h][1-8][qrbn]?)/', $buf)) {
            Log::info('StockfishService.weakMove no bestmove yet, sending stop');
            $this->send('stop');
            $buf .= $this->waitFor('/\bbestmove\s+[a-h][1-8][a-h][1-8][qrbn]?\b/', 500);
        }

        $lines = $this->parseMultiPVWithScores($buf);
        if (empty($lines)) {
            $moves = $this->legalMoves($fenOrStart);
            return $moves ? $moves[array_rand($moves)] : null;
        }

        usort($lines, function ($a, $b) {
            if ($a['mate'] !== null || $b['mate'] !== null) {
                if ($a['mate'] === null) return 1;
                if ($b['mate'] === null) return -1;
                return $a['mate'] <=> $b['mate'];
            }
            return $a['cp'] <=> $b['cp'];
        });

        $pRandomLegal = 0.5 + 0.4 * $weakness;
        if ($this->rand() < $pRandomLegal) {
            $moves = $this->legalMoves($fenOrStart);
            return $moves ? $moves[array_rand($moves)] : $lines[array_rand($lines)]['move'];
        }

        $tailFrac = 0.8 + 0.2 * $weakness;
        $tailStart = max(0, (int) floor(count($lines) * (1.0 - $tailFrac)));
        $tail = array_slice($lines, $tailStart);
        return $tail[array_rand($tail)]['move'];
    }

    private function parseMultiPVWithScores(string $buf): array
    {
        $rows = [];
        if (preg_match_all('/^info .*?multipv\s+(\d+).*?score\s+(cp|mate)\s+(-?\d+).*?\spv\s+([a-h][1-8][a-h][1-8][qrbn]?)/mi', $buf, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $rows[] = [
                    'pv'   => (int) $row[1],
                    'type' => $row[2],
                    'val'  => (int) $row[3],
                    'move' => $row[4],
                    'cp'   => $row[2] === 'cp' ? (int) $row[3] : null,
                    'mate' => $row[2] === 'mate' ? (int) $row[3] : null,
                ];
            }
            usort($rows, fn($a, $b) => $a['pv'] <=> $b['pv']);
        }
        return $rows;
    }

    private function legalMoves(string $fenOrStart): array
    {
        $this->send('ucinewgame');
        $this->send('isready');
        $this->waitFor('/\breadyok\b/');
        $base = ($fenOrStart === 'startpos') ? 'position startpos' : 'position fen ' . $fenOrStart;
        $this->send($base);
        $this->send('d');
        $out = $this->waitFor('/Legal moves:/i', 300);
        usleep(1000);
        $out .= $this->proc->getIncrementalOutput() . $this->proc->getIncrementalErrorOutput();
        preg_match_all('/\b([a-h][1-8][a-h][1-8][qrbn]?)\b/i', $out, $m);
        return $m[1] ?? [];
    }

    private function rand(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    public function fenAfter(string $fenOrStart, string $uci): ?string
    {
        $this->send('ucinewgame');
        $this->send('isready');
        $this->waitFor('/\breadyok\b/');

        $base = ($fenOrStart === 'startpos') ? 'position startpos' : 'position fen ' . $fenOrStart;
        $this->send($base . ' moves ' . $uci);
        $this->send('d');

        $out = $this->waitFor('/\bFen:\s+([^\r\n]+)/i', 500);
        if (preg_match('/\bFen:\s+([^\r\n]+)/i', $out, $m)) return trim($m[1]);
        return null;
    }
}
