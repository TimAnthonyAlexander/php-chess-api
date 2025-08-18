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
        ?int $hardCapMs = null // if null, choose heuristic below
    ): ?string {
        $this->send("ucinewgame");
        $this->send("isready");
        $this->waitFor("/\breadyok\b/");

        if ($eloCap !== null) {
            $this->send("setoption name Skill Level value 20");
            $this->send("setoption name UCI_LimitStrength value true");
            $this->send("setoption name UCI_Elo value " . max(1000, min(3000, $eloCap)));
        } else {
            $this->send("setoption name UCI_LimitStrength value false");
            $this->send("setoption name Skill Level value 20");
        }

        $pos = ($fenOrStart === 'startpos') ? 'position startpos' : 'position fen ' . $fenOrStart;
        $this->send($pos);

        // Choose a sane ceiling if not provided
        if ($hardCapMs === null) {
            // crude heuristic: more time on the clock → larger cap
            $sideTotal = max($wtimeMs, $btimeMs);
            $hardCapMs = (int) max(600, min(10000, ($sideTotal / 60))); // e.g. 30min → 30_000ms cap
        }

        $this->send(sprintf(
            "go wtime %d btime %d winc %d binc %d",
            max(0, $wtimeMs),
            max(0, $btimeMs),
            max(0, $wincMs),
            max(0, $bincMs)
        ));

        // Wait up to hard cap. If exceeded, send stop and wait for bestmove.
        $buf = $this->waitFor("/\bbestmove\s+[a-h][1-8][a-h][1-8][qrbn]?\b/", $hardCapMs);
        if (!preg_match('/bestmove\s+([a-h][1-8][a-h][1-8][qrbn]?)/', $buf, $m)) {
            $this->send("stop");
            $buf .= $this->waitFor("/\bbestmove\s+[a-h][1-8][a-h][1-8][qrbn]?\b/", 3000);
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
