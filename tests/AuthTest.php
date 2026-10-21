<?php

declare(strict_types=1);

namespace VkLibrary\Tests;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use VkLibrary\Auth\AccessToken;
use VkLibrary\Auth\EnvironmentTokenProvider;
use VkLibrary\Exception\AuthenticationException;

final class AuthTest extends TestCase
{
    private const VARIABLE = 'VK_LIBRARY_UNIT_TEST_TOKEN';

    protected function tearDown(): void
    {
        putenv(self::VARIABLE);
    }

    public function testTokenCanBeReadButIsHiddenInDebugInfo(): void
    {
        $token = new AccessToken('private-token');
        self::assertSame('private-token', $token->getToken());
        ob_start();
        var_dump($token);
        $debug = ob_get_clean();
        self::assertStringNotContainsString('private-token', $debug);
        self::assertStringContainsString('[redacted]', $debug);
    }

    public function testSerializationIsForbidden(): void
    {
        $this->expectException(LogicException::class);
        serialize(new AccessToken('private-token'));
    }

    public function testWhitespaceTokenIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AccessToken("private\ntoken");
    }

    public function testEnvironmentProviderSupportsRotation(): void
    {
        $provider = new EnvironmentTokenProvider(self::VARIABLE);
        putenv(self::VARIABLE . '=first');
        self::assertSame('first', $provider->getToken());
        putenv(self::VARIABLE . '=second');
        self::assertSame('second', $provider->getToken());
    }

    public function testMissingEnvironmentTokenFails(): void
    {
        putenv(self::VARIABLE);
        $this->expectException(AuthenticationException::class);
        (new EnvironmentTokenProvider(self::VARIABLE))->getToken();
    }

    public function testInvalidEnvironmentVariableIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EnvironmentTokenProvider('a=b');
    }
}
