<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Storage;

use OpenSwoole\Table;
use RuntimeException;

final readonly class SwooleTableStore implements KeyValueStore
{
    public function __construct(
        private Table $table,
    ) {
    }

    public static function create(int $size = 1024, int $valueSize = 8192): self
    {
        $table = new Table($size);
        $table->column('value', Table::TYPE_STRING, $valueSize);
        $table->create();

        return new self($table);
    }

    public function set(string $key, string $value): void
    {
        if (! $this->table->set($key, ['value' => $value])) {
            throw new RuntimeException(sprintf("Unable to store key '%s'.", $key));
        }
    }

    public function get(string $key): ?string
    {
        $row = $this->table->get($key);

        if ($row === false) {
            return null;
        }

        return $row['value'];
    }

    public function delete(string $key): bool
    {
        return $this->table->del($key);
    }

    public function exists(string $key): bool
    {
        return $this->table->exists($key);
    }
}
