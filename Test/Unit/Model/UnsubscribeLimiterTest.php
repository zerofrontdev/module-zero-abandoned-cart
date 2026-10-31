<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AbandonedCart\Test\Unit\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use PHPUnit\Framework\TestCase;
use Zero\AbandonedCart\Model\UnsubscribeLimiter;

/**
 * Unknown unsubscribe links per address in a fixed window, kept in the cache.
 */
class UnsubscribeLimiterTest extends TestCase
{
    /** @var array<string, array{string, int|false|null}> cache id => [value, lifetime] */
    private array $cache = [];
    private string $address = '203.0.113.7';

    public function testTwentyMissesBlockTheAddressForTheWindow(): void
    {
        $limiter = $this->limiter();
        for ($i = 1; $i < UnsubscribeLimiter::LIMIT; $i++) {
            $limiter->miss();
        }
        $this->assertFalse($limiter->blocked(), '19 misses');

        $limiter->miss();

        $this->assertTrue($limiter->blocked(), '20 misses');
        $this->assertSame(20, UnsubscribeLimiter::LIMIT);
        $this->assertCount(1, $this->cache);
        [$value, $lifetime] = reset($this->cache);
        $this->assertSame('20', $value);
        $this->assertSame(600, $lifetime);
    }

    public function testOtherAddressesAreNotBlocked(): void
    {
        $limiter = $this->limiter();
        for ($i = 0; $i < UnsubscribeLimiter::LIMIT; $i++) {
            $limiter->miss();
        }

        $this->address = '198.51.100.1';

        $this->assertFalse($limiter->blocked());
    }

    public function testTheCacheKeyHoldsNoPlainAddress(): void
    {
        $this->limiter()->miss();

        $id = (string) array_key_first($this->cache);
        $this->assertStringNotContainsString('203.0.113.7', $id);
        $this->assertStringContainsString(hash('sha256', '203.0.113.7'), $id);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_]+\z/', $id, 'a valid cache id');
    }

    public function testNoAddressCountsAsOne(): void
    {
        $this->address = '';
        $limiter = $this->limiter();
        $limiter->miss();

        $this->assertStringContainsString(hash('sha256', 'unknown'), (string) array_key_first($this->cache));
        $this->assertFalse($limiter->blocked());
    }

    private function limiter(): UnsubscribeLimiter
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $id) => $this->cache[$id][0] ?? false);
        $cache->method('save')->willReturnCallback(function ($data, $id, $tags = [], $lifetime = null) {
            $this->cache[$id] = [$data, $lifetime];
            return true;
        });
        $remoteAddress = $this->createStub(RemoteAddress::class);
        $remoteAddress->method('getRemoteAddress')->willReturnCallback(fn () => $this->address);

        return new UnsubscribeLimiter($cache, $remoteAddress);
    }
}
