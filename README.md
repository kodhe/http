# Kodhe HTTP Library

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/version-1.1.0-orange.svg)](composer.json)

Library HTTP komprehensif untuk framework Kodhe yang menyediakan Request, Response, Middleware, Pipeline, dan Routing system.

## 📦 Instalasi

```bash
composer require kodhe/http
```

### Requirements

- PHP >= 8.1
- Composer

## 🚀 Fitur Utama

- **Request Handling** - Pengelolaan HTTP request (GET, POST, cookies, files, environment)
- **Response Management** - Pembuatan dan pengelolaan HTTP response dengan status codes dan headers
- **Middleware System** - Sistem middleware yang fleksibel dengan support pipeline
- **Pipeline Architecture** - Request processing pipeline dengan exception handling
- **Advanced Routing** - Router modern dengan support untuk:
  - Named routes
  - Route groups
  - API versioning
  - Subdomain routing
  - Resource routes
  - Rate limiting
- **Controller System** - Base controller dengan theme binding
- **Helper Functions** - URL dan routing helpers

## 📁 Struktur Package

```
http/
├── src/
│   ├── Controllers/
│   │   ├── BaseController.php
│   │   ├── Controller.php
│   │   └── RESTController.php
│   ├── Kernel/
│   │   ├── Kernel.php
│   │   └── Pipeline.php
│   ├── Middleware/
│   │   ├── Middleware.php
│   │   ├── MiddlewareGroup.php
│   │   ├── MiddlewareInterface.php
│   │   ├── MiddlewareRegistry.php
│   │   └── CallableMiddleware.php
│   ├── Requests/
│   │   ├── FormRequest.php
│   │   └── ValidatesRequests.php
│   ├── Routing/
│   │   ├── Router.php
│   │   ├── Route.php
│   │   ├── RouteCollection.php
│   │   ├── RouteItem.php
│   │   ├── RoutingManager.php
│   │   ├── UnifiedRouter.php
│   │   └── ...
│   ├── JsonResponse.php
│   ├── RedirectResponse.php
│   ├── Request.php
│   ├── Response.php
│   ├── ResponseFactory.php
│   ├── Uri.php
│   └── helpers/
│       ├── url.php
│       └── routing.php
├── tests/
│   └── Unit/
│       ├── RequestTest.php
│       ├── ResponseTest.php
│       ├── UriTest.php
│       ├── Kernel/
│       ├── Middleware/
│       ├── Routing/
│       └── Helpers/
├── composer.json
└── phpunit.xml
```

## 💡 Penggunaan

### Request

```php
use Kodhe\Framework\Http\Request;

// Membuat request dari globals
$request = Request::fromGlobals();

// Mengakses data
$getId = $request->get('id');
$postData = $request->post('data');
$method = $request->getMethod();
$isAjax = $request->isAjax();

// JSON body
$jsonData = $request->json();
```

### Response

```php
use Kodhe\Framework\Http\Response;

$response = new Response();

// Set status dan body
$response->setStatus(200);
$response->setBody('Hello World');

// Set headers
$response->setHeader('Content-Type', 'application/json');

// JSON response
$response->asJson(['message' => 'Success']);

// Redirect
$response->redirect('/dashboard');
```

### Middleware

```php
use Kodhe\Framework\Http\Middleware\Middleware;
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;

class AuthMiddleware extends Middleware
{
    public function handle(Request $request, callable $next)
    {
        // Check authentication
        if (!$this->isAuthenticated()) {
            return $this->response->setStatus(401)->setBody('Unauthorized');
        }
        
        return $next($request);
    }
    
    protected function isAuthenticated()
    {
        // Your auth logic here
        return true;
    }
}
```

### Pipeline

```php
use Kodhe\Framework\Http\Kernel\Pipeline;
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;

$pipeline = new Pipeline($request, $response);

// Add middlewares
$pipeline->pipe(AuthMiddleware::class)
         ->pipe(CorsMiddleware::class)
         ->pipeMany([
             RateLimitMiddleware::class,
             SanitizeMiddleware::class
         ]);

// Set handler
$pipeline->setHandler(function($request, $response, $params) {
    // Your controller logic
    return $response->setBody('Processed');
});

// Execute pipeline
$result = $pipeline->execute();
```

