<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saboor\SwooleKv\Command\CommandParser;

final class CommandParserTest extends TestCase
{
    public function testItParsesCommandNameAndArguments(): void
    {
        $command = (new CommandParser())->parse("  set   name   Saboor  \r\n");

        self::assertNotNull($command);
        self::assertSame('SET', $command->name);
        self::assertSame(['name', 'Saboor'], $command->arguments);
    }

    public function testItRejectsEmptyInput(): void
    {
        self::assertNull((new CommandParser())->parse(" \r\n"));
    }
}
