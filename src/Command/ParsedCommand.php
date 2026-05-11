<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Command;

final readonly class ParsedCommand
{
    /**
     * @param list<string> $arguments
     */
    public function __construct(
        public string $name,
        public array $arguments,
    ) {
    }
}
