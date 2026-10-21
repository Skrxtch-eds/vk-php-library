<?php

declare(strict_types=1);

namespace VkLibrary\Internal;

use VkLibrary\DialogApi;
use VkLibrary\Exception\InvalidResponseException;
use VkLibrary\Model\Dialog;
use VkLibrary\Model\DialogPage;
use VkLibrary\Model\Message;
use VkLibrary\Model\PeerType;
use VkLibrary\Model\User;

/** @internal Отличия версий VK остаются здесь, а не попадают в пользовательский код. */
final class ResponseMapper
{
    public static function user(mixed $response, int $requestedId): User
    {
        $users = ResponseReader::list($response);
        if (count($users) !== 1) {
            throw new InvalidResponseException('VK did not return exactly one user.');
        }
        $user = ResponseReader::object($users[0]);
        $id = ResponseReader::integer($user['id'] ?? null, 1);
        if ($id !== $requestedId) {
            throw new InvalidResponseException('VK returned a different user.');
        }

        return new User(
            $id,
            ResponseReader::string($user['first_name'] ?? null),
            ResponseReader::string($user['last_name'] ?? null),
            isset($user['photo_100']) ? ResponseReader::string($user['photo_100']) : null,
        );
    }

    public static function dialogs(mixed $response, DialogApi $api): DialogPage
    {
        $page = ResponseReader::object($response);
        $count = ResponseReader::integer($page['count'] ?? null, 0);
        $items = [];
        foreach (ResponseReader::list($page['items'] ?? null) as $item) {
            $row = ResponseReader::object($item);
            $items[] = $api === DialogApi::Legacy ? self::legacyDialog($row) : self::conversation($row);
        }

        return new DialogPage($count, $items);
    }

    /** @param array<array-key, mixed> $row */
    private static function legacyDialog(array $row): Dialog
    {
        $message = ResponseReader::object($row['message'] ?? null);
        $userId = ResponseReader::integer($message['user_id'] ?? null);
        if (isset($message['chat_id'])) {
            $chatId = ResponseReader::integer($message['chat_id'], 1);
            // Проверяем до сложения: при переполнении PHP превращает int в float.
            if ($chatId > PHP_INT_MAX - 2000000000) {
                throw new InvalidResponseException('Legacy chat ID exceeds the supported integer range.');
            }
            $peerId = 2000000000 + $chatId;
            $type = PeerType::Chat;
        } else {
            $peerId = $userId;
            $type = $peerId < 0 ? PeerType::Group : PeerType::User;
        }

        $out = array_key_exists('out', $message) ? ResponseReader::integer($message['out'], 0) : null;
        if ($out !== null && $out > 1) {
            throw new InvalidResponseException('Invalid legacy message direction.');
        }
        // user_id у исходящего личного сообщения указывает на получателя.
        // Если сервер не сообщил автора, возвращаем null, не угадывая ID владельца токена.
        $fromId = array_key_exists('from_id', $message)
            ? ResponseReader::integer($message['from_id'])
            : ($out === 0 ? $userId : null);

        return new Dialog(
            $peerId,
            $type,
            isset($message['title']) ? ResponseReader::string($message['title']) : null,
            ResponseReader::integer($row['unread'] ?? 0, 0),
            new Message(
                ResponseReader::integer($message['id'] ?? null, 0),
                $fromId,
                ResponseReader::string($message['body'] ?? $message['text'] ?? null),
                ResponseReader::integer($message['date'] ?? null, 0),
            ),
        );
    }

    /** @param array<array-key, mixed> $row */
    private static function conversation(array $row): Dialog
    {
        $conversation = ResponseReader::object($row['conversation'] ?? null);
        $peer = ResponseReader::object($conversation['peer'] ?? null);
        $type = PeerType::tryFrom(ResponseReader::string($peer['type'] ?? null));
        if ($type === null) {
            throw new InvalidResponseException('Unknown VK peer type.');
        }
        $settings = ResponseReader::object($conversation['chat_settings'] ?? []);
        $lastMessage = null;
        if (isset($row['last_message'])) {
            $message = ResponseReader::object($row['last_message']);
            $lastMessage = new Message(
                ResponseReader::integer($message['id'] ?? null, 0),
                ResponseReader::integer($message['from_id'] ?? null),
                ResponseReader::string($message['text'] ?? null),
                ResponseReader::integer($message['date'] ?? null, 0),
            );
        }

        return new Dialog(
            ResponseReader::integer($peer['id'] ?? null),
            $type,
            isset($settings['title']) ? ResponseReader::string($settings['title']) : null,
            ResponseReader::integer($conversation['unread_count'] ?? 0, 0),
            $lastMessage,
        );
    }
}
