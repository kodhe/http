<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Tests\Unit\Routing;

use Kodhe\Framework\Http\JsonResponse;
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;
use Kodhe\Framework\Http\Routing\ControllerExecutor;
use Kodhe\Framework\Http\Routing\Route;
use Kodhe\Framework\Http\Routing\RouteCollection;
use Kodhe\Framework\Http\Routing\RouteItem;
use Kodhe\Framework\Http\Routing\Router;
use Kodhe\Framework\Http\Routing\RoutingManager;
use Kodhe\Framework\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * Test case untuk Closure Route (route fungsional).
 *
 * Mereproduksi dua model route yang dilaporkan masih dianggap "not found":
 *
 *   Route::get('ping', function () {
 *       return JsonResponse::ok(['pong' => true, 'time' => date('c')]);
 *   })->name('api.ping');
 *
 *   Route::get('coba', function () {
 *       return "coba";
 *   })->name('coba');
 *
 * Verifikasi:
 *  1. Router::matchRequest() mengenali closure action (class=Closure).
 *  2. RoutingManager::resolve() TIDAK mengembalikan routing is_404 untuk
 *     closure route (regresi utama bug "not found").
 *  3. ControllerExecutor::execute() menjalankan closure tanpa error
 *     "Call to undefined method ... isClosureRouting()" dan tanpa 404.
 */
class ClosureRouteTest extends TestCase
{
    /**
     * @var array Registered routes for the current test
     */
    private array $registered = [];

    protected function setUp(): void
    {
        // Reset static Route registry & collection antar test.
        $reflection = new \ReflectionClass(Route::class);

        $routesProperty = $reflection->getProperty('routes');
        $routesProperty->setAccessible(true);
        $routesProperty->setValue([
            'GET' => [], 'POST' => [], 'PUT' => [], 'PATCH' => [],
            'DELETE' => [], 'HEAD' => [], 'OPTIONS' => [], 'ANY' => [],
        ]);

        $namedProperty = $reflection->getProperty('namedRoutes');
        $namedProperty->setAccessible(true);
        $namedProperty->setValue([]);

        $collection = new class extends RouteCollection {
            public function __construct()
            {
                // Hindari dependensi app()/config pada constructor asli.
            }
        };

        Route::setCollection($collection);
        $this->registered = [];
    }

    private function register(string $uri, \Closure $action, ?string $name = null): RouteItem
    {
        $item = Route::get($uri, $action);

        if ($name !== null) {
            $item->name($name);
        }

        $this->registered[] = $item;

        return $item;
    }

    private function makeRequest(string $path, string $method = 'GET'): Request
    {
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path,
            'HTTP_HOST' => 'localhost',
            'SERVER_NAME' => 'localhost',
            'QUERY_STRING' => '',
        ];

