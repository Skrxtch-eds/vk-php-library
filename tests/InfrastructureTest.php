<?php

declare(strict_types=1);

namespace VkLibrary\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VkLibrary\Http\CurlHttpClient;
use VkLibrary\Http\HttpResponse;
use VkLibrary\Retry\NativeSleeper;
use VkLibrary\Retry\RetryPolicy;

final class InfrastructureTest extends TestCase
{
    public function testRetryDelayGrowsAndRespectsLimit(): void
    {
        $retry = new RetryPolicy(5, 100, 250);
        self::assertSame(100, $retry->delay(1));
        self::assertSame(200, $retry->delay(2));
        self::assertSame(250, $retry->delay(3));
        self::assertSame(250, $retry->delay(4, '999999999999999999999999'));
        self::assertSame(100, $retry->delay(1, 'nonsense'));
    }

    public function testRetryAfterSupportsDateAndSeconds(): void
    {
        $retry = new RetryPolicy();
        self::assertSame(2000, $retry->delay(1, '2'));
        $delay = $retry->delay(1, gmdate('D, d M Y H:i:s \G\M\T', time() + 5));
        self::assertGreaterThanOrEqual(4000, $delay);
        self::assertLessThanOrEqual(5000, $delay);
    }

    #[DataProvider('badPolicies')]
    public function testInvalidRetryPolicyIsRejected(int $attempts, int $base, int $max): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RetryPolicy($attempts, $base, $max);
    }

    public static function badPolicies(): iterable
    {
        yield [0, 1, 2];
        yield [11, 1, 2];
        yield [1, -1, 2];
        yield [1, 2, 1];
        yield [1, 2, 60001];
    }

    public function testInvalidAttemptIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RetryPolicy())->delay(0);
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $response = new HttpResponse(429, '', ['Retry-AFTER' => '2']);
        self::assertSame('2', $response->header('retry-after'));
        self::assertNull($response->header('missing'));
    }

    public function testInvalidHttpStatusIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HttpResponse(0, '');
    }

    public function testCurlRejectsMethodUrlBeforeNetworkCall(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CurlHttpClient())->post('https://evil.example', []);
    }

    public function testCurlRejectsInvalidTimeouts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CurlHttpClient(1000, 100);
    }

    public function testNativeSleeperAcceptsZero(): void
    {
        (new NativeSleeper())->sleep(0);
        self::assertTrue(true);
    }

    public function testNativeSleeperRejectsNegativeDelay(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new NativeSleeper())->sleep(-1);
    }
}
