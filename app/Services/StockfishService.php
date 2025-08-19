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
        $this->stdin = new InputStream();
        $this->proc = new Process([$this->bin]);
        $this->proc->setInput($this->stdin);
        $this->proc->setTimeout(null);
        $this->proc->start();

        $this->send('uci');
        $this->waitFor('/\buciok\b/');

        $this->send('setoption name Threads value ' . (int) env('SF_THREADS', 1));
        $this->send('setoption name Hash value ' . (int) env('SF_HASH_MB', 64));
        $this->send('setoption name Move Overhead value ' . (int) env('SF_MOVE_OVERHEAD_MS', 60));
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
        $this->send('ucinewgame');
        $this->send('isready');
        $this->waitFor('/\breadyok\b/');
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
            $perMoveMs = (int) min($hardCapMs, 300 + ($target - 1320) * 0.05);
            $go = sprintf(
                'go wtime %d btime %d winc %d binc %d movetime %d',
                max(0, $wtimeMs),
                max(0, $btimeMs),
                max(0, $wincMs),
                max(0, $bincMs),
                max(150, $perMoveMs)
            );
            $this->send($go);
            $buf = $this->waitFor('/\bbestmove\s+[a-h][1-8][a-h][1-8][qrbn]?\b/', max(300, $perMoveMs + 800));
            if (!preg_match('/bestmove\s+([a-h][1-8][a-h][1-8][qrbn]?)/', $buf)) {
                $this->send('stop');
                $buf .= $this->waitFor('/\bbestmove\s+[a-h][1-8][a-h][1-8][qrbn]?\b/', 1500);
            }
            if (preg_match('/bestmove\s+([a-h][1-8][a-h][1-8][qrbn]?)/', $buf, $m)) return $m[1];
            return null;
        }

        return $this->weakMove($fenOrStart, $target);
    }

    private function weakMove(string $fenOrStart, int $target): ?string
    {
        $this->send('setoption name UCI_LimitStrength value false');

        $skill = (int) max(0, min(6, (int) floor(($target - 200) / 150)));
        $this->send('setoption name Skill Level value ' . $skill);

        $w = max(0.0, min(1.0, (1200.0 - $target) / 1000.0));
        $k = 1 + (int) floor(5 * $w);
        $k = max(2, min(6, $k));

        $perMoveMs = (int) round(50 + (min(1200, $target) - 200) * 0.2);
        $perMoveMs = max(50, min(220, $perMoveMs));

        Log::info('stockfish.weak_move', [
            'fen' => $fenOrStart,
            'target' => $target,
            'skill' => $skill,
            'k' => $k,
            'perMoveMs' => $perMoveMs,
        ]);

        $this->send('setoption name MultiPV value ' . $k);

        $base = ($fenOrStart === 'startpos') ? 'position startpos' : 'position fen ' . $fenOrStart;
        $this->send($base);

        $this->send('go movetime ' . $perMoveMs);

        $buf = $this->waitFor('/\bbestmove\s+[a-h][1-8][a-h][1-8][qrbn]?\b/', max(300, $perMoveMs + 600));
        if (!preg_match('/bestmove\s+([a-h][1-8][a-h][1-8][qrbn]?)/', $buf)) {
            $this->send('stop');
            $buf .= $this->waitFor('/\bbestmove\s+[a-h][1-8][a-h][1-8][qrbn]?\b/', 1000);
        }

        $candidates = $this->parseTopMoves($buf, $k);
        if (empty($candidates)) {
            if (preg_match('/bestmove\s+([a-h][1-8][a-h][1-8][qrbn]?)/', $buf, $m)) return $m[1];
            return null;
        }

        $randLegalP = ($target <= 500) ? 0.25 : (($target <= 800) ? 0.12 : 0.05);
        if ($this->rand() < $randLegalP) {
            $moves = $this->legalMoves($fenOrStart);
            if ($moves) return $moves[array_rand($moves)];
        }

        $pWeak = 0.35 + 0.45 * $w;
        if ($this->rand() < $pWeak && count($candidates) > 1) {
            $idx = 1 + random_int(0, min($k - 1, count($candidates) - 1));
            return $candidates[$idx];
        }

        Log::info('stockfish.weak_move.chosen', [
            'fen' => $fenOrStart,
            'target' => $target,
            'k' => $k,
            'chosen' => $candidates[0],
        ]);

        return $candidates[0];
    }

    private function parseTopMoves(string $buf, int $k): array
    {
        $moves = [];
        if (preg_match_all('/^info .*?multipv\s+(\d+).*?\spv\s+([a-h][1-8][a-h][1-8][qrbn]?)/mi', $buf, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $i = (int) $row[1];
                $mv = $row[2];
                $moves[$i] = $mv;
            }
            ksort($moves);
        }
        $out = [];
        for ($i = 1; $i <= $k; $i++) {
            if (isset($moves[$i])) $out[] = $moves[$i];
        }
        return $out;
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
