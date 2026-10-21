<?php

declare(strict_types=1);

namespace VkLibrary\Internal;

use VkLibrary\Exception\AccessDeniedException;
use VkLibrary\Exception\ApiException;
use VkLibrary\Exception\AuthenticationException;
use VkLibrary\Exception\HttpException;
use VkLibrary\Exception\InvalidResponseException;
use VkLibrary\Exception\NetworkException;
use VkLibrary\Exception\RateLimitException;
use VkLibrary\Exception\VkException;

/** @internal Новое исключение получает только безопасные имя класса и код. */
final class SafeException
{
    public static function create(string $class, int $code = 0): VkException
    {
        // Не принимаем исходное исключение: оно само стало бы аргументом нового trace.
        // PHP 8.1 ещё не умеет скрывать аргументы через SensitiveParameter.
        return match ($class) {
            AuthenticationException::class => new AuthenticationException('VK authentication failed.', $code),
            AccessDeniedException::class => new AccessDeniedException('VK access denied.', $code),
            RateLimitException::class => new RateLimitException('VK rate limit reached.', $code),
            NetworkException::class => new NetworkException('VK network request failed.', $code),
            InvalidResponseException::class => new InvalidResponseException('Invalid VK response.', $code),
            HttpException::class => new HttpException('Unexpected VK HTTP status.', $code),
            ApiException::class => new ApiException('VK API request failed.', $code),
            default => new VkException('VK request failed.', $code),
        };
    }
}
