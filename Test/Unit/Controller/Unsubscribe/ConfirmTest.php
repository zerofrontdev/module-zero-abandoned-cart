<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AbandonedCart\Test\Unit\Controller\Unsubscribe;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\AbandonedCart\Controller\Unsubscribe\Confirm;
use Zero\AbandonedCart\Controller\Unsubscribe\Index;
use Zero\AbandonedCart\Model\OptOut;

/**
 * "Stop cart reminders" on the link's page: a POST only, form key checked,
 * the token from the session, used once.
 *
 * The mocks are shared by the tests; each sets expectations on the ones it's about.
 */
#[AllowMockObjectsWithoutExpectations]
class ConfirmTest extends TestCase
{
    private const TOKEN = 'b7dcd27f8a0836a18397791efada108c';

    private FakeSession $session;
    private ManagerInterface&MockObject $messages;
    private OptOut&MockObject $optOut;
    private ?string $path = null;
    /** @var array<string, string> */
    private array $headers = [];
    private Confirm $controller;

    protected function setUp(): void
    {
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function (string $path) use ($redirect) {
            $this->path = $path;
            return $redirect;
        });
        $redirect->method('setHeader')->willReturnCallback(function ($name, $value) use ($redirect) {
            $this->headers[$name] = $value;
            return $redirect;
        });
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);
        $this->session = new FakeSession();
        $this->messages = $this->createMock(ManagerInterface::class);
        $this->optOut = $this->createMock(OptOut::class);
        $this->controller = new Confirm($redirectFactory, $this->session, $this->messages, $this->optOut);
    }

    public function testIsAPostWithTheFormKeyNeverAGet(): void
    {
        $this->assertInstanceOf(HttpPostActionInterface::class, $this->controller);
        $this->assertNotInstanceOf(HttpGetActionInterface::class, $this->controller);
        $this->assertNotInstanceOf(CsrfAwareActionInterface::class, $this->controller, 'Magento checks the form key');
    }

    public function testTheLinkInTheSessionStopsTheRemindersOnce(): void
    {
        $this->session->data[Index::SESSION_KEY] = self::TOKEN;
        $this->optOut->expects($this->once())->method('unsubscribe')->with(self::TOKEN)->willReturn(true);
        $this->messages->expects($this->never())->method('addErrorMessage');
        $this->messages->expects($this->once())->method('addSuccessMessage')
            ->with($this->callback(static fn ($m) => (string) $m === "Done: we won't send you cart reminders any more."));

        $this->controller->execute();

        $this->assertSame('', $this->path);
        $this->assertSame([], $this->session->data, 'the link is used once');
        $this->assertSame('no-referrer', $this->headers['Referrer-Policy'] ?? null);
        $this->assertStringContainsString('no-store', $this->headers['Cache-Control'] ?? '');
    }

    public function testWithoutALinkInTheSessionNobodyIsUnsubscribed(): void
    {
        $this->optOut->expects($this->once())->method('unsubscribe')->with('')->willReturn(false);
        $this->messages->expects($this->never())->method('addSuccessMessage');
        $this->messages->expects($this->once())->method('addErrorMessage')
            ->with($this->callback(static fn ($m) => (string) $m === 'This link is no longer valid.'));

        $this->controller->execute();

        $this->assertSame('', $this->path);
    }
}
