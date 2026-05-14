<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Timer;

use OpenSwoole\Timer;
use RuntimeException;
use Saboor\SwooleKv\Metrics\ServerMetrics;
use Saboor\SwooleKv\Storage\KeyValueStore;

final class ExpirationTimer
{
    private ?int $timerId = null;

    public function __construct(
        private readonly KeyValueStore $store,
        private readonly ?ServerMetrics $metrics = null,
        private readonly int $intervalMilliseconds = 1000,
    ) {
    }

    public function start(): void
    {
        if ($this->timerId !== null) {
            return;
        }

        $timerId = Timer::tick(
            $this->intervalMilliseconds,
            function (): int {
                $purged = $this->store->purgeExpired();
                $this->metrics?->recordExpiredKeys($purged);

                return $purged;
            },
        );

        if ($timerId === false) {
            throw new RuntimeException('Unable to start expiration timer.');
        }

        $this->timerId = $timerId;
    }

    public function stop(): void
    {
        if ($this->timerId === null) {
            return;
        }

        Timer::clearAll();
        $this->timerId = null;
    }
}
