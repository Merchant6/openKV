<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Server;

use OpenSwoole\Server;
use Saboor\SwooleKv\Command\CommandHandler;
use Saboor\SwooleKv\Command\CommandParser;
use Saboor\SwooleKv\Metrics\ServerMetrics;
use Saboor\SwooleKv\Storage\KeyValueStore;
use Saboor\SwooleKv\Timer\ExpirationTimer;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ServerEventHandler
{
    private CommandHandler $commandHandler;
    private ExpirationTimer $expirationTimer;
    private ServerMetrics $metrics;

    public function __construct(
        private OutputInterface $output,
        private string $host,
        private int $port,
        KeyValueStore $store,
        private readonly ConnectionRegistry $connectionRegistry,
        private CommandParser $commandParser = new CommandParser(),
        ?CommandHandler $commandHandler = null,
        ?ExpirationTimer $expirationTimer = null,
        ?ServerMetrics $metrics = null,
    ) {
        $this->metrics = $metrics ?? new ServerMetrics();
        $this->commandHandler = $commandHandler ?? new CommandHandler($store, $this->metrics, $this->connectionRegistry);
        $this->expirationTimer = $expirationTimer ?? new ExpirationTimer($store, $this->metrics);
    }

    public function onStart(Server $server): void
    {
        $this->output->writeln(sprintf('SwooleKV TCP server listening on %s:%d', $this->host, $this->port));
    }

    public function onWorkerStart(Server $server, int $workerId): void
    {
        $this->metrics->recordWorkerStarted();
        $this->expirationTimer->start();
        $this->output->writeln(sprintf('Worker started: #%d', $workerId));
    }

    public function onWorkerStop(Server $server, int $workerId): void
    {
        $this->expirationTimer->stop();
        $this->metrics->recordWorkerStopped();
        $this->output->writeln(sprintf('Worker stopped: #%d', $workerId));
    }

    public function onConnect(Server $server, int $fd): void
    {
        if (! $this->connectionRegistry->register($fd)) {
            $server->send($fd, "-ERR max clients reached\r\n");
            $server->close($fd);

            return;
        }

        $this->metrics->recordConnectionOpened();
        $this->output->writeln(sprintf('Client connected: #%d', $fd));
        $server->send($fd, "+OK SwooleKV connected\r\n");
    }

    public function onReceive(Server $server, int $fd, int $reactorId, string $data): void
    {
        $this->connectionRegistry->touch($fd);
        $command = $this->commandParser->parse($data);

        if ($command === null) {
            $server->send($fd, "-ERR empty command\r\n");

            return;
        }

        $server->send($fd, $this->commandHandler->handle($command));
    }

    public function onClose(Server $server, int $fd): void
    {
        $this->connectionRegistry->unregister($fd);
        $this->metrics->recordConnectionClosed();
        $this->output->writeln(sprintf('Client disconnected: #%d', $fd));
    }
}
