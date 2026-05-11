<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Command;

final class CommandHandler
{
    public function handle(ParsedCommand $command): string
    {
        return match ($command->name) {
            'PING' => $this->handlePing($command),
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
}
