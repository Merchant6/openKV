<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Console\Command;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'stats',
    description: 'Read openKv server stats over TCP'
)]
final class StatsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Server host.', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Server port.', 9501)
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Socket timeout in seconds.', 2);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $connection = $this->connect(
                (string) $input->getOption('host'),
                (int) $input->getOption('port'),
                max(1, (int) $input->getOption('timeout')),
            );

            fgets($connection);
            fwrite($connection, "STATS\r\n");
            $output->writeln(trim($this->readBulkString($connection)));
            fclose($connection);
        } catch (RuntimeException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @return resource
     */
    private function connect(string $host, int $port, int $timeout)
    {
        $connection = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $errorCode, $errorMessage, $timeout);

        if ($connection === false) {
            throw new RuntimeException(sprintf('Unable to connect to TCP server: [%d] %s', $errorCode, $errorMessage));
        }

        stream_set_timeout($connection, $timeout);

        return $connection;
    }

    /**
     * @param resource $connection
     */
    private function readBulkString($connection): string
    {
        $line = fgets($connection);

        if ($line === false || ! str_starts_with($line, '$')) {
            throw new RuntimeException('Unable to read stats response from TCP server.');
        }

        $length = (int) substr($line, 1, -2);

        if ($length < 0) {
            return '';
        }

        $body = fread($connection, $length + 2);

        if ($body === false) {
            throw new RuntimeException('Unable to read stats response body from TCP server.');
        }

        return $body;
    }
}
