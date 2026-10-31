<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AbandonedCart\Test\Unit\Model\Config\Source;

use PHPUnit\Framework\TestCase;
use Zero\AbandonedCart\Model\Config\Source\Guests;

/**
 * Which guests get a reminder: subscribers by default, or none; there is no
 * "every guest" choice (owner decision 11).
 */
class GuestsTest extends TestCase
{
    public function testOnlySubscribersOrNobody(): void
    {
        $this->assertSame(
            ['newsletter', 'none'],
            array_column((new Guests())->toOptionArray(), 'value')
        );
    }

    public function testTheDefaultIsSubscribersOnly(): void
    {
        $config = simplexml_load_file(dirname(__DIR__, 5) . '/etc/config.xml');

        $this->assertSame(Guests::NEWSLETTER, (string) $config->default->zero_abandoned_cart->general->guests);
        $this->assertSame('0', (string) $config->default->zero_abandoned_cart->general->enabled, 'reminders stay off');
    }

    public function testTheFieldUsesThisSource(): void
    {
        $system = simplexml_load_file(dirname(__DIR__, 5) . '/etc/adminhtml/system.xml');
        $field = $system->xpath('//group[@id="general"]/field[@id="guests"]');

        $this->assertCount(1, $field);
        $this->assertSame(Guests::class, (string) $field[0]->source_model);
    }
}
