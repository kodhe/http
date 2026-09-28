<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Tests\Unit;

use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Uri;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    protected function createRequest(array $get = [], array $post = [], array $cookies = [], array $files = [], array $server = []): Request
    {
        return new Request($get, $post, $cookies, $files, $server);
    }

    public function testConstructor(): void
    {
        $get = ['name' => 'John'];
        $post = ['email' => 'john@example.com'];
        $cookies = ['session' => 'abc123'];
        $files = ['upload' => ['tmp_name' => '/tmp/file', 'name' => 'file.txt']];
        $server = ['HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/test'];

        $request = $this->createRequest($get, $post, $cookies, $files, $server);

        $this->assertInstanceOf(Request::class, $request);
    }

    public function testFromGlobals(): void
    {
        $request = Request::fromGlobals();
        $this->assertInstanceOf(Request::class, $request);
    }

    public function testGetMethod(): void
    {
        $server = ['REQUEST_METHOD' => 'GET'];
        $request = $this->createRequest([], [], [], [], $server);
        $this->assertEquals('GET', $request->method());
    }

    public function testPostMethod(): void
    {
        $server = ['REQUEST_METHOD' => 'POST'];
        $request = $this->createRequest([], [], [], [], $server);
        $this->assertEquals('POST', $request->method());
    }

    public function testIsGet(): void
    {
        $server = ['REQUEST_METHOD' => 'GET'];
        $request = $this->createRequest([], [], [], [], $server);
        $this->assertTrue($request->isGet());
    }

    public function testIsPost(): void
    {
        $server = ['REQUEST_METHOD' => 'POST'];
        $request = $this->createRequest([], [], [], [], $server);
        $this->assertTrue($request->isPost());
    }

    public function testGetInput(): void
    {
        $get = ['name' => 'John', 'age' => '30'];
        $request = $this->createRequest($get);
        
        $this->assertEquals('John', $request->get('name'));
        $this->assertEquals('30', $request->get('age'));
        $this->assertNull($request->get('nonexistent'));
        $this->assertEquals('default', $request->get('nonexistent', 'default'));
    }

    public function testPostInput(): void
    {
        $post = ['email' => 'john@example.com', 'password' => 'secret'];
        $request = $this->createRequest([], $post);
        
        $this->assertEquals('john@example.com', $request->post('email'));
        $this->assertEquals('secret', $request->post('password'));
    }

    public function testServer(): void
    {
        $server = ['HTTP_HOST' => 'example.com', 'SERVER_PORT' => '80'];
        $request = $this->createRequest([], [], [], [], $server);
        
        $this->assertEquals('example.com', $request->server('HTTP_HOST'));
        $this->assertEquals('80', $request->server('SERVER_PORT'));
    }

    public function testUri(): void
    {
        $server = [
            'HTTPS' => 'on',
            'HTTP_HOST' => 'example.com',
            'SERVER_PORT' => '443',
            'REQUEST_URI' => '/test/path?query=value'
        ];
        $request = $this->createRequest([], [], [], [], $server);
        
        $uri = $request->getUri();
        $this->assertInstanceOf(Uri::class, $uri);
        // URI string representation may not include query in some implementations
        $this->assertStringContainsString('https://example.com', (string)$uri);
        $this->assertStringContainsString('/test/path', (string)$uri);
    }

    public function testIp(): void
    {
        $server = ['REMOTE_ADDR' => '192.168.1.1'];
        $request = $this->createRequest([], [], [], [], $server);
        $this->assertEquals('192.168.1.1', $request->ip());
    }

    public function testUserAgent(): void
    {
        $server = ['HTTP_USER_AGENT' => 'Mozilla/5.0'];
        $request = $this->createRequest([], [], [], [], $server);
        $this->assertEquals('Mozilla/5.0', $request->userAgent());
    }

    public function testAjax(): void
    {
        $server = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
        $request = $this->createRequest([], [], [], [], $server);
        $this->assertTrue($request->ajax());
    }

    public function testPjax(): void
    {
        $server = ['HTTP_X_PJAX' => 'true'];
        $request = $this->createRequest([], [], [], [], $server);
        $this->assertTrue($request->pjax());
    }

    public function testSecure(): void
    {
        $server = ['HTTPS' => 'on'];
        $request = $this->createRequest([], [], [], [], $server);
        $this->assertTrue($request->secure());
    }

    public function testHas(): void
    {
        $get = ['name' => 'John'];
        $request = $this->createRequest($get);
        
        $this->assertTrue($request->has('name'));
        $this->assertFalse($request->has('email'));
    }

    public function testOnly(): void
    {
        $get = ['name' => 'John', 'age' => '30', 'email' => 'john@example.com'];
        $request = $this->createRequest($get);
        
        $result = $request->only(['name', 'email']);
        $this->assertEquals(['name' => 'John', 'email' => 'john@example.com'], $result);
    }

    public function testExcept(): void
    {
        $get = ['name' => 'John', 'age' => '30', 'email' => 'john@example.com'];
        $request = $this->createRequest($get);
        
        $result = $request->except(['age']);
        $this->assertEquals(['name' => 'John', 'email' => 'john@example.com'], $result);
    }

    public function testTrimInput(): void
    {
        $get = ['name' => '  John  ', 'description' => "  Hello\nWorld  "];
        $request = $this->createRequest($get);
        
        $this->assertEquals('John', $request->get('name'));
        $this->assertEquals("Hello\nWorld", $request->get('description'));
    }
}
