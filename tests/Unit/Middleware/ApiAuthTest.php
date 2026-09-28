<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Tests\Unit\Middleware;

use Kodhe\Framework\Http\JsonResponse;
use Kodhe\Framework\Http\Middleware\ApiAuth;
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ApiAuth middleware (REST API authentication).
 */
class ApiAuthTest extends TestCase
{
    protected function createRequest(array $server = [], array $get = []): Request
    {
        return new Request($get, [], [], [], array_merge(['REQUEST_METHOD' => 'GET'], $server));
    }

    protected function passNext(): callable
    {
        return function (Request $request, Response $response) {
            $response->setHeader('X-Passed', 'yes');
            return $response;
        };
    }

    // ------------------------------------------------------------------
    // Credential extraction
    // ------------------------------------------------------------------

    public function testBearerTokenIsAcceptedAndPrincipalExposed(): void
    {
        $middleware = new ApiAuth(function (string $credential) {
            return $credential === 'good-token'
                ? ['id' => 7, 'email' => 'api@example.com']
                : null;
        });

        $request  = $this->createRequest(['HTTP_AUTHORIZATION' => 'Bearer good-token']);
        $response = new JsonResponse();

        $result = $middleware->handle($request, $response, $this->passNext());

        $this->assertSame(200, $result->getStatus());
        $this->assertEquals('yes', $result->getHeader('X-Passed'));
        $this->assertSame('api@example.com', $request->getAttribute('auth.id'));
        $this->assertSame(['id' => 7, 'email' => 'api@example.com'], $request->getAttribute('auth.user'));
    }

    public function testTokenSchemeAndApiKeyHeaderAreSupported(): void
    {
        $middleware = new ApiAuth(fn (string $c) => $c === 'secret-key' ? ['id' => 'key-owner'] : null);

        // "Token <key>" scheme
        $request = $this->createRequest(['HTTP_AUTHORIZATION' => 'Token secret-key']);
        $result  = $middleware->handle($request, new JsonResponse(), $this->passNext());
        $this->assertSame(200, $result->getStatus());
        $this->assertSame('key-owner', $request->getAttribute('auth.id'));

        // Dedicated X-API-Key header
        $request = $this->createRequest(['HTTP_X_API_KEY' => 'secret-key']);
        $result  = $middleware->handle($request, new JsonResponse(), $this->passNext());
        $this->assertSame(200, $result->getStatus());
    }

    public function testRawAuthorizationValueWithoutSchemeIsAccepted(): void
    {
        $middleware = new ApiAuth(fn (string $c) => $c === 'raw-key' ? ['ok' => true] : null);

        $request = $this->createRequest(['HTTP_AUTHORIZATION' => 'raw-key']);
        $result  = $middleware->handle($request, new JsonResponse(), $this->passNext());

        $this->assertSame(200, $result->getStatus());
    }

    public function testQueryKeyFallbackIsDisabledByDefaultButConfigurable(): void
    {
        $validator = fn (string $c) => $c === 'q-token' ? ['ok' => true] : null;

        // Default: query string credentials are ignored
        $middleware = new ApiAuth($validator);
        $request    = $this->createRequest([], ['api_key' => 'q-token']);
        $result     = $middleware->handle($request, new JsonResponse(), $this->passNext());
        $this->assertSame(401, $result->getStatus());

        // Opt-in via config
        $middleware = new ApiAuth($validator, ['queryKey' => 'api_key']);
        $request    = $this->createRequest([], ['api_key' => 'q-token']);
        $result     = $middleware->handle($request, new JsonResponse(), $this->passNext());
        $this->assertSame(200, $result->getStatus());
    }

    // ------------------------------------------------------------------
    // Rejections
    // ------------------------------------------------------------------

    public function testMissingCredentialReturns401JsonEnvelope(): void
    {
        $middleware = new ApiAuth(fn (string $c) => ['ok' => true]);
        $request    = $this->createRequest();
        $response   = new JsonResponse();

        $result = $middleware->handle($request, $response, $this->passNext());

        $this->assertSame(401, $result->getStatus());
        $payload = $result->getPayload();
        $this->assertFalse($payload['success']);
        $this->assertSame('UNAUTHENTICATED', $payload['error']['code']);
        $this->assertStringContainsString('Authentication required', $payload['error']['message']);
        $this->assertStringContainsString('Bearer realm="api"', (string) $result->getHeader('WWW-Authenticate'));
    }

    public function testInvalidCredentialReturns401(): void
    {
        $middleware = new ApiAuth(fn (string $c) => null);
        $request    = $this->createRequest(['HTTP_AUTHORIZATION' => 'Bearer nope']);

        $result = $middleware->handle($request, new JsonResponse(), $this->passNext());

        $this->assertSame(401, $result->getStatus());
        $this->assertSame('UNAUTHENTICATED', $result->getPayload()['error']['code']);
    }

