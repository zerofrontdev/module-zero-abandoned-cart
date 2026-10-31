<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AbandonedCart\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\EmailMessage;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Url;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Message as SymfonyMessage;
use Zero\AbandonedCart\Model\OptOut;
use Zero\AbandonedCart\Model\Reminders;
use Zero\AbandonedCart\Model\Sender;

/**
 * Which carts are due in which store views, and a reminder recorded before it's sent,
 * with its unsubscribe link in the body and in the List-Unsubscribe headers.
 *
 * The mocks are shared by the tests; each sets expectations on the ones it's about.
 */
#[AllowMockObjectsWithoutExpectations]
class SenderTest extends TestCase
{
    private const NOW = 1791676800; // 2026-10-11 00:00:00 UTC

    private Reminders&MockObject $reminders;
    private TransportBuilder&MockObject $transportBuilder;
    private LoggerInterface&MockObject $logger;
    private OptOut&MockObject $optOut;
    /** @var array<int, array<string, mixed>> store id => zero_abandoned_cart/general/* */
    private array $config = [];
    /** @var array<int, Store> */
    private array $stores = [];
    /** @var array<int, array{0: string, 1: array<string, mixed>}> every Url::getUrl() call */
    private array $urls = [];
    private Sender $sender;

    protected function setUp(): void
    {
        $this->reminders = $this->createMock(Reminders::class);
        $this->transportBuilder = $this->createMock(TransportBuilder::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->optOut = $this->createMock(OptOut::class);
        $stores = [];
        foreach ([1 => true, 5 => true, 7 => false] as $id => $active) {
            $store = $this->createStub(Store::class);
            $store->method('getId')->willReturn($id);
            $store->method('isActive')->willReturn($active);
            $store->method('getWebsiteId')->willReturn(2);
            $stores[$id] = $store;
        }
        $this->stores = $stores;
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);
        $storeManager->method('getStore')->willReturnCallback(static fn ($id) => $stores[$id]);
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $field = static fn (string $path) => substr($path, strlen('zero_abandoned_cart/general/'));
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            fn ($path, $scope, $id) => (bool) ($this->config[$id][$field($path)] ?? false)
        );
        $scopeConfig->method('getValue')->willReturnCallback(
            fn ($path, $scope, $id) => $this->config[$id][$field($path)] ?? null
        );
        $quote = $this->createStub(Quote::class);
        $quote->method('getId')->willReturn(42);
        $quoteRepository = $this->createStub(CartRepositoryInterface::class);
        $quoteRepository->method('get')->willReturn($quote);
        $url = $this->createStub(Url::class);
        $url->method('getUrl')->willReturnCallback(
            function ($route, $params) {
                $this->urls[] = [$route, $params];
                return isset($params['_query'])
                    ? 'https://shop.test/ua/' . $route . '/?token=' . $params['_query']['token']
                    : 'https://shop.test/ua/' . $route . '/token/' . $params['token'] . '/';
            }
        );
        $this->sender = new Sender(
            $this->reminders,
            $quoteRepository,
            $this->transportBuilder,
            $storeManager,
            $scopeConfig,
            $url,
            $this->logger,
            $this->optOut
        );
    }

    public function testDueOnlyInActiveStoreViewsWithRemindersOnWithinTheirWindow(): void
    {
        $this->config = [
            1 => ['enabled' => '0'],
            5 => ['enabled' => '1', 'delay' => '60', 'max_age' => '7'],
            7 => ['enabled' => '1', 'delay' => '60', 'max_age' => '7'],
        ];
        $cart = ['quote_id' => 42, 'store_id' => 5, 'email' => 'a@example.com', 'firstname' => ''];
        $this->reminders->expects($this->once())->method('due')
            ->with(5, '2026-10-04 00:00:00', '2026-10-10 23:00:00', Sender::BATCH, true)
            ->willReturn([$cart]);

        $this->assertSame([$cart], $this->sender->due(self::NOW));
    }

    public function testRecordsTheReminderThenSendsItWithItsLink(): void
    {
        $this->config[5] = ['identity' => 'sales', 'template' => 'zero_abandoned_cart_general_template'];
        $token = null;
        $this->reminders->expects($this->once())->method('add')
            ->with(42, $this->callback(function (string $value) use (&$token) {
                $token = $value;
                return (bool) preg_match('/^[0-9a-f]{32}$/', $value);
            }));
        $vars = null;
        $this->transportBuilder->method('setTemplateIdentifier')->with('zero_abandoned_cart_general_template')->willReturnSelf();
        $this->transportBuilder->method('setTemplateOptions')->with(['area' => 'frontend', 'store' => 5])->willReturnSelf();
        $this->transportBuilder->method('setTemplateVars')->willReturnCallback(function (array $value) use (&$vars) {
            $vars = $value;
            return $this->transportBuilder;
        });
        $this->transportBuilder->method('setFromByScope')->with('sales', 5)->willReturnSelf();
        $this->transportBuilder->method('addTo')->with('olena@example.com', 'Олена')->willReturnSelf();
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->once())->method('sendMessage');
        $this->transportBuilder->method('getTransport')->willReturn($transport);

        $this->sender->send(['quote_id' => 42, 'store_id' => 5, 'email' => 'olena@example.com', 'firstname' => 'Олена']);

        $this->assertSame(42, $vars['quote_id']);
        $this->assertSame('Олена', $vars['customer_name']);
        $this->assertSame('https://shop.test/ua/abandonedcart/restore/index/token/' . $token . '/', $vars['restore_url']);
    }

    public function testDueAsksForGuestsUnlessTurnedOff(): void
    {
        $asked = [];
        $this->reminders->expects($this->exactly(3))->method('due')
            ->willReturnCallback(function (int $storeId, string $since, string $until, int $limit, bool $guests) use (&$asked) {
                $asked[] = $guests;
                return [];
            });
        $window = ['enabled' => '1', 'delay' => '60', 'max_age' => '7'];
        foreach ([null, 'newsletter', 'none'] as $guests) {
            $this->config = [5 => $guests === null ? $window : $window + ['guests' => $guests]];
            $this->sender->due(self::NOW);
        }

        $this->assertSame([true, true, false], $asked);
    }

    public function testEveryReminderHasItsOwnUnsubscribeLinkInTheBodyAndTheHeaders(): void
    {
        $this->config[5] = ['identity' => 'sales', 'template' => 'zero_abandoned_cart_general_template'];
        $log = [];
        $restore = null;
        $this->reminders->expects($this->once())->method('add')
            ->willReturnCallback(function (int $quoteId, string $token) use (&$log, &$restore) {
                $log[] = 'add';
                $restore = $token;
            });
        $unsubscribe = str_repeat('a', 32);
        $this->optOut->expects($this->once())->method('issue')->with('olena@example.com', 2)
            ->willReturnCallback(function () use (&$log, $unsubscribe) {
                $log[] = 'issue';
                return $unsubscribe;
            });
        $vars = null;
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setFromByScope', 'addTo'] as $m) {
            $this->transportBuilder->method($m)->willReturnSelf();
        }
        $this->transportBuilder->method('setTemplateVars')->willReturnCallback(function (array $value) use (&$vars) {
            $vars = $value;
            return $this->transportBuilder;
        });
        $symfony = new SymfonyMessage();
        $message = $this->createStub(EmailMessage::class);
        $message->method('getSymfonyMessage')->willReturn($symfony);
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('getMessage')->willReturn($message);
        $transport->expects($this->once())->method('sendMessage')->willReturnCallback(function () use (&$log) {
            $log[] = 'send';
        });
        $this->transportBuilder->method('getTransport')->willReturn($transport);

        $this->sender->send(['quote_id' => 42, 'store_id' => 5, 'email' => 'olena@example.com', 'firstname' => 'Олена']);

        $link = 'https://shop.test/ua/abandonedcart/unsubscribe/index/?token=' . $unsubscribe;
        $this->assertSame(['add', 'issue', 'send'], $log);
        $this->assertSame($link, $vars['unsubscribe_url']);
        $this->assertNotSame($unsubscribe, $restore, 'the restore token is not the unsubscribe token');
        $this->assertStringNotContainsString($unsubscribe, $vars['restore_url']);
        $headers = $symfony->getHeaders();
        $this->assertSame('<' . $link . '>', $headers->get('List-Unsubscribe')?->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $headers->get('List-Unsubscribe-Post')?->getBodyAsString());
        // The cart's own store view (not the current one), no session id in a link that goes by mail.
        $unsubscribeCalls = array_values(array_filter($this->urls, static fn ($call) => $call[0] === 'abandonedcart/unsubscribe/index'));
        $this->assertNotEmpty($unsubscribeCalls);
        foreach ($unsubscribeCalls as [, $params]) {
            $this->assertSame($this->stores[5], $params['_scope'] ?? null);
            $this->assertTrue($params['_nosid'] ?? false);
            $this->assertSame(['token' => $unsubscribe], $params['_query'] ?? null);
            $this->assertArrayNotHasKey('token', $params, 'the token goes in the query string, not the path');
        }
    }

    public function testTheEmailTemplateLinksToUnsubscribe(): void
    {
        $html = (string) file_get_contents(dirname(__DIR__, 3) . '/view/frontend/email/reminder.html');

        $this->assertStringContainsString('"var unsubscribe_url":', $html, 'offered in the admin template editor');
        $this->assertStringContainsString('<a href="{{var unsubscribe_url}}">', $html);
    }

    public function testPreviewRecordsNothingAndItsLinksDoNothing(): void
    {
        $this->config[5] = ['identity' => 'sales', 'template' => 'zero_abandoned_cart_general_template'];
        $this->reminders->expects($this->never())->method('add');
        $this->optOut->expects($this->never())->method('issue');
        $vars = null;
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setFromByScope', 'addTo'] as $m) {
            $this->transportBuilder->method($m)->willReturnSelf();
        }
        $this->transportBuilder->method('setTemplateVars')->willReturnCallback(function (array $value) use (&$vars) {
            $vars = $value;
            return $this->transportBuilder;
        });
        $message = $this->createStub(EmailMessage::class);
        $message->method('getBodyText')->willReturn('<p>Your cart</p>');
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('getMessage')->willReturn($message);
        $transport->expects($this->never())->method('sendMessage');
        $this->transportBuilder->method('getTransport')->willReturn($transport);

        $html = $this->sender->preview(['quote_id' => 42, 'store_id' => 5, 'email' => 'olena@example.com', 'firstname' => '']);

        $zero = str_repeat('0', 32);
        $this->assertSame('<p>Your cart</p>', $html);
        $this->assertSame('https://shop.test/ua/abandonedcart/unsubscribe/index/?token=' . $zero, $vars['unsubscribe_url']);
        $this->assertSame('https://shop.test/ua/abandonedcart/restore/index/token/' . $zero . '/', $vars['restore_url']);
    }

    public function testAFailedEmailIsLoggedAndTheRestGoOn(): void
    {
        $this->config[5] = ['enabled' => '1', 'delay' => '60', 'max_age' => '7'];
        $this->reminders->method('due')->willReturn([
            ['quote_id' => 41, 'store_id' => 5, 'email' => 'a@example.com', 'firstname' => ''],
            ['quote_id' => 42, 'store_id' => 5, 'email' => 'b@example.com', 'firstname' => ''],
        ]);
        $this->reminders->expects($this->exactly(2))->method('add');
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo'] as $m) {
            $this->transportBuilder->method($m)->willReturnSelf();
        }
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('sendMessage')->willReturnOnConsecutiveCalls(
            $this->throwException(new \RuntimeException('no sendmail')),
            null
        );
        $this->transportBuilder->method('getTransport')->willReturn($transport);
        $this->logger->expects($this->once())->method('error')->with('Zero_AbandonedCart: cart 41: no sendmail');

        $this->assertSame(1, $this->sender->sendDue());
    }
}