### Routing

```php
use Kodhe\Framework\Http\Routing\Router;

$router = new Router();

// Basic route
$router->get('/', 'HomeController@index');

// Named route
$router->get('/users', 'UserController@index')->name('users.index');

// Route with parameters
$router->get('/users/{id}', 'UserController@show');

// Route group with middleware
$router->group(['middleware' => ['auth']], function($router) {
    $router->get('/dashboard', 'DashboardController@index');
    $router->get('/profile', 'ProfileController@show');
});

// API routes with versioning
$router->apiGroup(['version' => 'v1'], function($router) {
    $router->get('/users', 'Api\UserController@index');
    $router->post('/users', 'Api\UserController@store');
});

// Resource route
$router->resource('posts', 'PostController');

// Generate URL for named route
$url = route('users.index');
$urlWithParams = route('users.show', ['id' => 1]);
```

### Helper Functions

#### URL Helpers

```php
// Base URL
$baseUrl = base_url();

// Full URL
$fullUrl = site_url('path/to/page');

// Current URL
$currentUrl = current_url();

// Previous URL
$prevUrl = previous_url();
```

#### Routing Helpers

```php
// Generate named route URL
$url = route('users.index');

// API route with version
$apiUrl = api_route('api.users', ['version' => 'v2']);

// Subdomain route
$subdomainUrl = subdomain_route('blog.post', 'blog', ['slug' => 'my-post']);
```

## 🧪 Testing

Jalankan unit tests menggunakan PHPUnit:

```bash
cd http
composer install
vendor/bin/phpunit
```

Atau dengan konfigurasi XML:

```bash
vendor/bin/phpunit --configuration phpunit.xml
```

## 📋 Class Overview

### Core Classes

| Class | Description |
|-------|-------------|
| `Request` | HTTP request handler dengan support GET, POST, JSON, files |
| `Response` | HTTP response builder dengan status codes dan headers |
| `Uri` | URI parser dan builder |
| `JsonResponse` | Specialized response untuk JSON |
| `RedirectResponse` | Specialized response untuk redirects |

### Kernel

| Class | Description |
|-------|-------------|
| `Pipeline` | Request processing pipeline |
| `Kernel` | Application kernel untuk bootstrap |

### Middleware

| Class | Description |
|-------|-------------|
| `Middleware` | Abstract base class untuk middleware |
| `MiddlewareInterface` | Interface untuk middleware |
| `MiddlewareGroup` | Grouping multiple middlewares |
| `MiddlewareRegistry` | Registry untuk middleware management |
| `CallableMiddleware` | Wrapper untuk closure-based middleware |

### Routing

| Class | Description |
|-------|-------------|
| `Router` | Main router dengan modern & legacy support |
| `Route` | Individual route definition |
| `RouteCollection` | Collection of routes |
| `RouteItem` | Route item dengan metadata |
| `RoutingManager` | Centralized routing management |
| `UnifiedRouter` | Advanced router dengan unified interface |
| `RateLimiter` | Rate limiting untuk routes |

### Controllers

| Class | Description |
|-------|-------------|
| `BaseController` | Base controller dengan theme binding |
| `Controller` | Standard controller implementation |
| `RESTController` | RESTful API controller base |

## 🔧 Konfigurasi

Router dapat dikonfigurasi dengan berbagai options:

```php
$router = new Router([
    'enable_modern_routing' => true,
    'enable_legacy_routing' => true,
    'prefer_modern' => true,
    'cache_routes' => true,
    'auto_detect_namespace' => true,
    'allow_namespace_in_routes' => true,
    'controller_suffix' => '',
    'default_404_controller' => 'FileNotFound',
    'default_404_namespace' => 'Kodhe\\Controllers\\Error\\'
]);
```

## 📄 License

MIT License - lihat file [LICENSE](LICENSE) untuk detail lengkap.

## 🤝 Contributing

Contributions are welcome! Please follow these steps:

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📞 Support

Untuk pertanyaan dan issue, silakan buat issue di repository atau hubungi tim development.

---

**Version:** 1.1.0  
**Package:** kodhe/http  
**Namespace:** `Kodhe\Framework\Http`
