<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing;

use Kodhe\Framework\Exceptions\Http\{
    NotFoundException,
    BadRequestException
};
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;

class Route
{
    /**
     * @var RouteCollection|null Collection instance
     */
    protected static $collection;

    /**
     * @var array Registered routes
     */
    protected static $routes = [
        'GET' => [],
        'POST' => [],
        'PUT' => [],
        'PATCH' => [],
        'DELETE' => [],
        'HEAD' => [],
        'OPTIONS' => [],
        'ANY' => []
    ];

    /**
     * @var array Named routes
     */
    public static $namedRoutes = [];

    /**
     * @var GroupHandler Group handler instance
     */
    protected static $groupHandler;

    /**
     * @var array Route patterns
     */
    protected static $patterns = [
        '{id}' => '([0-9]+)',
        '{slug}' => '([a-z0-9-]+)',
        '{uuid}' => '([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})',
        '{any}' => '(.+)',
        '{string}' => '([a-zA-Z]+)',
        '{alpha}' => '([a-zA-Z]+)',
        '{num}' => '([0-9]+)',
        '{alnum}' => '([a-zA-Z0-9]+)',
        '{subdomain}' => '([a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)',
        '{domain}' => '([a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*)',
    ];

    /**
     * @var array API version configurations
     */
    protected static $apiVersions = [];

    /**
     * @var string Current API version
     */
    protected static $currentApiVersion = '1';

    /**
     * @var array Subdomain routes registry
     */
    protected static $subdomainRoutes = [];

    /**
     * @var string Current subdomain
     */
    protected static $currentSubdomain = null;

    /**
     * @var array Domain configurations
     */
    protected static $domainConfigs = [];

    /**
     * Get group handler instance
     */
    protected static function getGroupHandler(): GroupHandler
    {
        if (!self::$groupHandler) {
            self::$groupHandler = new GroupHandler();
        }
        
        return self::$groupHandler;
    }

    /**
     * Set route collection instance
     */
    public static function setCollection(RouteCollection $collection): void
    {
        self::$collection = $collection;
    }

    /**
     * Get route collection instance
     */
    public static function getCollection(): ?RouteCollection
    {
        return self::$collection;
    }

    /**
     * Register a GET route
     */
    public static function get(string $uri, $action): RouteItem
    {
        return self::addRoute('GET', $uri, $action);
    }

    /**
     * Register a POST route
     */
    public static function post(string $uri, $action): RouteItem
    {
        return self::addRoute('POST', $uri, $action);
    }

    /**
     * Register a PUT route
     */
    public static function put(string $uri, $action): RouteItem
    {
        return self::addRoute('PUT', $uri, $action);
    }

    /**
     * Register a PATCH route
     */
    public static function patch(string $uri, $action): RouteItem
    {
        return self::addRoute('PATCH', $uri, $action);
    }

    /**
     * Register a DELETE route
     */
    public static function delete(string $uri, $action): RouteItem
    {
        return self::addRoute('DELETE', $uri, $action);
    }

    /**
     * Register a HEAD route
     */
    public static function head(string $uri, $action): RouteItem
    {
        return self::addRoute('HEAD', $uri, $action);
    }

    /**
     * Register an OPTIONS route
     */
    public static function options(string $uri, $action): RouteItem
    {
        return self::addRoute('OPTIONS', $uri, $action);
    }

    /**
     * Register a route for any HTTP method
     */
    public static function any(string $uri, $action): RouteItem
    {
        return self::addRoute('ANY', $uri, $action);
    }

    /**
     * Register dynamic routes
     */
    public static function dynamic(string $prefix, string $controller, array $options = []): void
    {
        DynamicRouteHandler::register($prefix, $controller, $options);
    }

    /**
     * Register a route for multiple HTTP methods
     */
    public static function match(array $methods, string $uri, $action): RouteItem
    {
        $route = null;
        
        foreach ($methods as $method) {
            $method = strtoupper($method);
            if ($route === null) {
                $route = self::addRoute($method, $uri, $action);
            } else {
                self::addRoute($method, $uri, $action);
            }
        }
        
        return $route;
    }

