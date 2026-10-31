<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AbandonedCart\Test\Unit\Controller\Unsubscribe;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\AbandonedCart\Controller\Unsubscribe\Index;
use Zero\AbandonedCart\Model\OptOut;
use Zero\AbandonedCart\Model\UnsubscribeLimiter;

/**
 * The reminder's unsubscribe link: a GET changes nothing (the token goes to
 * the session, the page asks to confirm), the mailbox's one-click POST
 * unsubscribes with the token alone, and a link that is not ours is counted
 * against the address without ever refusing one that is.
 *
 * The mocks are shared by the tests; each sets expectations on the ones it's about.
 */
#[AllowMockObjectsWithoutExpectations]
class IndexTest extends TestCase
{
    private const TOKEN = 'b7dcd27f8a0836a18397791efada108c';

    private HttpRequest&MockObject $request;
    private FakeSession $session;
    private ManagerInterface&MockObject $messages;
    private OptOut&MockObject $optOut;
    private UnsubscribeLimiter&MockObject $limiter;
    private PageConfig&MockObject $pageConfig;
    private Title&MockObject $title;
    private Redirect $redirect;
    private Raw $raw;
    private Page $page;
    private ?string $path = null;
    private ?int $code = null;
    private ?string $contents = null;
    /** @var array<string, array<string, string>> result class => header => value */
    private array $headers = [];
    /** @var mixed what getParam('token') answers */
    private mixed $param = null;
    private bool $post = false;
    private Index $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->request->method('getParam')->willReturnCallback(fn (string $name) => $name === 'token' ? $this->param : null);
        $this->request->method('isPost')->willReturnCallback(fn () => $this->post);
        $this->redirect = $this->createStub(Redirect::class);
        $this->redirect->method('setPath')->willReturnCallback(function (string $path) {
            $this->path = $path;
            return $this->redirect;
        });
        $this->raw = $this->createStub(Raw::class);
        $this->raw->method('setHttpResponseCode')->willReturnCallback(function (int $code) {
            $this->code = $code;
            return $this->raw;
        });
        $this->raw->method('setContents')->willReturnCallback(function (string $contents) {
            $this->contents = $contents;
            return $this->raw;
        });
        $this->title = $this->createMock(Title::class);
        $this->pageConfig = $this->createMock(PageConfig::class);
        $this->pageConfig->method('getTitle')->willReturn($this->title);
        $this->page = $this->createStub(Page::class);
        $this->page->method('getConfig')->willReturn($this->pageConfig);
        foreach ([$this->redirect, $this->raw, $this->page] as $result) {
            $result->method('setHeader')->willReturnCallback(function ($name, $value) use ($result) {
                $this->headers[$this->kind($result)][$name] = $value;
                return $result;
            });
        }
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($this->raw);
        $pageFactory = $this->createStub(PageFactory::class);
        $pageFactory->method('create')->willReturn($this->page);
        $this->session = new FakeSession();
        $this->messages = $this->createMock(ManagerInterface::class);
        $this->optOut = $this->createMock(OptOut::class);
        $this->limiter = $this->createMock(UnsubscribeLimiter::class);
        $this->controller = new Index(
            $this->request,
            $pageFactory,
            $redirectFactory,
            $rawFactory,
            $this->session,
            $this->messages,
            $this->optOut,
            $this->limiter
        );
    }

    public function testTheEmailsLinkChangesNothingAndComesBackWithoutTheToken(): void
    {
        $this->param = self::TOKEN;
        $this->optOut->method('known')->with(self::TOKEN)->willReturn(true);
        $this->optOut->expects($this->never())->method('unsubscribe');
        $this->limiter->expects($this->never())->method('miss');
        $this->messages->expects($this->never())->method('addErrorMessage');

        $result = $this->controller->execute();

        $this->assertSame($this->redirect, $result);
        $this->assertSame('abandonedcart/unsubscribe/index', $this->path, 'the same page, no token in its URL');
        $this->assertSame([Index::SESSION_KEY => self::TOKEN], $this->session->data);
        $this->assertNoReferrerNoStore('redirect');
    }

    public function testAKnownLinkOpensFromABlockedAddress(): void
    {
        $this->param = self::TOKEN;
        $this->optOut->method('known')->willReturn(true);
        $this->limiter->method('blocked')->willReturn(true);
        $this->limiter->expects($this->never())->method('miss');

        $this->controller->execute();

        $this->assertSame('abandonedcart/unsubscribe/index', $this->path);
        $this->assertSame([Index::SESSION_KEY => self::TOKEN], $this->session->data);
    }

    public function testAnUnknownLinkSaysItIsNotValidAndCountsAMiss(): void
    {
        $this->param = 'ffffffffffffffffffffffffffffffff';
        $this->optOut->method('known')->willReturn(false);
        $this->optOut->expects($this->never())->method('unsubscribe');
        $this->limiter->method('blocked')->willReturn(false);
        $this->limiter->expects($this->once())->method('miss');
        $this->session->data[Index::SESSION_KEY] = 'an earlier link';
        $this->messages->expects($this->once())->method('addErrorMessage')
            ->with($this->callback(static fn ($message) => (string) $message === 'This link is no longer valid.'));

        $this->controller->execute();

        $this->assertSame('', $this->path, 'the home page');
        $this->assertSame([], $this->session->data, 'an earlier link in the session is dropped');
        $this->assertNoReferrerNoStore('redirect');
    }

    public function testABlockedAddressCountsNoMoreMisses(): void
    {
        $this->param = 'ffffffffffffffffffffffffffffffff';
        $this->optOut->method('known')->willReturn(false);
        $this->limiter->method('blocked')->willReturn(true);
        $this->limiter->expects($this->never())->method('miss');
        $this->messages->expects($this->once())->method('addErrorMessage');

        $this->controller->execute();

        $this->assertSame('', $this->path);
    }

    public function testATokenSentAsAnArrayIsAnUnknownLinkNotAnError(): void
    {
        $this->param = [self::TOKEN];
        $this->optOut->expects($this->once())->method('known')->with('')->willReturn(false);
        $this->messages->expects($this->once())->method('addErrorMessage');

        $this->controller->execute();

        $this->assertSame('', $this->path);
        $this->assertSame([], $this->session->data);
    }

    public function testThePageAsksToConfirmAndIsNeverIndexedOrStored(): void
    {
        $this->session->data[Index::SESSION_KEY] = self::TOKEN;
        $this->optOut->method('known')->with(self::TOKEN)->willReturn(true);
        $this->optOut->expects($this->never())->method('unsubscribe');
        $this->pageConfig->expects($this->once())->method('setRobots')->with('NOINDEX,NOFOLLOW');
        $this->title->expects($this->once())->method('set')
            ->with($this->callback(static fn ($title) => (string) $title === 'Unsubscribe from cart reminders'));

        $this->assertSame($this->page, $this->controller->execute());
        $this->assertSame([Index::SESSION_KEY => self::TOKEN], $this->session->data, 'kept for the confirm button');
        $this->assertNoReferrerNoStore('page');
    }

    public function testThePageWithoutALinkInTheSessionGoesHome(): void
    {
        $this->optOut->expects($this->never())->method('known');
        $this->pageConfig->expects($this->never())->method('setRobots');
        $this->messages->expects($this->once())->method('addErrorMessage')
            ->with($this->callback(static fn ($message) => (string) $message === 'This link is no longer valid.'));

        $this->assertSame($this->redirect, $this->controller->execute());
        $this->assertSame('', $this->path);
    }

    public function testThePageForALinkThatExpiredMeanwhileGoesHome(): void
    {
        $this->session->data[Index::SESSION_KEY] = self::TOKEN;
        $this->optOut->method('known')->willReturn(false);
        $this->messages->expects($this->once())->method('addErrorMessage');
        $this->limiter->expects($this->never())->method('miss');

        $this->assertSame($this->redirect, $this->controller->execute());
        $this->assertSame('', $this->path);
        $this->assertSame([], $this->session->data);
    }

    public function testOneClickPostAnswers200WithoutARedirect(): void
    {
        $this->post = true;
        $this->param = self::TOKEN;
        $this->optOut->expects($this->once())->method('unsubscribe')->with(self::TOKEN)->willReturn(true);
        $this->limiter->expects($this->never())->method('blocked');
        $this->limiter->expects($this->never())->method('miss');

        $this->assertSame($this->raw, $this->controller->execute());
        $this->assertSame(200, $this->code);
        $this->assertSame('Unsubscribed from cart reminders.', $this->contents);
        $this->assertNull($this->path, 'no redirect');
        $this->assertNoReferrerNoStore('raw');
    }

    public function testOneClickPostWithAKnownTokenWorksFromABlockedAddress(): void
    {
        $this->post = true;
        $this->param = self::TOKEN;
        $this->optOut->method('unsubscribe')->willReturn(true);
        $this->limiter->method('blocked')->willReturn(true);

        $this->controller->execute();

        $this->assertSame(200, $this->code);
    }

    public function testPostWithAnUnknownTokenAnswers404AndCountsAMiss(): void
    {
        $this->post = true;
        $this->param = 'ffffffffffffffffffffffffffffffff';
        $this->optOut->method('unsubscribe')->willReturn(false);
        $this->limiter->method('blocked')->willReturn(false);
        $this->limiter->expects($this->once())->method('miss');

        $this->controller->execute();

        $this->assertSame(404, $this->code);
        $this->assertSame('This link is no longer valid.', $this->contents);
        $this->assertNoReferrerNoStore('raw');
    }

    public function testPostWithAnUnknownTokenFromABlockedAddressAnswers429(): void
    {
        $this->post = true;
        $this->param = 'ffffffffffffffffffffffffffffffff';
        $this->optOut->method('unsubscribe')->willReturn(false);
        $this->limiter->method('blocked')->willReturn(true);
        $this->limiter->expects($this->never())->method('miss');

        $this->controller->execute();

        $this->assertSame(429, $this->code);
        $this->assertSame('Too many unsubscribe attempts. Please try again later.', $this->contents);
    }

    public function testPostWithoutATokenUnsubscribesNobody(): void
    {
        $this->post = true;
        $this->param = null;
        $this->optOut->expects($this->once())->method('unsubscribe')->with('')->willReturn(false);

        $this->controller->execute();

        $this->assertSame(404, $this->code);
        $this->assertSame([], $this->session->calls, 'the one-click POST never reads the session');
    }

    public function testNeedsNoFormKey(): void
    {
        $request = $this->createStub(RequestInterface::class);

        $this->assertTrue($this->controller->validateForCsrf($request));
        $this->assertNull($this->controller->createCsrfValidationException($request));
    }

    private function assertNoReferrerNoStore(string $kind): void
    {
        $headers = $this->headers[$kind] ?? [];
        $this->assertSame('no-referrer', $headers['Referrer-Policy'] ?? null);
        $this->assertStringContainsString('no-store', $headers['Cache-Control'] ?? '');
        $this->assertSame('no-cache', $headers['Pragma'] ?? null);
    }

    private function kind(ResultInterface $result): string
    {
        return match (true) {
            $result instanceof Redirect => 'redirect',
            $result instanceof Raw => 'raw',
            default => 'page',
        };
    }
}
