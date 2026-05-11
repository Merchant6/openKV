<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Server;

use OpenSwoole\Server;
use Saboor\SwooleKv\Command\CommandHandler;
use Saboor\SwooleKv\Command\CommandParser;
use Saboor\SwooleKv\Storage\KeyValueStore;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ServerEventHandler
{
    private CommandHandler $commandHandler;

    public function __construct(
        private OutputInterface $output,
        private string $host,
        private int $port,
        KeyValueStore $store,
        private CommandParser $commandParser = new CommandParser(),
        ?CommandHandler $commandHandler = null,
    ) {
        $this->commandHandler = $commandHandler ?? new CommandHandler($store);
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
        $command = $this->commandParser->parse($data);

        if ($command === null) {
            $server->send($fd, "-ERR empty command\r\n");

            return;
        }

        $server->send($fd, $this->commandHandler->handle($command));
    }

    public function onClose(Server $server, int $fd): void
    {
        $this->output->writeln(sprintf('Client disconnected: #%d', $fd));
    }
}
