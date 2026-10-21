<?php

declare(strict_types=1);

// All tokens and responses in this file are artificial. No network calls occur.
require dirname(__DIR__) . '/vendor/autoload.php';

use VkLibrary\Auth\AccessToken;
use VkLibrary\Auth\EnvironmentTokenProvider;
use VkLibrary\Exception\NetworkException;
use VkLibrary\Http\HttpClient;
use VkLibrary\Http\HttpResponse;
use VkLibrary\Retry\RetryPolicy;
use VkLibrary\Retry\Sleeper;
use VkLibrary\VkClient;

function response(mixed $data): HttpResponse
{
    return new HttpResponse(200, json_encode(['response' => $data], JSON_THROW_ON_ERROR));
}

function tracePoC(bool $includeArguments = true): void
{
    ini_set('zend.exception_ignore_args', $includeArguments ? '0' : '1');
    $secret = 'FAKE_TOKEN_FOR_REVIEW_ONLY_123456789';
    $http = new class implements HttpClient {
        public function post(string $method, array $parameters): HttpResponse
        {
            // The response does not contain the fake token.
            return new HttpResponse(200, '{"error":{"error_code":15}}');
        }
    };
    try {
        (new VkClient($http, new AccessToken($secret)))->getUser(123);
    } catch (Throwable $error) {
        echo 'exception=' . get_class($error) . PHP_EOL;
        echo 'message_contains_token=' . (str_contains($error->getMessage(), $secret) ? 'yes' : 'no') . PHP_EOL;
        $traceContainsToken = str_contains(print_r($error->getTrace(), true), $secret);
        echo 'trace_contains_raw_access_token=' . ($traceContainsToken ? 'yes' : 'no') . PHP_EOL;
        ob_start();
        var_dump($error);
        $dump = ob_get_clean();
        echo 'var_dump_exception_contains_token=' . (str_contains($dump, $secret) ? 'yes' : 'no') . PHP_EOL;
    }
}

function rotationPoC(): void
{
    $variable = 'VK_LIBRARY_REVIEW_FAKE_TOKEN';
    putenv($variable . '=FAKE_SENDER_A');
    $http = new class implements HttpClient {
        public array $delivered = [];
        private array $seen = [];
        private int $attempts = 0;
        public function post(string $method, array $parameters): HttpResponse
        {
            $this->attempts++;
            // Model VK deduplication, which is scoped to the sender's identity.
            $key = $parameters['access_token'] . ':' . $parameters['random_id'];
            if (!isset($this->seen[$key])) {
                $this->seen[$key] = count($this->delivered) + 1;
                $this->delivered[] = [
                    'sender' => $parameters['access_token'],
                    'peer_id' => $parameters['peer_id'],
                    'random_id' => $parameters['random_id'],
                ];
            }
            if ($this->attempts === 1) {
                // The first message was accepted; only the HTTP response was lost.
                throw new NetworkException('Simulated lost response.');
            }
            return response($this->seen[$key]);
        }
    };
    $sleeper = new class ($variable) implements Sleeper {
        public function __construct(private string $variable)
        {
        }
        public function sleep(int $milliseconds): void
        {
            // A custom vault/provider can also rotate while a request is running.
            putenv($this->variable . '=FAKE_SENDER_B');
        }
    };
    try {
        $client = new VkClient(
            $http,
            new EnvironmentTokenProvider($variable),
            retryPolicy: new RetryPolicy(2, 0, 0),
            sleeper: $sleeper,
        );
        echo 'returned_message_id=' . $client->sendMessage(123, 'A single logical message', ['random_id' => 456]) . PHP_EOL;
        echo 'delivered=' . json_encode($http->delivered, JSON_THROW_ON_ERROR) . PHP_EOL;
    } finally {
        putenv($variable);
    }
}

function legacyPoC(): void
{
    $http = new class implements HttpClient {
        public function post(string $method, array $parameters): HttpResponse
        {
            return response([
                'count' => 1,
                'items' => [[
                    'unread' => 0,
                    'message' => [
                        'id' => 500,
                        'user_id' => 123,
                        'out' => 1,
                        'read_state' => 1,
                        'body' => 'Sent by authenticated user 999 to user 123',
                        'date' => 1700000000,
                    ],
                ]],
            ]);
        }
    };
    $dialog = (new VkClient($http, new AccessToken('FAKE_TOKEN_USER_999')))->getDialogs()->items[0];
    echo 'peer_id=' . $dialog->peerId . PHP_EOL;
    echo 'actual_sender=' . json_encode($dialog->lastMessage->fromId, JSON_THROW_ON_ERROR) . PHP_EOL;
    echo 'expected_sender=999 (or unknown; recipient 123 is not the sender)' . PHP_EOL;
}

$case = $argv[1] ?? 'all';
$cases = [
    'trace' => static fn () => tracePoC(),
    'trace-no-args' => static fn () => tracePoC(false),
    'rotation' => 'rotationPoC',
    'legacy' => 'legacyPoC',
];
echo 'PHP ' . PHP_VERSION . PHP_EOL;
foreach ($cases as $name => $run) {
    if ($case === 'all' || $case === $name) {
        echo '[' . $name . ']' . PHP_EOL;
        $run();
    }
}