    /**
     * Create a route group
     */
    public static function group(array $attributes, callable $callback): void
    {
        $groupHandler = self::getGroupHandler();
        
        // Start new group
        $groupHandler->startGroup($attributes);
        
        try {
            // Execute callback
            call_user_func($callback);
        } finally {
            // Always end group, even if exception occurs
            $groupHandler->endGroup();
        }
    }

    /**
     * Create API version group
     */
    public static function apiVersion(string $version, callable $callback, array $options = []): void
    {
        $defaultOptions = [
            'prefix' => 'api/v' . $version,
            'middleware' => ['api'],
            'as' => 'api.v' . $version . '.',
            'default' => false,
            'deprecated' => false,
            'sunset' => null,
            'headers' => [
                'X-API-Version' => $version,
            ],
        ];
        
        $config = array_merge($defaultOptions, $options);
        
        // Store version config
        self::$apiVersions[$version] = $config;
        
        // Set as default if specified
        if ($config['default']) {
            self::$currentApiVersion = $version;
        }
        
        // Prepare group attributes
        $groupAttributes = [
            'prefix' => $config['prefix'],
            'middleware' => $config['middleware'],
            'as' => $config['as'],
            'api_version' => $version,
            'api_deprecated' => $config['deprecated'],
            'api_sunset' => $config['sunset'],
            'api_headers' => $config['headers'],
        ];
        
        // Create route group
        self::group($groupAttributes, $callback);
    }

    /**
     * Get current API version
     */
    public static function getCurrentApiVersion(): string
    {
        return self::$currentApiVersion;
    }

    /**
     * Set current API version
     */
    public static function setApiVersion(string $version): void
    {
        if (isset(self::$apiVersions[$version])) {
            self::$currentApiVersion = $version;
        }
    }

    /**
     * Get API version configurations
     */
    public static function getApiVersions(): array
    {
        return self::$apiVersions;
    }

    /**
     * Check if API version exists
     */
    public static function hasApiVersion(string $version): bool
    {
        return isset(self::$apiVersions[$version]);
    }

    /**
     * Create API resource with version
     */
    public static function apiVersionResource(string $version, string $name, string $controller, array $options = []): void
    {
        self::apiVersion($version, function() use ($name, $controller, $options) {
            self::apiResource($name, $controller, $options);
        });
    }

    /**
     * API fallback to latest version
     */
    public static function api(string $uri, $action): RouteItem
    {
        $version = self::getCurrentApiVersion();
        $prefix = self::$apiVersions[$version]['prefix'] ?? 'api/v1';
        
        $fullUri = '/' . trim($prefix, '/') . '/' . trim($uri, '/');
        
        return self::any($fullUri, $action)->middleware('api');
    }

    /**
     * Create domain route group
     */
    public static function domain(string $domain, callable $callback, array $attributes = []): void
    {
        $groupHandler = self::getGroupHandler();
        
        // Parse domain
        $parsedDomain = self::parseDomain($domain);
        
        // Prepare domain attributes
        $domainAttributes = array_merge([
            'domain' => $domain,
            'subdomain' => $parsedDomain['subdomain'],
            'full_domain' => $parsedDomain['full'],
            'host' => $parsedDomain['host'],
            'port' => $parsedDomain['port'],
            'tld' => $parsedDomain['tld'] ?? null,
        ], $attributes);
        
        // Store domain config
        self::$domainConfigs[] = [
            'domain' => $domain,
            'subdomain' => $parsedDomain['subdomain'],
            'callback' => $callback,
            'attributes' => $attributes,
        ];
        
        // Create domain group
        self::group($domainAttributes, $callback);
    }

    /**
     * Domain dengan TLD spesifik
     */
    public static function domainWithTld(string $subdomain, string $tld, callable $callback, array $attributes = []): void
    {
        $domain = $subdomain . '.example.' . $tld;
        self::domain($domain, $callback, array_merge($attributes, [
            'required_tld' => $tld,
        ]));
    }

    /**
     * Domain dengan multiple TLD options
     */
    public static function domainWithTlds(string $subdomain, array $tlds, callable $callback, array $attributes = []): void
    {
        foreach ($tlds as $tld) {
            $domain = $subdomain . '.example.' . $tld;
            self::domain($domain, $callback, array_merge($attributes, [
                'allowed_tlds' => $tlds,
            ]));
        }
    }

