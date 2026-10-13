<?php

declare(strict_types=1);

namespace FiberInterop\React\Tests;

use PHPUnit\Framework\TestCase;

final class ConformanceTest extends TestCase
{
    public function testAllExamplesPass(): void
    {
        $cmd = [PHP_BINARY, __DIR__ . '/../vendor/fiber-interop/api/bin/fiber-interop-conformance', __DIR__ . '/bootstrap.php'];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $this->assertSame(0, proc_close($p), $out);
        $this->assertStringContainsString('all examples passed', $out);
    }
}
