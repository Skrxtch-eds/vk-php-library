<?php

declare(strict_types=1);

namespace VkLibrary\Http;

use InvalidArgumentException;
use VkLibrary\Exception\InvalidResponseException;
use VkLibrary\Exception\NetworkException;

final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private readonly int $connectTimeoutMs = 3000,
        private readonly int $timeoutMs = 10000,
        private readonly int $maxResponseBytes = 2097152,
    ) {
        if ($connectTimeoutMs < 1 || $timeoutMs < $connectTimeoutMs || $maxResponseBytes < 1) {
            throw new InvalidArgumentException('Invalid HTTP timeout or response limit.');
        }
    }

    public function post(string $method, array $parameters): HttpResponse
    {
        if (preg_match('/^[a-zA-Z]+\.[a-zA-Z]+$/D', $method) !== 1) {
            throw new InvalidArgumentException('Invalid VK method name.');
        }

        // Хост фиксирован: пользовательские параметры не могут перенаправить токен на другой сервер.
        $handle = curl_init('https://api.vk.com/method/' . $method);
        if ($handle === false) {
            throw new NetworkException('Unable to initialize HTTP transport.');
        }

        $body = '';
        $headers = [];
        $tooLarge = false;
        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
                CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
                CURLOPT_TIMEOUT_MS => $this->timeoutMs,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                    if (str_starts_with($line, 'HTTP/')) {
                        $headers = [];
                    } elseif (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $headers[strtolower(trim($name))] = trim($value);
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > $this->maxResponseBytes) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);

            $result = curl_exec($handle);
            if ($tooLarge) {
                throw new InvalidResponseException('VK response exceeded the size limit.');
            }
            if ($result === false) {
                // curl_error() может содержать детали окружения; наружу возвращаем только errno.
                throw new NetworkException('VK network request failed.', curl_errno($handle));
            }

            return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body, $headers);
        } finally {
            curl_close($handle);
        }
    }
}
