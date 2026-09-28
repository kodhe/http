<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;

class RoutingHelperTest extends TestCase
{
    public function testRouteFunctionExists(): void
    {
        $this->assertTrue(function_exists('route'));
    }

    public function testApiRouteFunctionExists(): void
    {
        $this->assertTrue(function_exists('api_route'));
    }

    public function testSubdomainRouteFunctionExists(): void
    {
        $this->assertTrue(function_exists('subdomain_route'));
    }

    public function testBuildUrlFunctionExists(): void
    {
        $this->assertTrue(function_exists('build_url'));
    }

    public function testCurrentRouteFunctionExists(): void
    {
        $this->assertTrue(function_exists('current_route'));
    }

    public function testCurrentRouteNameFunctionExists(): void
    {
        $this->assertTrue(function_exists('current_route_name'));
    }

    public function testRouteHasFunctionExists(): void
    {
        $this->assertTrue(function_exists('route_has'));
    }

    public function testRouteIsFunctionExists(): void
    {
        $this->assertTrue(function_exists('route_is'));
    }

    public function testRouteParametersFunctionExists(): void
    {
        $this->assertTrue(function_exists('route_parameters'));
    }

    public function testRouteParameterFunctionExists(): void
    {
        $this->assertTrue(function_exists('route_parameter'));
    }

    public function testRateLimitInfoFunctionExists(): void
    {
        $this->assertTrue(function_exists('rate_limit_info'));
    }

    public function testApiVersionFunctionExists(): void
    {
        $this->assertTrue(function_exists('api_version'));
    }

    public function testSubdomainFunctionExists(): void
    {
        $this->assertTrue(function_exists('subdomain'));
    }

    public function testRouteListFunctionExists(): void
    {
        $this->assertTrue(function_exists('route_list'));
    }

    public function testBuildUrlWithBasicParts(): void
    {
        $parts = [
            'scheme' => 'https',
            'host' => 'example.com',
            'path' => '/test'
        ];
        
        $url = build_url($parts);
        $this->assertEquals('https://example.com/test', $url);
    }

    public function testBuildUrlWithPort(): void
    {
        $parts = [
            'scheme' => 'http',
            'host' => 'example.com',
            'port' => 8080,
            'path' => '/test'
        ];
        
        $url = build_url($parts);
        $this->assertEquals('http://example.com:8080/test', $url);
    }

    public function testBuildUrlWithQuery(): void
    {
        $parts = [
            'scheme' => 'https',
            'host' => 'example.com',
            'path' => '/test',
            'query' => 'name=john'
        ];
        
        $url = build_url($parts);
        $this->assertEquals('https://example.com/test?name=john', $url);
    }

    public function testBuildUrlWithFragment(): void
    {
        $parts = [
            'scheme' => 'https',
            'host' => 'example.com',
            'path' => '/test',
            'fragment' => 'section1'
        ];
        
        $url = build_url($parts);
        $this->assertEquals('https://example.com/test#section1', $url);
    }

    public function testBuildUrlMinimal(): void
    {
        $parts = [
            'host' => 'example.com'
        ];
        
        $url = build_url($parts);
        $this->assertEquals('example.com', $url);
    }
}
