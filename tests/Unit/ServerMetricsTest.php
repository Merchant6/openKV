<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saboor\SwooleKv\Metrics\ServerMetrics;

final class ServerMetricsTest extends TestCase
{
    public function testItBuildsDeterministicSnapshots(): void
    {
        $now = 100;
        $metrics = new ServerMetrics(static function () use (&$now): int {
            return $now;
        });

        $metrics->recordCommand();
        $metrics->recordCommand();
        $metrics->recordConnectionOpened();
        $metrics->recordWorkerStarted();
        $metrics->recordExpiredKeys(3);
        $now = 104;

        $snapshot = $metrics->snapshot();

        self::assertSame(4, $snapshot['uptime_seconds']);
        self::assertSame(2, $snapshot['commands_processed']);
        self::assertSame(0.5, $snapshot['requests_per_second']);
        self::assertSame(1, $snapshot['connections_handled']);
        self::assertSame(1, $snapshot['active_connections']);
        self::assertSame(3, $snapshot['expired_keys']);
        self::assertSame(1, $snapshot['worker_count']);
        self::assertGreaterThan(0, $snapshot['memory_usage_bytes']);
    }
}