    /**
     * Parse domain string dengan TLD support
     */
    protected static function parseDomain(string $domain): array
    {
        $result = [
            'full' => $domain,
            'subdomain' => null,
            'host' => $domain,
            'port' => null,
            'tld' => null,
        ];
        
        // Extract port
        if (strpos($domain, ':') !== false) {
            list($host, $port) = explode(':', $domain, 2);
            $result['host'] = $host;
            $result['port'] = $port;
        }
        
        // Extract subdomain dan TLD
        $parts = explode('.', $result['host']);
        
        if (count($parts) > 2) {
            // Ada subdomain: admin.example.com
            $result['subdomain'] = $parts[0];
            $result['host'] = implode('.', array_slice($parts, 1));
            
            // Extract TLD dari host yang baru
            $hostParts = explode('.', $result['host']);
            if (count($hostParts) >= 2) {
                $result['tld'] = implode('.', array_slice($hostParts, 1));
            }
        } elseif (count($parts) === 2) {
            // example.com
            if ($parts[0] !== 'www') {
                $result['tld'] = $parts[1];
            }
        } elseif (count($parts) > 3) {
            // admin.example.co.id
            $result['subdomain'] = $parts[0];
            $result['tld'] = implode('.', array_slice($parts, 2));
        }
        
        return $result;
    }

    /**
     * Wildcard subdomain routing
     */
    public static function wildcardDomain(callable $callback): void
    {
        self::domain('{wildcard}', $callback);
    }

    /**
     * Wildcard domain dengan TLD constraint
     */
    public static function wildcardDomainWithTld(string $tld, callable $callback, array $attributes = []): void
    {
        self::domain('{wildcard}.example.' . $tld, $callback, array_merge($attributes, [
            'required_tld' => $tld,
        ]));
    }

    /**
     * Get subdomain from current group
     */
    public static function getCurrentSubdomain(): ?string
    {
        $groupHandler = self::getGroupHandler();
        $attributes = $groupHandler->getCurrentAttributes();
        
        return $attributes['subdomain'] ?? null;
    }

    /**
     * Get TLD from current group
     */
    public static function getCurrentTld(): ?string
    {
        $groupHandler = self::getGroupHandler();
        $attributes = $groupHandler->getCurrentAttributes();
        
        return $attributes['tld'] ?? null;
    }

    /**
     * Match subdomain dari request
     */
    protected static function matchSubdomain(Request $request): ?string
    {
        $host = $request->getUri()->getHost();
        
        // Remove port jika ada
        if (strpos($host, ':') !== false) {
            $host = explode(':', $host)[0];
        }
        
        $parts = explode('.', $host);
        
        if (count($parts) > 2) {
            // Assume subdomain adalah part pertama
            return $parts[0];
        }
        
        // Check for two-part domain with non-www first part
        if (count($parts) === 2 && $parts[0] !== 'www') {
            return $parts[0];
        }
        
        return null;
    }

    /**
     * Extract TLD dari host
     */
    protected static function extractTld(string $host): ?string
    {
        $parts = explode('.', $host);
        
        if (count($parts) < 2) {
            return null;
        }
        
        // Coba ambil 2-part TLD dulu (co.id, com.au, etc)
        if (count($parts) >= 3) {
            $twoPartTld = $parts[count($parts)-2] . '.' . $parts[count($parts)-1];
            
            // Common 2-part TLDs
            $twoPartTlds = ['co.id', 'ac.id', 'or.id', 'go.id', 'co.uk', 'org.uk', 
                           'com.au', 'org.au', 'net.au', 'edu.au', 'gov.au'];
            
            if (in_array($twoPartTld, $twoPartTlds)) {
                return $twoPartTld;
            }
        }
        
        // Return single part TLD
        return end($parts);
    }

