<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:stop',
    description: 'Stop the openKv TCP server'
)]
final class StopServerCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('pid-file', null, InputOption::VALUE_REQUIRED, 'Path to the server PID file.', 'runtime/openkv.pid')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Seconds to wait for shutdown.', 10);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pidFile = (string) $input->getOption('pid-file');
        $timeout = max(1, (int) $input->getOption('timeout'));

        if (! is_file($pidFile)) {
            $output->writeln(sprintf('<error>PID file not found: %s</error>', $pidFile));

            return Command::FAILURE;
        }

        $pid = (int) trim((string) file_get_contents($pidFile));

        if ($pid < 1 || ! $this->isRunning($pid)) {
            unlink($pidFile);
            $output->writeln('<comment>Removed stale PID file.</comment>');

            return Command::SUCCESS;
        }

        posix_kill($pid, SIGTERM);

        for ($attempt = 0; $attempt < $timeout * 10; ++$attempt) {
            if (! $this->isRunning($pid) || ! is_file($pidFile)) {
                $output->writeln(sprintf('Stopped openKv server with PID %d.', $pid));

                return Command::SUCCESS;
            }

            usleep(100_000);
        }

        $output->writeln(sprintf('<error>Server PID %d did not stop within %d seconds.</error>', $pid, $timeout));

        return Command::FAILURE;
    }

    private function isRunning(int $pid): bool
    {
        return posix_kill($pid, 0);
    }
}
