<?php

declare(strict_types=1);

namespace VkLibrary\Model;

final class Message
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $fromId,
        public readonly string $text,
        public readonly int $sentAt,
    ) {
    }
}
