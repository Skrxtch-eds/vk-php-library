<?php

declare(strict_types=1);

namespace VkLibrary\Model;

enum PeerType: string
{
    case User = 'user';
    case Chat = 'chat';
    case Group = 'group';
    case Email = 'email';
}