        return new Request([], [], [], [], $server);
    }

    /**
     * Bangun routing persis seperti RoutingManager::resolveFromModern():
     * hasil Router::matchRequest() + '_router' + '_route_item'.
     */
    private function buildRouting(Request $request): array
    {
        $router = new class extends Router {
            public function __construct()
            {
                // Lewati constructor berat (app(), Modules::init(), load routes).
            }

            public function matchRequest(\Kodhe\Framework\Http\Request $request): ?array
            {
                return parent::matchRequest($request);
            }
        };

        $routing = $router->matchRequest($request);

        if ($routing === null) {
            $this->fail('Router::matchRequest() tidak menemukan route yang terdaftar.');
        }

        $routing['_router'] = $router;

        if (isset($routing['route']) && $routing['route'] instanceof RouteItem) {
            $routing['_route_item'] = $routing['route'];
        }

        return $routing;
    }

    /**
     * Panggil protected RoutingManager::resolve() lewat reflection dengan
     * router modern ringan yang berisi route closure yang didaftarkan.
     */
    private function resolveViaManager(Request $request): array
    {
        $manager = new class(null, [
            'enable_modern_routing' => true,
            'cache_routes' => false,
            'enable_auto_route' => false,
        ]) extends RoutingManager {
            public function __construct(?Router $router = null, array $config = [])
            {
                $this->configuration = array_merge($this->configuration ?? [], $config);
            }

            public function callResolve(Request $request): array
            {
                return $this->resolve($request);
            }

            public function callIsClosureRouting(array $routing): bool
            {
                return $this->isClosureRouting($routing);
            }
        };

        $router = new class extends Router {
            public function __construct()
            {
            }

            public function matchRequest(\Kodhe\Framework\Http\Request $request): ?array
            {
                return parent::matchRequest($request);
            }
        };

        $prop = new \ReflectionProperty(RoutingManager::class, 'router');
        $prop->setAccessible(true);
        $prop->setValue($manager, $router);

        return $manager->callResolve($request);
    }

    private function makeExecutor(Request $request, Response $response): ControllerExecutor
    {
        $facade = Facade::getInstance();
        $facade->set('request', $request);
        $facade->set('response', $response);

        $manager = new class(null, ['cache_routes' => false]) extends RoutingManager {
            public function __construct(?Router $router = null, array $config = [])
            {
                $this->configuration = array_merge($this->configuration ?? [], $config);
            }
        };

        return new class($facade, $manager) extends ControllerExecutor {
            /** @var Response|null Response terakhir yang "dikirim" */
            public $capturedResponse = null;

            protected function handleResponse(Response $response): void
            {
                // Intercept agar tidak benar-benar mengirim header/echo.
                $this->capturedResponse = $response;
            }
        };
    }

    public function testRouterMatchesClosureRouteAsInvokeAction(): void
    {
        $this->register('ping', function () {
            return JsonResponse::ok(['pong' => true]);
        }, 'api.ping');

        $routing = $this->buildRouting($this->makeRequest('/ping'));

        $this->assertSame('modern', $routing['type']);
        $this->assertSame('Closure', $routing['class']);
        $this->assertSame('__invoke', $routing['method']);
        $this->assertInstanceOf(RouteItem::class, $routing['route']);
        $this->assertInstanceOf(\Closure::class, $routing['route']->getAction());
    }

    public function testIsClosureRoutingDetectsModernClosureRouting(): void
    {
        $this->register('ping', fn () => 'pong', 'api.ping');

        $routing = $this->buildRouting($this->makeRequest('/ping'));

        $manager = new class(null, ['cache_routes' => false]) extends RoutingManager {
            public function __construct(?Router $router = null, array $config = [])
            {
                $this->configuration = array_merge($this->configuration ?? [], $config);
            }

            public function callIsClosureRouting(array $routing): bool
            {
                return $this->isClosureRouting($routing);
            }
        };

        $this->assertTrue(
            $manager->callIsClosureRouting($routing),
            'RoutingManager harus mendeteksi route closure (bukan controller class).'
        );
    }

    public function testResolveClosureRoutePingDoesNotReturnNotFound(): void
    {
        $this->register('ping', function () {
            return JsonResponse::ok(['pong' => true, 'time' => date('c')]);
        }, 'api.ping');

        $resolved = $this->resolveViaManager($this->makeRequest('/ping'));

        $this->assertFalse(
            !empty($resolved['is_404']),
            'Route closure /ping tidak boleh dianggap Not Found. Routing: '
            . json_encode(array_intersect_key($resolved, array_flip(['class', 'method', 'fqcn', 'source'])))
        );
        $this->assertSame('modern', $resolved['type'] ?? null);
        $this->assertSame('Closure', $resolved['class'] ?? null);
    }

    public function testResolveClosureRouteCobaStringDoesNotReturnNotFound(): void
    {
        $this->register('coba', function () {
            return "coba";
        }, 'coba');

        $resolved = $this->resolveViaManager($this->makeRequest('/coba'));

        $this->assertFalse(
            !empty($resolved['is_404']),
            'Route closure /coba tidak boleh dianggap Not Found.'
        );
        $this->assertSame('Closure', $resolved['class'] ?? null);
    }

    public function testExecuteClosureRoutePingReturnsJsonEnvelope(): void
    {
        $this->register('ping', function () {
            return JsonResponse::ok(['pong' => true, 'time' => date('c')]);
        }, 'api.ping');

        $request = $this->makeRequest('/ping');
        $routing = $this->buildRouting($request);

        $executor = $this->makeExecutor($request, new Response());
        $executor->execute($routing);

        $this->assertNotNull($executor->capturedResponse, 'Response harus dikirim.');
        $this->assertSame(200, $executor->capturedResponse->getStatus());

        $body = (string) $executor->capturedResponse->getBody();
        $decoded = json_decode($body, true);

        $this->assertIsArray($decoded, 'Body harus JSON valid, actual: ' . $body);
        $this->assertTrue($decoded['success'] ?? false);
        $this->assertArrayHasKey('pong', $decoded['data'] ?? []);
        $this->assertTrue($decoded['data']['pong']);
    }

    public function testExecuteClosureRouteCobaReturnsStringBody(): void
    {
        $this->register('coba', function () {
            return "coba";
        }, 'coba');

        $request = $this->makeRequest('/coba');
        $routing = $this->buildRouting($request);

        $executor = $this->makeExecutor($request, new Response());
        $executor->execute($routing);

        $this->assertNotNull($executor->capturedResponse, 'Response harus dikirim.');
        $this->assertSame('coba', (string) $executor->capturedResponse->getBody());
    }

    public function testControllerExecutorHasIsClosureRoutingMethod(): void
    {
        // Regresi: dulu user melaporkan
        // "Call to undefined method ControllerExecutor::isClosureRouting()"
        // karena vendor copy tidak sinkron dengan source.
        $this->assertTrue(
            method_exists(ControllerExecutor::class, 'isClosureRouting'),
            'ControllerExecutor::isClosureRouting() harus ada.'
        );
        $this->assertTrue(
            method_exists(ControllerExecutor::class, 'executeClosureRoute'),
            'ControllerExecutor::executeClosureRoute() harus ada.'
        );
        $this->assertTrue(
            method_exists(RoutingManager::class, 'isClosureRouting'),
            'RoutingManager::isClosureRouting() harus ada.'
        );
    }

    /**
     * Regresi: closure yang meng-echo output (HTML/teks) harus tetap
     * netral — TIDAK dibungkus menjadi JSON.
     *
     *   Route::get('ping', function () {
     *       function data() { return "coba data"; }
     *       echo data();
     *   });
     */
    public function testEchoedClosureOutputIsNotWrappedAsJson(): void
    {
        $this->register('ping', function () {
            $data = 'coba data';
            echo $data;
        }, 'api.ping');

        $request = $this->makeRequest('/ping');
        $routing = $this->buildRouting($request);

        $executor = $this->makeExecutor($request, new Response());
        $executor->execute($routing);

        $this->assertNotNull($executor->capturedResponse, 'Response harus dikirim.');

        $body = (string) $executor->capturedResponse->getBody();
        $this->assertSame('coba data', $body, 'Output echo harus utuh, bukan JSON envelope.');
        $this->assertNotSame(
            'application/json; charset=UTF-8',
            (string) $executor->capturedResponse->getHeader('Content-Type'),
            'Response netral tidak boleh dipaksa Content-Type JSON.'
        );
    }

    /**
     * Regresi: closure yang mengembalikan string HTML (mis. hasil view)
     * harus dirender sebagai body netral, bukan di-json-encode sehingga
     * browser mencoba JSON.parse dan gagal dengan SyntaxError.
     */
    public function testReturnedHtmlStringStaysNeutralBody(): void
    {
        $html = '<!DOCTYPE html><html><body>Error page</body></html>';

        $this->register('ping', function () use ($html) {
            return $html; // mis. return view('errors/html/error_general');
        }, 'api.ping');

        $request = $this->makeRequest('/ping');
        $routing = $this->buildRouting($request);

        $executor = $this->makeExecutor($request, new Response());
        $executor->execute($routing);

        $this->assertNotNull($executor->capturedResponse);
        $this->assertSame($html, (string) $executor->capturedResponse->getBody());
        $this->assertFalse($executor->capturedResponse->isJson());
    }

    /**
     * Regresi: closure yang meng-echo output pada Response yang sudah
     * pernah di-mark JSON (mis. middleware memanggil $response->json()
     * sebelumnya) harus menetralkan kembali Content-Type JSON sehingga
     * klien tidak menjalankan JSON.parse di atas teks biasa dan gagal
     * dengan "SyntaxError: JSON.parse: unexpected character at line 1
     * column 1 of the JSON data".
     */
    public function testEchoedClosureNeutralizesStaleJsonContentType(): void
    {
        $this->register('ping', function () {
            function data()
            {
                return "coba data";
            }

            echo data();
        }, 'api.ping');

        $request = $this->makeRequest('/ping');
        $routing = $this->buildRouting($request);

        // Respons facade sempat berisi JSON (mis. dari middleware/handler
        // lain) sebelum closure dieksekusi.
        $facadeResponse = new Response();
        $facadeResponse->json(['stale' => true]);

        $facade = Facade::getInstance();
        $facade->set('request', $request);
        $facade->set('response', $facadeResponse);

        $manager = new class(null, ['cache_routes' => false]) extends RoutingManager {
            public function __construct(?Router $router = null, array $config = [])
            {
                $this->configuration = array_merge($this->configuration ?? [], $config);
            }
        };

        $executor = new class($facade, $manager) extends ControllerExecutor {
            public $capturedResponse = null;

            protected function handleResponse(Response $response): void
            {
                $this->capturedResponse = $response;
            }
        };

        $executor->execute($routing);

        $this->assertNotNull($executor->capturedResponse, 'Response harus dikirim.');
        $this->assertSame(
            'coba data',
            (string) $executor->capturedResponse->getBody(),
            'Output echo harus utuh, bukan JSON envelope.'
        );
        $this->assertFalse(
            $executor->capturedResponse->isJson(),
            'Flag JSON lama harus dinetralkan saat closure hanya meng-echo teks.'
        );
        $ct = (string) $executor->capturedResponse->getHeader('Content-Type');
        $this->assertStringNotContainsString(
            'application/json',
            $ct,
            'Content-Type JSON basi tidak boleh ikut terkirim. Actual: ' . $ct
        );
    }

    /**
     * Closure yang mengembalikan JsonResponse tetap netral terhadap
     * double-wrapping: body yang dikirim adalah persis envelope dari
     * JsonResponse, bukan {"success":true,"data":{"success":true,...}}.
     */
    public function testJsonResponseReturnedByClosureIsNotDoubleWrapped(): void
    {
        $this->register('ping', function () {
            return JsonResponse::ok(['pong' => true]);
        }, 'api.ping');

        $request = $this->makeRequest('/ping');
        $routing = $this->buildRouting($request);

        $executor = $this->makeExecutor($request, new Response());
        $executor->execute($routing);

        $this->assertNotNull($executor->capturedResponse);

        $decoded = json_decode((string) $executor->capturedResponse->getBody(), true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['success'] ?? false);
        $this->assertArrayHasKey('pong', $decoded['data'] ?? []);
        // Tidak boleh ada nested envelope (double wrap).
        $this->assertArrayNotHasKey('success', $decoded['data'] ?? []);
    }
}
