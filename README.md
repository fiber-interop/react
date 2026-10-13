# fiber-interop/react

Native ReactPHP adapter for [`fiber-interop/api`](../api): `run`, `go`, `service`, `await`, `sleep`, `readable`, `writable` (all called inside `run()`) on
`react/event-loop` ^1.5, `react/promise` ^3 and `react/async` ^4 (PHP 8.1+).

```
composer require fiber-interop/react
```

Requiring the package registers the adapter (`src/register.php`); nothing else to configure.

## api -> React mapping

| api | React |
| --- | --- |
| `service($f)` | same as `go()`. React's `await()` from `{main}` calls `Loop::stop()` the moment the awaited promise settles, so a sleeping service (pending timer or stream listener) never keeps the outermost `run()` alive; no unreference is needed. Leftover watchers stay registered and fire if the loop runs again. |
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

## Scope state

The adapter keeps the per-scope superglobals of `fiber-interop/scope` correct by calling
`\fiber_interop\scope\activate()` (lazy; swaps only on a real scope change) wherever control reaches user code:

- **Decorating loop.** The adapter constructor installs `FiberInterop\React\ActivatingLoop` with `Loop::set()` (once; never
  double-wrapped). It wraps every callback given to `addTimer`, `addPeriodicTimer`, `addReadStream`, `addWriteStream`,
  `addSignal` and `futureTick` as `activate(); $cb(...)`, and `run()` activates after the inner `run()` returns.
  Watchers registered before the adapter was created are not wrapped.
- **Adapter waits.** `activate()` runs at the start of every `go()` task, after each suspension returns in the resumed
  fiber (`sleep`, `await`, `readable`, `writable`), and after an in-place `Loop::run()` returns.

Probes (`php probes/scope-*.php`; exit 0 and print `ok`, or print the mismatch and exit 1):

- `scope-api.php` passes: everything goes through the adapter's waits.
- `scope-raw-fiber.php` passes: a plain `Fiber` resumed by its creator sees the creator's `$_GET` (polyfill fallback).
- `scope-loop.php` **fails, by design of React**: written with `React\Async\async` + `React\Async\await` + `Loop::addTimer` only.
  After the timer fires, `await()` resumes the fiber with `$fiber->resume()` from a scheduler tick, and nothing runs
  in the target fiber between that resume and the user's next line, so no hook can activate the fiber's scope; the code
  reads the active (root) scope's `$_GET` (`null` for `req` in the probe) instead of its own.
  Observed output: `a after sleep 1 saw null`, `a after sleep 2 saw null`, `b after sleep 1 saw null`, `b after sleep 2 saw null`.

**Precise ask to ReactPHP:** a resume hook (a callback run in the target fiber, or around `$fiber->resume()`, in
`react/async` `SimpleFiber`/`await()`), or routing `await()` through a suspension object that user-visible code returns
from, as Revolt's `Suspension::suspend()` does. Until then, code that waits with raw `React\Async\await()` and then
reads superglobals may see the previously active scope's values; waiting through `async\sleep()`/`async\await()` is safe.

## What the ReactPHP maintainers would own

`src/Adapter.php` (about 200 lines), `src/ActivatingLoop.php` and `src/register.php`. No React internals are used besides `FiberMap`'s
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
- A local `fclose()` of a stream that fibers wait on is noticed by a 10 ms periodic sweep that exists only while stream waits are pending; the waits return normally within ~10 ms and the next operation on the closed resource fails.
- No cancellation of `go()` tasks beyond what `React\Promise` `cancel()` gives.
- Foreign fibers started inside a loop callback cannot wait (see above).

## Stress tier

The api's stress tier (`vendor/fiber-interop/api/bin/fiber-interop-stress tests/bootstrap.php`) passes
5 of 6 tests here. The failing one, `FrameMembershipTest::testForeignFiberChildIsNeverOrphaned`,
starts a plain `new Fiber` inside a task and calls `async\sleep()` from it while the React loop is
running. React's loop cannot be re-entered from inside a callback, so this adapter throws a clear
`LogicException` ("cannot wait in place from a fiber nobody owns while the React loop is running")
rather than hang. That is the same limitation as the in-place rule above, seen from the other side,
and the same ask to React: a re-entrant "run until settled". The other five tests pass at 200
iterations.

## Tests

```
vendor/bin/phpunit
php vendor/fiber-interop/api/bin/fiber-interop-conformance tests/bootstrap.php
```
