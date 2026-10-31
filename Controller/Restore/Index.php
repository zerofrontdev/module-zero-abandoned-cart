<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AbandonedCart\Controller\Restore;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Zero\AbandonedCart\Model\Reminders;

/**
 * The reminder's link (abandonedcart/restore/index/token/…): a guest's cart
 * becomes the browser's cart again (or joins the cart of whoever is signed
 * in); a customer's cart is theirs once signed in. Then the cart page.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly Reminders $reminders,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $url,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute(): Redirect
    {
        $redirect = $this->redirectFactory->create()->setPath('checkout/cart');
        $tokenParam = $this->request->getParam('token');
        $quoteId = $this->reminders->quoteIdByToken(is_scalar($tokenParam) ? (string) $tokenParam : '');
        try {
            $quote = $quoteId === null ? null : $this->quoteRepository->get($quoteId);
        } catch (NoSuchEntityException) {
            $quote = null;
        }
        $website = (int) $this->storeManager->getStore()->getWebsiteId();
        if ($quote === null || (int) $this->storeManager->getStore($quote->getStoreId())->getWebsiteId() !== $website) {
            $this->messageManager->addErrorMessage(__('This link to a cart is no longer valid.'));
            return $redirect;
        }
        if (!$quote->getIsActive()) {
            $this->messageManager->addNoticeMessage(__('The order for this cart has been placed already.'));
            return $redirect;
        }
        $this->reminders->markRestored((int) $quote->getId());
        if ($quote->getCustomerId()) {
            if ((int) $this->customerSession->getCustomerId() !== (int) $quote->getCustomerId()) {
                $this->customerSession->setBeforeAuthUrl($this->url->getUrl('checkout/cart'));
                $this->messageManager->addNoticeMessage(__('Sign in to see your cart.'));
                $redirect->setPath('customer/account/login');
            }
            return $redirect;
        }
        if ($this->customerSession->isLoggedIn()) {
            // A guest's cart opened while signed in: its items join the customer's cart.
            $current = $this->checkoutSession->getQuote();
            if ((int) $current->getId() !== (int) $quote->getId()) {
                $current->merge($quote)->collectTotals();
                $this->quoteRepository->save($current);
                // Merged once: opened again, the link finds this cart closed.
                $this->quoteRepository->save($quote->setIsActive(false));
            }
            return $redirect;
        }
        $this->checkoutSession->setQuoteId((int) $quote->getId());

        return $redirect;
    }
}
