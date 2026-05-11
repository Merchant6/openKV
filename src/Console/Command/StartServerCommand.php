<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Console\Command;

use OpenSwoole\Server;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:start',
    description: 'Start the SwooleKV TCP server'
)]
final class StartServerCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host address to bind.', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'TCP port to listen on.', 9501);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');

        $server = new Server($host, $port);

        $server->on('start', static function (Server $server) use ($output, $host, $port): void {
            $output->writeln(sprintf('SwooleKV TCP server listening on %s:%d', $host, $port));
        });

        $server->on('connect', static function (Server $server, int $fd): void {
            $server->send($fd, "+OK SwooleKV connected\r\n");
        });

        $server->on('receive', static function (Server $server, int $fd, int $reactorId, string $data): void {
            $server->send($fd, "-ERR command handling is not implemented yet\r\n");
        });

        $server->on('close', static function (Server $server, int $fd): void {
        });

        $server->start();

        return Command::SUCCESS;
    }
}
