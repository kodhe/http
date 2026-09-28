<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Tests\Unit\Kernel;

use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;
use Kodhe\Framework\Http\Kernel\Pipeline;
use Kodhe\Framework\Http\Middleware\MiddlewareInterface;
use PHPUnit\Framework\TestCase;

class PipelineTest extends TestCase
{
    protected function createRequest(): Request
    {
        return new Request([], [], [], [], ['REQUEST_METHOD' => 'GET']);
    }

    protected function createResponse(): Response
    {
        return new Response();
    }

    public function testPipelineConstructor(): void
    {
        $pipeline = new Pipeline();
        $this->assertInstanceOf(Pipeline::class, $pipeline);
    }

    public function testPipelineWithCustomRequestResponse(): void
    {
        $request = $this->createRequest();
        $response = $this->createResponse();
        $pipeline = new Pipeline($request, $response);
        
        $this->assertInstanceOf(Pipeline::class, $pipeline);
    }

    public function testPipeMiddleware(): void
    {
        $pipeline = new Pipeline();
        $middleware = new class implements MiddlewareInterface {
            public function handle(Request $request, Response $response, callable $next, array $params = [])
            {
                return $next($request, $response);
            }
        };

        $result = $pipeline->pipe($middleware);
        $this->assertInstanceOf(Pipeline::class, $result);
    }

    public function testPipeManyMiddlewares(): void
    {
        $pipeline = new Pipeline();
        
        $middleware1 = new class implements MiddlewareInterface {
            public function handle(Request $request, Response $response, callable $next, array $params = [])
            {
                return $next($request, $response);
            }
        };

        $middleware2 = new class implements MiddlewareInterface {
            public function handle(Request $request, Response $response, callable $next, array $params = [])
            {
                return $next($request, $response);
            }
        };

        $result = $pipeline->pipeMany([$middleware1, $middleware2]);
        $this->assertInstanceOf(Pipeline::class, $result);
    }

    public function testSetHandler(): void
    {
        $pipeline = new Pipeline();
        $handler = function($request, $response) {
            $response->setBody('Handled');
            return $response;
        };

        $result = $pipeline->setHandler($handler);
        $this->assertInstanceOf(Pipeline::class, $result);
    }

    public function testPipelineExecution(): void
    {
        $request = $this->createRequest();
        $response = $this->createResponse();
        
        $pipeline = new Pipeline($request, $response);
        
        $middleware = new class implements MiddlewareInterface {
            public function handle(Request $request, Response $response, callable $next, array $params = [])
            {
                $response->setHeader('X-Middleware', 'executed');
                return $next($request, $response);
            }
        };

        $pipeline->pipe($middleware);
        $pipeline->setHandler(function($req, $res) {
            $res->setBody('Final response');
            return $res;
        });

        $result = $pipeline->execute();
        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals('Final response', $result->getBody());
        $this->assertEquals('executed', $result->getHeader('X-Middleware'));
    }

    public function testPipelineWithoutMiddleware(): void
    {
        $request = $this->createRequest();
        $response = $this->createResponse();
        
        $pipeline = new Pipeline($request, $response);
        $pipeline->setHandler(function($req, $res) {
            $res->setBody('Direct response');
            return $res;
        });

        $result = $pipeline->execute();
        $this->assertEquals('Direct response', $result->getBody());
    }

    public function testExceptionHandlingEnabled(): void
    {
        $pipeline = new Pipeline();
        $result = $pipeline->enableExceptionHandling();
        
        $this->assertInstanceOf(Pipeline::class, $result);
    }

    public function testExceptionHandlingDisabled(): void
    {
        $pipeline = new Pipeline();
        $result = $pipeline->disableExceptionHandling();
        
        $this->assertInstanceOf(Pipeline::class, $result);
    }

    public function testMiddlewareStopsPipeline(): void
    {
        $request = $this->createRequest();
        $response = $this->createResponse();
        
        $pipeline = new Pipeline($request, $response);
        
        // Middleware that returns early
        $earlyMiddleware = new class implements MiddlewareInterface {
            public function handle(Request $request, Response $response, callable $next, array $params = [])
            {
                $response->setStatus(403);
                $response->setBody('Blocked');
                return $response;
            }
        };

        $pipeline->pipe($earlyMiddleware);
        $pipeline->setHandler(function($req, $res) {
            $res->setBody('Should not reach here');
            return $res;
        });

        $result = $pipeline->execute();
        $this->assertEquals(403, $result->getStatus());
        $this->assertEquals('Blocked', $result->getBody());
    }
}
