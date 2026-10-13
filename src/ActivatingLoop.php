<?php

declare(strict_types=1);

namespace FiberInterop\React;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Decorates a React loop so that every callback it hands to user code first runs
 * \fiber_interop\scope\activate(), and so that run() activates the scope again when it returns to its caller.
 */
final class ActivatingLoop implements LoopInterface
{
    /** @var array<int|string, \Closure> original listener key => wrapper, for removeSignal() */
    private array $signals = [];

    public function __construct(private readonly LoopInterface $inner) {}

    public static function activate(): void
    {
        if (\function_exists('fiber_interop\\scope\\activate')) {
            \fiber_interop\scope\activate();
        }
    }

    private static function wrap(callable $f): \Closure
    {
        return static function (mixed ...$args) use ($f): mixed {
            self::activate();
            return $f(...$args);
        };
    }

    private static function key(callable $f): int|string
    {
        return \is_object($f) ? \spl_object_id($f) : (\is_array($f) ? (\is_object($f[0]) ? \spl_object_id($f[0]) : $f[0]) . '::' . $f[1] : $f);
    }

    public function addReadStream($stream, $listener): void
    {
        $this->inner->addReadStream($stream, self::wrap($listener));
    }

    public function addWriteStream($stream, $listener): void
    {
        $this->inner->addWriteStream($stream, self::wrap($listener));
    }

    public function removeReadStream($stream): void
    {
        $this->inner->removeReadStream($stream);
    }

    public function removeWriteStream($stream): void
    {
        $this->inner->removeWriteStream($stream);
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        // The timer passed to the callback is the inner loop's timer, which is also what we return.
        return $this->inner->addTimer($interval, self::wrap($callback));
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        return $this->inner->addPeriodicTimer($interval, self::wrap($callback));
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        $this->inner->cancelTimer($timer);
    }

    public function futureTick($listener): void
    {
        $this->inner->futureTick(self::wrap($listener));
    }

    public function addSignal($signal, $listener): void
    {
        $wrapper = self::wrap($listener);
        $this->signals[$signal . ':' . self::key($listener)] = $wrapper;
        $this->inner->addSignal($signal, $wrapper);
    }

    public function removeSignal($signal, $listener): void
    {
        $k = $signal . ':' . self::key($listener);
        $this->inner->removeSignal($signal, $this->signals[$k] ?? $listener);
        unset($this->signals[$k]);
    }

    public function run(): void
    {
        try {
            $this->inner->run();
        } finally {
            self::activate();
        }
    }

    public function stop(): void
    {
        $this->inner->stop();
    }
}
