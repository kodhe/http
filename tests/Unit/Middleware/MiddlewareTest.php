<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Tests\Unit\Middleware;

use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;
use Kodhe\Framework\Http\Middleware\MiddlewareInterface;
use PHPUnit\Framework\TestCase;

class MiddlewareTest extends TestCase
{
    protected function createRequest(): Request
    {
        return new Request([], [], [], [], ['REQUEST_METHOD' => 'GET']);
    }

    protected function createResponse(): Response
    {
        return new Response();
    }

    public function testMiddlewareInterface(): void
    {
        $middleware = new class implements MiddlewareInterface {
            public function handle(Request $request, Response $response, callable $next, array $params = [])
            {
                return $next($request, $response);
            }
        };

        $this->assertInstanceOf(MiddlewareInterface::class, $middleware);
    }

    public function testMiddlewareExecution(): void
    {
        $middleware = new class implements MiddlewareInterface {
            public function handle(Request $request, Response $response, callable $next, array $params = [])
            {
                $request->server('X-Middleware-Executed', 'true');
                return $next($request, $response);
            }
        };

        $request = $this->createRequest();
        $response = $this->createResponse();
        
        $next = function($req, $res) {
            return $res;
        };

        $result = $middleware->handle($request, $response, $next);
        $this->assertInstanceOf(Response::class, $result);
    }

    public function testMiddlewareCanModifyResponse(): void
    {
        $middleware = new class implements MiddlewareInterface {
            public function handle(Request $request, Response $response, callable $next, array $params = [])
            {
                $response = $next($request, $response);
                $response->setHeader('X-Custom', 'value');
                return $response;
            }
        };

        $request = $this->createRequest();
        $response = $this->createResponse();
        
        $next = function($req, $res) {
            return $res;
        };

        $result = $middleware->handle($request, $response, $next);
        $this->assertEquals('value', $result->getHeader('X-Custom'));
    }

    public function testMiddlewareCanReturnEarly(): void
    {
        $middleware = new class implements MiddlewareInterface {
            public function handle(Request $request, Response $response, callable $next, array $params = [])
            {
                $response->setStatus(403);
                $response->setBody('Forbidden');
                return $response; // Don't call next
            }
        };

        $request = $this->createRequest();
        $response = $this->createResponse();
        
        $nextCalled = false;
        $next = function($req, $res) use (&$nextCalled) {
            $nextCalled = true;
            return $res;
        };

        $result = $middleware->handle($request, $response, $next);
        $this->assertFalse($nextCalled);
        $this->assertEquals(403, $result->getStatus());
    }

    public function testMultipleMiddlewareChain(): void
    {
        $middleware1 = new class implements MiddlewareInterface {
            public function handle(Request $request, Response $response, callable $next, array $params = [])
            {
                $response->setHeader('X-Middleware-1', 'executed');
                return $next($request, $response);
            }
        };

        $middleware2 = new class implements MiddlewareInterface {
            public function handle(Request $request, Response $response, callable $next, array $params = [])
            {
                $response->setHeader('X-Middleware-2', 'executed');
                return $next($request, $response);
            }
        };

        $request = $this->createRequest();
        $response = $this->createResponse();
        
        $handler = function($req, $res) {
            return $res;
        };

        // Chain middleware
        $result = $middleware1->handle($request, $response, function($req, $res) use ($middleware2, $handler) {
            return $middleware2->handle($req, $res, $handler);
        });

        $this->assertEquals('executed', $result->getHeader('X-Middleware-1'));
        $this->assertEquals('executed', $result->getHeader('X-Middleware-2'));
    }

    public function testMiddlewareWithParameters(): void
    {
        $middleware = new class implements MiddlewareInterface {
            public function handle(Request $request, Response $response, callable $next, array $params = [])
            {
                $response->setHeader('X-Param-1', $params[0] ?? 'none');
                return $next($request, $response);
            }
        };

        $request = $this->createRequest();
        $response = $this->createResponse();
        
        $next = function($req, $res) {
            return $res;
        };

        $result = $middleware->handle($request, $response, $next, ['value1']);
        $this->assertEquals('value1', $result->getHeader('X-Param-1'));
    }
}
