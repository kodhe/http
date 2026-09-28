<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing;

use Kodhe\Framework\Exceptions\Http\{
    NotFoundException,
    BadRequestException,
    ForbiddenException,
    TooManyRequestsException
};
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;

class RouteItem
{
    /**
     * @var string HTTP method
     */
    protected $method;

    /**
     * @var string URI pattern
     */
    protected $uri;

    /**
     * @var mixed Route action (closure, string, array)
     */
    protected $action;

    /**
     * @var array Middleware
     */
    protected $middleware = [];

    /**
     * @var string|null Route name
     */
    protected $name;

    /**
     * @var array Route parameters
     */
    protected $parameters = [];

    /**
     * @var array Parameter patterns
     */
    protected $patterns = [];

    /**
     * @var string Namespace
     */
    protected $namespace = '';

    /**
     * @var string Compiled regex pattern
     */
    protected $compiledPattern;

    /**
     * @var array Parameter names
     */
    protected $parameterNames = [];

    /**
     * @var array|null Rate limiting configuration
     */
    protected $rateLimit = null;

    /**
     * @var string Rate limiter key
     */
    protected $rateLimitKey = null;

    /**
     * @var array API-specific attributes
     */
    protected $apiAttributes = [];

    /**
     * @var string|null Subdomain constraint
     */
    protected $subdomain = null;

    /**
     * @var bool Whether subdomain is wildcard
     */
    protected $subdomainWildcard = false;

    /**
     * @var string|null Domain constraint
     */
    protected $domain = null;

    /**
     * @var array Domain attributes
     */
    protected $domainAttributes = [];

    /**
     * @var array Compiled pattern cache
     */
    protected static $compiledCache = [];

    /**
     * Constructor
     */
    public function __construct(string $method, string $uri, $action, array $middleware = [], string $namespace = '')
    {
        $this->method = $method;
        $this->uri = $uri;
        $this->action = $action;
        $this->middleware = $middleware;
        $this->namespace = $namespace;
        
        $this->compilePattern();
    }

    /**
     * Compile URI pattern to regex
     */
    protected function compilePattern(): void
    {
        $cacheKey = $this->getRouteKey();
        
        // Check cache
        if (isset(self::$compiledCache[$cacheKey])) {
            $this->compiledPattern = self::$compiledCache[$cacheKey]['pattern'];
            $this->parameterNames = self::$compiledCache[$cacheKey]['params'];
            return;
        }
        
        $patterns = Route::getPatterns();
        
        // Extract parameter names
        preg_match_all('/\{([^}]+)\}/', $this->uri, $matches);
        $this->parameterNames = $matches[1];
        
        // Replace patterns with regex
        $pattern = preg_quote($this->uri, '#');
        $pattern = str_replace(['\{', '\}'], ['{', '}'], $pattern);
        
        foreach ($patterns as $key => $regex) {
            if (isset($this->patterns[$key])) {
                $regex = $this->patterns[$key];
            }
            $pattern = str_replace($key, '(' . $regex . ')', $pattern);
        }
        
        // Replace any remaining parameters with default pattern
        $pattern = preg_replace('/\{([^}]+)\}/', '([^/]+)', $pattern);
        
        $this->compiledPattern = '#^' . $pattern . '$#';
        
        // Cache the compiled pattern
        self::$compiledCache[$cacheKey] = [
            'pattern' => $this->compiledPattern,
            'params' => $this->parameterNames,
            'timestamp' => time(),
        ];
    }

    public function matches(string $uri): bool
    {
        $uri = '/' . trim($uri, '/');
        if ($uri === '') {
            $uri = '/';
        }
        
        // Try direct match
        if (preg_match($this->compiledPattern, $uri, $matches)) {
            // Extract parameters
            array_shift($matches);
            
            if (count($matches) === count($this->parameterNames)) {
                $this->parameters = array_combine($this->parameterNames, $matches);
            } else {
                $this->parameters = $matches;
            }
            
            return true;
        }
        
        return false;
    }

    /**
     * Set route name
     */
    public function name(string $name): self
    {
        $this->name = $name;
        Route::$namedRoutes[$name] = $this;
        return $this;
    }

