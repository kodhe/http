<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Tests\Unit\Middleware;

use Kodhe\Framework\Http\Middleware\ApiAuth;
use Kodhe\Framework\Http\Middleware\MiddlewareInterface;
use Kodhe\Framework\Http\Middleware\MiddlewareRegistry;
use Kodhe\Framework\Http\Middleware\Routing\ThrottleRequests;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MiddlewareRegistry built-in alias auto-wiring.
 *
 * Closes the "REST API has no authentication" issue end-to-end: the
 * ApiAuth / ThrottleRequests middleware can now be attached to routes
 * simply by alias ('api-auth', 'throttle') without any app-side
 * middleware.php config existing.
 */
class MiddlewareRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Simulate an app with NO middleware.php config file so the
        // registry falls back entirely to framework built-in aliases.
        if (!defined('APPPATH')) {
            define('APPPATH', sys_get_temp_dir() . '/kodhe-http-test-app-' . getmypid() . '/');
        }
    }

    public function testFrameworkAliasesRegisteredWithoutConfigFile(): void
    {
        $registry = new MiddlewareRegistry();

        $this->assertTrue($registry->hasAlias('api-auth'));
        $this->assertTrue($registry->hasAlias('throttle'));

        $aliases = $registry->getAliases();
        $this->assertSame(ApiAuth::class, $aliases['api-auth']);
        $this->assertSame(ThrottleRequests::class, $aliases['throttle']);
    }

    public function testResolveApiAuthAliasReturnsInstance(): void
    {
        $registry = new MiddlewareRegistry();

        $middleware = $registry->resolve('api-auth');

        $this->assertInstanceOf(MiddlewareInterface::class, $middleware);
        $this->assertInstanceOf(ApiAuth::class, $middleware);
    }

    public function testResolveThrottleAliasAutoWiresRateLimiter(): void
    {
        $registry = new MiddlewareRegistry();

        // Would previously fail: ThrottleRequests::__construct(RateLimiter)
        // requires a dependency that plain `new $class()` cannot supply.
        $middleware = $registry->resolve('throttle');

        $this->assertInstanceOf(MiddlewareInterface::class, $middleware);
        $this->assertInstanceOf(ThrottleRequests::class, $middleware);
    }

    public function testResolveThrottleWithParametersDoesNotCrash(): void
    {
        $registry = new MiddlewareRegistry();

        // Parameterised string form used in route definitions.
        $middleware = $registry->resolve('throttle:60,1');

        $this->assertInstanceOf(ThrottleRequests::class, $middleware);
    }

    public function testResolvedInstancesAreCached(): void
    {
        $registry = new MiddlewareRegistry();

        $a = $registry->resolve('throttle');
        $b = $registry->resolve('throttle');

        $this->assertSame($a, $b, 'Registry must return the cached instance for the same alias');
    }

    public function testCustomCacheStoreIsUsedForAutoBuiltThrottle(): void
    {
        $registry = new MiddlewareRegistry();

        $store = new class {
            /** @var array<string, mixed> */
            public array $data = [];

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

        $registry->setDefaultCacheStore($store);
        $registry->clearCache();

        $throttle = $registry->resolve('throttle');
        $this->assertInstanceOf(ThrottleRequests::class, $throttle);

        // Drive one request through the resolved middleware and confirm
        // the injected store actually received the rate-limit bucket.
        $request = new \Kodhe\Framework\Http\Request([], [], [], [], ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '10.0.0.1']);
        $response = new \Kodhe\Framework\Http\Response();

        $result = $throttle->handle($request, $response, static function ($req, $res) {
            return $res;
        }, [5, 1]);

        $this->assertInstanceOf(\Kodhe\Framework\Http\Response::class, $result);
        $this->assertNotEmpty($store->data, 'Injected cache store should hold the throttle bucket');
    }

    public function testAppAliasOverridesFrameworkDefault(): void
    {
        // Define the app-side override class in a temp file and require it.
        $classBody = <<<'PHP'
<?php
namespace Kodhe\Framework\Http\Tests\Unit\Middleware;

class CustomApiAuth implements \Kodhe\Framework\Http\Middleware\MiddlewareInterface
{
    public function handle(\Kodhe\Framework\Http\Request $request, \Kodhe\Framework\Http\Response $response, callable $next, array $params = [])
    {
        return $next($request, $response, $params);
    }
}
PHP;
        if (!class_exists(CustomApiAuth::class, false)) {
            $tmp = tempnam(sys_get_temp_dir(), 'custommw') . '.php';
            file_put_contents($tmp, $classBody);
            require_once $tmp;
            unlink($tmp);
        }

        // Simulate an app middleware.php that overrides the 'api-auth'
        // alias, without depending on the APPPATH constant (which is
        // already defined for the whole test process).
        $registry = new class (['aliases' => ['api-auth' => CustomApiAuth::class]]) extends MiddlewareRegistry {
            private array $fakeConfig;

            public function __construct(array $fakeConfig)
            {
                $this->fakeConfig = $fakeConfig;
                parent::__construct();
            }

            protected function loadConfig()
            {
                $this->aliases = array_merge(static::$frameworkAliases, $this->fakeConfig['aliases']);
            }
        };

        $resolved = $registry->resolve('api-auth');
        $this->assertInstanceOf(CustomApiAuth::class, $resolved,
            'App config aliases must take precedence over framework defaults');

        // Framework default must survive untouched.
        $this->assertArrayHasKey('throttle', $registry->getAliases());
        $this->assertSame(ThrottleRequests::class, $registry->getAliases()['throttle']);
    }

    public function testUnknownMiddlewareStillThrows(): void
    {
        $registry = new MiddlewareRegistry();

        $this->expectException(\Kodhe\Framework\Exceptions\BaseException::class);
        $registry->resolve('does-not-exist');
    }
}
