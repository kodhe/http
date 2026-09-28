<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Tests\Unit;

use Kodhe\Framework\Exceptions\Http\NotFoundException;
use Kodhe\Framework\Http\JsonResponse;
use Kodhe\Framework\Http\Response;
use PHPUnit\Framework\TestCase;

class JsonResponseTest extends TestCase
{
    protected function createResponse($data = null, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }

    protected function decode(JsonResponse $response)
    {
        return json_decode($response->getBody(), true);
    }

    public function testConstructorDefaultsToSuccessEnvelope(): void
    {
        $response = $this->createResponse();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertSame(['success' => true, 'data' => null], $this->decode($response));
    }

    public function testConstructorWrapsDataInSuccessEnvelope(): void
    {
        $response = $this->createResponse(['id' => 1]);

        $decoded = $this->decode($response);
        $this->assertTrue($decoded['success']);
        $this->assertEquals(['id' => 1], $decoded['data']);
    }

    public function testConstructorAcceptsFullEnvelope(): void
    {
        $envelope = ['success' => false, 'error' => ['code' => 'X', 'message' => 'boom']];
        $response = $this->createResponse($envelope, 500);

        $this->assertEquals($envelope, $this->decode($response));
        $this->assertEquals(500, $response->getStatus());
    }

    public function testConstructorSetsHeaders(): void
    {
        $response = $this->createResponse(null, 200, ['X-Api-Version' => 'v2']);

        $this->assertEquals('v2', $response->getHeader('X-Api-Version'));
    }

    public function testContentTypeIsJson(): void
    {
        $response = $this->createResponse(['a' => 1]);

        $this->assertStringContainsString('application/json', $response->getHeader('Content-Type'));
    }

    public function testMakeFactory(): void
    {
        $response = JsonResponse::make(['x' => 1], 201);

        $this->assertEquals(201, $response->getStatus());
        $this->assertEquals(1, $this->decode($response)['data']['x']);
    }

    public function testOkFactory(): void
    {
        $response = JsonResponse::ok(['x' => 1], 200, ['total' => 1]);

        $decoded = $this->decode($response);
        $this->assertTrue($decoded['success']);
        $this->assertEquals(['total' => 1], $decoded['meta']);
    }

    public function testCreatedFactory(): void
    {
        $response = JsonResponse::created(['id' => 7]);

        $this->assertEquals(201, $response->getStatus());
        $this->assertTrue($this->decode($response)['success']);
    }

    public function testNoContentFactory(): void
    {
        $response = JsonResponse::noContent();

        $this->assertEquals(204, $response->getStatus());
        $this->assertTrue($this->decode($response)['success']);
        $this->assertNull($this->decode($response)['data']);
    }

    public function testErrorFactory(): void
    {
        $response = JsonResponse::error('Bad input', 400, 'BAD_REQUEST');

        $decoded = $this->decode($response);
        $this->assertFalse($decoded['success']);
        $this->assertEquals('BAD_REQUEST', $decoded['error']['code']);
        $this->assertEquals('Bad input', $decoded['error']['message']);
        $this->assertEquals(400, $decoded['error']['status']);
        $this->assertEquals(400, $response->getStatus());
    }

    public function testNotFoundFactory(): void
    {
        $response = JsonResponse::notFound();

        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals('NOT_FOUND', $this->decode($response)['error']['code']);
    }

    public function testValidationFailedFactory(): void
    {
        $errors = ['title' => ['The title is required.']];
        $response = JsonResponse::validationFailed($errors);

        $decoded = $this->decode($response);
        $this->assertEquals(422, $response->getStatus());
        $this->assertEquals('VALIDATION_ERROR', $decoded['error']['code']);
        $this->assertEquals($errors, $decoded['error']['data']['errors']);
    }

    public function testSuccessMethodReturnsSelfAndSetsStatus(): void
    {
        $response = new JsonResponse();
        $result = $response->success(['a' => 1], 200, ['page' => 2]);

        $this->assertSame($response, $result);
        $decoded = $this->decode($response);
        $this->assertTrue($decoded['success']);
        $this->assertEquals(['page' => 2], $decoded['meta']);
    }

    public function testFailMethod(): void
    {
        $response = new JsonResponse();
        $response->fail('Gone', 410, 'GONE', ['reason' => 'deleted']);

        $decoded = $this->decode($response);
        $this->assertEquals(410, $response->getStatus());
        $this->assertEquals('deleted', $decoded['error']['data']['reason']);
    }

    public function testSetAndGetPayload(): void
    {
        $response = new JsonResponse();
        $payload = ['success' => true, 'data' => ['ping' => 'pong']];
        $response->setPayload($payload);

        $this->assertEquals($payload, $response->getPayload());
        $this->assertEquals($payload, $this->decode($response));
    }

    public function testWithAddsTopLevelKey(): void
    {
        $response = JsonResponse::ok(['id' => 1])->with('request_id', 'abc');

        $decoded = $this->decode($response);
        $this->assertEquals('abc', $decoded['request_id']);
    }

    public function testWithMetaMerges(): void
    {
        $response = JsonResponse::ok([], 200, ['page' => 1])->withMeta(['total' => 10]);

        $decoded = $this->decode($response);
        $this->assertEquals(['page' => 1, 'total' => 10], $decoded['meta']);
    }

    public function testFromException(): void
    {
        $exception = NotFoundException::resource('post', 99);
        $response = JsonResponse::fromException($exception);

        $this->assertEquals(404, $response->getStatus());
        $decoded = $this->decode($response);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertEquals('NOT_FOUND', $decoded['error']['code']);
    }

    public function testDecodeHelper(): void
    {
        $response = JsonResponse::ok(['x' => 5]);

        $this->assertEquals(['x' => 5], $response->decode()['data']);
    }

    public function testJsonSerialize(): void
    {
        $response = JsonResponse::ok(['x' => 5]);

        $this->assertEquals($response->getPayload(), $response->jsonSerialize());
        $this->assertEquals('{"success":true,"data":{"x":5}}', json_encode($response));
    }

    public function testEncodingOptionsAreApplied(): void
    {
        $response = JsonResponse::ok(['url' => 'https://example.com/a']);

        // Default flags unescape slashes and unicode
        $this->assertStringContainsString('https://example.com/a', (string) $response->getBody());

        // JSON_HEX_APOS (256): escape single quotes -> proves custom flags are applied
        $response->setPayload(['quote' => "it's"]);
        $response->setEncodingOptions(JSON_HEX_APOS | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('it\x27s', (string) $response->getBody());
    }

    public function testUnicodeIsUnescapedByDefault(): void
    {
        $response = JsonResponse::ok(['nama' => 'Kodhé']);

        $this->assertStringContainsString('Kodhé', $response->getBody());
    }
}
