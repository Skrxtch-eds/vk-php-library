<?php

declare(strict_types=1);

namespace VkLibrary\Retry;

interface Sleeper
{
    public function sleep(int $milliseconds): void;
}
