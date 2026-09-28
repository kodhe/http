<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Tests\Unit;

use Kodhe\Framework\Http\Uri;
use PHPUnit\Framework\TestCase;

class UriTest extends TestCase
{
    public function testConstructor(): void
    {
        $uri = new Uri('https', 'example.com', 443, '/path', 'query=value');
        $this->assertInstanceOf(Uri::class, $uri);
    }

    public function testToString(): void
    {
        $uri = new Uri('https', 'example.com', 443, '/path', 'query=value');
        $this->assertEquals('https://example.com/path?query=value', (string)$uri);
    }

    public function testGetScheme(): void
    {
        $uri = new Uri('https', 'example.com');
        $this->assertEquals('https', $uri->getScheme());
    }

    public function testGetHost(): void
    {
        $uri = new Uri('http', 'example.com');
        $this->assertEquals('example.com', $uri->getHost());
    }

    public function testGetPort(): void
    {
        $uri = new Uri('http', 'example.com', 8080);
        $this->assertEquals(8080, $uri->getPort());
    }

    public function testGetPath(): void
    {
        $uri = new Uri('http', 'example.com', 80, '/test/path');
        $this->assertEquals('/test/path', $uri->getPath());
    }

    public function testGetQuery(): void
    {
        $uri = new Uri('http', 'example.com', 80, '/', 'name=john&age=30');
        $this->assertEquals('name=john&age=30', $uri->getQuery());
    }

    public function testGetFragment(): void
    {
        $uri = new Uri('http', 'example.com', 80, '/', '', 'section1');
        $this->assertEquals('section1', $uri->getFragment());
    }

    public function testDefaultPorts(): void
    {
        $httpUri = new Uri('http', 'example.com', 80);
        $httpsUri = new Uri('https', 'example.com', 443);
        
        $this->assertStringNotContainsString(':80', (string)$httpUri);
        $this->assertStringNotContainsString(':443', (string)$httpsUri);
    }

    public function testNonDefaultPorts(): void
    {
        $uri = new Uri('http', 'example.com', 8080);
        $this->assertStringContainsString(':8080', (string)$uri);
    }

    public function testEmptyQuery(): void
    {
        $uri = new Uri('http', 'example.com', 80, '/path', '');
        $this->assertStringNotContainsString('?', (string)$uri);
    }

    public function testEmptyFragment(): void
    {
        $uri = new Uri('http', 'example.com', 80, '/path', '', '');
        $this->assertStringNotContainsString('#', (string)$uri);
    }

    public function testGetBaseUrl(): void
    {
        $uri = new Uri('https', 'example.com', 443, '/path');
        $this->assertEquals('https://example.com', $uri->getBaseUrl());
    }

    public function testGetSegments(): void
    {
        $uri = new Uri('http', 'example.com', 80, '/users/profile/settings');
        $segments = $uri->getSegments();
        $this->assertEquals(['users', 'profile', 'settings'], $segments);
    }

    public function testGetSegment(): void
    {
        $uri = new Uri('http', 'example.com', 80, '/users/123');
        $this->assertEquals('users', $uri->getSegment(1));
        $this->assertEquals('123', $uri->getSegment(2));
    }

    public function testMatches(): void
    {
        $uri = new Uri('http', 'example.com', 80, '/api/users');
        $this->assertTrue($uri->matches('/api/users'));
        $this->assertTrue($uri->matches('/api/*'));
        $this->assertFalse($uri->matches('/admin/*'));
    }
}
