<?php

declare(strict_types=1);

namespace VkLibrary\Model;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $avatarUrl,
    ) {
    }
}
