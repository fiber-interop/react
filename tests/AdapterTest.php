<?php

declare(strict_types=1);

namespace FiberInterop\React\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;

use function async\{await, await_all, go, readable, run, sleep};
use function React\Async\async;

final class AdapterTest extends TestCase
{
    public function testGoReturnsReactPromise(): void
    {
        \async\run(function () {
            $p = go(fn() => 42);
            $this->assertInstanceOf(\React\Promise\PromiseInterface::class, $p);
            $this->assertSame(42, await($p));
        });
    }

    public function testAwaitPromiseResolveAndReject(): void
    {
        \async\run(function () {
            $d = new Deferred();
            Loop::addTimer(0.01, fn() => $d->resolve('ok'));
            $this->assertSame('ok', await($d->promise()));

            $d = new Deferred();
            $e = new \DomainException('boom');
            Loop::addTimer(0.01, fn() => $d->reject($e));
            try {
                await($d->promise());
                $this->fail('expected exception');
            } catch (\DomainException $caught) {
                $this->assertSame($e, $caught);
            }
        });
    }

    public function testNonThrowableRejectionIsWrapped(): void
    {
        \async\run(function () {
            $this->expectException(\async\RejectedException::class);
            await(new class {
                public function then(callable $ok, callable $fail): void
                {
                    Loop::futureTick(fn() => $fail('plain string'));
                }
            });
        });
    }

    public function testTimeoutLeavesPromiseAloneAndIgnoresLateResult(): void
    {
        \async\run(function () {
            $d = new Deferred();
            Loop::addTimer(0.05, fn() => $d->resolve('late'));
            try {
                await($d->promise(), 0.01);
                $this->fail('expected timeout');
            } catch (\async\TimeoutException) {
            }
            sleep(0.06);
            $this->assertSame('late', await($d->promise()));
        });
    }

    public function testAwaitInsideReactAsyncFiber(): void
    {
        \async\run(function () {
            $p = async(function () {
                sleep(0.01);
                return await(go(fn() => 'inner')) . '!';
            })();
            $this->assertSame('inner!', await($p));
        });
    }

    public function testGoTaskAwaitedWithReactAwait(): void
    {
        \async\run(function () {
            $this->assertSame(7, \React\Async\await(go(function () {
                sleep(0.01);
                return 7;
            })));
        });
    }

    public function testTwoReadableWaitersOnOneStream(): void
    {
        \async\run(function () {
            [$r, $w] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            stream_set_blocking($r, false);
            $woke = [];
            $tasks = [];
            foreach (['a', 'b'] as $n) {
                $tasks[] = go(function () use ($r, $n, &$woke) {
                    readable($r);
                    $woke[] = $n;
                });
            }
            sleep(0.01);
            fwrite($w, 'x');
            await_all($tasks);
            sort($woke);
            $this->assertSame(['a', 'b'], $woke);
        });
    }

    public function testReadableTimeoutCancelsWaiter(): void
    {
        \async\run(function () {
            [$r, $w] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            try {
                readable($r, 0.01);
                $this->fail('expected timeout');
            } catch (\async\TimeoutException) {
            }
            // The listener is gone: a later write must not leave a watcher behind that keeps the loop alive.
            fwrite($w, 'x');
            sleep(0.01);
            $this->assertSame('x', fread($r, 10));
        });
    }

    #[RunInSeparateProcess]
    public function testInPlaceFromForeignFiber(): void
    {
        \async\run(function () {
            $result = null;
            $fiber = new \Fiber(function () use (&$result) {
                sleep(0.01);
                $result = await(go(fn() => 'in place'));
            });
            $fiber->start();
            $this->assertTrue($fiber->isTerminated());
            $this->assertSame('in place', $result);
        });
    }

