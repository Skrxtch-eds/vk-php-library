<?php

declare(strict_types=1);

namespace VkLibrary\Model;

final class DialogPage
{
    /** @param list<Dialog> $items */
    public function __construct(
        public readonly int $totalCount,
        public readonly array $items,
    ) {
    }
}
