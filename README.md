# VK PHP Library

PHP-библиотека для отправки сообщений, чтения диалогов и получения профиля пользователя VK. Требует PHP 8.1+ и расширения cURL. HTTP-клиент и источник токена передаются в конструктор.

## Установка

Пакет пока не опубликован в Packagist. Подключите репозиторий в своём приложении через Composer:

```bash
composer config repositories.vk-library vcs https://github.com/Skrxtch-eds/vk-php-library
composer require danila-evseev/vk-php-library:dev-main
```

Для работы с исходниками:

```bash
git clone https://github.com/Skrxtch-eds/vk-php-library
cd vk-php-library
composer install
composer check
```

## Токен

Для сообщений сообщества создайте ключ в управлении сообществом: раздел работы с API, ключи доступа, право на сообщения. Включите сообщения сообщества. Пользователь должен разрешить сообществу писать ему; для беседы сообщество нужно добавить в неё с нужными правами.

Для пользовательского токена используйте разрешённый для вашего приложения способ авторизации VK и проверьте доступ к Messages API. Сервисный ключ приложения не заменяет токен с правом на сообщения. Библиотека принимает готовый токен; OAuth и обновление токена остаются в приложении. [Документация VK о токенах](https://dev.vk.com/ru/api/access-token/getting-started).

Передайте токен через переменную окружения `VK_ACCESS_TOKEN` из настроек запуска или хранилища секретов. Файл `.env.example` показывает имя переменной; библиотека сама `.env` не читает. Не коммитьте рабочие ключи.

```php
use VkLibrary\Auth\EnvironmentTokenProvider;
use VkLibrary\Http\CurlHttpClient;
use VkLibrary\VkClient;

require 'vendor/autoload.php';

$vk = new VkClient(new CurlHttpClient(), new EnvironmentTokenProvider());
```

Для своего хранилища реализуйте `TokenProvider::getToken(): string`. Токен читается один раз на публичный вызов и сохраняется при всех его повторах; следующий вызов прочитает новое значение. Если токен уже получен приложением, его можно передать через `new AccessToken($token)` из пространства имён `VkLibrary\Auth`.

## Сообщения

```php
// Возвращает ID сообщения, подтверждённый VK.
$messageId = $vk->sendMessage(12345, 'Привет!', ['random_id' => 738291]);

// В беседах peer_id равен 2000000000 + chat_id.
$chatMessageId = $vk->sendMessage(2000000007, 'Встречаемся в 15:00');
```

Первый аргумент называется `$userId` по заданию, но принимает `peer_id`: ID пользователя, отрицательный ID сообщества или ID беседы. Текст должен быть непустым UTF-8, до 4096 символов.

Разрешены три опции: `random_id` (int от 1 до 2147483647), `disable_mentions` и `dont_parse_links` (bool). Неизвестные опции вызывают `InvalidArgumentException` до HTTP-запроса.

Если `random_id` не передан, библиотека генерирует его один раз и сохраняет при внутренних повторах. Для повторов после перезапуска приложения сохраняйте свой ID операции и передавайте прежний `random_id`. Новый вызов без этой опции создаёт новый ID. Успешный ответ VK подтверждает принятие сообщения, но не его прочтение.

## Диалоги и пользователь

```php
$page = $vk->getDialogs(offset: 0, count: 10);

foreach ($page->items as $dialog) {
    echo $dialog->peerId . ': ' . ($dialog->lastMessage?->text ?? '') . PHP_EOL;
}
echo 'Всего диалогов: ' . $page->totalCount;

$user = $vk->getUser(12345);
echo $user->firstName . ' ' . $user->lastName;
echo $user->avatarUrl ?? 'Аватар отсутствует';
```

`getDialogs()` возвращает `DialogPage`, содержащую список объектов `Dialog`. `offset` начинается с нуля; `count` от 1 до 200. `getUser()` запрашивает `photo_100` и возвращает `User`.

В старом формате `Message::fromId` может быть `null`: например, если исходящее сообщение содержит только ID получателя. Для входящего сообщения с `out=0` автор берётся из `user_id`. Явный `from_id` имеет приоритет. Без этих данных библиотека не угадывает автора.

По умолчанию вызывается `messages.getDialogs` с версией API `5.80`, как требует задание. Это старый метод: текущая [официальная схема VK](https://github.com/VKCOM/vk-api-schema/blob/master/messages/methods.json) описывает `messages.getConversations`. VK может отклонить старую версию или метод. Библиотека не переключает методы автоматически.

Для актуального формата выберите режим явно. Публичный метод и DTO остаются теми же:

```php
use VkLibrary\DialogApi;

$vk = new VkClient(
    new CurlHttpClient(),
    new EnvironmentTokenProvider(),
    dialogApi: DialogApi::Conversations, // API 5.199
);
```

При необходимости версию можно задать аргументом `apiVersion: '5.199'`. Оба формата проверяются unit-тестами. Реальные запросы требуют подходящего токена и доступного метода на стороне VK.

## Ошибки и повторы

Все ошибки библиотеки наследуют `VkException`. Неверные входные параметры вызывают стандартный `InvalidArgumentException`.

| Исключение | Когда возникает |
| --- | --- |
| `AuthenticationException` | Нет токена; VK 5, 27, 28; HTTP 401 |
| `AccessDeniedException` | VK 7, 15, 20, 188, 900, 901, 902, 917, 925; HTTP 403 |
| `RateLimitException` | Лимит запросов после последней попытки |
| `ApiException` | Остальные ошибки VK |
| `NetworkException` | Сетевая ошибка после последней попытки |
| `HttpException` | Остальные неуспешные HTTP-статусы |
| `InvalidResponseException` | Неверный JSON, форма данных или слишком большой ответ |

Код VK или HTTP доступен через `getCode()`. Код 188 обработан по условию задания; в текущей схеме VK его нет. Публичные методы `VkClient` пересоздают исключения с безопасным текстом и новой трассировкой, без `previous`. Защита охватывает HTTP-запрос и разбор результата: внутренние аргументы с токеном и текст ошибки сервера в наружное исключение не переносятся, в том числе при `zend.exception_ignore_args=0` на PHP 8.1.

По умолчанию выполняется до трёх попыток. Сетевые ошибки, HTTP 420/429/500/502/503/504 и коды VK 1/6/10/29/420/429 повторяются с паузами 250 и 500 мс. Заголовок `Retry-After` увеличивает паузу до указанного времени, но не больше 30 секунд. Ошибки доступа и CAPTCHA не повторяются.

```php
use VkLibrary\Exception\AccessDeniedException;

try {
    $vk->sendMessage(12345, 'Привет!');
} catch (AccessDeniedException $error) {
    // Проверьте права токена и разрешение получателя; повтор здесь не поможет.
    echo 'Нет доступа, код: ' . $error->getCode();
}
```

`retryPolicy: new RetryPolicy(maxAttempts: 1)` из `VkLibrary\Retry` отключает повторы. Для очереди можно внедрить свой `Sleeper`. Повторы синхронные, они блокируют текущий PHP-процесс.

cURL отправляет токен в теле POST по HTTPS, проверяет сертификат и запрещает редиректы. По умолчанию таймаут соединения 3 секунды, всего запроса 10 секунд, ответ ограничен 2 МиБ. Эти ограничения задаются в конструкторе `CurlHttpClient`. Собственная реализация `HttpClient` отвечает за соблюдение того же контракта. Объект `AccessToken` скрывает значение в `var_dump()` и запрещает PHP-сериализацию; произвольные дампы памяти и журналы приложения библиотека не контролирует.

## Публичный API

| Класс | Методы |
| --- | --- |
| `VkClient` | `__construct(HttpClient $http, TokenProvider $token, DialogApi $dialogApi = Legacy, ?string $apiVersion = null, RetryPolicy $retryPolicy = new RetryPolicy(), Sleeper $sleeper = new NativeSleeper())` |
| `VkClient` | `sendMessage(int $userId, string $message, array $options = []): int` |
| `VkClient` | `getDialogs(int $offset = 0, int $count = 10): DialogPage` |
| `VkClient` | `getUser(int $userId): User` |
| `TokenProvider`, `AccessToken`, `EnvironmentTokenProvider` | `getToken(): string` |
| `AccessToken` | `__construct(string $value)`, `__debugInfo(): array`, `__serialize(): array` (всегда бросает исключение) |
| `EnvironmentTokenProvider` | `__construct(string $variable = 'VK_ACCESS_TOKEN')` |
| `HttpClient`, `CurlHttpClient` | `post(string $method, array $parameters): HttpResponse` |
| `CurlHttpClient` | `__construct(int $connectTimeoutMs = 3000, int $timeoutMs = 10000, int $maxResponseBytes = 2097152)` |
| `HttpResponse` | `__construct(int $statusCode, string $body, array $headers = [])`, `header(string $name): ?string` |
| `RetryPolicy` | `__construct(int $maxAttempts = 3, int $baseDelayMs = 250, int $maxDelayMs = 30000)`, `delay(int $failedAttempt, ?string $retryAfter = null): int` |
| `Sleeper`, `NativeSleeper` | `sleep(int $milliseconds): void` |

DTO содержат readonly-свойства, доступные через конструктор:

| Объект | Поля конструктора в порядке аргументов |
| --- | --- |
| `User` | `int $id`, `string $firstName`, `string $lastName`, `?string $avatarUrl` |
| `DialogPage` | `int $totalCount`, `list<Dialog> $items` |
| `Dialog` | `int $peerId`, `PeerType $type`, `?string $title`, `int $unreadCount`, `?Message $lastMessage` |
| `Message` | `int $id`, `?int $fromId` (null, если автор неизвестен), `string $text`, `int $sentAt` (Unix time) |

`DialogApi` содержит `Legacy` и `Conversations`. `PeerType` содержит `User`, `Chat`, `Group`, `Email`; enum предоставляет стандартные `cases()`, `from()` и `tryFrom()`. Исключения используют конструктор и методы стандартного `RuntimeException`. Классы в `Internal` не входят в публичный API.

## Проверки

```bash
composer check       # PHPUnit, PSR-12, PHPStan level 8
composer coverage    # Нужен Xdebug в режиме coverage или PCOV
composer audit
```

Тесты подменяют HTTP-клиент и ожидание. Они не отправляют сообщения и не требуют токена. HTML-отчёт покрытия находится в `build/coverage/`, Clover в `build/coverage.xml`. CI повторяет проверки на PHP 8.1–8.5. Сетевой обмен cURL с VK unit-тесты не проверяют.

Код разделён на `Auth`, `Http`, `Retry`, `Model` и внутренний слой разбора ответов. Комментарии в исходниках объясняют повторы, обращение с токеном и отличия старого формата.

Лицензия MIT, текст в [LICENSE](LICENSE).
