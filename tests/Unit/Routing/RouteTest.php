<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Tests\Unit\Routing;

use Kodhe\Framework\Http\Routing\Route;
use Kodhe\Framework\Http\Routing\RouteCollection;
use PHPUnit\Framework\TestCase;

class RouteTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset static properties before each test
        $reflection = new \ReflectionClass(Route::class);
        
        $routesProperty = $reflection->getProperty('routes');
        $routesProperty->setAccessible(true);
        $routesProperty->setValue([
            'GET' => [],
            'POST' => [],
            'PUT' => [],
            'PATCH' => [],
            'DELETE' => [],
            'HEAD' => [],
            'OPTIONS' => [],
            'ANY' => []
        ]);

        $namedRoutesProperty = $reflection->getProperty('namedRoutes');
        $namedRoutesProperty->setAccessible(true);
        $namedRoutesProperty->setValue([]);
    }

    public function testGetRoute(): void
    {
        Route::get('/test', 'Controller@method');
        $routes = Route::getRoutes();
        
        $this->assertArrayHasKey('GET', $routes);
        $this->assertArrayHasKey('/test', $routes['GET']);
    }

    public function testPostRoute(): void
    {
        Route::post('/test', 'Controller@method');
        $routes = Route::getRoutes();
        
        $this->assertArrayHasKey('POST', $routes);
        $this->assertArrayHasKey('/test', $routes['POST']);
    }

    public function testPutRoute(): void
    {
        Route::put('/test', 'Controller@method');
        $routes = Route::getRoutes();
        
        $this->assertArrayHasKey('PUT', $routes);
        $this->assertArrayHasKey('/test', $routes['PUT']);
    }

    public function testDeleteRoute(): void
    {
        Route::delete('/test', 'Controller@method');
        $routes = Route::getRoutes();
        
        $this->assertArrayHasKey('DELETE', $routes);
        $this->assertArrayHasKey('/test', $routes['DELETE']);
    }

    public function testPatchRoute(): void
    {
        Route::patch('/test', 'Controller@method');
        $routes = Route::getRoutes();
        
        $this->assertArrayHasKey('PATCH', $routes);
        $this->assertArrayHasKey('/test', $routes['PATCH']);
    }

    public function testAnyRoute(): void
    {
        Route::any('/test', 'Controller@method');
        $routes = Route::getRoutes();
        
        $this->assertArrayHasKey('ANY', $routes);
        $this->assertArrayHasKey('/test', $routes['ANY']);
    }

    public function testNamedRoute(): void
    {
        Route::get('/test', 'Controller@method')->name('test.route');
        $namedRoutes = Route::getNamedRoutes();
        
        $this->assertArrayHasKey('test.route', $namedRoutes);
    }

    public function testRouteMiddleware(): void
    {
        Route::get('/test', 'Controller@method')->middleware(['auth', 'throttle:60,1']);
        $routes = Route::getRoutes();
        $route = $routes['GET']['/test'];
        
        $this->assertEquals(['auth', 'throttle:60,1'], $route->getMiddleware());
    }

    public function testRouteName(): void
    {
        Route::get('/test', 'Controller@method')->name('users.show');
        $routes = Route::getRoutes();
        $route = $routes['GET']['/test'];
        
        $this->assertEquals('users.show', $route->getName());
    }

    public function testRouteAction(): void
    {
        Route::get('/test', 'Controller@method');
        $routes = Route::getRoutes();
        $route = $routes['GET']['/test'];
        
        $this->assertEquals('Controller@method', $route->getAction());
    }

    public function testRouteUri(): void
    {
        Route::get('/users/{id}', 'UserController@show');
        $routes = Route::getRoutes();
        $route = $routes['GET']['/users/{id}'];
        
        $this->assertEquals('/users/{id}', $route->getUri());
    }

    public function testRouteGroup(): void
    {
        Route::group(['prefix' => 'api', 'middleware' => ['api']], function() {
            Route::get('/users', 'UserController@index');
        });
        
        $routes = Route::getRoutes();
        $this->assertArrayHasKey('GET', $routes);
    }

    public function testRouteResource(): void
    {
        Route::resource('photos', 'PhotoController');
        $routes = Route::getRoutes();
        
        // Resource should create multiple routes
        $this->assertNotEmpty($routes['GET']);
    }

    public function testRouteApiVersion(): void
    {
        Route::apiVersion('2')->group(function() {
            Route::get('/api/users', 'UserController@index');
        });
        
        $version = Route::getCurrentApiVersion();
        $this->assertEquals('2', $version);
    }

    public function testRouteSetCollection(): void
    {
        $collection = new RouteCollection();
        Route::setCollection($collection);
        
        $this->assertInstanceOf(RouteCollection::class, Route::getCollection());
    }

    public function testRouteUrlGeneration(): void
    {
        Route::get('/users/{id}', 'UserController@show')->name('users.show');
        
        $url = Route::url('users.show', ['id' => 123]);
        $this->assertStringContainsString('/users/123', $url);
    }

    public function testRouteWithPatterns(): void
    {
        Route::pattern('id', '[0-9]+');
        Route::get('/users/{id}', 'UserController@show');
        
        $routes = Route::getRoutes();
        $this->assertNotEmpty($routes['GET']);
    }

    public function testRouteWhere(): void
    {
        Route::get('/users/{id}', 'UserController@show')->where(['id' => '[0-9]+']);
        $routes = Route::getRoutes();
        $route = $routes['GET']['/users/{id}'];
        
        $this->assertNotNull($route);
    }
}
