<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Storage;

interface KeyValueStore
{
    public function set(string $key, string $value): void;

    public function get(string $key): ?string;

    public function delete(string $key): bool;

    public function exists(string $key): bool;

    public function increment(string $key, int $delta): int;

    public function expire(string $key, int $seconds): bool;

    public function ttl(string $key): int;

    public function purgeExpired(): int;
}