    /**
     * Add route dengan support untuk nested groups
     */
    protected static function addRoute(string $method, string $uri, $action): RouteItem
    {
        $groupHandler = self::getGroupHandler();
        
        // Prepare route data
        $routeUri = $uri;
        $routeMiddleware = [];
        $routeNamespace = '';
        
        // Apply group attributes
        $groupHandler->applyToRoute($routeUri, $routeMiddleware, $routeNamespace);
        
        // Normalize URI
        $routeUri = '/' . trim($routeUri, '/');
        if ($routeUri === '') {
            $routeUri = '/';
        }
        
        // Create route item
        $routeItem = new RouteItem(
            $method,
            $routeUri,
            $action,
            $routeMiddleware,
            $routeNamespace
        );
        
        // Apply group name prefix
        $baseName = $routeItem->getName();
        if ($baseName) {
            $name = $groupHandler->applyNamePrefix($baseName);
            $routeItem->name($name);
        } elseif (empty($baseName) && !empty($groupHandler->getCurrentAttributes()['as'])) {
            // Generate name untuk route tanpa nama
            $generatedName = self::generateRouteName($routeUri);
            $name = $groupHandler->applyNamePrefix($generatedName);
            $routeItem->name($name);
        }
        
        // Apply group constraints
        $groupHandler->applyConstraints($routeItem);
        
        // Apply subdomain
        $groupHandler->applySubdomain($routeItem);
        
        // Apply API attributes
        $groupHandler->applyApiAttributes($routeItem);
        
        // Apply domain dari group jika ada
        $currentAttributes = $groupHandler->getCurrentAttributes();
        if (!empty($currentAttributes['domain'])) {
            $routeItem->domain($currentAttributes['domain'], [
                'required_tld' => $currentAttributes['tld'] ?? null,
            ]);
        }
        
        // Add to routes
        self::$routes[$method][$routeUri] = $routeItem;
        
        // Add to collection if exists
        if (self::$collection) {
            self::$collection->add($routeItem);
        }
        
        // Jika itu ANY route, add ke semua methods
        if ($method === 'ANY') {
            foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] as $httpMethod) {
                self::$routes[$httpMethod][$routeUri] = $routeItem;
                if (self::$collection) {
                    self::$collection->add($routeItem);
                }
            }
        }
        
        return $routeItem;
    }

    /**
     * Generate route name dari URI
     */
    protected static function generateRouteName(string $uri): string
    {
        // Clean URI
        $uri = trim($uri, '/');
        if (empty($uri)) {
            return 'home';
        }
        
        // Replace non-alphanumeric dengan dots
        $name = preg_replace('/[^a-zA-Z0-9]+/', '.', $uri);
        
        // Remove leading/trailing dots
        $name = trim($name, '.');
        
        // Convert to lowercase
        $name = strtolower($name);
        
        // Replace multiple dots dengan single dot
        $name = preg_replace('/\.+/', '.', $name);
        
        return $name;
    }

    /**
     * Find a route that matches the request
     */
    public static function matchRequest(Request $request): ?RouteItem
    {
        $method = $request->method();
        $uri = $request->getUri()->getPath();
        
        // Clean URI
        $uri = '/' . trim($uri, '/');
        if ($uri === '') {
            $uri = '/';
        }

        // Match subdomain
        $subdomain = self::matchSubdomain($request);
        $host = $request->getUri()->getHost();
        $tld = self::extractTld($host);

        // Filter routes by domain/subdomain
        $filteredRoutes = self::filterRoutesByDomain($method, $subdomain, $host, $tld);
        
        // Check exact match first
        if (isset($filteredRoutes[$uri])) {
            return $filteredRoutes[$uri];
        }

        // Check for pattern matching
        foreach ($filteredRoutes as $pattern => $route) {
            if ($route->matches($uri)) {
                return $route;
            }
        }

        // Jika tidak ketemu dengan domain constraint, coba global routes
        if ($subdomain !== null) {
            $globalRoutes = self::$routes[$method];
            
            foreach ($globalRoutes as $pattern => $route) {
                // Skip routes yang punya domain/subdomain constraint
                if ($route->hasDomainConstraint() || $route->hasSubdomainConstraint()) {
                    continue;
                }
                
                if ($route->matches($uri)) {
                    return $route;
                }
            }
        }

        return null;
    }

    /**
     * Filter routes by domain (enhanced dengan TLD)
     */
    protected static function filterRoutesByDomain(string $method, ?string $subdomain, string $host, ?string $tld): array
    {
        $filtered = [];
        
        if (!isset(self::$routes[$method])) {
            return $filtered;
        }
        
        foreach (self::$routes[$method] as $uri => $route) {
            $routeDomain = $route->getDomain();
            $routeSubdomain = $route->getSubdomain();
            $requiresTld = $route->requiresTld();
            
            // Jika route punya domain constraint
            if ($routeDomain !== null) {
                // Check domain match
                if (self::domainMatches($host, $routeDomain, $tld, $requiresTld)) {
                    $filtered[$uri] = $route;
                }
            }
            // Jika route punya subdomain constraint saja
            elseif ($routeSubdomain !== null) {
                // Handle wildcard subdomain
                if ($routeSubdomain === '*' || $routeSubdomain === '{wildcard}') {
                    if ($subdomain !== null) {
                        $filtered[$uri] = $route;
                    }
                }
                // Cocokkan subdomain exact
                elseif ($routeSubdomain === $subdomain) {
                    $filtered[$uri] = $route;
                }
            } else {
                // Route tanpa domain constraint
                // Hanya include jika tidak ada subdomain
                if ($subdomain === null) {
                    $filtered[$uri] = $route;
                }
            }
        }
        
        return $filtered;
    }

    /**
     * Check if domain matches route domain constraint
     */
    protected static function domainMatches(string $requestHost, string $routeDomain, ?string $requestTld, ?string $requiredTld): bool
    {
        // Exact match
        if ($requestHost === $routeDomain) {
            return true;
        }
        
        // Wildcard domain match
        if ($routeDomain === '*' || $routeDomain === '{wildcard}') {
            return true;
        }
        
        // Pattern match dengan wildcard subdomain
        if (strpos($routeDomain, '{') !== false) {
            $pattern = str_replace('{wildcard}', '([^\.]+)', $routeDomain);
            $pattern = str_replace('{subdomain}', '([^\.]+)', $pattern);
            $pattern = preg_quote($pattern, '#');
            $pattern = str_replace(['\{', '\}'], ['{', '}'], $pattern);
            $pattern = preg_replace('/\{[^}]+\}/', '([^\.]+)', $pattern);
            $pattern = '#^' . $pattern . '$#';
            
            return preg_match($pattern, $requestHost) === 1;
        }
        
        // TLD validation
        if ($requiredTld !== null && $requestTld !== $requiredTld) {
            return false;
        }
        
        return false;
    }

    /**
     * Get route by name
     */
    public static function getByName(string $name): ?RouteItem
    {
        return self::$namedRoutes[$name] ?? null;
    }

    /**
     * Generate URL for named route
     */
    public static function url(string $name, array $parameters = []): string
    {
        $route = self::getByName($name);
        
        if (!$route) {
            throw NotFoundException::resource('route', $name);
        }
        
        return $route->url($parameters);
    }

    /**
     * Get all registered routes
     */
    public static function getRoutes(): array
    {
        return self::$routes;
    }

    /**
     * Clear all routes
     */
    public static function clear(): void
    {
        self::$routes = [
            'GET' => [],
            'POST' => [],
            'PUT' => [],
            'PATCH' => [],
            'DELETE' => [],
            'HEAD' => [],
            'OPTIONS' => [],
            'ANY' => []
        ];
        self::$namedRoutes = [];
        
        // Reset group handler
        if (self::$groupHandler) {
            self::$groupHandler->reset();
        }
        
        self::$apiVersions = [];
        self::$domainConfigs = [];
        self::$currentApiVersion = '1';
        self::$currentSubdomain = null;
        
        RouteItem::clearCache();
    }

    /**
     * Register a route pattern
     */
    public static function pattern(string $key, string $pattern): void
    {
        self::$patterns['{' . $key . '}'] = $pattern;
    }

    /**
     * Register multiple route patterns
     */
    public static function patterns(array $patterns): void
    {
        foreach ($patterns as $key => $pattern) {
            self::pattern($key, $pattern);
        }
    }

    /**
     * Get route patterns
     */
    public static function getPatterns(): array
    {
        return self::$patterns;
    }

    /**
     * Resource routing
     */
    public static function resource(string $name, string $controller, array $options = []): void
    {
        $only = $options['only'] ?? ['index', 'show', 'create', 'store', 'edit', 'update', 'destroy'];
        $except = $options['except'] ?? [];
        $names = $options['names'] ?? [];
        $parameters = $options['parameters'] ?? [];

        $routes = [
            'index'   => ['GET', "/{$name}", "{$controller}@index"],
            'create'  => ['GET', "/{$name}/create", "{$controller}@create"],
            'store'   => ['POST', "/{$name}", "{$controller}@store"],
            'show'    => ['GET', "/{$name}/{{$name}}", "{$controller}@show"],
            'edit'    => ['GET', "/{$name}/{{$name}}/edit", "{$controller}@edit"],
            'update'  => ['PUT', "/{$name}/{{$name}}", "{$controller}@update"],
            'destroy' => ['DELETE', "/{$name}/{{$name}}", "{$controller}@destroy"],
        ];

        foreach ($routes as $action => $route) {
            if (in_array($action, $except, true)) {
                continue;
            }

            if (!empty($only) && !in_array($action, $only, true)) {
                continue;
            }

            $routeName = $names[$action] ?? "{$name}.{$action}";
            $parameterName = $parameters[$name] ?? $name;

            $method = $route[0];
            $uri = str_replace("{{$name}}", "{{$parameterName}}", $route[1]);
            $handler = $route[2];

            self::$method($uri, $handler)->name($routeName);
        }
    }

    /**
     * API Resource routing (without create/edit routes)
     */
    public static function apiResource(string $name, string $controller, array $options = []): void
    {
        $options['except'] = array_merge($options['except'] ?? [], ['create', 'edit']);
        self::resource($name, $controller, $options);
    }




    /**
     * Create module route group
     */
    public static function module(string $module, callable $callback, array $attributes = []): void
    {
        $defaultAttributes = [
            'prefix' => $module,
            'namespace' => 'App\\Modules\\' . ucfirst($module) . '\\Controllers\\',
            'as' => $module . '.',
            'module' => $module
        ];
        
        $groupAttributes = array_merge($defaultAttributes, $attributes);
        
        self::group($groupAttributes, $callback);
    }
    
    /**
     * Get current module dari group
     */
    public static function getCurrentModule(): ?string
    {
        $groupHandler = self::getGroupHandler();
        $attributes = $groupHandler->getCurrentAttributes();
        
        return $attributes['module'] ?? null;
    }


    /**
     * Register a fallback route
     */
    public static function fallback($action): RouteItem
    {
        return self::any('{any}', $action)->where('any', '.*');
    }

    /**
     * Get current group attributes
     */
    public static function getGroupAttributes(): array
    {
        $groupHandler = self::getGroupHandler();
        return $groupHandler->getCurrentAttributes();
    }

    /**
     * Get group handler instance
     */
    public static function getGroupHandlerInstance(): GroupHandler
    {
        return self::getGroupHandler();
    }

    /**
     * Check if currently in a group
     */
    public static function inGroup(): bool
    {
        $groupHandler = self::getGroupHandler();
        return $groupHandler->inGroup();
    }

    /**
     * Register view route
     */
    public static function view(string $uri, string $view, array $data = []): RouteItem
    {
        return self::get($uri, function() use ($view, $data) {
            return view($view, $data);
        });
    }

    /**
     * Register redirect route
     */
    public static function redirect(string $from, string $to, int $status = 302): RouteItem
    {
        return self::any($from, function() use ($to, $status) {
            return redirect($to, $status);
        });
    }

    /**
     * Register permanent redirect
     */
    public static function permanentRedirect(string $from, string $to): RouteItem
    {
        return self::redirect($from, $to, 301);
    }

    /**
     * Register all routes to collection
     */
    public static function registerToCollection(RouteCollection $collection): void
    {
        self::$collection = $collection;
        
        // Register existing routes
        foreach (self::$routes as $method => $methodRoutes) {
            foreach ($methodRoutes as $uri => $route) {
                if ($route instanceof RouteItem) {
                    $collection->add($route);
                }
            }
        }
    }

    /**
     * Get domain configurations
     */
    public static function getDomainConfigs(): array
    {
        return self::$domainConfigs;
    }

    /**
     * Generate API URL
     */
    public static function apiUrl(string $path = '', string $version = null, array $parameters = []): string
    {
        $version = $version ?? self::getCurrentApiVersion();
        $prefix = self::$apiVersions[$version]['prefix'] ?? 'api/v' . $version;
        
        $uri = '/' . trim($prefix, '/') . '/' . trim($path, '/');
        
        if (!empty($parameters)) {
            $query = http_build_query($parameters);
            $uri .= '?' . $query;
        }
        
        return url($uri);
    }

    /**
     * Generate subdomain URL
     */
    public static function subdomainUrl(string $subdomain, string $path = '', array $parameters = []): string
    {
        $baseUrl = config('app.url') ?? 'http://localhost';
        $parsed = parse_url($baseUrl);
        
        // Build host dengan subdomain
        $host = $subdomain . '.' . ($parsed['host'] ?? 'localhost');
        
        $url = ($parsed['scheme'] ?? 'http') . '://' . $host;
        
        if (isset($parsed['port'])) {
            $url .= ':' . $parsed['port'];
        }
        
        $url .= '/' . ltrim($path, '/');
        
        if (!empty($parameters)) {
            $query = http_build_query($parameters);
            $url .= '?' . $query;
        }
        
        return $url;
    }

    /**
     * Generate domain URL
     */
    public static function domainUrl(string $domain, string $path = '', array $parameters = []): string
    {
        $parsed = parse_url($domain);
        
        if (!isset($parsed['scheme'])) {
            $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
            $domain = $scheme . $domain;
        }
        
        $url = rtrim($domain, '/') . '/' . ltrim($path, '/');
        
        if (!empty($parameters)) {
            $query = http_build_query($parameters);
            $url .= '?' . $query;
        }
        
        return $url;
    }

    /**
     * Get route statistics
     */
    public static function getStats(): array
    {
        $stats = [
            'total_routes' => 0,
            'by_method' => [],
            'with_middleware' => 0,
            'with_rate_limit' => 0,
            'api_routes' => 0,
            'subdomain_routes' => 0,
            'domain_routes' => 0,
            'named_routes' => count(self::$namedRoutes),
        ];
        
        foreach (self::$routes as $method => $methodRoutes) {
            $stats['by_method'][$method] = count($methodRoutes);
            $stats['total_routes'] += count($methodRoutes);
            
            foreach ($methodRoutes as $route) {
                if (!empty($route->getMiddleware())) {
                    $stats['with_middleware']++;
                }
                
                if ($route->getRateLimit()) {
                    $stats['with_rate_limit']++;
                }
                
                if ($route->getApiVersion()) {
                    $stats['api_routes']++;
                }
                
                if ($route->getSubdomain()) {
                    $stats['subdomain_routes']++;
                }
                
                if ($route->getDomain()) {
                    $stats['domain_routes']++;
                }
            }
        }
        
        return $stats;
    }

    /**
     * Debug: Show all registered routes
     */
    public static function debug(): array
    {
        $debug = [];
        
        foreach (self::$routes as $method => $methodRoutes) {
            $debug[$method] = [];
            foreach ($methodRoutes as $uri => $route) {
                $debug[$method][$uri] = [
                    'uri' => $route->getUri(),
                    'action' => is_string($route->getAction()) ? $route->getAction() : gettype($route->getAction()),
                    'namespace' => $route->getNamespace(),
                    'middleware' => $route->getMiddleware(),
                    'name' => $route->getName(),
                    'subdomain' => $route->getSubdomain(),
                    'domain' => $route->getDomain(),
                    'tld_required' => $route->requiresTld(),
                ];
            }
        }
        
        return $debug;
    }

    /**
     * Debug: Show group state
     */
    public static function debugGroups(): array
    {
        $groupHandler = self::getGroupHandler();
        return $groupHandler->debug();
    }

    /**
     * Test method untuk verify nested groups dan domain
     */
    public static function testNestedGroups(): array
    {
        // Clear existing routes untuk test
        self::clear();
        
        // Test 1: Simple nested groups
        self::group(['prefix' => 'api', 'as' => 'api.'], function() {
            self::group(['prefix' => 'v1', 'as' => 'v1.'], function() {
                self::get('users', 'UserController@index')->name('users.index');
                self::get('users/{id}', 'UserController@show')->name('users.show');
            });
        });
        
        // Test 2: Complex nested dengan middleware
        self::group(['prefix' => 'admin', 'middleware' => ['auth']], function() {
            self::group(['prefix' => 'users', 'as' => 'users.', 'middleware' => ['admin']], function() {
                self::get('/', 'Admin\UserController@index')->name('index');
                
                self::group(['prefix' => '{user}'], function() {
                    self::get('/', 'Admin\UserController@show')->name('show');
                    self::get('edit', 'Admin\UserController@edit')->name('edit');
                });
            });
        });
        
        // Test 3: Triple nested groups
        self::group(['prefix' => 'shop'], function() {
            self::group(['prefix' => 'categories'], function() {
                self::group(['prefix' => '{category}'], function() {
                    self::get('products', 'ShopController@categoryProducts');
                });
            });
        });
        
        // Test 4: Original case dari user
        self::group(['prefix' => 'users'], function() {
            self::group(['prefix' => 'data'], function() {
                self::get('/', 'App\Modules\Users\Controllers\Users@index_view')->name('data.index');
            });
            self::get('/', 'App\Modules\Users\Controllers\Users@index_view')->name('dashboard');
        });
        
        // Test 5: Domain dengan TLD
        self::domain('admin.example.com', function() {
            self::get('dashboard', 'AdminController@dashboard')->name('dashboard');
        });
        
        self::domain('admin.example.co.id', function() {
            self::get('dashboard', 'AdminIdController@dashboard')->name('dashboard.id');
        });
        
        // Test 6: Wildcard domain
        self::wildcardDomainWithTld('com', function($tenant) {
            self::get('/', 'TenantController@show')->name('tenant');
        });
        
        $results = [];
        
        // Check expected routes
        $expectedRoutes = [
            '/api/v1/users' => 'api.v1.users.index',
            '/api/v1/users/{id}' => 'api.v1.users.show',
            '/admin/users' => 'admin.users.index',
            '/admin/users/{user}' => 'admin.users.show',
            '/admin/users/{user}/edit' => 'admin.users.edit',
            '/shop/categories/{category}/products' => null,
            '/users/data' => 'users.data.index',
            '/users' => 'users.dashboard',
        ];
        
        foreach ($expectedRoutes as $uri => $expectedName) {
            foreach (self::$routes as $method => $methodRoutes) {
                if (isset($methodRoutes[$uri])) {
                    $route = $methodRoutes[$uri];
                    $actualName = $route->getName();
                    $results[$uri] = [
                        'expected_name' => $expectedName,
                        'actual_name' => $actualName,
                        'match' => $actualName === $expectedName,
                        'method' => $method,
                        'action' => $route->getAction(),
                    ];
                    break;
                }
            }
            
            // Jika tidak ditemukan di exact URI, cari pattern match
            if (!isset($results[$uri])) {
                foreach (self::$routes as $method => $methodRoutes) {
                    foreach ($methodRoutes as $pattern => $route) {
                        if (strpos($pattern, '{') !== false && $route->matches($uri)) {
                            $actualName = $route->getName();
                            $results[$uri] = [
                                'expected_name' => $expectedName,
                                'actual_name' => $actualName,
                                'match' => $actualName === $expectedName,
                                'method' => $method,
                                'pattern' => $pattern,
                                'action' => $route->getAction(),
                            ];
                            break 2;
                        }
                    }
                }
            }
        }
        
        // Check domain routes
        $domainRoutes = [];
        foreach (self::$routes as $method => $methodRoutes) {
            foreach ($methodRoutes as $uri => $route) {
                if ($route->getDomain()) {
                    $domainRoutes[] = [
                        'uri' => $uri,
                        'domain' => $route->getDomain(),
                        'tld_required' => $route->requiresTld(),
                        'name' => $route->getName(),
                    ];
                }
            }
        }
        
        return [
            'test_results' => $results,
            'domain_routes' => $domainRoutes,
            'all_routes' => self::debug(),
            'named_routes' => self::$namedRoutes,
            'group_state' => self::debugGroups(),
        ];
    }
}
