<?php

declare(strict_types=1);

namespace VkLibrary\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VkLibrary\Auth\AccessToken;
use VkLibrary\DialogApi;
use VkLibrary\Exception\AccessDeniedException;
use VkLibrary\Exception\ApiException;
use VkLibrary\Exception\AuthenticationException;
use VkLibrary\Exception\HttpException;
use VkLibrary\Exception\InvalidResponseException;
use VkLibrary\Exception\NetworkException;
use VkLibrary\Exception\RateLimitException;
use VkLibrary\Http\HttpResponse;
use VkLibrary\Model\Dialog;
use VkLibrary\Model\PeerType;
use VkLibrary\Retry\RetryPolicy;
use VkLibrary\Tests\Support\FakeHttpClient;
use VkLibrary\Tests\Support\RecordingSleeper;
use VkLibrary\VkClient;

final class VkClientTest extends TestCase
{
    private function client(
        FakeHttpClient $http,
        ?RecordingSleeper $sleeper = null,
        DialogApi $api = DialogApi::Legacy,
    ): VkClient {
        return new VkClient(
            $http,
            new AccessToken('test-token'),
            $api,
            retryPolicy: new RetryPolicy(),
            sleeper: $sleeper ?? new RecordingSleeper(),
        );
    }

    public function testSendMessagePreservesExplicitIdAndEncodesOptions(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::response(51)]);
        $id = $this->client($http)->sendMessage(2000000003, 'Привет & + = 👋', [
            'random_id' => 77,
            'disable_mentions' => true,
            'dont_parse_links' => false,
        ]);

        self::assertSame(51, $id);
        self::assertSame('messages.send', $http->requests[0]['method']);
        self::assertSame([
            'peer_id' => 2000000003,
            'message' => 'Привет & + = 👋',
            'random_id' => 77,
            'disable_mentions' => 1,
            'dont_parse_links' => 0,
            'access_token' => 'test-token',
            'v' => '5.80',
        ], $http->requests[0]['parameters']);
    }

    public function testNetworkRetryKeepsGeneratedRandomId(): void
    {
        $http = new FakeHttpClient([
            new NetworkException('timeout'),
            new NetworkException('connection reset'),
            FakeHttpClient::response(11),
        ]);
        $sleeper = new RecordingSleeper();

        self::assertSame(11, $this->client($http, $sleeper)->sendMessage(1, 'Привет'));
        self::assertCount(3, $http->requests);
        self::assertSame($http->requests[0], $http->requests[1]);
        self::assertSame($http->requests[1], $http->requests[2]);
        self::assertGreaterThan(0, $http->requests[0]['parameters']['random_id']);
        self::assertSame([250, 500], $sleeper->delays);
    }

    public function testNetworkRetriesStopAndDiscardUnsafePreviousException(): void
    {
        $http = new FakeHttpClient(array_fill(0, 3, new NetworkException('secret-token')));
        $sleeper = new RecordingSleeper();
        try {
            $this->client($http, $sleeper)->getUser(1);
            self::fail('Expected network exception.');
        } catch (NetworkException $error) {
            self::assertStringNotContainsString('secret-token', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertCount(3, $http->requests);
            self::assertCount(2, $sleeper->delays);
        }
    }

    public function testGetUserReturnsTypedProfileAndRequestsAvatar(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::response([[
            'id' => 1, 'first_name' => 'Павел', 'last_name' => 'Дуров', 'photo_100' => 'https://example.com/avatar.jpg',
        ]])]);
        $user = $this->client($http)->getUser(1);

        self::assertSame(1, $user->id);
        self::assertSame('Павел', $user->firstName);
        self::assertSame('Дуров', $user->lastName);
        self::assertSame('https://example.com/avatar.jpg', $user->avatarUrl);
        self::assertSame('users.get', $http->requests[0]['method']);
        self::assertSame('photo_100', $http->requests[0]['parameters']['fields']);
    }

    public function testMissingAvatarIsNullable(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::response([[
            'id' => 1, 'first_name' => 'Удалён', 'last_name' => '',
        ]])]);
        self::assertNull($this->client($http)->getUser(1)->avatarUrl);
    }

    public function testLegacyDialogsMapUsersChatsAndGroups(): void
    {
        $message = ['id' => 50, 'user_id' => 7, 'body' => 'Текст', 'date' => 1700000000];
        $http = new FakeHttpClient([FakeHttpClient::response([
            'count' => 8,
            'items' => [
                ['unread' => 2, 'message' => $message],
                ['message' => $message + ['chat_id' => 3, 'title' => 'Работа']],
                ['message' => array_replace($message, ['user_id' => -10])],
            ],
        ])]);
        $page = $this->client($http)->getDialogs(5, 3);

        self::assertSame(8, $page->totalCount);
        self::assertCount(3, $page->items);
        self::assertContainsOnlyInstancesOf(Dialog::class, $page->items);
        self::assertSame(7, $page->items[0]->peerId);
        self::assertSame(PeerType::User, $page->items[0]->type);
        self::assertSame(2, $page->items[0]->unreadCount);
        self::assertSame('Текст', $page->items[0]->lastMessage->text);
        self::assertSame(2000000003, $page->items[1]->peerId);
        self::assertSame(PeerType::Chat, $page->items[1]->type);
        self::assertSame('Работа', $page->items[1]->title);
        self::assertSame(PeerType::Group, $page->items[2]->type);
        self::assertSame('messages.getDialogs', $http->requests[0]['method']);
        self::assertSame(5, $http->requests[0]['parameters']['offset']);
        self::assertSame(3, $http->requests[0]['parameters']['count']);
    }

    public function testModernDialogsHaveSameDtoAndKeepOptionalLastMessage(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::response([
            'count' => 2,
            'items' => [
                [
                    'conversation' => [
                        'peer' => ['id' => 2000000003, 'type' => 'chat'],
                        'chat_settings' => ['title' => 'Команда'], 'unread_count' => 1,
                    ],
                    'last_message' => ['id' => 1, 'from_id' => 7, 'text' => 'Привет', 'date' => 1700000000],
                ],
                ['conversation' => ['peer' => ['id' => -10, 'type' => 'group']]],
            ],
        ])]);
        $page = $this->client($http, api: DialogApi::Conversations)->getDialogs();

        self::assertSame(2, $page->totalCount);
        self::assertSame('Команда', $page->items[0]->title);
        self::assertSame('Привет', $page->items[0]->lastMessage->text);
        self::assertNull($page->items[1]->lastMessage);
        self::assertSame('messages.getConversations', $http->requests[0]['method']);
        self::assertSame('5.199', $http->requests[0]['parameters']['v']);
    }

    public function testEmptyDialogsReturnAnEmptyPage(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::response(['count' => 0, 'items' => []])]);
        self::assertSame([], $this->client($http)->getDialogs()->items);
    }

    #[DataProvider('apiErrors')]
    public function testApiErrorsAreMappedWithoutLeakingServerText(int $code, string $class): void
    {
        $http = new FakeHttpClient([FakeHttpClient::error($code, 'secret-token')]);
        try {
            $this->client($http)->getUser(1);
            self::fail('Expected API exception.');
        } catch (ApiException $error) {
            self::assertInstanceOf($class, $error);
            self::assertSame($code, $error->getCode());
            self::assertStringNotContainsString('secret-token', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertCount(1, $http->requests);
        }
    }

    public static function apiErrors(): iterable
    {
        yield 'authentication' => [5, AuthenticationException::class];
        yield 'group token' => [27, AuthenticationException::class];
        yield 'permissions' => [7, AccessDeniedException::class];
        yield 'access' => [15, AccessDeniedException::class];
        yield 'assignment example' => [188, AccessDeniedException::class];
        yield 'recipient forbids messages' => [901, AccessDeniedException::class];
        yield 'unknown error' => [99999, ApiException::class];
        yield 'captcha must not retry' => [14, ApiException::class];
    }

    #[DataProvider('temporaryCodes')]
    public function testTemporaryApiErrorsRetry(int $code): void
    {
        $http = new FakeHttpClient([FakeHttpClient::error($code), FakeHttpClient::response(9)]);
        self::assertSame(9, $this->client($http)->sendMessage(1, 'Текст'));
        self::assertCount(2, $http->requests);
    }

    public static function temporaryCodes(): iterable
    {
        foreach ([1, 6, 10, 29, 420, 429] as $code) {
            yield (string) $code => [$code];
        }
    }

    public function testExhaustedApiRateLimitRetainsCode(): void
    {
        $http = new FakeHttpClient(array_fill(0, 3, FakeHttpClient::error(6)));
        $this->expectException(RateLimitException::class);
        $this->expectExceptionCode(6);
        $this->client($http)->getUser(1);
    }

    #[DataProvider('temporaryStatuses')]
    public function testTemporaryHttpErrorsRetryWithoutParsingBody(int $status): void
    {
        $http = new FakeHttpClient([
            new HttpResponse($status, '<html>proxy failure</html>', ['Retry-After' => '2']),
            FakeHttpClient::response(9),
        ]);
        $sleeper = new RecordingSleeper();
        self::assertSame(9, $this->client($http, $sleeper)->sendMessage(1, 'Текст'));
        self::assertSame([2000], $sleeper->delays);
    }

    public static function temporaryStatuses(): iterable
    {
        foreach ([420, 429, 500, 502, 503, 504] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('httpFailures')]
    public function testHttpFailuresKeepStatus(int $status, string $class, int $attempts): void
    {
        $http = new FakeHttpClient(array_fill(0, $attempts, new HttpResponse($status, 'private body')));
        $this->expectException($class);
        $this->expectExceptionCode($status);
        $this->client($http)->getUser(1);
    }

    public static function httpFailures(): iterable
    {
        yield [401, AuthenticationException::class, 1];
        yield [403, AccessDeniedException::class, 1];
        yield [302, HttpException::class, 1];
        yield [400, HttpException::class, 1];
        yield [429, RateLimitException::class, 3];
        yield [503, HttpException::class, 3];
    }

    #[DataProvider('badEnvelopes')]
    public function testInvalidEnvelopeIsRejectedWithoutRetry(string $body): void
    {
        $http = new FakeHttpClient([new HttpResponse(200, $body)]);
        $this->expectException(InvalidResponseException::class);
        $this->client($http)->getUser(1);
    }

    public static function badEnvelopes(): iterable
    {
        foreach (['{', 'null', 'true', '[]', '[1]', '{}', '{"error":null}', '{"error":{"error_code":"15"}}'] as $body) {
            yield $body => [$body];
        }
    }

    #[DataProvider('badUsers')]
    public function testMalformedUsersAreRejected(mixed $response): void
    {
        $http = new FakeHttpClient([FakeHttpClient::response($response)]);
        $this->expectException(InvalidResponseException::class);
        $this->client($http)->getUser(1);
    }

    public static function badUsers(): iterable
    {
        yield 'empty result' => [[]];
        yield 'wrong user' => [[['id' => 2, 'first_name' => 'A', 'last_name' => 'B']]];
        yield 'string id' => [[['id' => '1', 'first_name' => 'A', 'last_name' => 'B']]];
        yield 'missing name' => [[['id' => 1, 'last_name' => 'B']]];
        yield 'wrong photo type' => [[['id' => 1, 'first_name' => 'A', 'last_name' => 'B', 'photo_100' => 3]]];
        yield 'not a list' => [['id' => 1]];
    }

    #[DataProvider('badDialogs')]
    public function testMalformedDialogsAreRejected(mixed $response, DialogApi $api): void
    {
        $http = new FakeHttpClient([FakeHttpClient::response($response)]);
        $this->expectException(InvalidResponseException::class);
        $this->client($http, api: $api)->getDialogs();
    }

    public static function badDialogs(): iterable
    {
        yield 'missing count' => [['items' => []], DialogApi::Legacy];
        yield 'negative count' => [['count' => -1, 'items' => []], DialogApi::Legacy];
        yield 'missing message' => [['count' => 1, 'items' => [[]]], DialogApi::Legacy];
        yield 'unknown peer' => [[
            'count' => 1, 'items' => [['conversation' => ['peer' => ['id' => 1, 'type' => 'new-type']]]],
        ], DialogApi::Conversations];
        yield 'missing peer' => [[
            'count' => 1, 'items' => [['conversation' => []]],
        ], DialogApi::Conversations];
    }

    #[DataProvider('badMessages')]
    public function testInvalidSendInputNeverCallsHttp(int $peer, string $text, array $options): void
    {
        $http = new FakeHttpClient([]);
        try {
            $this->client($http)->sendMessage($peer, $text, $options);
            self::fail('Expected validation failure.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $http->requests);
        }
    }

    public static function badMessages(): iterable
    {
        yield [0, 'text', []];
        yield [1, '', []];
        yield [1, "\xFF", []];
        yield [1, str_repeat('я', 4097), []];
        yield [1, 'text', ['random_id' => 0]];
        yield [1, 'text', ['random_id' => '12']];
        yield [1, 'text', ['random_id' => 2147483648]];
        yield [1, 'text', ['peer_id' => 9]];
        yield [1, 'text', ['access_token' => 'evil']];
        yield [1, 'text', ['v' => '5.1']];
        yield [1, 'text', ['disable_mentions' => 1]];
        yield [1, 'text', ['random_id' => null]];
    }

    public function testMessageBoundaryAndNegativePeerAreAccepted(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::response(1)]);
        self::assertSame(1, $this->client($http)->sendMessage(-10, str_repeat('я', 4096)));
    }

    #[DataProvider('badPagination')]
    public function testInvalidPaginationNeverCallsHttp(int $offset, int $count): void
    {
        $http = new FakeHttpClient([]);
        $this->expectException(InvalidArgumentException::class);
        $this->client($http)->getDialogs($offset, $count);
    }

    public static function badPagination(): iterable
    {
        yield [-1, 10];
        yield [0, 0];
        yield [0, 201];
    }

    public function testInvalidUserIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client(new FakeHttpClient([]))->getUser(-1);
    }

    public function testCustomVersionIsPassedThrough(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::response(1)]);
        $client = new VkClient($http, new AccessToken('test'), apiVersion: '5.131');
        $client->sendMessage(1, 'text');
        self::assertSame('5.131', $http->requests[0]['parameters']['v']);
    }

    public function testInvalidVersionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new VkClient(new FakeHttpClient([]), new AccessToken('test'), apiVersion: '../invalid');
    }

    public function testMalformedSendResponseIsRejected(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::response(['message_id' => 1])]);
        $this->expectException(InvalidResponseException::class);
        $this->client($http)->sendMessage(1, 'text');
    }
}
