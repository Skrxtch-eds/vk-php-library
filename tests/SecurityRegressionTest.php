<?php

declare(strict_types=1);

namespace VkLibrary\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VkLibrary\Auth\AccessToken;
use VkLibrary\Auth\TokenProvider;
use VkLibrary\Exception\AccessDeniedException;
use VkLibrary\Exception\InvalidResponseException;
use VkLibrary\Exception\NetworkException;
use VkLibrary\Exception\VkException;
use VkLibrary\Http\HttpClient;
use VkLibrary\Http\HttpResponse;
use VkLibrary\Retry\RetryPolicy;
use VkLibrary\Tests\Support\FakeHttpClient;
use VkLibrary\Tests\Support\RecordingSleeper;
use VkLibrary\VkClient;

final class SecurityRegressionTest extends TestCase
{
    #[DataProvider('failuresWithSecrets')]
    public function testExceptionsDoNotExposeTokenWhenTraceArgumentsAreEnabled(
        string $scenario,
        string $expectedClass,
        string $operation = 'user',
    ): void {
        $previousSetting = ini_set('zend.exception_ignore_args', '0');
        try {
            $response = match ($scenario) {
                'access' => FakeHttpClient::error(15),
                'network' => new NetworkException('REGRESSION_SECRET_TOKEN'),
                'size' => new InvalidResponseException('REGRESSION_SECRET_TOKEN'),
                'unexpected' => new RuntimeException('REGRESSION_SECRET_TOKEN'),
                'reflected' => new HttpResponse(200, '{"error":["REGRESSION_SECRET_TOKEN"]}'),
                'user_result' => FakeHttpClient::response([[
                    'id' => 123, 'first_name' => ['token' => 'REGRESSION_SECRET_TOKEN'], 'last_name' => 'B',
                ]]),
                'dialog_result' => FakeHttpClient::response([
                    'count' => 1, 'items' => [['token' => 'REGRESSION_SECRET_TOKEN']],
                ]),
                'send_result' => FakeHttpClient::response(['token' => 'REGRESSION_SECRET_TOKEN']),
            };
            $client = new VkClient(
                new FakeHttpClient([$response]),
                new AccessToken('REGRESSION_SECRET_TOKEN'),
                retryPolicy: new RetryPolicy(maxAttempts: 1),
            );
            try {
                match ($operation) {
                    'user' => $client->getUser(123),
                    'dialogs' => $client->getDialogs(),
                    'send' => $client->sendMessage(123, 'Text'),
                };
                self::fail('Expected a library exception.');
            } catch (VkException $error) {
                self::assertInstanceOf($expectedClass, $error);
                self::assertNull($error->getPrevious());
                self::assertFalse(str_contains(print_r($error->getTrace(), true), 'REGRESSION_SECRET_TOKEN'));
                self::assertFalse(str_contains((string) $error, 'REGRESSION_SECRET_TOKEN'));
                ob_start();
                var_dump($error);
                $dump = ob_get_clean();
                self::assertFalse(str_contains($dump, 'REGRESSION_SECRET_TOKEN'));
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previousSetting);
        }
    }

    public static function failuresWithSecrets(): iterable
    {
        yield 'access denied' => ['access', AccessDeniedException::class];
        yield 'network failure' => ['network', NetworkException::class];
        yield 'HTTP size failure' => ['size', InvalidResponseException::class];
        yield 'unexpected HTTP failure' => ['unexpected', VkException::class];
        yield 'malformed reflected error' => ['reflected', InvalidResponseException::class];
        yield 'malformed user result' => ['user_result', InvalidResponseException::class];
        yield 'malformed dialog result' => ['dialog_result', InvalidResponseException::class, 'dialogs'];
        yield 'malformed send result' => ['send_result', InvalidResponseException::class, 'send'];
    }

