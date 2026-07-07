<?php

declare(strict_types=1);

namespace Saboor\SwooleKv\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saboor\SwooleKv\Storage\SwooleTableStore;

final class SwooleTableStoreTest extends TestCase
{
    private int $now = 1000;
    private SwooleTableStore $store;

    protected function setUp(): void
    {
        if (! extension_loaded('openswoole')) {
            self::markTestSkipped('OpenSwoole extension is required for Swoole table tests.');
        }

        $this->store = SwooleTableStore::create(clock: fn (): int => $this->now);
    }

    public function testItStoresReadsAndDeletesValues(): void
    {
        $this->store->set('name', 'Saboor');

        self::assertSame('Saboor', $this->store->get('name'));
        self::assertTrue($this->store->exists('name'));
        self::assertSame(1, $this->store->keyCount());
        self::assertTrue($this->store->delete('name'));
        self::assertNull($this->store->get('name'));
    }

    public function testItExpiresKeysDeterministically(): void
    {
        $this->store->set('session', 'abc');
        self::assertTrue($this->store->expire('session', 5));
        self::assertSame(5, $this->store->ttl('session'));

        $this->now += 5;

        self::assertNull($this->store->get('session'));
        self::assertSame(-2, $this->store->ttl('session'));
    }

    public function testItMutatesIntegerValues(): void
    {
        self::assertSame(1, $this->store->increment('count', 1));
        self::assertSame(2, $this->store->increment('count', 1));
        self::assertSame(1, $this->store->increment('count', -1));
        self::assertSame('1', $this->store->get('count'));
    }

    public function testItRejectsNonIntegerMutation(): void
    {
        $this->store->set('name', 'Saboor');

        $this->expectException(InvalidArgumentException::class);

        $this->store->increment('name', 1);
    }
}
