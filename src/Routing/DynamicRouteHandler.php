<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing;

use Kodhe\Framework\Exceptions\Http\{
    BadRequestException,
    NotFoundException,
    ForbiddenException,
    ValidationException,
    MethodNotAllowedException,
    UnauthorizedException
};
use Kodhe\Framework\Http\Response;

class DynamicRouteHandler
{
    /**
     * @var array Dynamic route configurations
     */
    protected static $configurations = [];

    /**
     * @var array Route patterns cache
     */
    protected static $patternsCache = [];

    /**
     * @var array Closure cache untuk menghindari serialization issues
     */
    protected static $closureCache = [];
    
    /**
     * Register dynamic routes for a controller
     */
    public static function register(string $prefix, string $controller, array $options = []): void
    {
        $config = array_merge([
            'max_params' => 4,
            'http_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
            'parameter_pattern' => '[^/]+',
            'default_method' => 'index',
            'exclude_methods' => ['__construct', '__destruct'],
            'namespace' => 'App\\Controllers\\',
            'middleware' => [],
            'rate_limit' => null,
            'api_version' => null,
            'subdomain' => null,
        ], $options);

        // Store configuration
        self::$configurations[$prefix] = $config;

        // Register routes
        self::registerRoutes($prefix, $controller, $config);
    }

    /**
     * Register all dynamic routes
     */
    protected static function registerRoutes(string $prefix, string $controller, array $config): void
    {
        // Clean prefix
        $prefix = '/' . trim($prefix, '/');
        
        // Apply subdomain jika ada
        if (!empty($config['subdomain'])) {
            Route::domain($config['subdomain'], function() use ($prefix, $controller, $config) {
                self::registerRoutesForDomain($prefix, $controller, $config);
            });
        } else {
            self::registerRoutesForDomain($prefix, $controller, $config);
        }
    }

    /**
     * Register routes for specific domain
     */
    protected static function registerRoutesForDomain(string $prefix, string $controller, array $config): void
    {
        // Apply API version jika ada
        if (!empty($config['api_version'])) {
            Route::apiVersion($config['api_version'], function() use ($prefix, $controller, $config) {
                self::registerActualRoutes($prefix, $controller, $config);
            });
        } else {
            self::registerActualRoutes($prefix, $controller, $config);
        }
    }

    /**
     * Register actual routes
     */
    protected static function registerActualRoutes(string $prefix, string $controller, array $config): void
    {
        // Default route: /prefix
        $defaultRoute = Route::match($config['http_methods'], $prefix, "{$controller}@{$config['default_method']}")
            ->middleware($config['middleware']);

        // Apply rate limit jika ada
        if (!empty($config['rate_limit'])) {
            if (is_array($config['rate_limit'])) {
                $defaultRoute->limit(
                    $config['rate_limit']['max_attempts'] ?? 60,
                    $config['rate_limit']['decay_minutes'] ?? 1,
                    $config['rate_limit']['key_by'] ?? 'ip'
                );
            } else {
                $defaultRoute->limit($config['rate_limit']);
            }
        }

        // Dynamic routes with parameters
        for ($i = 0; $i <= $config['max_params']; $i++) {
            self::registerParamRoute($prefix, $controller, $i, $config);
        }
    }

    /**
     * Register a route with specific number of parameters
     */
    protected static function registerParamRoute(string $prefix, string $controller, int $paramCount, array $config): void
    {
        $routePattern = $prefix . '/{method}';
        
        // Add parameter placeholders
        for ($i = 1; $i <= $paramCount; $i++) {
            $routePattern .= '/{p' . $i . '}';
        }

        // Create closure untuk route handler
        // Gunakan cache untuk menghindari pembuatan closure berulang
        $cacheKey = md5($controller . ':' . $paramCount . ':' . json_encode($config));
        
        if (!isset(self::$closureCache[$cacheKey])) {
            self::$closureCache[$cacheKey] = function(string $method, ...$params) use ($controller, $config) {
                return self::execute($controller, $method, $params, $config);
            };
        }
        
        $handler = self::$closureCache[$cacheKey];

        $route = Route::match($config['http_methods'], $routePattern, $handler)
            ->middleware($config['middleware'])
            ->where('method', '[a-zA-Z][a-zA-Z0-9_]*');

        // Apply rate limit jika ada
        if (!empty($config['rate_limit'])) {
            if (is_array($config['rate_limit'])) {
                $route->limit(
                    $config['rate_limit']['max_attempts'] ?? 60,
                    $config['rate_limit']['decay_minutes'] ?? 1,
                    $config['rate_limit']['key_by'] ?? 'ip'
                );
            } else {
                $route->limit($config['rate_limit']);
            }
        }
    }

