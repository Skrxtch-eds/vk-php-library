<?php

declare(strict_types=1);

namespace VkLibrary;

use InvalidArgumentException;
use Throwable;
use VkLibrary\Auth\TokenProvider;
use VkLibrary\Exception\VkException;
use VkLibrary\Http\HttpClient;
use VkLibrary\Internal\ApiTransport;
use VkLibrary\Internal\ResponseMapper;
use VkLibrary\Internal\ResponseReader;
use VkLibrary\Internal\SafeException;
use VkLibrary\Model\DialogPage;
use VkLibrary\Model\User;
use VkLibrary\Retry\NativeSleeper;
use VkLibrary\Retry\RetryPolicy;
use VkLibrary\Retry\Sleeper;

final class VkClient
{
    private readonly ApiTransport $transport;

    public function __construct(
        HttpClient $http,
        TokenProvider $token,
        private readonly DialogApi $dialogApi = DialogApi::Legacy,
        ?string $apiVersion = null,
        RetryPolicy $retryPolicy = new RetryPolicy(),
        Sleeper $sleeper = new NativeSleeper(),
    ) {
        // getDialogs относится к старой ветке VK API; современный режим выбирается явно.
        $version = $apiVersion ?? ($dialogApi === DialogApi::Legacy ? '5.80' : '5.199');
        if (preg_match('/^5\.[0-9]{1,3}$/D', $version) !== 1) {
            throw new InvalidArgumentException('Expected VK API version in the form 5.xxx.');
        }
        $this->transport = new ApiTransport($http, $token, $version, $retryPolicy, $sleeper);
    }

    /**
     * $userId принимает peer_id: ID пользователя, отрицательный ID группы или 2000000000 + chat_id.
     *
     * @param array<string, mixed> $options random_id, disable_mentions, dont_parse_links
     */
    public function sendMessage(int $userId, string $message, array $options = []): int
    {
        if ($userId === 0) {
            throw new InvalidArgumentException('Recipient must not be zero.');
        }
        if ($message === '' || preg_match('//u', $message) !== 1) {
            throw new InvalidArgumentException('Message must be non-empty UTF-8 text.');
        }
        // VK ограничивает текст 4096 символами; подсчёт не требует расширения mbstring.
        $length = preg_match_all('/./us', $message);
        if ($length === false || $length > 4096) {
            throw new InvalidArgumentException('Message cannot exceed 4096 characters.');
        }
        $randomId = null;
        $flags = [];
        foreach ($options as $key => $value) {
            if (!in_array($key, ['random_id', 'disable_mentions', 'dont_parse_links'], true)) {
                throw new InvalidArgumentException('Unsupported send option.');
            }
            if ($key === 'random_id') {
                if (!is_int($value) || $value < 1 || $value > 2147483647) {
                    throw new InvalidArgumentException('random_id must be an integer between 1 and 2147483647.');
                }
                $randomId = $value;
            } else {
                if (!is_bool($value)) {
                    throw new InvalidArgumentException('Message flags must be boolean.');
                }
                $flags[$key] = (int) $value;
            }
        }

        // Генерируем до retry: при потере ответа VK повтор увидит тот же random_id.
        $parameters = [
            'peer_id' => $userId,
            'message' => $message,
            'random_id' => $randomId ?? random_int(1, 2147483647),
        ];

        try {
            return ResponseReader::integer($this->transport->request('messages.send', $parameters + $flags), 1);
        } catch (Throwable $error) {
            // Граница включает HTTP и разбор результата: оба слоя могут получить секретные данные.
            throw SafeException::create(
                $error instanceof VkException ? $error::class : VkException::class,
                $error instanceof VkException ? $error->getCode() : 0,
            );
        }
    }

    public function getDialogs(int $offset = 0, int $count = 10): DialogPage
    {
        if ($offset < 0 || $count < 1 || $count > 200) {
            throw new InvalidArgumentException('Offset must be non-negative; count must be between 1 and 200.');
        }

        try {
            return ResponseMapper::dialogs(
                $this->transport->request($this->dialogApi->value, ['offset' => $offset, 'count' => $count]),
                $this->dialogApi,
            );
        } catch (Throwable $error) {
            throw SafeException::create(
                $error instanceof VkException ? $error::class : VkException::class,
                $error instanceof VkException ? $error->getCode() : 0,
            );
        }
    }

    public function getUser(int $userId): User
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('User ID must be positive.');
        }

        try {
            return ResponseMapper::user(
                $this->transport->request('users.get', ['user_ids' => $userId, 'fields' => 'photo_100']),
                $userId,
            );
        } catch (Throwable $error) {
            throw SafeException::create(
                $error instanceof VkException ? $error::class : VkException::class,
                $error instanceof VkException ? $error->getCode() : 0,
            );
        }
    }
}
