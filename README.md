# fiber-interop/react

Native ReactPHP adapter for [`fiber-interop/api`](../api): `go`, `await`, `sleep`, `readable`, `writable` on
`react/event-loop` ^1.5, `react/promise` ^3 and `react/async` ^4 (PHP 8.1+).

```
composer require fiber-interop/react
```

Requiring the package registers the adapter (`src/register.php`); nothing else to configure.

## api -> React mapping

| api | React |
| --- | --- |
| `go($f)` | `React\Async\async($f)()`; returns a real `React\Promise\PromiseInterface` (scope captured at call time) |
| `await($o)` | `React\Async\await()` in `{main}` or in an `async()` fiber; promises and plain then-ables accepted |
| `sleep($s)` | `Loop::addTimer()` + suspension |
| `readable()` / `writable()` | `Loop::addReadStream()` / `addWriteStream()`, one listener per stream shared by all waiters |
| timeout | `Loop::addTimer()` racing the awaited thing; `async\Timeout`, the awaited promise is left alone, late result ignored |
| non-Throwable rejection | `async\RejectedException` (plain then-ables; React v3 itself rejects with `TypeError`) |
| unawaited failure | React's own unhandled-rejection reporting (`React\Promise\set_rejection_handler`) |

`await()` decides how to wait by walking `debug_backtrace()` (it spans fibers): a fiber entered through
`react/async` is React's and suspends through `React\Async\await()`; any other fiber is foreign.

## In-place rule

From a foreign fiber (one started with `new Fiber`), `React\Async\await()` would `Fiber::suspend()` a fiber React
does not own. The adapter instead runs `Loop::run()` inside that fiber until the awaited thing settles
(`Loop::stop()` from the settle callback). If the foreign fiber was started from inside a running loop callback the
loop cannot be re-entered; a `LogicException` is thrown.

## What the ReactPHP maintainers would own

`src/Adapter.php` (about 200 lines) and `src/register.php`. No React internals are used besides `FiberMap`'s
location, used to recognise fibers made by `async()`.

## The ask to React

A re-entrant, foreign-fiber-safe "run until settled". Concretely, in `react/async` `SimpleFiber::suspend()`
(src/SimpleFiber.php) and `await()` (src/functions.php): when `Fiber::getCurrent()` is a fiber React did not create,
`suspend()` calls `Fiber::suspend()` and parks a fiber nobody will resume. A public
`Loop::runUntil(PromiseInterface|callable $done)` (or `Loop::isRunning()` plus a stop token that does not end an
outer `run()`) would let the adapter drop backtrace inspection and make `Loop::stop()` safe when nested.

## Limitations

- In-place waiting from a foreign fiber shares `Loop::stop()` with any suspended `{main}` scheduler that
  `React\Async\await()` created earlier in the same process: the nested stop ends that scheduler's `Loop::run()`.
  Mix the two styles in one process only if foreign fibers are not used after a `{main}` await (the tests for the
  in-place path run in separate processes for this reason).
- No cancellation of `go()` tasks beyond what `React\Promise` `cancel()` gives.
- Foreign fibers started inside a loop callback cannot wait (see above).

## Tests

```
vendor/bin/phpunit
php vendor/fiber-interop/api/bin/fiber-interop-conformance tests/bootstrap.php
```
