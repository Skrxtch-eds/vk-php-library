<?php

declare(strict_types=1);

namespace VkLibrary\Internal;

use JsonException;
use VkLibrary\Auth\TokenProvider;
use VkLibrary\Exception\AccessDeniedException;
use VkLibrary\Exception\ApiException;
use VkLibrary\Exception\AuthenticationException;
use VkLibrary\Exception\HttpException;
use VkLibrary\Exception\InvalidResponseException;
use VkLibrary\Exception\NetworkException;
use VkLibrary\Exception\RateLimitException;
use VkLibrary\Http\HttpClient;
use VkLibrary\Retry\RetryPolicy;
use VkLibrary\Retry\Sleeper;

/** @internal Единственное место, где запрос получает токен, версию и правила повторов. */
final class ApiTransport
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly TokenProvider $token,
        private readonly string $version,
        private readonly RetryPolicy $retry,
        private readonly Sleeper $sleeper,
    ) {
    }

    /** @param array<string, int|string> $parameters */
    public function request(string $method, array $parameters): mixed
    {
        // Один вызов использует один токен, даже если хранилище обновится во время ожидания.
        // Новый публичный вызов прочитает актуальное значение из провайдера.
        $authenticatedParameters = $parameters;
        $authenticatedParameters['access_token'] = $this->token->getToken();
        $authenticatedParameters['v'] = $this->version;
        for ($attempt = 1; $attempt <= $this->retry->maxAttempts; $attempt++) {
            try {
                $response = $this->http->post($method, $authenticatedParameters);
            } catch (NetworkException $error) {
                if ($attempt === $this->retry->maxAttempts) {
                    // Не включаем previous: сторонний HTTP-клиент мог положить туда токен.
                    throw new NetworkException('VK request failed after all network attempts.');
                }
                $this->sleeper->sleep($this->retry->delay($attempt));
                continue;
            }

            $status = $response->statusCode;
            if (in_array($status, [420, 429, 500, 502, 503, 504], true)) {
                if ($attempt < $this->retry->maxAttempts) {
                    $this->sleeper->sleep($this->retry->delay($attempt, $response->header('Retry-After')));
                    continue;
                }
                if ($status === 420 || $status === 429) {
                    throw new RateLimitException('VK HTTP rate limit reached.', $status);
                }
                throw new HttpException('VK service is temporarily unavailable.', $status);
            }
            if ($status === 401) {
                throw new AuthenticationException('VK HTTP authentication failed.', $status);
            }
            if ($status === 403) {
                throw new AccessDeniedException('VK HTTP access denied.', $status);
            }
            if ($status < 200 || $status >= 300) {
                throw new HttpException('Unexpected VK HTTP status.', $status);
            }

            try {
                $payload = ResponseReader::object(json_decode($response->body, true, 64, JSON_THROW_ON_ERROR));
            } catch (JsonException $error) {
                throw new InvalidResponseException('VK returned invalid JSON.');
            }
            if (array_key_exists('error', $payload)) {
                $error = ResponseReader::object($payload['error']);
                $code = ResponseReader::integer($error['error_code'] ?? null, 1);
                // 6/29: ограничения VK; 1/10: временные ошибки сервера.
                $isTemporary = in_array($code, [1, 6, 10, 29, 420, 429], true);
                if ($isTemporary && $attempt < $this->retry->maxAttempts) {
                    $this->sleeper->sleep($this->retry->delay($attempt, $response->header('Retry-After')));
                    continue;
                }
                throw match (true) {
                    in_array($code, [5, 27, 28], true) => new AuthenticationException('VK token rejected.', $code),
                    in_array($code, [7, 15, 20, 188, 900, 901, 902, 917, 925], true)
                        => new AccessDeniedException('VK access denied. Check token permissions and recipient.', $code),
                    in_array($code, [6, 29, 420, 429], true)
                        => new RateLimitException('VK API rate limit reached.', $code),
                    default => new ApiException('VK API request failed.', $code),
                };
            }
            if (!array_key_exists('response', $payload)) {
                throw new InvalidResponseException('VK response envelope is missing.');
            }

            return $payload['response'];
        }

        throw new NetworkException('VK request attempts exhausted.');
    }
}