    /**
     * Add middleware to route
     */
    public function middleware($middleware): self
    {
        $middlewares = is_array($middleware) ? $middleware : func_get_args();
        
        $this->middleware = array_merge($this->middleware, $middlewares);
        return $this;
    }

    /**
     * Add parameter pattern
     */
    public function where(string $parameter, string $pattern): self
    {
        $this->patterns['{' . $parameter . '}'] = $pattern;
        $this->compilePattern();
        return $this;
    }

    /**
     * Add multiple parameter patterns
     */
    public function whereArray(array $patterns): self
    {
        foreach ($patterns as $parameter => $pattern) {
            $this->where($parameter, $pattern);
        }
        return $this;
    }

    /**
     * Set rate limit for this route
     */
    public function limit(int $maxAttempts, int $decayMinutes = 1, string $keyBy = 'ip'): self
    {
        $this->rateLimit = [
            'max_attempts' => $maxAttempts,
            'decay_minutes' => $decayMinutes,
            'key_by' => $keyBy,
        ];
        
        $this->middleware('throttle:' . $maxAttempts . ',' . $decayMinutes . ',' . $keyBy);
        
        return $this;
    }

    /**
     * Set custom rate limit key
     */
    public function limitKey(string $key): self
    {
        $this->rateLimitKey = $key;
        return $this;
    }

    /**
     * Mark route as API endpoint
     */
    public function api(): self
    {
        $this->middleware('api');
        return $this;
    }

    /**
     * Set API version for this route
     */
    public function version(string $version): self
    {
        $this->apiAttributes['version'] = $version;
        $this->middleware('api.version:' . $version);
        return $this;
    }

    /**
     * Mark API as deprecated
     */
    public function deprecated(string $sunset = null): self
    {
        $this->apiAttributes['deprecated'] = true;
        $this->apiAttributes['sunset'] = $sunset;
        
        $middleware = 'api.deprecated';
        if ($sunset) {
            $middleware .= ':' . $sunset;
        }
        
        $this->middleware($middleware);
        return $this;
    }

    /**
     * Set API response format
     */
    public function format(string $format): self
    {
        $this->apiAttributes['format'] = $format;
        $this->middleware('api.format:' . $format);
        return $this;
    }

    /**
     * Set subdomain for route
     */
    public function subdomain(string $subdomain): self
    {
        $this->subdomain = $subdomain;
        
        if ($subdomain === '*' || $subdomain === '{wildcard}') {
            $this->subdomainWildcard = true;
            $this->middleware('subdomain:wildcard');
        } else {
            $this->middleware('subdomain:' . $subdomain);
        }
        
        return $this;
    }

    /**
     * Set domain untuk route dengan TLD support
     */
    public function domain(string $domain, array $attributes = []): self
    {
        $this->domain = $domain;
        $this->domainAttributes = $attributes;
        
        // Parse domain untuk ekstrak info
        $parsed = $this->parseDomainWithTld($domain);
        
        if ($parsed['subdomain']) {
            $this->subdomain($parsed['subdomain']);
        }
        
        if ($parsed['has_tld_constraint']) {
            $this->domainAttributes['required_tld'] = $parsed['tld'];
        }
        
        // Add domain middleware
        $this->middleware('domain:' . $domain);
        
        return $this;
    }

    /**
     * Set domain dengan TLD spesifik
     */
    public function domainWithTld(string $subdomain, string $tld, array $attributes = []): self
    {
        $domain = $subdomain . '.example.' . $tld; // example bisa diganti
        return $this->domain($domain, array_merge($attributes, [
            'required_tld' => $tld,
        ]));
    }

