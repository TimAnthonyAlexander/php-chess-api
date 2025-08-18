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

        $this->send("uci");
        $this->waitFor("/\buciok\b/");

        $this->send("setoption name Threads value " . (int) env('SF_THREADS', 1));
        $this->send("setoption name Hash value " . (int) env('SF_HASH_MB', 64));
        $this->send("setoption name Move Overhead value " . (int) env('SF_MOVE_OVERHEAD_MS', 60));

        $this->send("isready");
        $this->waitFor("/\breadyok\b/");
    }

    public function __destruct()
    {
        if (isset($this->stdin)) {
            $this->send("quit");
            $this->stdin->close();
        }
    }

    private function send(string $cmd): void
    {
        $this->stdin->write($cmd . "\n");
    }

    private function readAll(): string
    {
        $out = '';
        while ($this->proc->isRunning()) {
            $chunk = $this->proc->getIncrementalOutput() . $this->proc->getIncrementalErrorOutput();
            if ($chunk === '') break;
            $out .= $chunk;
            usleep(1000);
        }
        return $out;
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
        $this->send("ucinewgame");
        $this->send("isready");
        $this->waitFor("/\breadyok\b/");
        $this->send("setoption name MultiPV value 1");
        $this->send("setoption name Threads value 1");

        $pos = ($fenOrStart === 'startpos') ? 'position startpos' : 'position fen ' . $fenOrStart;
        $this->send($pos);

        if ($hardCapMs === null) {
            $sideTotal = max($wtimeMs, $btimeMs);
            $hardCapMs = (int) max(150, min(4000, ($sideTotal / 120)));
        }

        $target = (int) max(200, min(3000, $eloTarget ?? 800));

        if ($target >= 1320) {
            $this->send("setoption name UCI_LimitStrength value true");
            $this->send("setoption name UCI_Elo value " . min(3190, $target));
            $perMoveMs = (int) min($hardCapMs, 300 + ($target - 1320) * 0.05);
            $goCmd = sprintf(
                "go wtime %d btime %d winc %d binc %d movetime %d",
                max(0, $wtimeMs),
                max(0, $btimeMs),
                max(0, $wincMs),
                max(0, $bincMs),
                max(150, $perMoveMs)
            );
            $this->send($goCmd);
            $buf = $this->waitFor("/\bbestmove\s+[a-h][1-8][a-h][1-8][qrbn]?\b/", $hardCapMs);
            if (!preg_match('/bestmove\s+([a-h][1-8][a-h][1-8][qrbn]?)/', $buf)) {
                $this->send("stop");
                $buf .= $this->waitFor("/\bbestmove\s+[a-h][1-8][a-h][1-8][qrbn]?\b/", 2000);
            }
            if (preg_match('/bestmove\s+([a-h][1-8][a-h][1-8][qrbn]?)/', $buf, $m)) {
                return $m[1];
            }
            return null;
        }

        return $this->weakMove($fenOrStart, $target, $hardCapMs);
    }

    private function weakMove(string $fenOrStart, int $target, int $hardCapMs): ?string
    {
        $this->send("setoption name UCI_LimitStrength value false");

        $skill = (int) max(0, min(10, (int) floor(($target - 200) / 100)));
        $this->send("setoption name Skill Level value " . $skill);

        $weakness = max(0.0, min(1.0, (1200.0 - $target) / 1000.0));
        $randPickP = 0.15 + 0.65 * $weakness;

        $depth = ($target <= 400) ? 1 : (($target <= 800) ? 2 : 3);

        if (mt_rand() / mt_getrandmax() < $randPickP) {
            $moves = $this->legalMoves($fenOrStart);
            if (!$moves) {
                $this->send("go depth " . $depth);
                $buf = $this->waitFor("/\bbestmove\s+[a-h][1-8][a-h][1-8][qrbn]?\b/", $hardCapMs);
                if (preg_match('/bestmove\s+([a-h][1-8][a-h][1-8][qrbn]?)/', $buf, $m)) return $m[1];
                $this->send("stop");
                $buf .= $this->waitFor("/\bbestmove\s+[a-h][1-8][a-h][1-8][qrbn]?\b/", 1000);
                if (preg_match('/bestmove\s+([a-h][1-8][a-h][1-8][qrbn]?)/', $buf, $m)) return $m[1];
                return null;
            }
            return $moves[array_rand($moves)];
        }

        $this->send("go depth " . $depth);
        $buf = $this->waitFor("/\bbestmove\s+[a-h][1-8][a-h][1-8][qrbn]?\b/", max(150, $hardCapMs));
        if (!preg_match('/bestmove\s+([a-h][1-8][a-h][1-8][qrbn]?)/', $buf)) {
            $this->send("stop");
            $buf .= $this->waitFor("/\bbestmove\s+[a-h][1-8][a-h][1-8][qrbn]?\b/", 1000);
        }
        if (preg_match('/bestmove\s+([a-h][1-8][a-h][1-8][qrbn]?)/', $buf, $m)) {
            if ($target <= 600 && mt_rand() / mt_getrandmax() < 0.35) {
                $moves = $this->legalMoves($fenOrStart);
                if ($moves) return $moves[array_rand($moves)];
            }
            return $m[1];
        }
        return null;
    }

    private function legalMoves(string $fenOrStart): array
    {
        $this->send("ucinewgame");
        $this->send("isready");
        $this->waitFor("/\breadyok\b/");
        $base = ($fenOrStart === 'startpos') ? 'position startpos' : 'position fen ' . $fenOrStart;
        $this->send($base);
        $this->send('d');
        $out = $this->waitFor('/Legal moves:/i', 300);
        usleep(1000);
        $out .= $this->proc->getIncrementalOutput() . $this->proc->getIncrementalErrorOutput();
        preg_match_all('/\b([a-h][1-8][a-h][1-8][qrbn]?)\b/i', $out, $m);
        return $m[1] ?? [];
    }

    public function fenAfter(string $fenOrStart, string $uci): ?string
    {
        $this->send("ucinewgame");
        $this->send("isready");
        $this->waitFor("/\breadyok\b/");

        $base = ($fenOrStart === 'startpos') ? 'position startpos' : 'position fen ' . $fenOrStart;
        $this->send($base . ' moves ' . $uci);
        $this->send('d');

        $out = $this->waitFor('/\bFen:\s+([^\r\n]+)/i', 500);
        if (preg_match('/\bFen:\s+([^\r\n]+)/i', $out, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
