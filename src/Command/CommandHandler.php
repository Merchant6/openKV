<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Command;

use InvalidArgumentException;
use Saboor\SwooleKv\Metrics\ServerMetrics;
use Saboor\SwooleKv\Server\ConnectionRegistry;
use Saboor\SwooleKv\Storage\KeyValueStore;

final class CommandHandler
{
    public function __construct(
        private readonly KeyValueStore $store,
        private readonly ServerMetrics $metrics = new ServerMetrics(),
        private readonly ?ConnectionRegistry $connectionRegistry = null,
    ) {
    }

    public function handle(ParsedCommand $command): string
    {
        $this->metrics->recordCommand();

        return match ($command->name) {
            'PING' => $this->handlePing($command),
            'SET' => $this->handleSet($command),
            'GET' => $this->handleGet($command),
            'DEL' => $this->handleDelete($command),
            'EXISTS' => $this->handleExists($command),
            'EXPIRE' => $this->handleExpire($command),
            'TTL' => $this->handleTtl($command),
            'INCR' => $this->handleIncrement($command, 1),
            'DECR' => $this->handleIncrement($command, -1),
            'INFO' => $this->handleInfo($command),
            'STATS' => $this->handleStats($command),
            default => sprintf("-ERR command '%s' is not implemented yet\r\n", $command->name),
        };
    }

    private function handlePing(ParsedCommand $command): string
    {
        if ($command->arguments === []) {
            return "+PONG\r\n";
        }

        if (count($command->arguments) === 1) {
            return sprintf("$%d\r\n%s\r\n", strlen($command->arguments[0]), $command->arguments[0]);
        }

        return "-ERR wrong number of arguments for 'PING' command\r\n";
    }

    private function handleSet(ParsedCommand $command): string
    {
        if (count($command->arguments) !== 2) {
            return "-ERR wrong number of arguments for 'SET' command\r\n";
        }

        [$key, $value] = $command->arguments;

        $this->store->set($key, $value);

        return "+OK\r\n";
    }

    private function handleGet(ParsedCommand $command): string
    {
        if (count($command->arguments) !== 1) {
            return "-ERR wrong number of arguments for 'GET' command\r\n";
        }

        $value = $this->store->get($command->arguments[0]);

        if ($value === null) {
            return "$-1\r\n";
        }

        return sprintf("$%d\r\n%s\r\n", strlen($value), $value);
    }

    private function handleDelete(ParsedCommand $command): string
    {
        if (count($command->arguments) !== 1) {
            return "-ERR wrong number of arguments for 'DEL' command\r\n";
        }

        return sprintf(":%d\r\n", $this->store->delete($command->arguments[0]) ? 1 : 0);
    }

    private function handleExists(ParsedCommand $command): string
    {
        if (count($command->arguments) !== 1) {
            return "-ERR wrong number of arguments for 'EXISTS' command\r\n";
        }

        return sprintf(":%d\r\n", $this->store->exists($command->arguments[0]) ? 1 : 0);
    }

    private function handleExpire(ParsedCommand $command): string
    {
        if (count($command->arguments) !== 2) {
            return "-ERR wrong number of arguments for 'EXPIRE' command\r\n";
        }

        [$key, $seconds] = $command->arguments;

        if (! ctype_digit($seconds) || (int) $seconds < 1) {
            return "-ERR seconds must be a positive integer\r\n";
        }

        return sprintf(":%d\r\n", $this->store->expire($key, (int) $seconds) ? 1 : 0);
    }

    private function handleTtl(ParsedCommand $command): string
    {
        if (count($command->arguments) !== 1) {
            return "-ERR wrong number of arguments for 'TTL' command\r\n";
        }

        return sprintf(":%d\r\n", $this->store->ttl($command->arguments[0]));
    }

    private function handleIncrement(ParsedCommand $command, int $delta): string
    {
        if (count($command->arguments) !== 1) {
            return sprintf("-ERR wrong number of arguments for '%s' command\r\n", $command->name);
        }

        try {
            return sprintf(":%d\r\n", $this->store->increment($command->arguments[0], $delta));
        } catch (InvalidArgumentException $exception) {
            return sprintf("-ERR %s\r\n", $exception->getMessage());
        }
    }

    private function handleInfo(ParsedCommand $command): string
    {
        if ($command->arguments !== []) {
            return "-ERR wrong number of arguments for 'INFO' command\r\n";
        }

        $snapshot = $this->metrics->snapshot();
        $connectionSnapshot = $this->connectionSnapshot($snapshot);
        $body = implode("\r\n", [
            '# Server',
            'swoolekv_version:0.1.0',
            sprintf('uptime_seconds:%d', $snapshot['uptime_seconds']),
            sprintf('worker_count:%d', $snapshot['worker_count']),
            '',
            '# Clients',
            sprintf('max_connections:%d', $connectionSnapshot['max_connections']),
            sprintf('connections_handled:%d', $connectionSnapshot['connections_handled']),
            sprintf('active_connections:%d', $connectionSnapshot['active_connections']),
            sprintf('rejected_connections:%d', $connectionSnapshot['rejected_connections']),
            '',
            '# Stats',
            sprintf('commands_processed:%d', $snapshot['commands_processed']),
            sprintf('requests_per_second:%.2f', $snapshot['requests_per_second']),
            sprintf('expired_keys:%d', $snapshot['expired_keys']),
            '',
            '# Storage',
            sprintf('total_keys:%d', $this->store->keyCount()),
            '',
            '# Memory',
            sprintf('memory_usage_bytes:%d', $snapshot['memory_usage_bytes']),
        ]);

        return sprintf("$%d\r\n%s\r\n", strlen($body), $body);
    }

    private function handleStats(ParsedCommand $command): string
    {
        if ($command->arguments !== []) {
            return "-ERR wrong number of arguments for 'STATS' command\r\n";
        }

        $snapshot = $this->metrics->snapshot();
        $connectionSnapshot = $this->connectionSnapshot($snapshot);
        $body = implode("\r\n", [
            sprintf('commands_processed:%d', $snapshot['commands_processed']),
            sprintf('total_keys:%d', $this->store->keyCount()),
            sprintf('expired_keys:%d', $snapshot['expired_keys']),
            sprintf('uptime_seconds:%d', $snapshot['uptime_seconds']),
            sprintf('requests_per_second:%.2f', $snapshot['requests_per_second']),
            sprintf('max_connections:%d', $connectionSnapshot['max_connections']),
            sprintf('connections_handled:%d', $connectionSnapshot['connections_handled']),
            sprintf('active_connections:%d', $connectionSnapshot['active_connections']),
            sprintf('rejected_connections:%d', $connectionSnapshot['rejected_connections']),
        ]);

        return sprintf("$%d\r\n%s\r\n", strlen($body), $body);
    }

    /**
     * @param array{
     *     connections_handled: int,
     *     active_connections: int
     * } $snapshot
     *
     * @return array{
     *     max_connections: int,
     *     connections_handled: int,
     *     active_connections: int,
     *     rejected_connections: int
     * }
     */
    private function connectionSnapshot(array $snapshot): array
    {
        if ($this->connectionRegistry !== null) {
            return $this->connectionRegistry->snapshot();
        }

        return [
            'max_connections' => 0,
            'connections_handled' => $snapshot['connections_handled'],
            'active_connections' => $snapshot['active_connections'],
            'rejected_connections' => 0,
        ];
    }
}