    public function testValidatorRejectionWithErrorPayloadHonoursStatusAndDetails(): void
    {
        $middleware = new ApiAuth(fn (string $c) => [
            'error' => [
                'message' => 'Token has expired',
                'code'    => 'TOKEN_EXPIRED',
                'status'  => 401,
                'data'    => ['expired_at' => 1700000000],
            ],
        ]);

        $result = $middleware->handle(
            $this->createRequest(['HTTP_AUTHORIZATION' => 'Bearer stale']),
            new JsonResponse(),
            $this->passNext()
        );

        $payload = $result->getPayload();
        $this->assertSame(401, $result->getStatus());
        $this->assertSame('TOKEN_EXPIRED', $payload['error']['code']);
        $this->assertSame('Token has expired', $payload['error']['message']);
        $this->assertSame(1700000000, $payload['error']['data']['expired_at']);
    }

    public function testThrownExceptionBecomesUnauthorizedResponse(): void
    {
        $exception = new \Kodhe\Framework\Exceptions\Auth\AuthenticationException(
            'Account locked',
            'api',
            'user@example.com'
        );

        $middleware = new ApiAuth(function () use ($exception) {
            throw $exception;
        });

        $result = $middleware->handle(
            $this->createRequest(['HTTP_AUTHORIZATION' => 'Bearer whatever']),
            new JsonResponse(),
            $this->passNext()
        );

        $this->assertSame(401, $result->getStatus());
        $payload = $result->getPayload();
        $this->assertFalse($payload['success']);
        $this->assertSame('Account locked', $payload['error']['message']);
    }

    public function testMisconfiguredMiddlewareFailsClosed(): void
    {
        $middleware = new ApiAuth();

        $result = $middleware->handle($this->createRequest(), new JsonResponse(), $this->passNext());

        $this->assertSame(500, $result->getStatus());
        $this->assertSame('SERVER_MISCONFIGURATION', $result->getPayload()['error']['code']);
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function testScopeEnforcement(): void
    {
        $principal = ['id' => 1, 'scopes' => ['read:posts', 'write:posts']];
        $middleware = new ApiAuth(fn (string $c) => $principal, ['scopes' => ['write:posts']]);

        $result = $middleware->handle(
            $this->createRequest(['HTTP_AUTHORIZATION' => 'Bearer tok']),
            new JsonResponse(),
            $this->passNext()
        );
        $this->assertSame(200, $result->getStatus());

        $middleware = new ApiAuth(fn (string $c) => $principal, ['scopes' => ['admin']]);
        $result = $middleware->handle(
            $this->createRequest(['HTTP_AUTHORIZATION' => 'Bearer tok']),
            new JsonResponse(),
            $this->passNext()
        );
        $this->assertSame(403, $result->getStatus());
        $this->assertSame('INSUFFICIENT_SCOPE', $result->getPayload()['error']['code']);
    }

    public function testSpaceDelimitedScopeStringIsParsed(): void
    {
        $middleware = new ApiAuth(
            fn (string $c) => ['id' => 1, 'scope' => 'read:posts write:posts'],
            ['scopes' => ['read:posts']]
        );

        $result = $middleware->handle(
            $this->createRequest(['HTTP_AUTHORIZATION' => 'Bearer tok']),
            new JsonResponse(),
            $this->passNext()
        );

        $this->assertSame(200, $result->getStatus());
    }

    // ------------------------------------------------------------------
    // Optional paths & call conventions
    // ------------------------------------------------------------------

    public function testOptionalPathsSkipAuthentication(): void
    {
        $middleware = new ApiAuth(null, ['optional' => ['/api/auth/login']]);

        $request = $this->createRequest([
            'REQUEST_URI' => '/api/auth/login',
            'REQUEST_METHOD' => 'POST',
        ]);

        $result = $middleware->handle($request, new JsonResponse(), $this->passNext());

        $this->assertSame(200, $result->getStatus());
        $this->assertEquals('yes', $result->getHeader('X-Passed'));
    }

    public function testLaravelStyleCallConvention(): void
    {
        $middleware = new ApiAuth(fn (string $c) => ['id' => 'u1']);

        $next = function (Request $request) {
            $response = new JsonResponse();
            $response->success(['passed' => true]);
            $response->setHeader('X-Auth-Id', (string) $request->getAttribute('auth.id'));
            return $response;
        };

        $result = $middleware->handle(
            $this->createRequest(['HTTP_AUTHORIZATION' => 'Bearer abc']),
            $next
        );

        $this->assertSame(200, $result->getStatus());
        $this->assertEquals('u1', $result->getHeader('X-Auth-Id'));
    }

    public function testParamsOverrideConstructorConfig(): void
    {
        $middleware = new ApiAuth(fn (string $c) => $c === 'scoped' ? ['id' => 1] : null);

        // scopes passed as route params should apply
        $result = $middleware->handle(
            $this->createRequest(['HTTP_AUTHORIZATION' => 'Bearer scoped']),
            new JsonResponse(),
            $this->passNext(),
            ['scopes' => ['missing-scope']]
        );

        $this->assertSame(403, $result->getStatus());
    }

    public function testPlainResponseGetsJsonErrorBody(): void
    {
        $middleware = new ApiAuth(fn (string $c) => null);

        $result = $middleware->handle(
            $this->createRequest(['HTTP_AUTHORIZATION' => 'Bearer bad']),
            new Response(),
            $this->passNext()
        );

        $this->assertSame(401, $result->getStatus());
        $decoded = json_decode((string) $result->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame('UNAUTHENTICATED', $decoded['error']['code']);
        $this->assertStringContainsString('application/json', (string) $result->getHeader('Content-Type'));
    }
}
