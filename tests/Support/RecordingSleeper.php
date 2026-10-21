<?php

declare(strict_types=1);

namespace VkLibrary\Tests\Support;

use VkLibrary\Retry\Sleeper;

final class RecordingSleeper implements Sleeper
{
    /** @var list<int> */
    public array $delays = [];

    public function sleep(int $milliseconds): void
    {
        $this->delays[] = $milliseconds;
    }
}
