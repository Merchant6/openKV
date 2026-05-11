<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Server;

use OpenSwoole\Server;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ServerEventHandler
{
    public function __construct(
        private OutputInterface $output,
        private string $host,
        private int $port,
    ) {
    }

    public function onStart(Server $server): void
    {
        $this->output->writeln(sprintf('SwooleKV TCP server listening on %s:%d', $this->host, $this->port));
    }

    public function onConnect(Server $server, int $fd): void
    {
        $this->output->writeln(sprintf('Client connected: #%d', $fd));
        $server->send($fd, "+OK SwooleKV connected\r\n");
    }

    public function onReceive(Server $server, int $fd, int $reactorId, string $data): void
    {
        $server->send($fd, "-ERR command handling is not implemented yet\r\n");
    }

    public function onClose(Server $server, int $fd): void
    {
        $this->output->writeln(sprintf('Client disconnected: #%d', $fd));
    }
}
