<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AbandonedCart\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * zero_abandoned_cart/general/guests: which guests' carts get a reminder.
 */
class Guests implements OptionSourceInterface
{
    public const NEWSLETTER = 'newsletter';
    public const NONE = 'none';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::NEWSLETTER, 'label' => __('Only Newsletter Subscribers')],
            ['value' => self::NONE, 'label' => __("Never (customers' carts only)")],
        ];
    }
}
