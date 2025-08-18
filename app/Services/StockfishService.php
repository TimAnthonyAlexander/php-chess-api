<?php

namespace App\Services;

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class StockfishService
{
    private string $bin;

    public function __construct()
    {
        // On macOS it's /opt/homebrew/bin/stockfish; on Ubuntu usually /usr/bin/stockfish
        $this->bin = env('STOCKFISH_BIN', 'stockfish');
    }

    public function bestMove(string $fenOrStart = 'startpos', int $skill = 8, int $moveTimeMs = 300): ?string
    {
        $pos = $fenOrStart === 'startpos' ? 'position startpos' : 'position fen ' . $fenOrStart;

        $script = implode("\n", [
            'uci',
            'setoption name Threads value 1',
            'setoption name Skill Level value ' . $skill,
            'setoption name UCI_LimitStrength value true',
            'setoption name UCI_Elo value ' . (1000 + $skill * 75),
            'ucinewgame',
            $pos,
            'go movetime ' . $moveTimeMs,
            'quit',
        ]);

        $p = new Process([$this->bin]);
        $p->setInput($script);
        $p->setTimeout(5);
        $p->run();

        $out = $p->getOutput() . $p->getErrorOutput();
        if (preg_match('/bestmove\s+([a-h][1-8][a-h][1-8][qrbn]?)/', $out, $m)) {
            return $m[1];
        }
        return null;
    }

    public function fenAfter(string $fenOrStart, string $uci): ?string
    {
        $base = $fenOrStart === 'startpos' ? 'position startpos' : 'position fen ' . $fenOrStart;

        $script = implode("\n", [
            'uci',
            'ucinewgame',
            $base . ' moves ' . $uci,
            'd',
            'quit',
        ]);

        $p = new Process([$this->bin]);
        $p->setInput($script);
        $p->setTimeout(5);
        $p->run();

        $out = $p->getOutput() . $p->getErrorOutput();
        // Stockfish 'd' prints a line like: "Fen: rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1"
        if (preg_match('/\bFen:\s+([^\r\n]+)/i', $out, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}