    #[RunInSeparateProcess]
    public function testForeignFiberStartedInsideLoopCallbackThrows(): void
    {
        \async\run(function () {
            $error = null;
            $d = new Deferred();
            Loop::addTimer(0.001, function () use (&$error, $d) {
                (new \Fiber(function () use (&$error) {
                    try {
                        sleep(0.001);
                    } catch (\LogicException $e) {
                        $error = $e;
                    }
                }))->start();
                $d->resolve(null);
            });
            await($d->promise());
            $this->assertInstanceOf(\LogicException::class, $error);
        });
    }

    #[RunInSeparateProcess]
    public function testUnhandledRejectionReachesHandler(): void
    {
        \async\run(function () {
            $seen = null;
            $previous = \React\Promise\set_rejection_handler(function (\Throwable $e) use (&$seen) { $seen = $e; });
            try {
                \async\service(fn() => throw new \RuntimeException('nobody awaits'));
                gc_collect_cycles();
                sleep(0.01);
                gc_collect_cycles();
            } finally {
                \React\Promise\set_rejection_handler($previous);
            }
            $this->assertInstanceOf(\RuntimeException::class, $seen);
        });
    }

    public function testDecoratingLoopInstalledOnceAndSwapsOnlyOnScopeChange(): void
    {
        \async\run(function () {
            $this->assertInstanceOf(\FiberInterop\React\ActivatingLoop::class, Loop::get());
            new \FiberInterop\React\Adapter(); // idempotent: no double wrap
            $inner = (new \ReflectionProperty(\FiberInterop\React\ActivatingLoop::class, 'inner'))->getValue(Loop::get());
            $this->assertNotInstanceOf(\FiberInterop\React\ActivatingLoop::class, $inner);

            // Same scope all along: $_GET set here is still here after a timer callback and a sleep.
            $_GET = ['k' => 'same'];
            $seen = null;
            Loop::addTimer(0.001, function () use (&$seen) { $seen = $_GET['k'] ?? null; });
            sleep(0.01);
            $this->assertSame('same', $seen);
            $this->assertSame('same', $_GET['k']);
        });
    }

    public function testActivatesOnTimerCallbackApiResumeAndReturnToMain(): void
    {
        $root = $_GET;
        $timerSaw = null;
        \async\run(function () use (&$timerSaw) {
            $scope = new \Scope();
            $scope->run(function () use (&$timerSaw) {
                $_GET = ['req' => 'inner'];
                // Timer registered in the inner scope but fired by the loop while another scope may be active.
                Loop::addTimer(0.005, function () use (&$timerSaw) { $timerSaw = $_GET['req'] ?? null; });
            });
            $other = (new \Scope())->run(fn() => go(function () {
                $_GET = ['req' => 'other'];
                sleep(0.02);
                $this->assertSame('other', $_GET['req'], 'api resume');
            }));
            await($other);
        });
        $this->assertSame($root, $_GET, 'return to main');
        $this->assertNotSame('other', $timerSaw);
    }
    public function testLocalCloseInCallbackRecoversWithoutTimer(): void
    {
        run(function () {
            [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $t = go(function () use ($a) { readable($a); return 'woken'; });
            sleep(0.001);
            // Closed from inside a loop callback; the loop selects again immediately afterwards.
            Loop::futureTick(static function () use ($a) { fclose($a); });
            $this->assertSame('woken', await($t));
        });
    }

    public function testLocalCloseUnblocksWaitersNormally(): void
    {
        \async\run(function () {
            [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $woke = [];
            $start = microtime(true);
            $w1 = go(function () use ($a, &$woke, $start) {
                readable($a, 5.0);
                $woke[] = microtime(true) - $start;
            });
            $w2 = go(function () use ($a, &$woke, $start) {
                readable($a, 5.0);
                $woke[] = microtime(true) - $start;
            });
            sleep(0.02);
            fclose($a);
            await_all([$w1, $w2]);
            $this->assertCount(2, $woke);
            $this->assertLessThan(0.1, max($woke));
            fclose($b);
        });
    }
}
