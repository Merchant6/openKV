<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Server;

use OpenSwoole\Atomic;
use OpenSwoole\Table;

final class ConnectionRegistry
{
    private readonly Atomic $activeConnections;
    private readonly Atomic $connectionsHandled;
    private readonly Atomic $rejectedConnections;

    public function __construct(
        private readonly Table $connections,
        private readonly int $maxConnections,
    ) {
        $this->activeConnections = new Atomic();
        $this->connectionsHandled = new Atomic();
        $this->rejectedConnections = new Atomic();
    }

    public static function create(int $maxConnections): self
    {
        $table = new Table($maxConnections + 128);
        $table->column('connected_at', Table::TYPE_INT);
        $table->column('last_seen_at', Table::TYPE_INT);
        $table->create();

        return new self($table, $maxConnections);
    }

    public function register(int $fd): bool
    {
        $activeConnections = $this->activeConnections->add(1);

        if ($activeConnections > $this->maxConnections) {
            $this->activeConnections->sub(1);
            $this->rejectedConnections->add(1);

            return false;
        }

        $now = time();
        $this->connectionsHandled->add(1);
        $this->connections->set((string) $fd, [
            'connected_at' => $now,
            'last_seen_at' => $now,
        ]);

        return true;
    }

    public function touch(int $fd): void
    {
        $row = $this->connections->get((string) $fd);

        if ($row === false) {
            return;
        }

        $this->connections->set((string) $fd, [
            'connected_at' => $row['connected_at'],
            'last_seen_at' => time(),
        ]);
    }

    public function unregister(int $fd): void
    {
        if (! $this->connections->del((string) $fd)) {
            return;
        }

        $this->activeConnections->sub(1);
    }

    /**
     * @return array{
     *     max_connections: int,
     *     connections_handled: int,
     *     active_connections: int,
     *     rejected_connections: int
     * }
     */
    public function snapshot(): array
    {
        return [
            'max_connections' => $this->maxConnections,
            'connections_handled' => $this->connectionsHandled->get(),
            'active_connections' => $this->activeConnections->get(),
            'rejected_connections' => $this->rejectedConnections->get(),
        ];
    }
}
