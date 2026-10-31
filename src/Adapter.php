<?php

declare(strict_types=1);

namespace FiberInterop\React;

use fiber\RejectedException;
use fiber\Timeout;
use React\Async\FiberMap;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Async\async;
use function React\Async\await;

/**
 * fiber-interop adapter on ReactPHP (react/event-loop, react/promise, react/async).
 *
 * Waiting strategy (see README, "In-place rule"):
 *  - {main} or a fiber created by React\Async\async() (including every go() task): React\Async\await().
 *  - any other fiber (one nobody's loop owns): the loop is run in place, from inside that fiber.
 */
final class Adapter implements \fiber\Adapter
{
    /** @var array{read: array<int, array{0: resource, 1: array<int, \Closure>}>, write: array<int, array{0: resource, 1: array<int, \Closure>}>} */
    private array $streams = ['read' => [], 'write' => []];
    private int $waiterId = 0;
    /** @var \WeakMap<\Fiber, PromiseInterface> fiber -> the handle go() returned for the task it runs */
    private \WeakMap $tasks;
    /** @var \WeakMap<PromiseInterface, \Closure(bool, mixed): void> task handle -> settle closure of its current wait */
    private \WeakMap $waits;

    public function __construct()
    {
        $this->tasks = new \WeakMap();
        $this->waits = new \WeakMap();
        // Idempotent: the decorating loop activates the scope before every callback the loop runs.
        if (!Loop::get() instanceof ActivatingLoop) {
            Loop::set(new ActivatingLoop(Loop::get()));
        }
    }

    /**
     * async() starts the fiber before it returns its promise, so the handle is a Deferred's promise made
     * first and settled by async()'s: current() knows it from the task's first line. Cancelling the handle
     * (React's promise cancel()) cancels async()'s promise.
     */
    public function go(\Closure $f): object
    {
        $scope = \Scope::getCurrent();
        $inner = null;
        $deferred = new Deferred(static function () use (&$inner): void {
            $inner?->cancel();
        });
        $handle = $deferred->promise();
        $tasks = $this->tasks;
        $inner = async(static function (mixed ...$args) use ($scope, $f, $tasks, $handle): mixed {
            $tasks[\Fiber::getCurrent()] = $handle;
            return $scope->run(static function () use ($f, $args): mixed {
                ActivatingLoop::activate(); // task start
                return $f(...$args);
            });
        })();
        $inner->then($deferred->resolve(...), $deferred->reject(...));
        return $handle;
    }

    /** The handle go() or service() returned for the running task, null outside one. */
    public function current(): ?object
    {
        $fiber = \Fiber::getCurrent();
        return $fiber !== null ? $this->tasks[$fiber] ?? null : null;
    }

    /**
     * Fail the wait $task is suspended in with $reason: its settle closure cancels the timer, removes the
     * stream waiter, ignores the awaited promise's later outcome and rejects the wait's Deferred, so
     * React\Async\await() throws $reason inside the task. A settled wait ignores every later wake-up.
     */
    public function cancel(object $task, \fiber\CancelledException $reason): void
    {
        ($this->waits[$task] ?? null)?->__invoke(false, $reason);
    }

    /**
     * React's await() from {main} stops the loop as soon as the awaited promise settles (Loop::stop()), whether
     * or not timers or streams are still registered, so a sleeping service never keeps the outermost run()'s
     * wait alive and no unreference is needed. A service is therefore go(); its leftover watchers stay in the
     * loop and fire if the loop is run again.
     */
    public function service(\Closure $f): object
    {
        return $this->go($f);
    }

    public function canAwait(object $o): bool
    {
        return $o instanceof PromiseInterface || \method_exists($o, 'then');
    }

    public function await(object $o, float $timeout): mixed
    {
        return $this->wait(static function (\Closure $settle) use ($o): ?\Closure {
            $o->then(
                static fn($v) => $settle(true, $v),
                static fn($r) => $settle(false, $r instanceof \Throwable ? $r : new RejectedException($r)),
            );
            return null;
        }, $timeout);
    }

    public function sleep(float $seconds): void
    {
        $this->wait(static function (\Closure $settle) use ($seconds): \Closure {
            $timer = Loop::addTimer(\max(0.0, $seconds), static fn() => $settle(true, null));
            return static fn() => Loop::cancelTimer($timer);
        }, \PHP_FLOAT_MAX);
    }

    public function readable(mixed $resource, float $timeout): void
    {
        $this->waitStream('read', $resource, $timeout);
    }

    public function writable(mixed $resource, float $timeout): void
    {
        $this->waitStream('write', $resource, $timeout);
    }

    /**
     * React keeps one listener per stream and direction, so several waiters on the same stream share one
     * listener, and all of them wake when it fires.
     *
     * @param 'read'|'write' $dir
     */
    private ?\React\EventLoop\TimerInterface $sweeper = null;

    private function waitStream(string $dir, mixed $resource, float $timeout): void
    {
        $this->wait(function (\Closure $settle) use ($dir, $resource): \Closure {
            $key = (int) $resource;
            $id = ++$this->waiterId;
            if (!isset($this->streams[$dir][$key])) {
                $this->streams[$dir][$key] = [$resource, []];
                $fire = function () use ($dir, $key): void {
                    if (!isset($this->streams[$dir][$key])) {
                        return;
                    }
                    $waiters = $this->streams[$dir][$key][1];
                    $this->unlisten($dir, $key);
                    foreach ($waiters as $wake) {
                        $wake();
                    }
                };
                $dir === 'read' ? Loop::addReadStream($resource, $fire) : Loop::addWriteStream($resource, $fire);
                // A local fclose() is not an event stream_select can report: re-check while stream waits are pending.
                $this->sweeper ??= Loop::addPeriodicTimer(0.02, $this->sweep(...));
                ActivatingLoop::$recover = $this->recoverFromDeadStream(...);
            }
            $this->streams[$dir][$key][1][$id] = static fn() => $settle(true, null);

            return function () use ($dir, $key, $id): void {
                if (isset($this->streams[$dir][$key])) {
                    unset($this->streams[$dir][$key][1][$id]);
                    if ($this->streams[$dir][$key][1] === []) {
                        $this->unlisten($dir, $key);
                    }
                }
            };
        }, $timeout);
    }

