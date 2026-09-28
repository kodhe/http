<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Tests\Unit\Middleware;

use Kodhe\Framework\Http\Middleware\MiddlewareGroup;
use Kodhe\Framework\Http\Middleware\MiddlewareInterface;
use Kodhe\Framework\Http\Middleware\Routing\ThrottleRequests;
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;
use Kodhe\Framework\Http\Routing\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the "Too few arguments to function ...{closure}(),
 * 2 passed ... and exactly 3 expected" fatal error raised when a middleware
 * inside a MiddlewareGroup (e.g. Routing\ThrottleRequests) invokes the next
 * handler with only ($request, $response) instead of the full
 * ($request, $response, $params) triple.
 */
class MiddlewareGroupTest extends TestCase
{
    protected function makeRequest(): Request
    {
        return new Request([], [], [], [], ['REQUEST_METHOD' => 'GET', 'SERVER_NAME' => 'localhost']);
    }

    /**
     * A middleware that calls $next with only 2 arguments — exactly what
     * ThrottleRequests does on its inner pipeline call.
     */
    protected function twoArgMiddleware(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function handle(Request $request, Response $response, callable $next, array $params = [])
            {
                return $next($request, $response);
            }
        };
    }

    /**
     * A well-behaved middleware that forwards all three arguments.
     */
    protected function threeArgMiddleware(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function handle(Request $request, Response $response, callable $next, array $params = [])
            {
                return $next($request, $response, $params);
            }
        };
    }

    public function testGroupToleratesNextCalledWithTwoArguments()
    {
        $group = new MiddlewareGroup([$this->twoArgMiddleware(), $this->twoArgMiddleware()]);

        $request = $this->makeRequest();
        $response = new Response();

        $handlerCalled = false;
        $result = $group->handle(
            $request,
            $response,
            function ($req, $res, $params = []) use (&$handlerCalled, $response) {
                $handlerCalled = true;
                $this->assertInstanceOf(Request::class, $req);
                $this->assertInstanceOf(Response::class, $res);
                $this->assertIsArray($params);
                return $response;
            },
            []
        );

        $this->assertTrue($handlerCalled, 'Final handler must be reached even when inner middlewares pass only 2 args');
        $this->assertSame($response, $result);
    }

    public function testGroupForwardsParamsToWellBehavedMiddlewares()
    {
        $seen = null;
        $recorder = new class($seen) implements MiddlewareInterface {
            public $captured = null;

            public function handle(Request $request, Response $response, callable $next, array $params = [])
            {
                $this->captured = $params;
                return $next($request, $response, $params);
            }
        };

        $group = new MiddlewareGroup([$recorder]);

        $group->handle(
            $this->makeRequest(),
            new Response(),
            function ($req, $res, $params = []) {
                return $res;
            },
            ['max' => 10]
        );

        $this->assertSame(['max' => 10], $recorder->captured);
    }

    public function testMixedArityPipelineCompletes()
    {
        $group = new MiddlewareGroup([
            $this->threeArgMiddleware(),
            $this->twoArgMiddleware(),
            $this->threeArgMiddleware(),
        ]);

        $response = new Response();
        $result = $group->handle(
            $this->makeRequest(),
            $response,
            function ($req, $res, $params = []) use ($response) {
                return $response;
            },
            []
        );

        $this->assertSame($response, $result);
    }

    /**
     * End-to-end reproduction of the reported bug: a real ThrottleRequests
     * instance (which calls $next($request, $response) with only 2 args on
     * its inner pipeline call) inside a MiddlewareGroup — e.g. the "api"
     * middleware group resolved from config — must not fatal with
     * "Too few arguments to function ...{closure}(), 2 passed ... and
     * exactly 3 expected".
     */
    public function testThrottleRequestsInsideGroupDoesNotFatal()
    {
        $cache = new class {
            /** @var array<string, mixed> */
            private array $data = [];

            public function get(string $key, $default = null)
            {
                return $this->data[$key] ?? $default;
            }

            public function set(string $key, $value, $ttl = null): bool
            {
                $this->data[$key] = $value;
                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->data[$key]);
                return true;
            }
        };

        $throttle = new ThrottleRequests(new RateLimiter($cache));

        // Group with two middlewares, exactly like the failing request
        // (middleware_count = 2 in the error payload).
        $group = new MiddlewareGroup([$throttle, $this->twoArgMiddleware()]);

        $response = new Response();
        $result = $group->handle(
            $this->makeRequest(),
            $response,
            function ($req, $res, $params = []) use ($response) {
                return $response;
            },
            [60, 1]
        );

        $this->assertSame($response, $result);
    }
}
