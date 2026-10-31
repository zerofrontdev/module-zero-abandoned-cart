<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AbandonedCart\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * The reminders' unsubscribe links and the emails that used one.
 *
 * - zero_abandoned_cart_unsubscribe: one row per email sent, the sha256 of
 *   its link's token (the token itself is never stored), the sha256 of the
 *   email and the website. Kept TOKEN_DAYS, then purged by the cron.
 * - zero_abandoned_cart_optout: (sha256 of the email, website), kept for good.
 *   Reminders::due() leaves these emails out on that website.
 *
 * The token is not the restore link's token and opens no cart: the worst a
 * leaked one can do is stop the reminders of one email on one website.
 * No foreign keys: an opt-out outlives the cart and the store views.
 */
class OptOut
{
    /**
     * The email's hash, as SQL around a quoted value or a column: the same
     * expression writes and matches it, so PHP and MySQL never disagree on
     * case or spaces.
     */
    public const EMAIL_HASH = 'SHA2(LOWER(TRIM(%s)), 256)';

    /** How long an unsubscribe link works (CAN-SPAM asks for 30 days). */
    public const TOKEN_DAYS = 365;

    public const TOKEN_TABLE = 'zero_abandoned_cart_unsubscribe';
    public const OPTOUT_TABLE = 'zero_abandoned_cart_optout';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * A new unsubscribe link token for this email on this website.
     *
     * @return string 32 hex characters
     */
    public function issue(string $email, int $websiteId): string
    {
        $token = bin2hex(random_bytes(16));
        $db = $this->resource->getConnection();
        $db->insert($this->resource->getTableName(self::TOKEN_TABLE), [
            'token_hash' => hash('sha256', $token),
            'email_hash' => new \Zend_Db_Expr($db->quoteInto(sprintf(self::EMAIL_HASH, '?'), $email)),
            'website_id' => $websiteId,
        ]);

        return $token;
    }

    /**
     * Whether the value has the shape of a token (no query runs for another).
     */
    public static function valid(string $token): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}\z/', $token);
    }

    /**
     * Whether the token is a link we sent and still honour (nothing changes).
     */
    public function known(string $token): bool
    {
        return $this->find($token) !== null;
    }

    /**
     * The token's email stops getting reminders on the token's website.
     * Using a link again is fine: still one opt-out.
     *
     * @return bool false for a token we did not send or no longer honour
     */
    public function unsubscribe(string $token): bool
    {
        $row = $this->find($token);
        if ($row === null) {
            return false;
        }
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::OPTOUT_TABLE),
            ['email_hash' => $row['email_hash'], 'website_id' => (int) $row['website_id']],
            ['email_hash']
        );

        return true;
    }

    /**
     * Deletes the link tokens older than $days; the opt-outs stay.
     *
     * @return int tokens deleted
     */
    public function purge(int $days = self::TOKEN_DAYS): int
    {
        return $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TOKEN_TABLE),
            ['created_at < ?' => gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400)]
        );
    }

    /**
     * @return array{email_hash: string, website_id: int|string}|null
     */
    private function find(string $token): ?array
    {
        if (!self::valid($token)) {
            return null;
        }
        $db = $this->resource->getConnection();
        $row = $db->fetchRow(
            $db->select()
                ->from($this->resource->getTableName(self::TOKEN_TABLE), ['email_hash', 'website_id'])
                ->where('token_hash = ?', hash('sha256', $token))
        );

        return $row ?: null;
    }
}
