<?php

declare(strict_types=1);

namespace VkLibrary\Retry;

use InvalidArgumentException;

final class NativeSleeper implements Sleeper
{
    public function sleep(int $milliseconds): void
    {
        if ($milliseconds < 0 || $milliseconds > 60000) {
            throw new InvalidArgumentException('Sleep must be between 0 and 60000 ms.');
        }
        usleep($milliseconds * 1000);
    }
}
