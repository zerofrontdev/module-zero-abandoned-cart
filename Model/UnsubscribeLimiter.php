<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AbandonedCart\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;

/**
 * Unsubscribe links that were not ours, per address: after LIMIT of them in
 * WINDOW seconds a miss from the address answers 429 instead of 404 until the
 * window ends. It never decides whether a link works: the caller looks the
 * token up first, so a real link works from any address. A courtesy limit
 * (the token has 128 random bits, guessing is not the risk): RemoteAddress
 * follows X-Forwarded-For, which a client can forge, so a blocked address
 * must never refuse a known token. No store-wide ceiling, which would let
 * anyone stop everybody's unsubscribes.
 *
 * A fixed window in the cache, approximate under concurrency (a load and a
 * save, no lock).
 */
class UnsubscribeLimiter
{
    public const LIMIT = 20;
    public const WINDOW = 600;

    private const PREFIX = 'zero_abandoned_cart_unsubscribe_misses_';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly RemoteAddress $remoteAddress
    ) {
    }

    public function blocked(): bool
    {
        return (int) $this->cache->load($this->key()) >= self::LIMIT;
    }

    public function miss(): void
    {
        $key = $this->key();
        $this->cache->save((string) ((int) $this->cache->load($key) + 1), $key, [], self::WINDOW);
    }

    private function key(): string
    {
        $address = (string) $this->remoteAddress->getRemoteAddress();

        return self::PREFIX . hash('sha256', $address !== '' ? $address : 'unknown') . '_'
            . intdiv(time(), self::WINDOW);
    }
}
