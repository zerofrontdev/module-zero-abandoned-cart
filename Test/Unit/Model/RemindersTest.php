<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AbandonedCart\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Zero\AbandonedCart\Model\Reminders;

/**
 * Who is due a reminder, decided in the SQL before the LIMIT: opted-out
 * emails never, customers' carts as before, guests' carts only for a
 * newsletter subscriber on the cart's website (owner decision 11).
 *
 * Each select() is a recorder of its calls; a sub-select reads as its label
 * (`{SELECT n}`) where it is pasted into another select's condition.
 */
class RemindersTest extends TestCase
{
    /** @var array<int, array<int, array<int, mixed>>> select number => [method, ...args] */
    private array $selects = [];
    private string $sql = '';
    private Reminders $reminders;

    protected function setUp(): void
    {
        $db = $this->createStub(Mysql::class);
        $db->method('select')->willReturnCallback(function () {
            $n = count($this->selects);
            $this->selects[$n] = [];
            $select = $this->createStub(Select::class);
            foreach (['from', 'join', 'joinLeft', 'where', 'order', 'limit'] as $method) {
                $select->method($method)->willReturnCallback(function (...$args) use ($method, $select, $n) {
                    while ($args && end($args) === null) {
                        array_pop($args); // the defaults PHPUnit passes on
                    }
                    $this->selects[$n][] = [$method, ...array_map(
                        static fn ($arg) => $arg instanceof \Zend_Db_Expr ? (string) $arg : $arg,
                        $args
                    )];
                    return $select;
                });
            }
            $select->method('__toString')->willReturn('{SELECT ' . $n . '}');

            return $select;
        });
        $db->method('fetchAll')->willReturnCallback(function ($select) {
            $this->sql = (string) $select;
            return [[
                'entity_id' => '42', 'store_id' => '1', 'customer_email' => 'olena@example.com',
                'customer_firstname' => ' Олена ',
            ]];
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnCallback(static fn (string $name) => $name);
        $this->reminders = new Reminders($resource);
    }

    public function testOptedOutEmailsAreLeftOutOfEveryCart(): void
    {
        foreach ([true, false] as $guests) {
            $this->selects = [];
            $this->reminders->due(1, '2026-10-24 00:00:00', '2026-10-30 23:00:00', 100, $guests);

            $optOut = $this->subSelect('zero_abandoned_cart_optout');
            $this->assertContains(['where', 'o.website_id = s.website_id'], $optOut);
            $this->assertContains(['where', 'o.email_hash = SHA2(LOWER(TRIM(q.customer_email)), 256)'], $optOut);
            $this->assertContains(['where', 'NOT EXISTS (' . $this->label('zero_abandoned_cart_optout') . ')'], $this->main());
        }
    }

    public function testGuestCartsNeedANewsletterSubscriptionOnTheCartsWebsite(): void
    {
        $this->reminders->due(1, '2026-10-24 00:00:00', '2026-10-30 23:00:00', 100);

        $customer = $this->subSelect('customer_entity');
        $this->assertContains(['where', 'ce.entity_id = q.customer_id'], $customer);
        $subscriber = $this->subSelect('newsletter_subscriber');
        $this->assertContains(['join', ['nss' => 'store'], 'nss.store_id = ns.store_id', []], $subscriber);
        $this->assertContains(['where', 'ns.subscriber_email = q.customer_email'], $subscriber);
        // Subscribed (1) only: not active (2), unsubscribed (3), unconfirmed (4).
        $this->assertContains(['where', 'ns.subscriber_status = ?', 1], $subscriber);
        $this->assertContains(['where', 'nss.website_id = s.website_id'], $subscriber);
        $this->assertContains([
            'where',
            '(q.customer_id > 0 AND EXISTS (' . $this->label('customer_entity') . '))'
                . ' OR EXISTS (' . $this->label('newsletter_subscriber') . ')',
        ], $this->main());
    }

    public function testWithoutGuestsOnlyCustomerCarts(): void
    {
        $this->reminders->due(1, '2026-10-24 00:00:00', '2026-10-30 23:00:00', 100, false);

        $this->assertNull($this->label('newsletter_subscriber'), 'no newsletter sub-select');
        $this->assertContains(
            ['where', 'q.customer_id > 0 AND EXISTS (' . $this->label('customer_entity') . ')'],
            $this->main()
        );
        foreach ($this->main() as $call) {
            if ($call[0] === 'where') {
                $this->assertStringNotContainsString(' OR ', $call[1]);
            }
        }
    }

    public function testTheAudienceIsInTheQueryBeforeTheLimit(): void
    {
        $this->reminders->due(1, '2026-10-24 00:00:00', '2026-10-30 23:00:00', 100);

        $methods = array_column($this->main(), 0);
        $limit = array_search('limit', $methods, true);
        $audience = array_keys(array_filter(
            $this->main(),
            static fn (array $call) => $call[0] === 'where' && str_contains((string) $call[1], 'EXISTS')
        ));
        $this->assertCount(2, $audience, 'the opt-out and the audience');
        $this->assertLessThan($limit, max($audience));
        $this->assertSame('{SELECT ' . (count($this->selects) - 1) . '}', $this->sql, 'one query, no PHP filter after it');
    }

    public function testTheExistingConditionsStay(): void
    {
        $due = $this->reminders->due(1, '2026-10-24 00:00:00', '2026-10-30 23:00:00', 100);

        $main = $this->main();
        $this->assertContains(['join', ['s' => 'store'], 's.store_id = q.store_id', []], $main);
        $this->assertContains(['joinLeft', ['r' => 'zero_abandoned_cart_reminder'], 'r.quote_id = q.entity_id', []], $main);
        foreach ([
            ['where', 'r.quote_id IS NULL'],
            ['where', 'q.is_active = 1'],
            ['where', 'q.items_count > 0'],
            ['where', 'q.customer_email LIKE ?', '%@%'],
            ['where', 'q.store_id = ?', 1],
            ['where', 'q.updated_at >= ?', '2026-10-24 00:00:00'],
            ['where', 'q.updated_at <= ?', '2026-10-30 23:00:00'],
            ['order', 'q.updated_at ASC'],
            ['limit', 100],
        ] as $call) {
            $this->assertContains($call, $main);
        }
        $this->assertSame(
            [['quote_id' => 42, 'store_id' => 1, 'email' => 'olena@example.com', 'firstname' => 'Олена']],
            $due
        );
    }

    /**
     * @return array<int, array<int, mixed>> the calls of the cart query (the last select built)
     */
    private function main(): array
    {
        return $this->selects[array_key_last($this->selects)];
    }

    /**
     * @return array<int, array<int, mixed>> the calls of the sub-select from $table
     */
    private function subSelect(string $table): array
    {
        $label = $this->label($table);
        $this->assertNotNull($label, 'a sub-select from ' . $table);

        return $this->selects[(int) substr($label, 8, -1)];
    }

    private function label(string $table): ?string
    {
        foreach ($this->selects as $n => $calls) {
            if ($calls && $calls[0][0] === 'from' && in_array($table, (array) $calls[0][1], true)) {
                return '{SELECT ' . $n . '}';
            }
        }

        return null;
    }
}
