<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Storage;

use Closure;
use InvalidArgumentException;
use OpenSwoole\Table;
use RuntimeException;

final class SwooleTableStore implements KeyValueStore
{
    private readonly Closure $clock;

    public function __construct(
        private readonly Table $table,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public static function create(int $size = 1024, int $valueSize = 8192, ?Closure $clock = null): self
    {
        $table = new Table($size);
        $table->column('value', Table::TYPE_STRING, $valueSize);
        $table->column('expires_at', Table::TYPE_INT);
        $table->create();

        return new self($table, $clock);
    }

    public function set(string $key, string $value): void
    {
        if (! $this->table->set($key, ['value' => $value, 'expires_at' => 0])) {
            throw new RuntimeException(sprintf("Unable to store key '%s'.", $key));
        }
    }

    public function get(string $key): ?string
    {
        $row = $this->table->get($key);

        if ($row === false) {
            return null;
        }

        if ($this->isExpired($row)) {
            $this->table->del($key);

            return null;
        }

        return $row['value'];
    }

    public function delete(string $key): bool
    {
        $this->deleteIfExpired($key);

        return $this->table->del($key);
    }

    public function exists(string $key): bool
    {
        $this->deleteIfExpired($key);

        return $this->table->exists($key);
    }

    public function increment(string $key, int $delta): int
    {
        $row = $this->table->get($key);

        if ($row === false || $this->isExpired($row)) {
            if ($row !== false) {
                $this->table->del($key);
            }

            $newValue = $delta;
            $expiresAt = 0;
        } else {
            $currentValue = $this->parseIntegerValue($key, $row['value']);
            $newValue = $currentValue + $delta;
            $expiresAt = $row['expires_at'];
        }

        if (! $this->table->set($key, ['value' => (string) $newValue, 'expires_at' => $expiresAt])) {
            throw new RuntimeException(sprintf("Unable to mutate key '%s'.", $key));
        }

        return $newValue;
    }

    public function expire(string $key, int $seconds): bool
    {
        $row = $this->table->get($key);

        if ($row === false || $this->isExpired($row)) {
            if ($row !== false) {
                $this->table->del($key);
            }

            return false;
        }

        return $this->table->set($key, [
            'value' => $row['value'],
            'expires_at' => $this->now() + $seconds,
        ]);
    }

    public function ttl(string $key): int
    {
        $row = $this->table->get($key);

        if ($row === false) {
            return -2;
        }

        if ($this->isExpired($row)) {
            $this->table->del($key);

            return -2;
        }

        if ($row['expires_at'] === 0) {
            return -1;
        }

        return max(0, $row['expires_at'] - $this->now());
    }

    public function purgeExpired(): int
    {
        $purged = 0;

        foreach ($this->table as $key => $row) {
            if (! $this->isExpired($row)) {
                continue;
            }

            if ($this->table->del((string) $key)) {
                ++$purged;
            }
        }

        return $purged;
    }

    /**
     * @param array{expires_at: int} $row
     */
    private function isExpired(array $row): bool
    {
        return $row['expires_at'] > 0 && $row['expires_at'] <= $this->now();
    }

    private function deleteIfExpired(string $key): void
    {
        $row = $this->table->get($key);

        if ($row !== false && $this->isExpired($row)) {
            $this->table->del($key);
        }
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    private function parseIntegerValue(string $key, string $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        if ($integer === false) {
            throw new InvalidArgumentException(sprintf("Value for key '%s' is not an integer.", $key));
        }

        return $integer;
    }
}
