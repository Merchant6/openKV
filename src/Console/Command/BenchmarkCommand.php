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
    name: 'benchmark',
    description: 'Benchmark openKv with a reusable client-side connection pool'
)]
final class BenchmarkCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Server host.', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Server port.', 9501)
            ->addOption('connections', null, InputOption::VALUE_REQUIRED, 'Number of persistent client connections.', 50)
            ->addOption('requests', null, InputOption::VALUE_REQUIRED, 'Total requests to send.', 10000)
            ->addOption('command', null, InputOption::VALUE_REQUIRED, 'Command payload to benchmark.', 'PING')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Socket timeout in seconds.', 2);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');
        $connections = max(1, (int) $input->getOption('connections'));
        $requests = max(1, (int) $input->getOption('requests'));
        $command = trim((string) $input->getOption('command'));
        $timeout = max(1, (int) $input->getOption('timeout'));

        if ($command === '') {
            $output->writeln('<error>Command payload cannot be empty.</error>');

            return Command::INVALID;
        }

        $pool = [];
        $failed = 0;

        try {
            for ($connectionNumber = 0; $connectionNumber < $connections; ++$connectionNumber) {
                $connection = $this->connect($host, $port, $timeout);
                $banner = $this->readLine($connection);

                if (! str_starts_with($banner, '+OK')) {
                    throw new RuntimeException(sprintf('Unexpected server banner: %s', trim($banner)));
                }

                $pool[] = $connection;
            }

            $startedAt = microtime(true);

            for ($requestNumber = 0; $requestNumber < $requests; ++$requestNumber) {
                $connection = $pool[$requestNumber % $connections];

                try {
                    fwrite($connection, $command . "\r\n");
                    $this->readResponse($connection);
                } catch (RuntimeException) {
                    ++$failed;
                }
            }

            $elapsed = max(0.000001, microtime(true) - $startedAt);
        } catch (RuntimeException $exception) {
            $this->closePool($pool);
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }

        $this->closePool($pool);

        $successful = $requests - $failed;
        $output->writeln([
            'openKv benchmark complete',
            sprintf('command: %s', $command),
            sprintf('connections: %d', $connections),
            sprintf('requests: %d', $requests),
            sprintf('successful_requests: %d', $successful),
            sprintf('failed_requests: %d', $failed),
            sprintf('elapsed_seconds: %.4f', $elapsed),
            sprintf('requests_per_second: %.2f', $successful / $elapsed),
        ]);

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return resource
     */
    private function connect(string $host, int $port, int $timeout)
    {
        $connection = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errorCode,
            $errorMessage,
            $timeout,
        );

        if ($connection === false) {
            throw new RuntimeException(sprintf('Unable to connect to TCP server: [%d] %s', $errorCode, $errorMessage));
        }

        stream_set_timeout($connection, $timeout);

        return $connection;
    }

    /**
     * @param resource $connection
     */
    private function readResponse($connection): string
    {
        $line = $this->readLine($connection);

        if (! str_starts_with($line, '$') || $line === "$-1\r\n") {
            return $line;
        }

        $length = (int) substr($line, 1, -2);

        return $line . $this->readBytes($connection, $length + 2);
    }

    /**
     * @param resource $connection
     */
    private function readLine($connection): string
    {
        $line = fgets($connection);

        if ($line === false) {
            throw new RuntimeException('Unable to read line from TCP server.');
        }

        return $line;
    }

    /**
     * @param resource $connection
     */
    private function readBytes($connection, int $bytes): string
    {
        $buffer = '';

        while (strlen($buffer) < $bytes) {
            $chunk = fread($connection, $bytes - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Unable to read response body from TCP server.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * @param array<int, resource> $pool
     */
    private function closePool(array $pool): void
    {
        foreach ($pool as $connection) {
            fclose($connection);
        }
    }
}
