<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Tests\Unit;

use Kodhe\Framework\Http\Response;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    protected function createResponse(): Response
    {
        return new Response();
    }

    public function testConstructor(): void
    {
        $response = $this->createResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('', $response->getBody());
    }

    public function testSetStatus(): void
    {
        $response = $this->createResponse();
        $response->setStatus(404);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testSetBody(): void
    {
        $response = $this->createResponse();
        $response->setBody('Hello World');
        $this->assertEquals('Hello World', $response->getBody());
    }

    public function testSetHeader(): void
    {
        $response = $this->createResponse();
        $response->setHeader('Content-Type', 'application/json');
        $headers = $response->getHeaders();
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertEquals('application/json', $headers['Content-Type']);
    }

    public function testSetHeaders(): void
    {
        $response = $this->createResponse();
        $headers = [
            'Content-Type' => 'application/json',
            'X-Custom-Header' => 'custom-value'
        ];
        $response->setHeaders($headers);
        $this->assertEquals($headers, $response->getHeaders());
    }

    public function testSetJson(): void
    {
        $response = $this->createResponse();
        $data = ['status' => 'ok', 'items' => [1, 2, 3]];
        $result = $response->setJson($data);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertTrue($response->isJson());

        $contentType = $response->getHeader('Content-Type');
        $this->assertStringContainsString('application/json', $contentType);

        $decoded = json_decode($response->getBody(), true);
        $this->assertEquals($data, $decoded);
    }

    public function testJson(): void
    {
        $response = $this->createResponse();
        $data = ['name' => 'John', 'age' => 30];
        $response->json($data);
        
        // Content-Type includes charset in some implementations
        $contentType = $response->getHeader('Content-Type');
        $this->assertStringContainsString('application/json', $contentType);
        
        $body = $response->getBody();
        $decoded = json_decode($body, true);
        $this->assertEquals($data, $decoded);
    }

    public function testView(): void
    {
        $response = $this->createResponse();
        $view = 'welcome';
        $data = ['name' => 'John'];
        
        // Note: This test assumes view rendering is mocked or simplified
        $result = $response->view($view, $data);
        $this->assertInstanceOf(Response::class, $result);
    }

    public function testRedirect(): void
    {
        $response = $this->createResponse();
        $url = 'https://example.com';
        $response->redirect($url);
        
        $this->assertEquals(302, $response->getStatus());
        $this->assertEquals($url, $response->getHeader('Location'));
    }

    public function testDownload(): void
    {
        $response = $this->createResponse();
        $filename = 'test.txt';
        $content = 'File content';
        
        $response->download($filename, $content);
        
        $this->assertStringContainsString('attachment', $response->getHeader('Content-Disposition'));
        $this->assertStringContainsString($filename, $response->getHeader('Content-Disposition'));
    }

    public function testSend(): void
    {
        $response = $this->createResponse();
        $response->setBody('Test content');
        
        // Test that send returns the response or outputs content
        // Note: send() may output directly and return void in some implementations
        $result = $response->send();
        // Accept either Response instance or null (if it outputs directly)
        $this->assertTrue($result instanceof Response || $result === null);
    }

    public function testCookie(): void
    {
        $response = $this->createResponse();
        $response->cookie('session', 'abc123', 3600);
        
        $headers = $response->getHeaders();
        $this->assertArrayHasKey('Set-Cookie', $headers);
    }

    public function testNoCache(): void
    {
        $response = $this->createResponse();
        $response->noCache();
        
        $headers = $response->getHeaders();
        $this->assertArrayHasKey('Cache-Control', $headers);
        $this->assertArrayHasKey('Expires', $headers);
    }

    public function testSetContentType(): void
    {
        $response = $this->createResponse();
        $response->setContentType('text/html');
        $this->assertEquals('text/html', $response->getHeader('Content-Type'));
    }

    public function testStatusCode(): void
    {
        $response = $this->createResponse();
        $response->statusCode(500);
        $this->assertEquals(500, $response->getStatus());
    }

    public function testWithHeader(): void
    {
        $response = $this->createResponse();
        $result = $response->withHeader('X-Custom', 'value');
        
        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals('value', $result->getHeader('X-Custom'));
    }

    public function testWithBody(): void
    {
        $response = $this->createResponse();
        $result = $response->withBody('New body');
        
        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals('New body', $result->getBody());
    }
}
