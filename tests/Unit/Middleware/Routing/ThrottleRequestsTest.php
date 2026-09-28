<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Tests\Unit\Middleware\Routing;

use Kodhe\Framework\Cache\Contracts\CacheInterface;
use Kodhe\Framework\Exceptions\Http\TooManyRequestsException;
use Kodhe\Framework\Http\Middleware\ApiAuth;
use Kodhe\Framework\Http\Middleware\Routing\ThrottleRequests;
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;
use Kodhe\Framework\Http\Routing\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Array-backed cache stub implementing CacheInterface (test-only).
 */
final class ArrayCacheStub implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $store = [];

    public function get(string $id)
    {
        return $this->store[$id] ?? false;
    }

    public function save(string $id, $data, int $ttl = 60, bool $raw = false): bool
    {
        $this->store[$id] = $data;

        return true;
    }

    public function delete(string $id): bool
    {
        unset($this->store[$id]);

        return true;
    }

    public function increment(string $id, int $offset = 1)
    {
        return $this->store[$id] = ((int) ($this->store[$id] ?? 0)) + $offset;
    }

    public function decrement(string $id, int $offset = 1)
    {
        return $this->store[$id] = ((int) ($this->store[$id] ?? 0)) - $offset;
    }

    public function clean(): bool
    {
        $this->store = [];

        return true;
    }

    public function cacheInfo(?string $type = null)
    {
        return count($this->store);
    }

    public function getMetadata(string $id)
    {
        return null;
    }

    public function isSupported(string $driver): bool
    {
        return true;
    }
}

/**
 * Unit tests for the ThrottleRequests middleware, including its
 * integration with ApiAuth (per-API-client rate-limit buckets).
 */
class ThrottleRequestsTest extends TestCase
{
    protected ArrayCacheStub $cache;
    protected RateLimiter $limiter;

    protected function setUp(): void
    {
        $this->cache   = new ArrayCacheStub();
        $this->limiter = new RateLimiter($this->cache);
    }

    protected function createRequest(array $server = []): Request
    {
        return new Request([], [], [], [], array_merge(
            ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.10'],
            $server
        ));
    }

    protected function passNext(): callable
    {
        return function (Request $request, Response $response) {
            return $response;
        };
    }

    // ------------------------------------------------------------------
    // Basic throttling
    // ------------------------------------------------------------------

    public function testPassesUnderLimitAndAddsRateLimitHeaders(): void
    {
        $middleware = new ThrottleRequests($this->limiter);
        $request    = $this->createRequest();

        $result = $middleware->handle($request, new Response(), $this->passNext(), [10, 1]);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('10', $this->headerValue($result, 'X-RateLimit-Limit'));
        $this->assertSame('9', $this->headerValue($result, 'X-RateLimit-Remaining'));
        $this->assertNotNull($this->headerValue($result, 'X-RateLimit-Reset'));
    }

    public function testThrowsTooManyRequestsWhenLimitExceeded(): void
    {
        $middleware = new ThrottleRequests($this->limiter);

        for ($i = 0; $i < 3; $i++) {
            $middleware->handle($this->createRequest(), new Response(), $this->passNext(), [3, 1]);
        }

        try {
            $middleware->handle($this->createRequest(), new Response(), $this->passNext(), [3, 1]);
            $this->fail('Expected TooManyRequestsException was not thrown.');
        } catch (TooManyRequestsException $e) {
            $this->assertSame(429, $e->getHttpStatusCode());
            $this->assertSame('TOO_MANY_REQUESTS', $e->getErrorCode());
            $this->assertGreaterThanOrEqual(1, $e->getRetryAfter());
            $this->assertArrayHasKey('Retry-After', $e->getHeaders());
        }
    }

    public function testDefaultParametersAreUsedWhenNoneGiven(): void
    {
        $middleware = new ThrottleRequests($this->limiter);
        $request    = $this->createRequest();

        $result = $middleware->handle($request, new Response(), $this->passNext());

        $this->assertSame('60', $this->headerValue($result, 'X-RateLimit-Limit'));
    }

