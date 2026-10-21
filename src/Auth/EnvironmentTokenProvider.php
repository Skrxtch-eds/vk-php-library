<?php

declare(strict_types=1);

namespace VkLibrary\Auth;

use InvalidArgumentException;
use VkLibrary\Exception\AuthenticationException;

final class EnvironmentTokenProvider implements TokenProvider
{
    public function __construct(private readonly string $variable = 'VK_ACCESS_TOKEN')
    {
        if ($variable === '' || str_contains($variable, '=')) {
            throw new InvalidArgumentException('Invalid environment variable name.');
        }
    }

    public function getToken(): string
    {
        $value = getenv($this->variable);
        if ($value === false || $value === '' || preg_match('/\s/', $value) === 1) {
            throw new AuthenticationException('VK access token is missing or invalid.');
        }

        // Читаем при каждом запросе: приложение может сменить токен без пересоздания клиента.
        return $value;
    }
}