    /**
     * Execute dynamic controller method
     */
    public static function execute(string $controller, string $method, array $params = [], array $config = null): Response
    {
        // Get configuration if not provided
        if ($config === null) {
            $config = self::findConfigForController($controller);
        }

        // Validate method name
        if (!self::isValidMethodName($method)) {
            throw BadRequestException::malformedRequest('Invalid method name');
        }

        // Add namespace if needed
        $fullController = self::resolveControllerName($controller, $config['namespace'] ?? '');

        // Check controller exists
        if (!class_exists($fullController)) {
            throw NotFoundException::resource('controller', $fullController);
        }

        // Check if method should be excluded
        if (in_array($method, $config['exclude_methods'] ?? [])) {
            throw NotFoundException::resource('method', $method . ' (method excluded from access)');
        }

        try {
            $instance = new $fullController();
            
            // Validate method exists and is callable
            if (!method_exists($instance, $method) || !is_callable([$instance, $method])) {
                throw NotFoundException::resource('method', $method . ' in ' . $fullController);
            }

            // Check method accessibility dengan reflection
            $reflection = new \ReflectionMethod($instance, $method);
            if ($reflection->isPrivate() || $reflection->isProtected()) {
                throw ForbiddenException::insufficientPermissions('Method ' . $method . ' is not accessible');
            }

            // Execute method
            $result = call_user_func_array([$instance, $method], $params);

            // Convert to Response if needed
            return self::normalizeResponse($result);

        } catch (\Kodhe\Framework\Exceptions\Http\HttpException $e) {
            // Re-throw HTTP exceptions
            throw $e;
        } catch (\Exception $e) {
            // Wrap other exceptions in BadRequestException
            throw new BadRequestException('Dynamic route execution error: ' . $e->getMessage());
        }
    }

    /**
     * Validate method name
     */
    protected static function isValidMethodName(string $method): bool
    {
        // Check for PHP valid function name
        if (preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $method) !== 1) {
            return false;
        }

        // Check for reserved keywords
        $reserved = [
            'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
            'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do',
            'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach',
            'endif', 'endswitch', 'endwhile', 'eval', 'exit', 'extends', 'final',
            'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements',
            'include', 'include_once', 'instanceof', 'insteadof', 'interface',
            'isset', 'list', 'namespace', 'new', 'or', 'print', 'private',
            'protected', 'public', 'require', 'require_once', 'return', 'static',
            'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var', 'while',
            'xor', 'yield', 'yield from'
        ];

        if (in_array(strtolower($method), $reserved)) {
            return false;
        }

        return true;
    }

    /**
     * Resolve controller name with namespace
     */
    protected static function resolveControllerName(string $controller, string $defaultNamespace): string
    {
        if (class_exists($controller)) {
            return $controller;
        }

        if (strpos($controller, '\\') === false && !empty($defaultNamespace)) {
            return rtrim($defaultNamespace, '\\') . '\\' . $controller;
        }

        return $controller;
    }

    /**
     * Normalize response to Response object
     */
    protected static function normalizeResponse($result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        $response = new Response();
        
        if (is_array($result) || is_object($result)) {
            $response->setHeader('Content-Type', 'application/json');
            $response->setBody(json_encode($result));
        } else {
            $response->setBody((string) $result);
        }
        
        return $response;
    }

    /**
     * Find configuration for controller
     */
    protected static function findConfigForController(string $controller): array
    {
        foreach (self::$configurations as $config) {
            if (strpos($controller, $config['namespace']) === 0) {
                return $config;
            }
        }

        return [
            'namespace' => 'App\\Controllers\\',
            'exclude_methods' => ['__construct', '__destruct'],
            'max_params' => 4,
        ];
    }

    /**
     * Get all registered configurations
     */
    public static function getConfigurations(): array
    {
        return self::$configurations;
    }

    /**
     * Clear configurations cache
     */
    public static function clearCache(): void
    {
        self::$configurations = [];
        self::$patternsCache = [];
        self::$closureCache = [];
    }

    /**
     * Register dynamic routes with rate limiting
     */
    public static function registerWithLimit(string $prefix, string $controller, int $maxAttempts, int $decayMinutes = 1, array $options = []): void
    {
        $options['rate_limit'] = [
            'max_attempts' => $maxAttempts,
            'decay_minutes' => $decayMinutes,
            'key_by' => 'ip',
        ];
        
        self::register($prefix, $controller, $options);
    }

    /**
     * Register dynamic routes for API version
     */
    public static function registerForApi(string $prefix, string $controller, string $version, array $options = []): void
    {
        $options['api_version'] = $version;
        self::register($prefix, $controller, $options);
    }

    /**
     * Register dynamic routes for subdomain
     */
    public static function registerForSubdomain(string $prefix, string $controller, string $subdomain, array $options = []): void
    {
        $options['subdomain'] = $subdomain;
        self::register($prefix, $controller, $options);
    }
}
