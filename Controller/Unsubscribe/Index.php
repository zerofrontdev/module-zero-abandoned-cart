<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AbandonedCart\Controller\Unsubscribe;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Zero\AbandonedCart\Model\OptOut;
use Zero\AbandonedCart\Model\UnsubscribeLimiter;

/**
 * A reminder's unsubscribe link (abandonedcart/unsubscribe/index/?token=…).
 *
 * - GET with the token (the email's link): the token moves into the session
 *   and the browser comes back without it, so it never sits in a page's URL,
 *   a Referer or an analytics hit. Nothing changes on a GET: a mail scanner
 *   that opens links unsubscribes nobody.
 * - GET without it: the page that asks to confirm (a POST to
 *   abandonedcart/unsubscribe/confirm, with the form key).
 * - POST with the token: the mailbox's own "Unsubscribe" button (RFC 8058
 *   List-Unsubscribe-Post), no form key and no cookie, so the token is the
 *   only credential and the CSRF check is off for this action only. Answers
 *   200 or 404 (429 once the address is over the limit) in plain text, never
 *   a redirect.
 *
 * Works whether the reminders are on or off. The token is always looked up
 * first (one primary-key read; a malformed one never reaches the database),
 * so a known link always works. Links that are not ours count against the
 * visitor's address (UnsubscribeLimiter), which only decides the answer to a
 * miss. Never cached.
 */
class Index implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    public const SESSION_KEY = 'zero_abandoned_cart_unsubscribe_token';

    public function __construct(
        private readonly HttpRequest $request,
        private readonly PageFactory $pageFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly RawFactory $rawFactory,
        private readonly CustomerSession $session,
        private readonly ManagerInterface $messageManager,
        private readonly OptOut $optOut,
        private readonly UnsubscribeLimiter $limiter
    ) {
    }

    public function execute(): ResultInterface
    {
        $param = $this->request->getParam('token');
        $token = is_string($param) ? $param : '';
        if ($this->request->isPost()) {
            return $this->oneClick($token);
        }
        if ($param !== null) {
            if ($this->honoured($token)) {
                $this->session->setData(self::SESSION_KEY, $token);
                $result = $this->redirectFactory->create()->setPath('abandonedcart/unsubscribe/index');
            } else {
                $this->session->unsetData(self::SESSION_KEY);
                $this->messageManager->addErrorMessage(self::invalid());
                $result = $this->redirectFactory->create()->setPath('');
            }

            return self::noStore($result);
        }
        $token = (string) $this->session->getData(self::SESSION_KEY);
        if (!OptOut::valid($token) || !$this->optOut->known($token)) {
            $this->session->unsetData(self::SESSION_KEY);
            $this->messageManager->addErrorMessage(self::invalid());

            return self::noStore($this->redirectFactory->create()->setPath(''));
        }
        $page = $this->pageFactory->create();
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');
        $page->getConfig()->getTitle()->set(__('Unsubscribe from cart reminders'));

        return self::noStore($page);
    }

    public static function invalid(): Phrase
    {
        return __('This link is no longer valid.');
    }

    /**
     * No Referer, no copy in any cache.
     *
     * @template T of ResultInterface
     * @param T $result
     * @return T
     */
    public static function noStore(ResultInterface $result): ResultInterface
    {
        $result->setHeader('Referrer-Policy', 'no-referrer', true);
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('Pragma', 'no-cache', true);

        return $result;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * The one-click POST carries no form key; the token is the credential.
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    private function oneClick(string $token): ResultInterface
    {
        $raw = $this->rawFactory->create();
        // The lookup comes first: a known token always unsubscribes, whatever
        // the address (it can be forged, or be a mailbox provider's).
        if ($this->optOut->unsubscribe($token)) {
            return self::noStore($raw->setHttpResponseCode(200)->setContents(
                (string) __('Unsubscribed from cart reminders.')
            ));
        }
        if ($this->limiter->blocked()) {
            return self::noStore($raw->setHttpResponseCode(429)->setContents(
                (string) __('Too many unsubscribe attempts. Please try again later.')
            ));
        }
        $this->limiter->miss();

        return self::noStore($raw->setHttpResponseCode(404)->setContents((string) self::invalid()));
    }

    /**
     * Whether the emailed token is one we honour; a miss counts against the
     * address. A known token is honoured even from a blocked address.
     */
    private function honoured(string $token): bool
    {
        if ($this->optOut->known($token)) {
            return true;
        }
        if (!$this->limiter->blocked()) {
            $this->limiter->miss();
        }

        return false;
    }
}