    /**
     * Parse domain dengan TLD support
     */
    protected function parseDomainWithTld(string $domain): array
    {
        $result = [
            'full_domain' => $domain,
            'host' => $domain,
            'port' => null,
            'subdomain' => null,
            'domain' => null,
            'tld' => null,
            'has_tld_constraint' => false,
        ];
        
        // Extract port
        if (strpos($domain, ':') !== false) {
            list($host, $port) = explode(':', $domain, 2);
            $result['host'] = $host;
            $result['port'] = $port;
        }
        
        $host = $result['host'];
        
        // Parse domain parts
        $parts = explode('.', $host);
        
        if (count($parts) === 1) {
            // Single part: example
            $result['domain'] = $parts[0];
        } elseif (count($parts) === 2) {
            // Two parts: example.com
            $result['domain'] = $parts[0];
            $result['tld'] = $parts[1];
            $result['has_tld_constraint'] = true;
        } elseif (count($parts) > 2) {
            // Multiple parts: admin.example.com
            $result['subdomain'] = $parts[0];
            $result['domain'] = $parts[1];
            
            // Get TLD (bisa multi-part seperti co.id)
            $tldParts = array_slice($parts, 2);
            $result['tld'] = implode('.', $tldParts);
            $result['has_tld_constraint'] = true;
        }
        
        return $result;
    }

    /**
     * Get route parameters
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Get parameter value
     */
    public function getParameter(string $name, $default = null)
    {
        return $this->parameters[$name] ?? $default;
    }

    /**
     * Run the route 
     */
    public function run(Request $request, Response $response): mixed
    {
        try {
            // Check rate limit jika ada direct limit configuration
            if ($this->rateLimit && !$this->checkRateLimit($request)) {
                throw TooManyRequestsException::create($this->getRateLimitRetryAfter());
            }
            
            // Handle closure routes
            if ($this->action instanceof \Closure) {
                return $this->executeClosure($this->action, $request, $response);
            }
            
            // Handle controller routes (string or array)
            if (is_string($this->action) || is_array($this->action)) {
                // Return routing info for ControllerExecutor to handle
                return $this->getRoutingInfo();
            }
            
            throw new BadRequestException("Unsupported action type: " . gettype($this->action));
            
        } catch (\Kodhe\Framework\Exceptions\Http\HttpException $e) {
            // Re-throw HTTP exceptions
            throw $e;
        } catch (\Exception $e) {
            throw new BadRequestException('Route execution error: ' . $e->getMessage());
        }
    }

    /**
     * Check rate limit before executing route
     */
    protected function checkRateLimit(Request $request): bool
    {
        if (!$this->rateLimit) {
            return true;
        }
        
        $limiter = app('rate_limiter');
        $key = $this->generateRateLimitKey($request);
        $maxAttempts = $this->rateLimit['max_attempts'];
        $decaySeconds = $this->rateLimit['decay_minutes'] * 60;
        
        if ($limiter->tooManyAttempts($key, $maxAttempts)) {
            return false;
        }
        
        $limiter->hit($key, $decaySeconds);
        return true;
    }

    /**
     * Generate rate limit key
     */
    protected function generateRateLimitKey(Request $request): string
    {
        if ($this->rateLimitKey) {
            return $this->rateLimitKey;
        }
        
        $keyBy = $this->rateLimit['key_by'] ?? 'ip';
        
        switch ($keyBy) {
            case 'ip':
                return 'route:' . $this->getRouteKey() . ':ip:' . $request->ip();
            case 'user':
                $userId = $request->user() ? $request->user()->id : 'guest';
                return 'route:' . $this->getRouteKey() . ':user:' . $userId;
            case 'session':
                return 'route:' . $this->getRouteKey() . ':session:' . session_id();
            default:
                return 'route:' . $this->getRouteKey() . ':custom:' . $keyBy;
        }
    }

    /**
     * Get retry after time for rate limit
     */
    protected function getRateLimitRetryAfter(): int
    {
        return $this->rateLimit['decay_minutes'] * 60 ?? 60;
    }

