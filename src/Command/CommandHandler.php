<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Command;

use Saboor\SwooleKv\Storage\KeyValueStore;

final class CommandHandler
{
    public function __construct(
        private readonly KeyValueStore $store,
    ) {
    }

    public function handle(ParsedCommand $command): string
    {
        return match ($command->name) {
            'PING' => $this->handlePing($command),
            'SET' => $this->handleSet($command),
            'GET' => $this->handleGet($command),
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
}
