<?php
// Portable probe: a plain Fiber resumed by its creator sees the creator's $_GET (most-recently-current fallback).
require __DIR__ . '/../vendor/autoload.php';

use function async\run;

$bad = [];
run(function () use (&$bad) {
    (new Scope())->run(function () use (&$bad) {
        $_GET = ['req' => 'creator'];
        $seen = [];
        $f = new Fiber(function () use (&$seen) {
            $seen[] = $_GET['req'] ?? null;
            Fiber::suspend();
            $seen[] = $_GET['req'] ?? null;
        });
        $f->start();
        $f->resume();
        foreach ($seen as $i => $v) {
            if ($v !== 'creator') { $bad[] = "read $i saw " . json_encode($v); }
        }
    });
});
if ($bad) { echo implode("\n", $bad), "\n"; exit(1); }
echo "ok\n";