    public function testRetryKeepsSenderButNextPublicCallReadsNewToken(): void
    {
        $provider = new class implements TokenProvider {
            public int $reads = 0;

            public function getToken(): string
            {
                $this->reads++;
                return $this->reads === 1 ? 'SENDER_A' : 'SENDER_B';
            }
        };
        $http = new class implements HttpClient {
            /** @var list<string> */
            public array $senders = [];

            /** @var array<string, int> */
            public array $deliveries = [];

            public function post(string $method, array $parameters): HttpResponse
            {
                $sender = (string) $parameters['access_token'];
                $this->senders[] = $sender;
                $key = $sender . ':' . $parameters['random_id'];
                $this->deliveries[$key] ??= count($this->deliveries) + 1;
                if (count($this->senders) === 1) {
                    // Сервер принял отправку; потерялся только ответ первой попытки.
                    throw new NetworkException('Lost first response.');
                }

                return FakeHttpClient::response($this->deliveries[$key]);
            }
        };
        $client = new VkClient($http, $provider, sleeper: new RecordingSleeper());

        self::assertSame(1, $client->sendMessage(123, 'First operation', ['random_id' => 456]));
        self::assertSame(['SENDER_A', 'SENDER_A'], $http->senders);
        self::assertCount(1, $http->deliveries);
        self::assertSame(1, $provider->reads);

        self::assertSame(2, $client->sendMessage(123, 'Second operation', ['random_id' => 457]));
        self::assertSame(['SENDER_A', 'SENDER_A', 'SENDER_B'], $http->senders);
        self::assertSame(2, $provider->reads);
    }

    #[DataProvider('legacySenders')]
    public function testLegacySenderIsNeverGuessedFromRecipient(array $fields, ?int $expectedSender): void
    {
        $message = $fields + ['id' => 1, 'user_id' => 123, 'body' => 'Text', 'date' => 1700000000];
        $http = new FakeHttpClient([FakeHttpClient::response([
            'count' => 1, 'items' => [['message' => $message]],
        ])]);
        $dialog = (new VkClient($http, new AccessToken('fake')))->getDialogs()->items[0];

        self::assertSame($expectedSender, $dialog->lastMessage->fromId);
    }

    public static function legacySenders(): iterable
    {
        yield 'outgoing without author' => [['out' => 1], null];
        yield 'incoming without author' => [['out' => 0], 123];
        yield 'unknown direction' => [[], null];
        yield 'explicit outgoing author' => [['out' => 1, 'from_id' => 999], 999];
        yield 'explicit incoming author' => [['out' => 0, 'from_id' => 456], 456];
        yield 'outgoing group chat' => [['out' => 1, 'chat_id' => 7], null];
        yield 'incoming group chat' => [['out' => 0, 'chat_id' => 7], 123];
    }

    public function testInvalidLegacyDirectionIsRejected(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::response([
            'count' => 1,
            'items' => [['message' => [
                'id' => 1, 'user_id' => 123, 'out' => 2, 'body' => 'Text', 'date' => 1700000000,
            ]]],
        ])]);
        $this->expectException(InvalidResponseException::class);
        (new VkClient($http, new AccessToken('fake')))->getDialogs();
    }

    #[DataProvider('invalidLegacyChatIds')]
    public function testInvalidLegacyChatIdIsReportedAsInvalidResponse(mixed $chatId): void
    {
        $http = new FakeHttpClient([FakeHttpClient::response([
            'count' => 1,
            'items' => [['message' => [
                'id' => 1, 'user_id' => 123, 'chat_id' => $chatId,
                'out' => 0, 'body' => 'Text', 'date' => 1700000000,
            ]]],
        ])]);

        $this->expectException(InvalidResponseException::class);
        (new VkClient($http, new AccessToken('fake')))->getDialogs();
    }

    public static function invalidLegacyChatIds(): iterable
    {
        yield 'largest integer' => [PHP_INT_MAX];
        yield 'first overflowing peer' => [PHP_INT_MAX - 2000000000 + 1];
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'numeric string' => ['7'];
        yield 'float' => [7.5];
    }

    public function testLargestRepresentableLegacyPeerDoesNotOverflow(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::response([
            'count' => 1,
            'items' => [['message' => [
                'id' => 1, 'user_id' => 123, 'chat_id' => PHP_INT_MAX - 2000000000,
                'out' => 0, 'body' => 'Text', 'date' => 1700000000,
            ]]],
        ])]);

        $dialog = (new VkClient($http, new AccessToken('fake')))->getDialogs()->items[0];
        self::assertSame(PHP_INT_MAX, $dialog->peerId);
        self::assertSame(123, $dialog->lastMessage->fromId);
    }
}
