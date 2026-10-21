<?php

declare(strict_types=1);

namespace VkLibrary\Model;

final class Dialog
{
    public function __construct(
        public readonly int $peerId,
        public readonly PeerType $type,
        public readonly ?string $title,
        public readonly int $unreadCount,
        public readonly ?Message $lastMessage,
    ) {
    }
}
