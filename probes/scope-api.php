<?php
// Portable probe: per-scope $_GET survives async\sleep() inside async\run().
require __DIR__ . '/../vendor/autoload.php';

use function async\{await_all, go, run, sleep};

$root = $_GET;
$bad = [];
run(function () use (&$bad) {
    $task = function (string $name) use (&$bad) {
        return (new Scope())->run(fn() => go(function () use ($name, &$bad) {
            $_GET = ['req' => $name];
            for ($i = 1; $i <= 2; $i++) {
                sleep(0.01);
                if (($_GET['req'] ?? null) !== $name) {
                    $bad[] = "$name after sleep $i saw " . json_encode($_GET);
                }
            }
        }));
    };
    await_all([$task('a'), $task('b')]);
});
if ($_GET !== $root) {
    $bad[] = 'after run() $_GET is ' . json_encode($_GET) . ', expected root ' . json_encode($root);
}
if ($bad) { echo implode("\n", $bad), "\n"; exit(1); }
echo "ok\n";
