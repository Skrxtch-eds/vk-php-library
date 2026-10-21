<?php

declare(strict_types=1);

namespace VkLibrary\Exception;

class ApiException extends VkException
{
    // getCode() хранит исходный код VK; текст ответа сервера намеренно не сохраняем.
}
