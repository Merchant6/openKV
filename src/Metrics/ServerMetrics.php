<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Metrics;

use Closure;

final class ServerMetrics
{
    private readonly Closure $clock;
    private readonly int $startedAt;
    private int $commandsProcessed = 0;
    private int $connectionsHandled = 0;
    private int $activeConnections = 0;
    private int $expiredKeys = 0;
    private int $workerCount = 0;

    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
        $this->startedAt = $this->now();
    }

    public function recordCommand(): void
    {
        ++$this->commandsProcessed;
    }

    public function recordConnectionOpened(): void
    {
        ++$this->connectionsHandled;
        ++$this->activeConnections;
    }

    public function recordConnectionClosed(): void
    {
        $this->activeConnections = max(0, $this->activeConnections - 1);
    }

    public function recordWorkerStarted(): void
    {
        ++$this->workerCount;
    }

    public function recordWorkerStopped(): void
    {
        $this->workerCount = max(0, $this->workerCount - 1);
    }

    public function recordExpiredKeys(int $count): void
    {
        $this->expiredKeys += $count;
    }

    /**
     * @return array{
     *     uptime_seconds: int,
     *     commands_processed: int,
     *     requests_per_second: float,
     *     connections_handled: int,
     *     active_connections: int,
     *     expired_keys: int,
     *     worker_count: int,
     *     memory_usage_bytes: int
     * }
     */
    public function snapshot(): array
    {
        $uptime = max(0, $this->now() - $this->startedAt);

        return [
            'uptime_seconds' => $uptime,
            'commands_processed' => $this->commandsProcessed,
            'requests_per_second' => $uptime === 0 ? 0.0 : $this->commandsProcessed / $uptime,
            'connections_handled' => $this->connectionsHandled,
            'active_connections' => $this->activeConnections,
            'expired_keys' => $this->expiredKeys,
            'worker_count' => $this->workerCount,
            'memory_usage_bytes' => memory_get_usage(true),
        ];
    }

    private function now(): int
    {
        return ($this->clock)();
    }
}
