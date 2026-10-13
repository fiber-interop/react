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
}
