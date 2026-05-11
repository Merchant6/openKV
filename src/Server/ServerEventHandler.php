<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Server;

use OpenSwoole\Server;
use Saboor\SwooleKv\Command\CommandHandler;
use Saboor\SwooleKv\Command\CommandParser;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ServerEventHandler
{
    public function __construct(
        private OutputInterface $output,
        private string $host,
        private int $port,
        private CommandParser $commandParser = new CommandParser(),
        private CommandHandler $commandHandler = new CommandHandler(),
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