    private function unlisten(string $dir, int $key): void
    {
        $resource = $this->streams[$dir][$key][0];
        unset($this->streams[$dir][$key]);
        try {
            $dir === 'read' ? Loop::removeReadStream($resource) : Loop::removeWriteStream($resource);
        } catch (\Throwable $e) {
            if (\is_resource($resource)) {
                throw $e; // only a vanished fd may fail to be removed
            }
        }
        if ($this->sweeper !== null && ($this->streams['read'] ?? []) === [] && ($this->streams['write'] ?? []) === []) {
            Loop::cancelTimer($this->sweeper);
            $this->sweeper = null;
            ActivatingLoop::$recover = null;
        }
    }

    /** stream_select() met a closed resource: remove the dead watchers (so the loop's arrays are clean) and wake. */
    private function recoverFromDeadStream(\Throwable $e): bool
    {
        if (!\str_contains($e->getMessage(), 'not a valid stream resource') && !\str_contains($e->getMessage(), 'No stream arrays')) {
            return false;
        }
        $this->sweep();
        return true;
    }

    /** Wake the waiters of every watched stream that was closed locally; they return normally. */
    private function sweep(): void
    {
        foreach (['read', 'write'] as $dir) {
            foreach ($this->streams[$dir] ?? [] as $key => [$resource, $waiters]) {
                if (!\is_resource($resource)) {
                    $this->unlisten($dir, $key); // removed before the loop selects on it again
                    foreach ($waiters as $wake) {
                        $wake();
                    }
                }
            }
        }
    }

    /**
     * Who is driving the current fiber? Walk the backtrace (it spans fibers) from the innermost frame
     * outwards: the first of {React\Async function, Fiber::start/resume, React\EventLoop frame} tells
     * whether the fiber was entered by React (async()), by user code (foreign), or from a loop callback.
     *
     * @return 'react'|'foreign'|'loop-foreign'
     */
    private function context(): string
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            return 'react';
        }
        $foreign = false;
        // The frame that called into user code from a fiber made by React\Async\async() lives in react/async.
        $asyncDir = \dirname((new \ReflectionClass(FiberMap::class))->getFileName()) . \DIRECTORY_SEPARATOR;
        foreach (\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? '';
            if (!$foreign && ($class === \Fiber::class)) {
                $foreign = true; // entered by user code, not by React\Async\async()
                continue;
            }
            if (!$foreign && \str_starts_with($frame['file'] ?? '', $asyncDir)) {
                return 'react';
            }
            if ($foreign && \str_starts_with($class, 'React\\EventLoop\\')) {
                return 'loop-foreign';
            }
        }
        return 'foreign';
    }

    /**
     * @param \Closure(\Closure(bool, mixed): void): (\Closure|null) $subscribe registers interest, returns an optional canceller
     */
    private function wait(\Closure $subscribe, float $timeout): mixed
    {
        $context = $this->context();
        if ($context === 'loop-foreign') {
            throw new \LogicException('fiber-interop/react: cannot wait in place from a fiber nobody owns while the React loop is running; create the fiber with React\Async\async() or go()');
        }

        $scope = \Scope::getCurrent();
        $deferred = new Deferred();
        $done = false;
        $cancel = null;
        $timer = null;

        $settle = static function (bool $ok, mixed $value) use (&$done, &$cancel, &$timer, $deferred): void {
            if ($done) {
                return;
            }
            $done = true;
            $cancel !== null && $cancel();
            $timer !== null && Loop::cancelTimer($timer);
            $ok ? $deferred->resolve($value) : $deferred->reject($value);
        };
        $scoped = static fn(bool $ok, mixed $value) => $scope->run($settle, $ok, $value);

        $cancel = $subscribe($scoped);
        if (!$done && $timeout !== \PHP_FLOAT_MAX) {
            $timer = Loop::addTimer(\max(0.0, $timeout), static fn() => $scope->run($settle, false, new Timeout(\sprintf('Timed out after %.3fs', $timeout))));
        }

        if ($context === 'react') {
            $task = $this->current();
            $task !== null && $this->waits[$task] = $scoped;
            try {
                return await($deferred->promise());
            } finally {
                if ($task !== null) {
                    unset($this->waits[$task]);
                }
                ActivatingLoop::activate(); // api resume (also on return to {main})
            }
        }

        // Foreign fiber: React\Async\await() would suspend it, so run the loop here until settled.
        $result = null;
        $deferred->promise()->then(
            static function ($v) use (&$result): void { $result = [true, $v]; Loop::stop(); },
            static function ($e) use (&$result): void { $result = [false, $e]; Loop::stop(); },
        );
        while ($result === null) {
            Loop::run();
            ActivatingLoop::activate();
            if ($result === null) {
                throw new \LogicException('Deadlock: waiting with nothing scheduled');
            }
        }
        if (!$result[0]) {
            throw $result[1];
        }
        return $result[1];
    }
}
