<?php

declare(strict_types=1);

namespace VkLibrary\Http;

use VkLibrary\Exception\NetworkException;

interface HttpClient
{
    /**
     * Реализация отправляет POST к VK по HTTPS, без redirect и без логирования параметров.
     * Ошибки транспорта должны иметь безопасный текст, без токена и тела запроса.
     *
     * @param array<string, int|string> $parameters
     * @throws NetworkException
     */
    public function post(string $method, array $parameters): HttpResponse;
}
