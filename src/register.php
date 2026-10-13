<?php

declare(strict_types=1);

\async\register(static fn() => new \FiberInterop\React\Adapter(), 'fiber-interop/react');
