<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AbandonedCart\Cron;

use Zero\AbandonedCart\Model\OptOut;
use Zero\AbandonedCart\Model\Sender;

/**
 * Every 10 minutes: the reminders due, then the unsubscribe link tokens past
 * OptOut::TOKEN_DAYS (also with the reminders off: old links still expire).
 */
class SendReminders
{
    public function __construct(
        private readonly Sender $sender,
        private readonly OptOut $optOut
    ) {
    }

    public function execute(): void
    {
        $this->sender->sendDue();
        $this->optOut->purge();
    }
}