    /**
     * Execute closure
     */
    protected function executeClosure(\Closure $closure, Request $request, Response $response): Response
    {
        try {
            // Get closure parameters
            $reflection = new \ReflectionFunction($closure);
            $params = $reflection->getParameters();

            $args = [];
            foreach ($params as $param) {
                $paramName = $param->getName();

                // Check for common parameters
                if ($paramName === 'request' || $param->getType() === Request::class) {
                    $args[] = $request;
                } elseif ($paramName === 'response' || $param->getType() === Response::class) {
                    $args[] = $response;
                } elseif ($paramName === 'route' || $param->getType() === RouteItem::class) {
                    $args[] = $this;
                } elseif (isset($this->parameters[$paramName])) {
                    $args[] = $this->parameters[$paramName];
                } elseif ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                } else {
                    $args[] = null;
                }
            }

            // Capture any direct output produced by the closure so the
            // response stays neutral (echo/print, legacy views, etc.):
            //
            //   Route::get('ping', function () { echo 'pong'; });
            //   Route::get('ping', function () { view('errors/x'); });
            //
            // Without buffering, echoed content escapes before sendBody()
            // and the framework falls back to an empty/JSON envelope.
            ob_start();

            try {
                // Execute closure
                $result = $reflection->invokeArgs($args);
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }

            $output = ob_get_clean();

            // Handle result
            if ($result instanceof Response) {
                // If the closure both echoed and returned a Response,
                // prepend the captured output to the returned body.
                if ($output !== '' && $output !== false) {
                    $result->setBody($output . $result->getBody());
                }

                return $result;
            }

            // The closure produced output directly (echo/print or a legacy
            // view). Serve it verbatim — NOT as JSON.
            if ($output !== '' && $output !== false) {
                // Cases like:
                //   Route::get('ping', function () {
                //       function data() { return "coba data"; }
                //       echo data();
                //   })->name('ping');
                //
                // The shared Response object may still carry a JSON state
                // from an earlier json()/setJson() call (middleware, a
                // previous handler, etc.). setBody() alone cannot clear a
                // pre-existing Content-Type header on subclasses that
                // override it, so scrub the stale JSON marker explicitly —
                // otherwise browsers/fetch clients run JSON.parse on plain
                // text and fail with:
                //   "SyntaxError: JSON.parse: unexpected character at
                //    line 1 column 1 of the JSON data"
                $reflectionResponse = new \ReflectionProperty(Response::class, 'jsonResponse');
                $reflectionResponse->setAccessible(true);
                $reflectionResponse->setValue($response, false);

                if ($response->hasHeader('Content-Type')
                    && stripos((string) $response->getHeader('Content-Type'), 'application/json') !== false) {
                    $response->removeHeader('Content-Type');
                }

                $response->setBody($output);
                return $response;
            }

            // Nothing echoed: fall back to the return value.

            // Array / JSON-serializable results must be encoded as JSON.
            // Casting them with (string) raises "Array to string
            // conversion" and breaks closures such as:
            //   Route::get('api/status', fn() => ['api' => [...]]);
            if (is_array($result) || $result instanceof \JsonSerializable) {
                return $response->json($result);
            }

            // Objects without __toString() would also fatal on the cast
            // below; serve them as JSON instead.
            if (is_object($result) && !method_exists($result, '__toString')) {
                return $response->json($result);
            }

            // Convert other scalar types to a string body
            $response->setBody((string) $result);
            return $response;

        } catch (BadRequestException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BadRequestException('Closure execution error: ' . $e->getMessage());
        }
    }

    public function getRoutingInfo(): array
    {
        $action = $this->action;
        
        $routing = [
            'method' => $this->method,
            'uri' => $this->uri,
            'parameters' => $this->parameters,
            'segments' => array_values($this->parameters),
            'middleware' => $this->middleware,
            'type' => 'modern',
            'source' => 'route_item',
            '_route_item' => $this,
            'namespace' => $this->namespace,
            'rate_limit' => $this->rateLimit,
            'api_attributes' => $this->apiAttributes,
            'subdomain' => $this->subdomain,
            'domain' => $this->domain,
            'domain_attributes' => $this->domainAttributes,
        ];
    
        // Handle dynamic method dari parameter route
        if (is_string($action)) {
            // Jika action mengandung {method} pattern
            if (strpos($action, '{method}') !== false) {
                // Ganti {method} dengan nilai parameter yang sebenarnya
                if (isset($this->parameters['method'])) {
                    $methodName = $this->parameters['method'];
                    $controller = str_replace('@{method}', '', $action);
                    $action = $controller . '@' . $methodName;
                }
            }
        }
    
        // Parse action untuk mendapatkan controller dan method
        if ($action instanceof \Closure) {
            $routing['action_type'] = 'closure';
            $routing['class'] = 'Closure';
            $routing['method'] = '__invoke';
        } elseif (is_string($action)) {
            if (strpos($action, '@') !== false) {
                list($controller, $method) = explode('@', $action, 2);
                $routing['class'] = $controller;
                $routing['method'] = $method;
                $routing['action'] = $action;
            } else {
                $routing['class'] = $action;
                $routing['method'] = 'index';
                $routing['action'] = $action;
            }
            $routing['action_type'] = 'controller';
            
            // Apply namespace
            if ($this->namespace && 
                !empty($routing['class']) && 
                strpos($routing['class'], '\\') === false) {
                $routing['fqcn'] = rtrim($this->namespace, '\\') . '\\' . $routing['class'];
            } else {
                $routing['fqcn'] = $routing['class'];
            }
        }
        
        return $routing;
    }

    /**
     * Generate URL for route
     */
    public function url(array $parameters = []): string
    {
        $uri = $this->uri;

        // Replace parameters in URI
        foreach ($parameters as $key => $value) {
            $placeholder = '{' . $key . '}';
            if (strpos($uri, $placeholder) !== false) {
                $uri = str_replace($placeholder, $value, $uri);
            }
        }

        // Remove optional parameters that weren't provided
        $uri = preg_replace('/\{[^}]+\}\/?/', '', $uri);
        $uri = rtrim($uri, '/');
        
        // Ensure leading slash
        if ($uri === '') {
            $uri = '/';
        } elseif ($uri[0] !== '/') {
            $uri = '/' . $uri;
        }

        // Build full URL dengan domain/subdomain
        $fullUrl = $this->buildUrlWithDomain($uri);
        
        return $fullUrl;
    }

    /**
     * Build URL dengan domain
     */
    protected function buildUrlWithDomain(string $uri): string
    {
        // Use site_url jika available
        if (function_exists('site_url')) {
            $url = site_url($uri);
            
            // Add domain jika ada
            if ($this->domain) {
                $url = $this->injectDomainIntoUrl($url, $this->domain);
            }
            // Add subdomain jika ada
            elseif ($this->subdomain && $this->subdomain !== '*' && $this->subdomain !== '{wildcard}') {
                $url = $this->injectSubdomainIntoUrl($url, $this->subdomain);
            }
            
            return $url;
        }

        // Manual URL building
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        
        // Get base domain
        $baseDomain = config('app.domain') ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // Remove port jika ada
        if (strpos($baseDomain, ':') !== false) {
            $baseDomain = explode(':', $baseDomain)[0];
        }
        
        // Gunakan domain constraint jika ada
        if ($this->domain) {
            $host = $this->domain;
        } 
        // Gunakan subdomain constraint jika ada
        elseif ($this->subdomain && $this->subdomain !== '*' && $this->subdomain !== '{wildcard}') {
            $host = $this->subdomain . '.' . $baseDomain;
        } else {
            $host = $baseDomain;
        }
        
        // Add port jika perlu
        $port = isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] != 80 && $_SERVER['SERVER_PORT'] != 443 
            ? ':' . $_SERVER['SERVER_PORT'] 
            : '';
        
        return $protocol . $host . $port . $uri;
    }

    /**
     * Inject domain into existing URL
     */
    protected function injectDomainIntoUrl(string $url, string $domain): string
    {
        $parsed = parse_url($url);
        
        if (!isset($parsed['host'])) {
            $parsed['host'] = 'localhost';
        }
        
        $parsed['host'] = $domain;
        
        return $this->buildUrlFromParts($parsed);
    }

    /**
     * Inject subdomain into existing URL
     */
    protected function injectSubdomainIntoUrl(string $url, string $subdomain): string
    {
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
        
        $newHost = implode('.', $parts);
        $parsed['host'] = $newHost;
        
        return $this->buildUrlFromParts($parsed);
    }

    /**
     * Build URL from parsed parts
     */
    protected function buildUrlFromParts(array $parts): string
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

    /**
     * Get route name
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Get URI pattern
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * Get HTTP method
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Get action
     */
    public function getAction()
    {
        return $this->action;
    }

    /**
     * Get middleware
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Get namespace
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Get rate limit configuration
     */
    public function getRateLimit(): ?array
    {
        return $this->rateLimit;
    }

    /**
     * Get API attributes
     */
    public function getApiAttributes(): array
    {
        return $this->apiAttributes;
    }

    /**
     * Get API version
     */
    public function getApiVersion(): ?string
    {
        return $this->apiAttributes['version'] ?? null;
    }

    /**
     * Check if API is deprecated
     */
    public function getApiDeprecated(): bool
    {
        return $this->apiAttributes['deprecated'] ?? false;
    }

    /**
     * Get API sunset date
     */
    public function getApiSunset(): ?string
    {
        return $this->apiAttributes['sunset'] ?? null;
    }

    /**
     * Get subdomain constraint
     */
    public function getSubdomain(): ?string
    {
        return $this->subdomain;
    }

    /**
     * Check if has subdomain constraint
     */
    public function hasSubdomainConstraint(): bool
    {
        return $this->subdomain !== null;
    }

    /**
     * Check if wildcard subdomain
     */
    public function isWildcardSubdomain(): bool
    {
        return $this->subdomainWildcard;
    }

    /**
     * Get domain constraint
     */
    public function getDomain(): ?string
    {
        return $this->domain;
    }

    /**
     * Get domain attributes
     */
    public function getDomainAttributes(): array
    {
        return $this->domainAttributes;
    }

    /**
     * Check if has domain constraint
     */
    public function hasDomainConstraint(): bool
    {
        return $this->domain !== null;
    }

    /**
     * Check if domain requires specific TLD
     */
    public function requiresTld(): ?string
    {
        return $this->domainAttributes['required_tld'] ?? null;
    }

    /**
     * Get allowed TLDs untuk domain ini
     */
    public function getAllowedTlds(): array
    {
        return $this->domainAttributes['allowed_tlds'] ?? [];
    }

    /**
     * Get unique route key for caching/identification
     */
    public function getRouteKey(): string
    {
        // Handle Closure action secara khusus
        if ($this->action instanceof \Closure) {
            // Untuk Closure, gunakan hash dari source code jika memungkinkan
            try {
                $reflection = new \ReflectionFunction($this->action);
                $filename = $reflection->getFileName();
                $startLine = $reflection->getStartLine();
                $endLine = $reflection->getEndLine();
                
                // Baca source code Closure
                $source = '';
                if ($filename && file_exists($filename)) {
                    $lines = file($filename);
                    if ($lines) {
                        $source = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
                    }
                }
                
                $actionKey = 'closure:' . md5($filename . ':' . $startLine . ':' . $endLine . ':' . $source);
            } catch (\ReflectionException $e) {
                // Fallback: gunakan random ID untuk anonymous closure
                $actionKey = 'closure:' . md5(spl_object_hash($this->action) . microtime());
            }
        } elseif (is_string($this->action)) {
            // Untuk string action (controller@method)
            $actionKey = 'string:' . md5($this->action);
        } elseif (is_array($this->action)) {
            // Untuk array action
            $actionKey = 'array:' . md5(json_encode($this->action));
        } else {
            // Untuk tipe lain
            $actionKey = 'other:' . md5(serialize([gettype($this->action)]));
        }
        
        // Bangun key
        $key = $this->method . ':' . $this->uri . ':' . $actionKey;
        
        if ($this->subdomain) {
            $key .= ':subdomain:' . $this->subdomain;
        }
        
        if ($this->domain) {
            $key .= ':domain:' . md5($this->domain);
        }
        
        if ($this->rateLimit) {
            $key .= ':rate_limit:' . md5(json_encode($this->rateLimit));
        }
        
        if ($this->apiAttributes) {
            $key .= ':api:' . md5(json_encode($this->apiAttributes));
        }
        
        if ($this->namespace) {
            $key .= ':namespace:' . md5($this->namespace);
        }
        
        if ($this->name) {
            $key .= ':name:' . md5($this->name);
        }
        
        return md5($key);
    }

    /**
     * Clear compiled pattern cache
     */
    public static function clearCache(): void
    {
        self::$compiledCache = [];
    }
}
