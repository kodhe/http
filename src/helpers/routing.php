<?php

use Kodhe\Framework\Http\Routing\Route;

if (!function_exists('route')) {
    /**
     * Generate URL for named route
     */
    function route(string $name, array $parameters = []): string
    {
        return Route::url($name, $parameters);
    }
}

if (!function_exists('api_route')) {
    /**
     * Generate API route URL
     */
    function api_route(string $name, array $parameters = [], string $version = null): string
    {
        $route = Route::getByName($name);
        
        if (!$route) {
            throw new \InvalidArgumentException("Route [{$name}] not found.");
        }
        
        // Add API version prefix jika diperlukan
        if ($version) {
            $currentVersion = $route->getApiVersion();
            if ($currentVersion !== $version) {
                // Replace version in route
                $path = $route->getUri();
                $pattern = '#/api/v\d+(\.\d+)?/#';
                $replacement = "/api/v{$version}/";
                $path = preg_replace($pattern, $replacement, $path);
                
                // Build URL dengan path yang sudah diubah
                return url($path, $parameters);
            }
        }
        
        return $route->url($parameters);
    }
}

if (!function_exists('subdomain_route')) {
    /**
     * Generate subdomain route URL
     */
    function subdomain_route(string $name, string $subdomain, array $parameters = []): string
    {
        $route = Route::getByName($name);
        
        if (!$route) {
            throw new \InvalidArgumentException("Route [{$name}] not found.");
        }
        
        // Get base URL
        $url = $route->url($parameters);
        
        // Inject subdomain
        $parsed = parse_url($url);
        
        if (!isset($parsed['host'])) {
            return $url;
        }
        
        $host = $parsed['host'];
        $parts = explode('.', $host);
        
        if (count($parts) >= 3) {
            // Replace existing subdomain
            $parts[0] = $subdomain;
        } else {
            // Add subdomain
            array_unshift($parts, $subdomain);
        }
        
        $parsed['host'] = implode('.', $parts);
        
        return build_url($parsed);
    }
}

if (!function_exists('build_url')) {
    /**
     * Build URL from parts
     */
    function build_url(array $parts): string
    {
        $url = '';
        
        if (isset($parts['scheme'])) {
            $url .= $parts['scheme'] . '://';
        }
        
        if (isset($parts['host'])) {
            $url .= $parts['host'];
        }
        
        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }
        
        if (isset($parts['path'])) {
            $url .= $parts['path'];
        }
        
        if (isset($parts['query'])) {
            $url .= '?' . $parts['query'];
        }
        
        if (isset($parts['fragment'])) {
            $url .= '#' . $parts['fragment'];
        }
        
        return $url;
    }
}

if (!function_exists('current_route')) {
    /**
     * Get current route
     */
    function current_route(): ?\Kodhe\Framework\Routing\RouteItem
    {
        $request = app('request');
        return Route::matchRequest($request);
    }
}

if (!function_exists('current_route_name')) {
    /**
     * Get current route name
     */
    function current_route_name(): ?string
    {
        $route = current_route();
        return $route ? $route->getName() : null;
    }
}

if (!function_exists('route_has')) {
    /**
     * Check if route has middleware
     */
    function route_has(string $middleware): bool
    {
        $route = current_route();
        
        if (!$route) {
            return false;
        }
        
        $middlewares = $route->getMiddleware();
        
        foreach ($middlewares as $mw) {
            if (strpos($mw, $middleware) === 0) {
                return true;
            }
        }
        
        return false;
    }
}

if (!function_exists('route_is')) {
    /**
     * Check if current route matches pattern
     */
    function route_is(string $pattern): bool
    {
        $route = current_route();
        
        if (!$route) {
            return false;
        }
        
        $uri = $route->getUri();
        
        // Simple pattern matching
        if ($pattern === $uri) {
            return true;
        }
        
        // Wildcard matching
        if (strpos($pattern, '*') !== false) {
            $regex = '#^' . str_replace('*', '.*', $pattern) . '$#';
            return preg_match($regex, $uri) === 1;
        }
        
        return false;
    }
}

if (!function_exists('route_parameters')) {
    /**
     * Get current route parameters
     */
    function route_parameters(): array
    {
        $route = current_route();
        return $route ? $route->getParameters() : [];
    }
}

if (!function_exists('route_parameter')) {
    /**
     * Get specific route parameter
     */
    function route_parameter(string $name, $default = null)
    {
        $params = route_parameters();
        return $params[$name] ?? $default;
    }
}

if (!function_exists('rate_limit_info')) {
    /**
     * Get rate limit information for current route
     */
    function rate_limit_info(): ?array
    {
        $route = current_route();
        
        if (!$route) {
            return null;
        }
        
        $rateLimit = $route->getRateLimit();
        
        if (!$rateLimit) {
            return null;
        }
        
        // Get current attempts from limiter
        $limiter = app('rate_limiter');
        $key = 'route:' . $route->getRouteKey() . ':ip:' . request()->ip();
        
        return [
            'limit' => $rateLimit['max_attempts'],
            'remaining' => $limiter->remaining($key, $rateLimit['max_attempts']),
            'reset' => $limiter->availableIn($key),
        ];
    }
}

if (!function_exists('api_version')) {
    /**
     * Get current API version
     */
    function api_version(): string
    {
        return Route::getCurrentApiVersion();
    }
}

if (!function_exists('subdomain')) {
    /**
     * Get current subdomain
     */
    function subdomain(): ?string
    {
        $request = request();
        $host = $request->getUri()->getHost();
        
        // Remove port if exists
        if (strpos($host, ':') !== false) {
            $host = explode(':', $host)[0];
        }
        
        $parts = explode('.', $host);
        
        if (count($parts) >= 3) {
            return $parts[0];
        }
        
        return null;
    }
}

if (!function_exists('route_list')) {
    /**
     * Get list of all registered routes
     */
    function route_list(bool $grouped = true): array
    {
        $routes = Route::getRoutes();
        $list = [];
        
        foreach ($routes as $method => $methodRoutes) {
            foreach ($methodRoutes as $uri => $route) {
                $item = [
                    'method' => $method,
                    'uri' => $uri,
                    'name' => $route->getName(),
                    'action' => $route->getAction(),
                    'middleware' => $route->getMiddleware(),
                    'rate_limit' => $route->getRateLimit(),
                    'api_version' => $route->getApiVersion(),
                    'subdomain' => $route->getSubdomain(),
                ];
                
                if ($grouped) {
                    $list[$method][] = $item;
                } else {
                    $list[] = $item;
                }
            }
        }
        
        return $list;
    }
}