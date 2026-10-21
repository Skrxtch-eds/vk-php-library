<?php

declare(strict_types=1);

namespace VkLibrary\Retry;

use InvalidArgumentException;

final class RetryPolicy
{
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly int $baseDelayMs = 250,
        public readonly int $maxDelayMs = 30000,
    ) {
        if ($maxAttempts < 1 || $maxAttempts > 10 || $baseDelayMs < 0 || $maxDelayMs < $baseDelayMs) {
            throw new InvalidArgumentException('Invalid retry policy.');
        }
        if ($maxDelayMs > 60000) {
            throw new InvalidArgumentException('Maximum retry delay is 60000 ms.');
        }
    }

    public function delay(int $failedAttempt, ?string $retryAfter = null): int
    {
        if ($failedAttempt < 1 || $failedAttempt > $this->maxAttempts) {
            throw new InvalidArgumentException('Invalid attempt number.');
        }

        $delay = min($this->maxDelayMs, $this->baseDelayMs * (2 ** ($failedAttempt - 1)));
        if ($retryAfter !== null) {
            // Retry-After допускает число секунд и HTTP-date. Верхняя граница ограничивает ожидание.
            $seconds = ctype_digit($retryAfter) ? (float) $retryAfter : max(0, (strtotime($retryAfter) ?: 0) - time());
            $delay = max($delay, (int) min($this->maxDelayMs, $seconds * 1000));
        }

        return $delay;
    }
}
