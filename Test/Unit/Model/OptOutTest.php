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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\AbandonedCart\Model\OptOut;

/**
 * The unsubscribe link tokens (only their hash stored) and the opt-outs they leave.
 *
 * The mocks are shared by the tests; each sets expectations on the ones it's about.
 */
#[AllowMockObjectsWithoutExpectations]
class OptOutTest extends TestCase
{
    private const TOKEN = 'b7dcd27f8a0836a18397791efada108c';

    private Mysql&MockObject $db;
    /** @var array<int, array<int, mixed>> where() calls of the token lookup */
    private array $where = [];
    private OptOut $optOut;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Mysql::class);
        $this->db->method('quoteInto')->willReturnCallback(
            static fn (string $sql, $value) => str_replace('?', "'" . addslashes((string) $value) . "'", $sql)
        );
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($condition, $value = null) use ($select) {
            $this->where[] = [$condition, $value];
            return $select;
        });
        $this->db->method('select')->willReturn($select);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->db);
        $resource->method('getTableName')->willReturnCallback(static fn (string $name) => 'pfx_' . $name);
        $this->optOut = new OptOut($resource);
    }

    public function testIssueStoresOnlyTheHashOfARandomToken(): void
    {
        $row = null;
        $this->db->expects($this->once())->method('insert')
            ->with('pfx_zero_abandoned_cart_unsubscribe', $this->callback(function (array $value) use (&$row) {
                $row = $value;
                return true;
            }));

        $token = $this->optOut->issue('Olena@Example.com ', 3);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\z/', $token);
        $this->assertSame(['token_hash', 'email_hash', 'website_id'], array_keys($row));
        $this->assertSame(hash('sha256', $token), $row['token_hash']);
        $this->assertNotContains($token, $row, 'the plain token is never stored');
        $this->assertSame(3, $row['website_id']);
        $this->assertInstanceOf(\Zend_Db_Expr::class, $row['email_hash']);
        // The same expression Reminders::due() matches with: case and spaces never matter.
        $this->assertSame("SHA2(LOWER(TRIM('Olena@Example.com ')), 256)", (string) $row['email_hash']);
    }

    public function testTwoIssuesGiveDifferentTokens(): void
    {
        $this->assertNotSame($this->optOut->issue('a@example.com', 1), $this->optOut->issue('a@example.com', 1));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedTokens(): array
    {
        return [
            'empty' => [''],
            'short word' => ['abc'],
            'a trailing newline' => [self::TOKEN . "\n"],
            'uppercase' => [strtoupper(self::TOKEN)],
            '31 characters' => [substr(self::TOKEN, 1)],
            '33 characters' => [self::TOKEN . 'a'],
            'not hex' => [str_repeat('g', 32)],
            'a SQL fragment' => ["' OR 1=1 -- " . substr(self::TOKEN, 12)],
        ];
    }

    #[DataProvider('malformedTokens')]
    public function testMalformedTokenUnsubscribesNobodyAndRunsNoQuery(string $token): void
    {
        $this->db->expects($this->never())->method('select');
        $this->db->expects($this->never())->method('fetchRow');
        $this->db->expects($this->never())->method('insertOnDuplicate');

        $this->assertFalse(OptOut::valid($token));
        $this->assertFalse($this->optOut->known($token));
        $this->assertFalse($this->optOut->unsubscribe($token));
    }

    public function testAWellFormedTokenIsValid(): void
    {
        $this->assertTrue(OptOut::valid(self::TOKEN));
    }

    public function testUnknownTokenUnsubscribesNobody(): void
    {
        $this->db->expects($this->exactly(2))->method('fetchRow')->willReturn(false);
        $this->db->expects($this->never())->method('insertOnDuplicate');

        $this->assertFalse($this->optOut->known(self::TOKEN));
        $this->assertFalse($this->optOut->unsubscribe(self::TOKEN));
        // Looked up by its hash, never by the token itself.
        $this->assertSame(['token_hash = ?', hash('sha256', self::TOKEN)], $this->where[0]);
    }

    public function testKnownTokenOptsTheEmailOutOnItsWebsite(): void
    {
        $this->db->method('fetchRow')->willReturn(['email_hash' => 'h', 'website_id' => '3']);
        $this->db->expects($this->exactly(2))->method('insertOnDuplicate')
            ->with('pfx_zero_abandoned_cart_optout', ['email_hash' => 'h', 'website_id' => 3], ['email_hash']);

        $this->assertTrue($this->optOut->known(self::TOKEN));
        $this->assertTrue($this->optOut->unsubscribe(self::TOKEN));
        // A link used twice: the same row again, still one opt-out.
        $this->assertTrue($this->optOut->unsubscribe(self::TOKEN));
    }

    public function testKnownWritesNothing(): void
    {
        $this->db->method('fetchRow')->willReturn(['email_hash' => 'h', 'website_id' => '3']);
        $this->db->expects($this->never())->method('insertOnDuplicate');
        $this->db->expects($this->never())->method('insert');
        $this->db->expects($this->never())->method('delete');

        $this->assertTrue($this->optOut->known(self::TOKEN));
    }

    public function testPurgeDeletesOnlyTokensOlderThanAYear(): void
    {
        $where = null;
        $this->db->expects($this->once())->method('delete')
            ->with('pfx_zero_abandoned_cart_unsubscribe', $this->callback(function (array $value) use (&$where) {
                $where = $value;
                return true;
            }))
            ->willReturn(4);

        $this->assertSame(4, $this->optOut->purge());
        $this->assertSame(['created_at < ?'], array_keys($where));
        $this->assertEqualsWithDelta(time() - 365 * 86400, strtotime($where['created_at < ?'] . ' UTC'), 5);
    }

    public function testPurgeNeverDeletesTodaysTokens(): void
    {
        $where = null;
        $this->db->expects($this->once())->method('delete')
            ->willReturnCallback(function (string $table, array $value) use (&$where) {
                $where = $value;
                return 0;
            });

        $this->optOut->purge(0);

        $this->assertEqualsWithDelta(time() - 86400, strtotime($where['created_at < ?'] . ' UTC'), 5);
    }
}
