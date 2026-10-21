<?php

declare(strict_types=1);

namespace VkLibrary;

enum DialogApi: string
{
    case Legacy = 'messages.getDialogs';
    case Conversations = 'messages.getConversations';
}
