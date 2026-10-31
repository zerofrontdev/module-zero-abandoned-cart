<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AbandonedCart\Controller\Unsubscribe;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Zero\AbandonedCart\Model\OptOut;

/**
 * "Unsubscribe" on the link's page (form key checked by Magento): the token
 * the link left in the session stops the reminders of its email on its
 * website, then the home page with a message.
 */
class Confirm implements HttpPostActionInterface
{
    public function __construct(
        private readonly RedirectFactory $redirectFactory,
        private readonly CustomerSession $session,
        private readonly ManagerInterface $messageManager,
        private readonly OptOut $optOut
    ) {
    }

    public function execute(): ResultInterface
    {
        $token = (string) $this->session->getData(Index::SESSION_KEY);
        $this->session->unsetData(Index::SESSION_KEY);
        if ($this->optOut->unsubscribe($token)) {
            $this->messageManager->addSuccessMessage(__("Done: we won't send you cart reminders any more."));
        } else {
            $this->messageManager->addErrorMessage(Index::invalid());
        }

        return Index::noStore($this->redirectFactory->create()->setPath(''));
    }
}