    public function testNamedParameterBagIsSupported(): void
    {
        $middleware = new ThrottleRequests($this->limiter);

        $result = $middleware->handle(
            $this->createRequest(),
            new Response(),
            $this->passNext(),
            ['max_attempts' => 5, 'decay_seconds' => 30]
        );

        $this->assertSame('5', $this->headerValue($result, 'X-RateLimit-Limit'));
    }

    // ------------------------------------------------------------------
    // Client identity / bucketing
    // ------------------------------------------------------------------

    public function testDifferentIpsGetSeparateBuckets(): void
    {
        $middleware = new ThrottleRequests($this->limiter);

        $a = $this->createRequest(['REMOTE_ADDR' => '198.51.100.1']);
        $b = $this->createRequest(['REMOTE_ADDR' => '198.51.100.2']);

        $middleware->handle($a, new Response(), $this->passNext(), [2, 1]);

        // Second client must not be throttled by the first client's hit.
        $result = $middleware->handle($b, new Response(), $this->passNext(), [2, 1]);
        $this->assertSame('1', $this->headerValue($result, 'X-RateLimit-Remaining'));
    }

    public function testCustomKeySharesOneBucketAcrossClients(): void
    {
        $middleware = new ThrottleRequests($this->limiter);

        $params = ['key' => 'public-widgets', 'max_attempts' => 3];

        $middleware->handle($this->createRequest(['REMOTE_ADDR' => '198.51.100.1']), new Response(), $this->passNext(), $params);
        $result = $middleware->handle($this->createRequest(['REMOTE_ADDR' => '198.51.100.2']), new Response(), $this->passNext(), $params);

        // Same custom bucket regardless of IP: two of three slots consumed.
        $this->assertSame('1', $this->headerValue($result, 'X-RateLimit-Remaining'));
    }

    public function testApiKeyPrincipalGetsOwnBucketNotSharedIp(): void
    {
        // ApiAuth runs first in the pipeline and decorates the request.
        $auth = new ApiAuth(fn (string $c) => $c === 'client-a' ? ['id' => 'A'] : ['id' => 'B']);

        $middleware = new ThrottleRequests($this->limiter);

        $reqA = $this->createRequest(['HTTP_AUTHORIZATION' => 'Bearer client-a']);
        $reqB = $this->createRequest(['HTTP_AUTHORIZATION' => 'Bearer client-b']);

        $auth->handle($reqA, new Response(), $this->passNext());
        $auth->handle($reqB, new Response(), $this->passNext());

        // Exhaust A's bucket (2 of 2 slots used) without touching B's.
        $middleware->handle($reqA, new Response(), $this->passNext(), [2, 1]);
        $middleware->handle($reqA, new Response(), $this->passNext(), [2, 1]);

        // B shares the same IP but has its own authenticated bucket.
        $result = $middleware->handle($reqB, new Response(), $this->passNext(), [2, 1]);
        $this->assertSame('1', $this->headerValue($result, 'X-RateLimit-Remaining'));

        // A is now over the limit -> 429.
        $this->expectException(TooManyRequestsException::class);
        $middleware->handle($reqA, new Response(), $this->passNext(), [2, 1]);
    }

    public function testUnauthenticatedRequestFallsBackToIpBucket(): void
    {
        $middleware = new ThrottleRequests($this->limiter);

        $middleware->handle($this->createRequest(), new Response(), $this->passNext(), [1, 1]);

        $this->expectException(TooManyRequestsException::class);
        $middleware->handle($this->createRequest(), new Response(), $this->passNext(), [1, 1]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Case-insensitive header lookup on the Response header bag.
     */
    protected function headerValue(Response $response, string $name): ?string
    {
        foreach ($response->getHeaders() as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return (string) $value;
            }
        }

        return null;
    }
}
