<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AbandonedCart\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Newsletter\Model\Subscriber;

/**
 * The carts due a reminder and the reminders sent (zero_abandoned_cart_reminder:
 * one row per cart, so a cart is reminded of once).
 *
 * Who is due one is decided here, in SQL, before the LIMIT: a cart skipped
 * later in PHP would never get a reminder row and would fill every batch.
 */
class Reminders
{
    private const TABLE = 'zero_abandoned_cart_reminder';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Active carts of the store view with items and an email, last changed
     * between $since and $until (UTC, Y-m-d H:i:s), not reminded of yet, whose
     * email has not unsubscribed on the store view's website (OptOut). A
     * guest's cart only with $guests and when its email is a confirmed
     * newsletter subscriber on that website (owner decision 11: no reminder
     * to an email nobody agreed to); a customer's cart as before. A cart of a
     * deleted customer counts as a guest's.
     *
     * @return array<int, array{quote_id: int, store_id: int, email: string, firstname: string}>
     */
    public function due(int $storeId, string $since, string $until, int $limit, bool $guests = true): array
    {
        $db = $this->resource->getConnection('checkout');
        $optedOut = $db->select()
            ->from(['o' => $this->resource->getTableName(OptOut::OPTOUT_TABLE)], [new \Zend_Db_Expr('1')])
            ->where('o.website_id = s.website_id')
            ->where('o.email_hash = ' . sprintf(OptOut::EMAIL_HASH, 'q.customer_email'));
        $customer = $db->select()
            ->from(['ce' => $this->resource->getTableName('customer_entity')], [new \Zend_Db_Expr('1')])
            ->where('ce.entity_id = q.customer_id');
        $audience = 'q.customer_id > 0 AND EXISTS (' . $customer . ')';
        if ($guests) {
            $subscribed = $db->select()
                ->from(['ns' => $this->resource->getTableName('newsletter_subscriber')], [new \Zend_Db_Expr('1')])
                ->join(['nss' => $this->resource->getTableName('store')], 'nss.store_id = ns.store_id', [])
                ->where('ns.subscriber_email = q.customer_email')
                ->where('ns.subscriber_status = ?', Subscriber::STATUS_SUBSCRIBED)
                ->where('nss.website_id = s.website_id');
            $audience = '(' . $audience . ') OR EXISTS (' . $subscribed . ')';
        }
        $select = $db->select()
            ->from(
                ['q' => $this->resource->getTableName('quote', 'checkout')],
                ['entity_id', 'store_id', 'customer_email', 'customer_firstname']
            )
            ->join(['s' => $this->resource->getTableName('store')], 's.store_id = q.store_id', [])
            ->joinLeft(['r' => $this->table()], 'r.quote_id = q.entity_id', [])
            ->where('r.quote_id IS NULL')
            ->where('q.is_active = 1')
            ->where('q.items_count > 0')
            ->where('q.customer_email LIKE ?', '%@%')
            ->where('q.store_id = ?', $storeId)
            ->where('q.updated_at >= ?', $since)
            ->where('q.updated_at <= ?', $until)
            ->where('NOT EXISTS (' . $optedOut . ')')
            ->where($audience)
            ->order('q.updated_at ASC')
            ->limit($limit);

        return array_map(
            static fn (array $row) => [
                'quote_id' => (int) $row['entity_id'],
                'store_id' => (int) $row['store_id'],
                'email' => (string) $row['customer_email'],
                'firstname' => trim((string) $row['customer_firstname']),
            ],
            $db->fetchAll($select)
        );
    }

    public function add(int $quoteId, string $token): void
    {
        $this->resource->getConnection('checkout')->insert(
            $this->table(),
            ['quote_id' => $quoteId, 'token' => $token, 'sent_at' => gmdate('Y-m-d H:i:s')]
        );
    }

    /**
     * The cart a restore link is for.
     */
    public function quoteIdByToken(string $token): ?int
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }
        $db = $this->resource->getConnection('checkout');
        $id = $db->fetchOne(
            $db->select()->from($this->table(), ['quote_id'])->where('token = ?', $token)
        );

        return $id === false ? null : (int) $id;
    }

    public function markRestored(int $quoteId): void
    {
        $this->resource->getConnection('checkout')->update(
            $this->table(),
            ['restored_at' => gmdate('Y-m-d H:i:s')],
            ['quote_id = ?' => $quoteId, 'restored_at IS NULL']
        );
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE, 'checkout');
    }
}
