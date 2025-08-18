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

        // Engine options you actually want once, up-front
        $this->send("setoption name Threads value " . (int) env('SF_THREADS', 1));
        // Hash helps a lot even at short time controls
        $this->send("setoption name Hash value " . (int) env('SF_HASH_MB', 64));
        // Be conservative against flagging
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
        // drain current output buffer
        $out = '';
        while ($this->proc->isRunning()) {
            $chunk = $this->proc->getIncrementalOutput() . $this->proc->getIncrementalErrorOutput();
            if ($chunk === '') break;
            $out .= $chunk;
            // small break so we don’t spin
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

    /**
     * Strong play (no Elo cap): pass $eloCap=null. For Elo-limited play, pass target Elo.
     */
    public function bestMoveFromClock(
        string $fenOrStart,
        int $wtimeMs,
        int $btimeMs,
        int $wincMs = 0,
        int $bincMs = 0,
        ?int $eloCap = null,
        ?int $hardCapMs = null
    ): ?string {
        $this->send("ucinewgame");
        $this->send("isready");
        $this->waitFor("/\breadyok\b/");

        // Keep engine tame and deterministic enough
        $this->send("setoption name MultiPV value 1");
        $this->send("setoption name Threads value 1");

        // Position
        $pos = ($fenOrStart === 'startpos') ? 'position startpos' : 'position fen ' . $fenOrStart;
        $this->send($pos);

        // If no hard cap provided, derive one from clocks but keep it conservative
        if ($hardCapMs === null) {
            $sideTotal = max($wtimeMs, $btimeMs);
            $hardCapMs = (int) max(150, min(4000, ($sideTotal / 120))); // much tighter than before
        }

        // Decide weakening mode
        $useElo = $eloCap !== null && $eloCap >= 1320; // Stockfish min is ~1320
        if ($useElo) {
            $this->send("setoption name UCI_LimitStrength value true");
            $this->send("setoption name UCI_Elo value " . min(3190, $eloCap)); // doc max ~3190
            // Do NOT set Skill Level here (it’s overridden by UCI_LimitStrength)
            // Cap per-move time a bit so it doesn't think forever
            $perMoveMs = (int) min($hardCapMs, 300 + ($eloCap - 1320) * 0.05); // ~300–450ms typical
            $goCmd = sprintf(
                "go wtime %d btime %d winc %d binc %d movetime %d",
                max(0, $wtimeMs),
                max(0, $btimeMs),
                max(0, $wincMs),
                max(0, $bincMs),
                max(150, $perMoveMs)
            );
        } else {
            // Below ~1300 Elo target: use Skill Level + very small movetime
            $this->send("setoption name UCI_LimitStrength value false");

            // Map 200..1200 target to Skill 0..6 (aggressively weak and blunder-prone)
            $target = $eloCap ?? 800;
            $skill = (int) round(max(0, min(6, ($target - 200) / (1200 - 200) * 6)));
            $this->send("setoption name Skill Level value " . $skill);

            // Very small per-move time to enforce fast, weak play
            // ~60ms at 200 Elo rising to ~180ms at 1200
            $perMoveMs = (int) round(60 + (max(200, min(1200, $target)) - 200) * (120.0 / 1000.0));
            $perMoveMs = (int) min($perMoveMs, $hardCapMs);

            $goCmd = sprintf(
                "go wtime %d btime %d winc %d binc %d movetime %d",
                max(0, $wtimeMs),
                max(0, $btimeMs),
                max(0, $wincMs),
                max(0, $bincMs),
                max(40, $perMoveMs)
            );
        }

        $this->send($goCmd);

        // Wait up to hard cap; if needed, 'stop'
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

    public function fenAfter(string $fenOrStart, string $uci): ?string
    {
        // Reuse running engine; ask it to play the move and dump FEN
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
