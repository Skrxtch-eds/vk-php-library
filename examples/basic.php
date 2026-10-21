<?php

declare(strict_types=1);

use VkLibrary\Auth\EnvironmentTokenProvider;
use VkLibrary\DialogApi;
use VkLibrary\Http\CurlHttpClient;
use VkLibrary\VkClient;

require dirname(__DIR__) . '/vendor/autoload.php';

// Пример только читает данные. Подставьте ID пользователя в первый аргумент CLI.
$userId = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($userId === false) {
    fwrite(STDERR, "Usage: php examples/basic.php USER_ID\n");
    exit(1);
}

$vk = new VkClient(new CurlHttpClient(), new EnvironmentTokenProvider());
$user = $vk->getUser($userId);
printf("%s %s\n", $user->firstName, $user->lastName);

// Для современного метода создайте клиента с именованным аргументом:
// $vk = new VkClient(new CurlHttpClient(), new EnvironmentTokenProvider(), DialogApi::Conversations);
$page = $vk->getDialogs();
foreach ($page->items as $dialog) {
    printf("%d: %s\n", $dialog->peerId, $dialog->lastMessage?->text ?? '');
}

// Отправку включите явно. Для повтора одной операции храните random_id в своей БД.
// $messageId = $vk->sendMessage($userId, 'Привет!', ['random_id' => 123456]);
