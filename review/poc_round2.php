<?php

declare(strict_types=1);

// Local response-validation check. No network or real credentials are used.
require $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';

use VkLibrary\Auth\AccessToken;
use VkLibrary\Exception\InvalidResponseException;
use VkLibrary\Http\HttpClient;
use VkLibrary\Http\HttpResponse;
use VkLibrary\VkClient;

$http = new class implements HttpClient {
    public function post(string $method, array $parameters): HttpResponse
    {
        return new HttpResponse(200, json_encode(['response' => [
            'count' => 1,
            'items' => [['message' => [
                'id' => 1,
                'user_id' => 123,
                'chat_id' => PHP_INT_MAX,
                'out' => 0,
                'body' => 'Synthetic response',
                'date' => 1700000000,
            ]]],
        ]], JSON_THROW_ON_ERROR));
    }
};

echo 'PHP ' . PHP_VERSION . PHP_EOL;
try {
    (new VkClient($http, new AccessToken('SYNTHETIC_REVIEW_VALUE')))->getDialogs();
    echo 'unexpected_success=yes' . PHP_EOL;
    exit(1);
} catch (Throwable $error) {
    echo 'observed_exception=' . $error::class . PHP_EOL;
    echo 'expected_invalid_response=' . ($error instanceof InvalidResponseException ? 'yes' : 'no') . PHP_EOL;
    echo 'message=' . $error->getMessage() . PHP_EOL;
    exit($error instanceof InvalidResponseException ? 0 : 1);
}
