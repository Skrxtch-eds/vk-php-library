<?php

declare(strict_types=1);

namespace VkLibrary\Tests\Support;

use LogicException;
use Throwable;
use VkLibrary\Http\HttpClient;
use VkLibrary\Http\HttpResponse;

final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method: string, parameters: array<string, int|string>}> */
    public array $requests = [];

    /** @param list<HttpResponse|Throwable> $responses */
    public function __construct(private array $responses)
    {
    }

    public function post(string $method, array $parameters): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'parameters' => $parameters];
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }
        if ($response === null) {
            throw new LogicException('Test did not supply enough responses.');
        }

        return $response;
    }

    public static function response(mixed $value): HttpResponse
    {
        return new HttpResponse(200, json_encode(['response' => $value], JSON_THROW_ON_ERROR));
    }

    public static function error(int $code, string $message = 'Server error'): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'error' => ['error_code' => $code, 'error_msg' => $message],
        ], JSON_THROW_ON_ERROR));
    }
}
