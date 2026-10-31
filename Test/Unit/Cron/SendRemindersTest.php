<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AbandonedCart\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Zero\AbandonedCart\Cron\SendReminders;
use Zero\AbandonedCart\Model\OptOut;
use Zero\AbandonedCart\Model\Sender;

class SendRemindersTest extends TestCase
{
    public function testSendsThenPurgesOldTokens(): void
    {
        $log = [];
        $sender = $this->createMock(Sender::class);
        $sender->expects($this->once())->method('sendDue')->willReturnCallback(function () use (&$log) {
            $log[] = 'send';
            return 0;
        });
        $optOut = $this->createMock(OptOut::class);
        $optOut->expects($this->once())->method('purge')->with()->willReturnCallback(function (int $days) use (&$log) {
            $log[] = 'purge ' . $days;
            return 0;
        });

        (new SendReminders($sender, $optOut))->execute();

        $this->assertSame(['send', 'purge ' . OptOut::TOKEN_DAYS], $log);
    }
}
