<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TcpServerTest extends TestCase
{
    /**
     * @var resource|null
     */
    private $process = null;

    private int $port;

    protected function setUp(): void
    {
        if (! extension_loaded('openswoole')) {
            self::markTestSkipped('OpenSwoole extension is required for TCP integration tests.');
        }

        $this->port = $this->reservePort();
        $this->startServer();
    }

    protected function tearDown(): void
    {
        $this->stopServer();
    }

    public function testItRejectsConnectionsAboveConfiguredLimit(): void
    {
        $this->stopServer();
        $this->startServer(1);

        $firstConnection = $this->connect();
        self::assertSame("+OK openKv connected\r\n", $this->readLine($firstConnection));

        $secondConnection = $this->connect();
        self::assertSame("-ERR max clients reached\r\n", $this->readLine($secondConnection));

        $stats = $this->sendCommand($firstConnection, 'STATS');
        self::assertStringContainsString("max_connections:1\r\n", $stats);
        self::assertStringContainsString("active_connections:1\r\n", $stats);
        self::assertStringContainsString("rejected_connections:1\r\n", $stats);

        fclose($firstConnection);
        fclose($secondConnection);
    }

    private function stopServer(): void
    {
        if ($this->process === null) {
            return;
        }

        proc_terminate($this->process);
        proc_close($this->process);
        $this->process = null;
    }

    public function testItHandlesCoreCommandsOverTcp(): void
    {
        $connection = $this->connect();

        self::assertSame("+OK openKv connected\r\n", $this->readLine($connection));
        self::assertSame("+PONG\r\n", $this->sendCommand($connection, 'PING'));
        self::assertSame("+OK\r\n", $this->sendCommand($connection, 'SET count 10'));
        self::assertSame(":11\r\n", $this->sendCommand($connection, 'INCR count'));
        self::assertSame(":10\r\n", $this->sendCommand($connection, 'DECR count'));
        self::assertSame("$2\r\n10\r\n", $this->sendCommand($connection, 'GET count'));
        self::assertSame(":1\r\n", $this->sendCommand($connection, 'EXISTS count'));
        self::assertSame(":1\r\n", $this->sendCommand($connection, 'DEL count'));
        self::assertSame("$-1\r\n", $this->sendCommand($connection, 'GET count'));

        fclose($connection);
    }

    public function testItExpiresKeysOverTcp(): void
    {
        $connection = $this->connect();

        self::assertSame("+OK openKv connected\r\n", $this->readLine($connection));
        self::assertSame("+OK\r\n", $this->sendCommand($connection, 'SET session abc123'));
        self::assertSame(":1\r\n", $this->sendCommand($connection, 'EXPIRE session 1'));
        self::assertMatchesRegularExpression('/^:[01]\r\n$/', $this->sendCommand($connection, 'TTL session'));

        sleep(2);

        self::assertSame("$-1\r\n", $this->sendCommand($connection, 'GET session'));
        self::assertSame(":-2\r\n", $this->sendCommand($connection, 'TTL session'));

        fclose($connection);
    }

    public function testItReportsInfoAndStatsOverTcp(): void
    {
        $connection = $this->connect();

        self::assertSame("+OK openKv connected\r\n", $this->readLine($connection));
        self::assertSame("+OK\r\n", $this->sendCommand($connection, 'SET name Saboor'));

        $stats = $this->sendCommand($connection, 'STATS');
        self::assertStringContainsString("commands_processed:2\r\n", $stats);
        self::assertStringContainsString("total_keys:1\r\n", $stats);
        self::assertStringContainsString('connections_handled:', $stats);
        self::assertStringContainsString("active_connections:1\r\n", $stats);

        $info = $this->sendCommand($connection, 'INFO');
        self::assertStringContainsString("# Server\r\n", $info);
        self::assertStringContainsString("commands_processed:3\r\n", $info);
        self::assertStringContainsString("total_keys:1\r\n", $info);
        self::assertStringContainsString("# Memory\r\n", $info);

        fclose($connection);
    }

    public function testItRejectsInvalidCommandsOverTcp(): void
    {
        $connection = $this->connect();

        self::assertSame("+OK openKv connected\r\n", $this->readLine($connection));
        self::assertSame("-ERR command 'UNKNOWN' is not implemented yet\r\n", $this->sendCommand($connection, 'UNKNOWN'));
        self::assertSame("-ERR wrong number of arguments for 'SET' command\r\n", $this->sendCommand($connection, 'SET only-key'));
        self::assertSame("-ERR seconds must be a positive integer\r\n", $this->sendCommand($connection, 'EXPIRE key nope'));
        self::assertSame("+OK\r\n", $this->sendCommand($connection, 'SET name Saboor'));
        self::assertSame("-ERR Value for key 'name' is not an integer.\r\n", $this->sendCommand($connection, 'INCR name'));

        fclose($connection);
    }

    /**
     * @return resource
     */
    private function connect()
    {
        $connection = @stream_socket_client(
            sprintf('tcp://127.0.0.1:%d', $this->port),
            $errorCode,
            $errorMessage,
            2.0,
        );

        if ($connection === false) {
            throw new RuntimeException(sprintf('Unable to connect to TCP server: [%d] %s', $errorCode, $errorMessage));
        }

        stream_set_timeout($connection, 2);

        return $connection;
    }

    /**
     * @param resource $connection
     */
    private function sendCommand($connection, string $command): string
    {
        fwrite($connection, $command . "\r\n");

        return $this->readResponse($connection);
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
        $body = $this->readBytes($connection, $length + 2);

        return $line . $body;
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

    private function reservePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new RuntimeException('Unable to reserve a local TCP port.');
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($name === false) {
            throw new RuntimeException('Unable to inspect reserved TCP port.');
        }

        return (int) substr(strrchr($name, ':'), 1);
    }

    private function startServer(?int $maxConnections = null): void
    {
        $command = [
            'php',
            dirname(__DIR__, 2) . '/bin/swoole-kv',
            'server:start',
            '--host=127.0.0.1',
            sprintf('--port=%d', $this->port),
        ];

        if ($maxConnections !== null) {
            $command[] = sprintf('--max-connections=%d', $maxConnections);
        }

        $this->process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
        );

        if (! is_resource($this->process)) {
            throw new RuntimeException('Unable to start TCP server process.');
        }

        fclose($pipes[0]);

        $deadline = microtime(true) + 5.0;

        do {
            $connection = @stream_socket_client(
                sprintf('tcp://127.0.0.1:%d', $this->port),
                $errorCode,
                $errorMessage,
                0.1,
            );

            if ($connection !== false) {
                stream_set_timeout($connection, 1);
                fgets($connection);
                fclose($connection);
                usleep(100_000);

                return;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(sprintf('TCP server did not start on port %d.', $this->port));
    }
}
