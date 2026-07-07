<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Console\Command;

use OpenSwoole\Server;
use Saboor\SwooleKv\Server\ConnectionRegistry;
use Saboor\SwooleKv\Server\ServerEventHandler;
use Saboor\SwooleKv\Storage\SwooleTableStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:start',
    description: 'Start the openKv TCP server'
)]
final class StartServerCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host address to bind.', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'TCP port to listen on.', 9501)
            ->addOption('max-connections', null, InputOption::VALUE_REQUIRED, 'Maximum active client connections.', 10000)
            ->addOption('worker-num', null, InputOption::VALUE_REQUIRED, 'Number of OpenSwoole workers.', 1)
            ->addOption('backlog', null, InputOption::VALUE_REQUIRED, 'TCP listen backlog.', 1024)
            ->addOption('heartbeat-idle-time', null, InputOption::VALUE_REQUIRED, 'Seconds before idle clients are closed.', 120)
            ->addOption('heartbeat-check-interval', null, InputOption::VALUE_REQUIRED, 'Seconds between idle connection checks.', 30)
            ->addOption('pid-file', null, InputOption::VALUE_REQUIRED, 'Path to the server PID file.', 'runtime/openkv.pid');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');
        $maxConnections = max(1, (int) $input->getOption('max-connections'));
        $workerNum = max(1, (int) $input->getOption('worker-num'));
        $backlog = max(1, (int) $input->getOption('backlog'));
        $heartbeatIdleTime = max(1, (int) $input->getOption('heartbeat-idle-time'));
        $heartbeatCheckInterval = max(1, (int) $input->getOption('heartbeat-check-interval'));
        $pidFile = (string) $input->getOption('pid-file');
        $reservedFileDescriptors = 128;

        if (! $this->hasEnoughFileDescriptors($maxConnections, $reservedFileDescriptors, $output)) {
            return Command::FAILURE;
        }

        $server = new Server($host, $port);
        $server->set([
            'worker_num' => $workerNum,
            'max_conn' => $maxConnections + $reservedFileDescriptors,
            'backlog' => $backlog,
            'open_tcp_nodelay' => true,
            'heartbeat_idle_time' => $heartbeatIdleTime,
            'heartbeat_check_interval' => $heartbeatCheckInterval,
        ]);

        $store = SwooleTableStore::create();
        $connectionRegistry = ConnectionRegistry::create($maxConnections);
        $eventHandler = new ServerEventHandler($output, $host, $port, $store, $connectionRegistry, $pidFile);

        $server->on('start', $eventHandler->onStart(...));
        $server->on('workerStart', $eventHandler->onWorkerStart(...));
        $server->on('workerStop', $eventHandler->onWorkerStop(...));
        $server->on('connect', $eventHandler->onConnect(...));
        $server->on('receive', $eventHandler->onReceive(...));
        $server->on('close', $eventHandler->onClose(...));
        $server->on('shutdown', $eventHandler->onShutdown(...));

        $server->start();

        return Command::SUCCESS;
    }

    private function hasEnoughFileDescriptors(
        int $maxConnections,
        int $reservedFileDescriptors,
        OutputInterface $output,
    ): bool {
        $openFilesLimit = $this->openFilesLimit();

        if ($openFilesLimit === null) {
            return true;
        }

        $requiredOpenFiles = $maxConnections + $reservedFileDescriptors;

        if ($requiredOpenFiles <= $openFilesLimit) {
            return true;
        }

        $output->writeln(sprintf(
            '<error>Cannot start with --max-connections=%d because this process can open only %d files.</error>',
            $maxConnections,
            $openFilesLimit,
        ));
        $output->writeln(sprintf(
            '<comment>Raise the file descriptor limit to at least %d, for example: ulimit -n %d</comment>',
            $requiredOpenFiles,
            $requiredOpenFiles,
        ));
        $output->writeln('<comment>Or start the server with a lower --max-connections value.</comment>');

        return false;
    }

    private function openFilesLimit(): ?int
    {
        if (! function_exists('posix_getrlimit')) {
            return null;
        }

        $limits = posix_getrlimit();
        $softOpenFiles = $limits['soft openfiles'] ?? null;

        if (! is_int($softOpenFiles)) {
            return null;
        }

        return $softOpenFiles;
    }
}
