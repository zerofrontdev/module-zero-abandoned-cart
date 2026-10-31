<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AbandonedCart\Model;

use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\EmailMessage;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Url;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zero\AbandonedCart\Model\Config\Source\Guests;

/**
 * Sends the reminders due in the store views where they're on
 * (zero_abandoned_cart/general/*). A guest's cart only for a newsletter
 * subscriber, or never (general/guests); every email has an unsubscribe link
 * and the List-Unsubscribe headers (OptOut).
 */
class Sender
{
    /** Carts per store view per run (cron runs every 10 minutes). */
    public const BATCH = 100;

    public function __construct(
        private readonly Reminders $reminders,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Url $url,
        private readonly LoggerInterface $logger,
        private readonly OptOut $optOut
    ) {
    }

    /**
     * @return array<int, array{quote_id: int, store_id: int, email: string, firstname: string}>
     */
    public function due(?int $now = null): array
    {
        $now ??= time();
        $due = [];
        foreach ($this->storeManager->getStores() as $store) {
            if (!$store->isActive() || !$this->scopeConfig->isSetFlag(
                'zero_abandoned_cart/general/enabled',
                ScopeInterface::SCOPE_STORE,
                $store->getId()
            )) {
                continue;
            }
            $delay = max(1, (int) $this->value('delay', $store));
            $maxAge = max(1, (int) $this->value('max_age', $store));
            array_push($due, ...$this->reminders->due(
                (int) $store->getId(),
                gmdate('Y-m-d H:i:s', $now - $maxAge * 86400),
                gmdate('Y-m-d H:i:s', $now - $delay * 60),
                self::BATCH,
                $this->value('guests', $store) !== Guests::NONE
            ));
        }

        return $due;
    }

    /**
     * @return int reminders sent
     */
    public function sendDue(): int
    {
        $sent = 0;
        foreach ($this->due() as $cart) {
            try {
                $this->send($cart);
                $sent++;
            } catch (\Throwable $e) {
                $this->logger->error('Zero_AbandonedCart: cart ' . $cart['quote_id'] . ': ' . $e->getMessage());
            }
        }

        return $sent;
    }

    /**
     * @param array{quote_id: int, store_id: int, email: string, firstname: string} $cart
     */
    public function send(array $cart): void
    {
        $token = bin2hex(random_bytes(16));
        // Recorded first: a cart whose email fails isn't retried every 10 minutes.
        $this->reminders->add($cart['quote_id'], $token);
        /** @var Store $store */
        $store = $this->storeManager->getStore($cart['store_id']);
        $unsubscribeToken = $this->optOut->issue($cart['email'], (int) $store->getWebsiteId());
        $transport = $this->message($cart, $token, $unsubscribeToken)->getTransport();
        $message = $transport->getMessage();
        if ($message instanceof EmailMessage) {
            // RFC 8058: the mailbox's own "Unsubscribe" button, one POST to the same link.
            $headers = $message->getSymfonyMessage()->getHeaders();
            $headers->addTextHeader('List-Unsubscribe', '<' . $this->unsubscribeUrl($store, $unsubscribeToken) . '>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }
        $transport->sendMessage();
    }

    /**
     * The email's HTML without sending or recording it (its links restore
     * nothing and unsubscribe nobody).
     *
     * @param array{quote_id: int, store_id: int, email: string, firstname: string} $cart
     */
    public function preview(array $cart): string
    {
        $zero = str_repeat('0', 32);

        return quoted_printable_decode(
            $this->message($cart, $zero, $zero)->getTransport()->getMessage()->getBodyText()
        );
    }

    /**
     * @param array{quote_id: int, store_id: int, email: string, firstname: string} $cart
     */
    private function message(array $cart, string $token, string $unsubscribeToken): TransportBuilder
    {
        /** @var Store $store */
        $store = $this->storeManager->getStore($cart['store_id']);
        $quote = $this->quoteRepository->get($cart['quote_id']);

        return $this->transportBuilder
            ->setTemplateIdentifier((string) $this->value('template', $store))
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => (int) $store->getId()])
            ->setTemplateVars([
                'quote_id' => (int) $quote->getId(),
                'store' => $store,
                'customer_name' => $cart['firstname'],
                // Not $store->getUrl(): for a store other than the current one it adds ?___store=.
                'restore_url' => $this->url->getUrl(
                    'abandonedcart/restore/index',
                    ['_scope' => $store, 'token' => $token, '_nosid' => true]
                ),
                'unsubscribe_url' => $this->unsubscribeUrl($store, $unsubscribeToken),
            ])
            ->setFromByScope((string) $this->value('identity', $store), (int) $store->getId())
            ->addTo($cart['email'], $cart['firstname']);
    }

    private function unsubscribeUrl(Store $store, string $token): string
    {
        return $this->url->getUrl(
            'abandonedcart/unsubscribe/index',
            // In the query string, not the path: Zero_Mail's Secrets redacts
            // `?token=` in the Email Log, a path-style `/token/X/` it cannot.
            ['_scope' => $store, '_query' => ['token' => $token], '_nosid' => true]
        );
    }

    private function value(string $field, Store $store): mixed
    {
        return $this->scopeConfig->getValue(
            'zero_abandoned_cart/general/' . $field,
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        );
    }
}
