<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Console;

use Saboor\SwooleKv\Console\Command\StartServerCommand;
use Symfony\Component\Console\Application;

final class ApplicationFactory
{
    public static function create(): Application
    {
        $application = new Application('SwooleKV', '0.1.0');
        $application->add(new StartServerCommand());

        return $application;
    }
}
