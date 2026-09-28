<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;

class UrlHelperTest extends TestCase
{
    public function testSiteUrlFunctionExists(): void
    {
        $this->assertTrue(function_exists('site_url'));
    }

    public function testBaseUrlFunctionExists(): void
    {
        $this->assertTrue(function_exists('base_url'));
    }

    public function testCurrentUrlFunctionExists(): void
    {
        $this->assertTrue(function_exists('current_url'));
    }

    public function testUriStringFunctionExists(): void
    {
        $this->assertTrue(function_exists('uri_string'));
    }

    public function testIndexPageFunctionExists(): void
    {
        $this->assertTrue(function_exists('index_page'));
    }

    public function testAnchorFunctionExists(): void
    {
        $this->assertTrue(function_exists('anchor'));
    }

    public function testAnchorPopupFunctionExists(): void
    {
        $this->assertTrue(function_exists('anchor_popup'));
    }

    public function testMailtoFunctionExists(): void
    {
        $this->assertTrue(function_exists('mailto'));
    }

    public function testSafeMailtoFunctionExists(): void
    {
        $this->assertTrue(function_exists('safe_mailto'));
    }

    public function testAutoLinkFunctionExists(): void
    {
        $this->assertTrue(function_exists('auto_link'));
    }

    public function testPrepUrlFunctionExists(): void
    {
        $this->assertTrue(function_exists('prep_url'));
    }

    public function testUrlTitleFunctionExists(): void
    {
        $this->assertTrue(function_exists('url_title'));
    }

    public function testRedirectFunctionExists(): void
    {
        $this->assertTrue(function_exists('redirect'));
    }

    public function testPrepUrlAddsHttp(): void
    {
        $result = prep_url('example.com');
        $this->assertEquals('http://example.com', $result);
    }

    public function testPrepUrlWithExistingScheme(): void
    {
        $result = prep_url('https://example.com');
        $this->assertEquals('https://example.com', $result);
    }

    public function testPrepUrlEmptyString(): void
    {
        $result = prep_url('');
        $this->assertEquals('', $result);
    }

    public function testPrepUrlHttpOnly(): void
    {
        $result = prep_url('http://');
        $this->assertEquals('', $result);
    }

    public function testUrlTitleWithSpaces(): void
    {
        $result = url_title('Hello World Test');
        $this->assertEquals('hello-world-test', $result);
    }

    public function testUrlTitleWithCustomSeparator(): void
    {
        $result = url_title('Hello World Test', '_');
        $this->assertEquals('hello_world_test', $result);
    }

    public function testUrlTitleWithoutLowercase(): void
    {
        $result = url_title('Hello World Test', '-', false);
        $this->assertEquals('Hello-World-Test', $result);
    }

    public function testUrlTitleWithSpecialChars(): void
    {
        $result = url_title('Hello & World!');
        $this->assertEquals('Hello-World', $result);
    }
}
