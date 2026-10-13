<?php
// Runtime-specific probe: React\Async\async + React\Async\await + Loop::addTimer only (no async\ functions).
// EXPECTED TO FAIL: React\Async\await() resumes the fiber from a scheduler tick, so nothing runs in the target
// fiber between resume and the user's next line (see README, "Scope state").
require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use React\Promise\Deferred;
use function React\Async\{async, await};

\async\run(function () {}); // instantiates the adapter, which installs the decorating loop

$root = $_GET;
$seen = [];
$sleep = static function (float $s) {
    $d = new Deferred();
    Loop::addTimer($s, static fn() => $d->resolve(null));
    await($d->promise());
};
$task = static function (string $name) use (&$seen, $sleep) {
    return (new Scope())->run(fn() => async(function () use ($name, &$seen, $sleep) {
        $_GET = ['req' => $name];
        for ($i = 1; $i <= 2; $i++) {
            $sleep(0.01);
            $seen[$name . $i] = $_GET['req'] ?? null;
        }
    })());
};
await(\React\Promise\all([$task('a'), $task('b')]));
$bad = [];
foreach (['a', 'b'] as $n) {
    foreach ([1, 2] as $i) {
        $v = $seen[$n . $i] ?? null;
        if ($v !== $n) { $bad[] = "$n after sleep $i saw " . json_encode($v); }
    }
}
if ($_GET !== $root) { $bad[] = 'after await in {main} $_GET is ' . json_encode($_GET); }
if ($bad) { echo implode("\n", $bad), "\n"; exit(1); }
echo "ok\n";
