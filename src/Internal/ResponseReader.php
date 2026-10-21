<?php

declare(strict_types=1);

namespace VkLibrary\Internal;

use VkLibrary\Exception\InvalidResponseException;

/** @internal Проверяем JSON до создания DTO, не приводя ошибочные строки к int. */
final class ResponseReader
{
    /** @return array<array-key, mixed> */
    public static function object(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidResponseException('Expected a VK response object.');
        }

        return $value;
    }

    /** @return list<mixed> */
    public static function list(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidResponseException('Expected a VK response list.');
        }

        return $value;
    }

    public static function integer(mixed $value, int $minimum = PHP_INT_MIN): int
    {
        if (!is_int($value) || $value < $minimum) {
            throw new InvalidResponseException('Expected an integer in VK response.');
        }

        return $value;
    }

    public static function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidResponseException('Expected a string in VK response.');
        }

        return $value;
    }
}
