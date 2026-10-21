<?php

declare(strict_types=1);

namespace VkLibrary\Auth;

use InvalidArgumentException;
use LogicException;

final class AccessToken implements TokenProvider
{
    private readonly string $value;

    public function __construct(string $value)
    {
        if ($value === '' || preg_match('/\s/', $value) === 1) {
            throw new InvalidArgumentException('Token must be non-empty and contain no whitespace.');
        }

        $this->value = $value;
    }

    public function getToken(): string
    {
        return $this->value;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        // Сериализация объекта не должна превращаться в случайную выгрузку секрета.
        throw new LogicException('Access tokens cannot be serialized.');
    }
}
