<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Command;

final class CommandParser
{
    public function parse(string $input): ?ParsedCommand
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $input);

        if ($parts === false || $parts === []) {
            return null;
        }

        $name = strtoupper(array_shift($parts));

        return new ParsedCommand($name, array_values($parts));
    }
}
