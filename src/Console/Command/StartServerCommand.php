<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Console\Command;

use OpenSwoole\Server;
use Saboor\SwooleKv\Server\ServerEventHandler;
use Saboor\SwooleKv\Storage\SwooleTableStore;
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
        $store = SwooleTableStore::create();
        $eventHandler = new ServerEventHandler($output, $host, $port, $store);

        $server->on('start', $eventHandler->onStart(...));
        $server->on('workerStart', $eventHandler->onWorkerStart(...));
        $server->on('workerStop', $eventHandler->onWorkerStop(...));
        $server->on('connect', $eventHandler->onConnect(...));
        $server->on('receive', $eventHandler->onReceive(...));
        $server->on('close', $eventHandler->onClose(...));

        $server->start();

        return Command::SUCCESS;
    }
}
