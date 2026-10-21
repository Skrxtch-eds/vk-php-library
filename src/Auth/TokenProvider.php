<?php

declare(strict_types=1);

namespace VkLibrary\Auth;

interface TokenProvider
{
    public function getToken(): string;
}
