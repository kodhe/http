<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Middleware;

use Kodhe\Framework\Exceptions\BaseException;
use Kodhe\Framework\Exceptions\ConfigurationException;
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;

// Guarantee the case-insensitive app path helpers (app_path_in(),
// app_config_folder(), app_folder(), app_controller_file(), ...) are
// available even when this package is loaded without composer's autoload
// "files" section (manual includes, bundled copies, etc.).
if ( ! function_exists('app_path_in'))
{
    foreach (array(
        dirname(__DIR__, 3).'/framework/src/Support/app_path.php',
        dirname(__DIR__, 3).'/../framework/src/Support/app_path.php',
    ) as $__app_path_helper) {
        if (is_file($__app_path_helper)) {
            require_once $__app_path_helper;
            break;
        }
    }
}
unset($__app_path_helper);

class MiddlewareRegistry
{
    
    protected $aliases = [];
    protected $groups = [];
    protected $priority = [];
    
    /**
     * @var array Stack untuk mencegah infinite recursion
     */
    protected $resolvingStack = [];
    
    /**
     * @var array Cache untuk resolved middlewares
     */
    protected $resolvedCache = [];

    /**
     * Framework built-in middleware aliases.
     *
     * Registered automatically (app config aliases take precedence on merge)
     * so routes can simply use 'api-auth' / 'throttle' without requiring a
     * middleware.php config file — closing the gap where the REST API auth
     * and rate-limiting middleware existed but had no alias wiring.
     *
     * @var array<string, class-string>
     */
    protected static $frameworkAliases = [
        'api-auth' => \Kodhe\Framework\Http\Middleware\ApiAuth::class,
        'throttle' => \Kodhe\Framework\Http\Middleware\Routing\ThrottleRequests::class,
    ];

    /**
     * @var object|null Cache store injected for auto-built RateLimiter instances
     */
    protected ?object $cacheStore = null;

    public function __construct() {
        // Pre-register framework built-in aliases; app config may override them.
        $this->aliases = static::$frameworkAliases;

        $this->loadConfig();
    }
    
    protected function loadConfig() {
        try {
            $configFile = app_config_file_in(APPPATH, 'middleware.php');
            
            if (!file_exists($configFile)) {
                log_message('debug', 'Middleware config file not found at: ' . $configFile);
                return;
            }
            
            $config = require $configFile;
            
            if (isset($config['aliases'])) {
                if (!is_array($config['aliases'])) {
                    throw new ConfigurationException('Middleware aliases must be an array');
                }
                $this->aliases = array_merge(static::$frameworkAliases, $this->aliases);
                log_message('debug', 'Loaded ' . count($this->aliases) . ' middleware aliases');
            }
            
            if (isset($config['groups'])) {
                if (!is_array($config['groups'])) {
                    throw new ConfigurationException('Middleware groups must be an array');
                }
                $this->groups = $config['groups'];
                log_message('debug', 'Loaded ' . count($this->groups) . ' middleware groups');
            }
            
            if (isset($config['priority'])) {
                if (!is_array($config['priority'])) {
                    throw new ConfigurationException('Middleware priority must be an array');
                }
                $this->priority = $config['priority'];
            }
            
        } catch (ConfigurationException $e) {
            log_message('error', 'Configuration error in middleware config: ' . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            log_message('error', 'Error loading middleware config: ' . $e->getMessage());
            
            $configException = new ConfigurationException(
                'Failed to load middleware configuration: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
            
            throw $configException;
        }
    }
    
    /**
     * Resolve middleware dari string ke object
     */
    public function resolve($middleware) {
        // Generate key untuk tracking dan cache
        $key = $this->getMiddlewareKey($middleware);
        
        // Cek cache dulu
        if (isset($this->resolvedCache[$key])) {
            log_message('debug', 'Returning cached middleware: ' . $key);
            return $this->resolvedCache[$key];
        }
        
        // Cek jika sedang di-resolve (prevent infinite recursion)
        if (in_array($key, $this->resolvingStack)) {
            $errorMessage = 'Circular middleware dependency detected: ' . $key;
            log_message('error', $errorMessage);
            
            $circularException = new BaseException($errorMessage);
            
            throw $circularException;
        }
        
        // Tambahkan ke stack
        $this->resolvingStack[] = $key;
        
        try {
            $result = $this->doResolve($middleware);
            
            // Cache hasil resolve
            if ($result !== null) {
                $this->resolvedCache[$key] = $result;
            }
            
        } catch (\Throwable $e) {
            // Hapus dari stack jika error
            array_pop($this->resolvingStack);
            
            if ($e instanceof BaseException) {
                log_message('error', 'Error resolving middleware: ' . $e->getMessage());
                throw $e;
            }
            
            log_message('error', 'Error resolving middleware: ' . $e->getMessage());
            
            $resolveException = new BaseException(
                'Failed to resolve middleware: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
            
            throw $resolveException;
        }
        
        // Hapus dari stack setelah selesai
        array_pop($this->resolvingStack);
        
        return $result;
    }
    
    /**
     * Actual resolve logic
     */
    protected function doResolve($middleware) {
        log_message('debug', 'Resolving middleware: ' . $this->getMiddlewareKey($middleware));
        
        // Jika sudah object, return langsung
        if ($middleware instanceof MiddlewareInterface) {
            log_message('debug', 'Already a MiddlewareInterface object');
            return $middleware;
        }
        
        // Jika callable
        if (is_callable($middleware) && !is_string($middleware)) {
            log_message('debug', 'Creating callable middleware');
            return $this->createCallableMiddleware($middleware);
        }
        
        // Jika string
        if (is_string($middleware)) {
            return $this->resolveString($middleware);
        }
        
        // Jika array, anggap sebagai list of middlewares untuk group
        if (is_array($middleware)) {
            log_message('debug', 'Creating group from array with ' . count($middleware) . ' items');
            return $this->createGroupFromArray($middleware);
        }
        
        $errorMessage = 'Unknown middleware type: ' . gettype($middleware);
        log_message('error', $errorMessage);
        
        throw new BaseException($errorMessage);
    }
    
    /**
     * Resolve string middleware
     */
    protected function resolveString($middleware) {
        log_message('debug', 'Resolving string middleware: ' . $middleware);
        
        // Cek jika ini adalah group name
        if (isset($this->groups[$middleware])) {
            log_message('debug', 'Resolving as group: ' . $middleware);
            return $this->resolveGroup($middleware);
        }
        
        // Parse parameters jika ada (format: "alias:param1,param2" atau "class:param1,param2")
        $params = [];
        $original = $middleware;
        
        if (strpos($middleware, ':') !== false) {
            list($mwName, $paramString) = explode(':', $middleware, 2);
            $params = array_map('trim', explode(',', $paramString));
            $middleware = $mwName;
            log_message('debug', 'Parsed parameters for ' . $middleware . ': ' . print_r($params, true));
        }
        
        // Cek jika ini alias
        if (isset($this->aliases[$middleware])) {
            $resolvedAlias = $this->aliases[$middleware];
            log_message('debug', 'Alias "' . $middleware . '" resolved to: ' . $resolvedAlias);
            
            // Jika alias merujuk ke class, gunakan itu
            if (class_exists($resolvedAlias)) {
                $middleware = $resolvedAlias;
            } else {
                // Mungkin alias merujuk ke alias lain atau group
                // Coba resolve lagi
                $instance = $this->resolve($resolvedAlias);
                if ($instance instanceof MiddlewareInterface) {
                    // Set parameters jika ada
                    if (!empty($params) && method_exists($instance, 'setParameters')) {
                        $instance->setParameters($params);
                    }
                    return $instance;
                }
                
                $errorMessage = 'Alias "' . $original . '" resolves to invalid middleware: ' . $resolvedAlias;
                log_message('error', $errorMessage);
                
                throw new BaseException($errorMessage);
            }
        }
        
        // Sekarang $middleware harus berupa class name
        if (!class_exists($middleware)) {
            // Coba dengan namespace default untuk App Middlewares
            $defaultNamespace = 'App\\Middlewares\\' . $middleware;
            if (class_exists($defaultNamespace)) {
                $middleware = $defaultNamespace;
                log_message('debug', 'Using default namespace: ' . $middleware);
            } else {
                $errorMessage = "Middleware class not found: {$original} (tried: {$middleware}, {$defaultNamespace})";
                log_message('error', $errorMessage);
                
                throw new BaseException($errorMessage);
            }
        }
        
        try {
            // Framework built-in middleware may require constructor
            // dependencies (e.g. ThrottleRequests needs a RateLimiter).
            // Build those automatically instead of failing on `new $class()`.
            $instance = $this->buildBuiltIn($middleware);

            if ($instance === null) {
                $instance = new $middleware();
            }
            
            if ($instance instanceof MiddlewareInterface) {
                log_message('debug', 'Successfully created middleware instance: ' . $middleware);
                
                // Set parameters jika ada
                if (!empty($params) && method_exists($instance, 'setParameters')) {
                    $instance->setParameters($params);
                }
                
                return $instance;
            }
            
            // Legacy Laravel-style middleware (e.g. app middlewares extending
            // the old Kodhe\Framework\Middleware\Middleware shim with a
            // handle($request, $next, ...$params) signature). Wrap it in an
            // adapter so it can run inside Pipeline/MiddlewareGroup without
            // arity fatals ("Too few arguments to function ...{closure}()").
            if (method_exists($instance, 'handle')) {
                log_message('debug', 'Wrapping legacy middleware in LegacyMiddlewareAdapter: ' . $middleware);

                $adapter = new LegacyMiddlewareAdapter($instance, $params);

                if (!empty($params)) {
                    $adapter->setParameters($params);
                }

                return $adapter;
            }

            $errorMessage = "Class {$middleware} does not implement MiddlewareInterface";
            log_message('error', $errorMessage);
            
            throw new BaseException($errorMessage);
            
        } catch (\Throwable $e) {
            $errorMessage = "Error creating middleware instance {$middleware}: " . $e->getMessage();
            log_message('error', $errorMessage);
            
            if ($e instanceof BaseException) {
                throw $e;
            }
            
            $instanceException = new BaseException($errorMessage, $e->getCode(), $e);
            
            throw $instanceException;
        }
    }
    
    /**
     * Resolve middleware group
     */
    protected function resolveGroup($groupName) {
        if (!isset($this->groups[$groupName])) {
            $errorMessage = "Middleware group not found: {$groupName}";
            log_message('error', $errorMessage);
            
            throw new BaseException($errorMessage);
        }
        
        $groupMiddlewares = $this->groups[$groupName];
        
        if (!is_array($groupMiddlewares)) {
            $errorMessage = "Middleware group '{$groupName}' must be an array";
            log_message('error', $errorMessage);
            
            throw new BaseException($errorMessage);
        }
        
        log_message('debug', "Resolving middleware group: '{$groupName}' with " . count($groupMiddlewares) . " middlewares");
        
        // Log isi group untuk debugging
        foreach ($groupMiddlewares as $index => $mw) {
            log_message('debug', "  Group[{$groupName}][{$index}]: " . (is_string($mw) ? $mw : gettype($mw)));
        }
        
        return $this->createGroupFromArray($groupMiddlewares);
    }
    
    /**
     * Create middleware group dari array
     */
    protected function createGroupFromArray(array $middlewares) {
        $group = new MiddlewareGroup();
        
        foreach ($middlewares as $index => $mw) {
            log_message('debug', "Resolving group item [{$index}]: " . (is_string($mw) ? $mw : gettype($mw)));
            
            try {
                $resolved = $this->resolve($mw);
                
                if ($resolved !== null) {
                    $group->add($resolved);
                    log_message('debug', "  Successfully resolved group item [{$index}]");
                } else {
                    log_message('error', "Failed to resolve middleware in group at index {$index}: " . print_r($mw, true));
                    
                    // Tambahkan dummy middleware sebagai placeholder
                    $dummy = $this->createErrorMiddleware(
                        "Failed to resolve middleware at index {$index}",
                        $mw
                    );
                    $group->add($dummy);
                }
            } catch (BaseException $e) {
                log_message('error', "Error resolving middleware in group at index {$index}: " . $e->getMessage());
                
                // Tambahkan error middleware
                $errorMw = $this->createErrorMiddleware(
                    $e->getMessage(),
                    $mw,
                    $e
                );
                $group->add($errorMw);
            } catch (\Throwable $e) {
                log_message('error', "Unexpected error resolving middleware in group at index {$index}: " . $e->getMessage());
                
                $errorMw = $this->createErrorMiddleware(
                    'Unexpected error: ' . $e->getMessage(),
                    $mw,
                    $e
                );
                $group->add($errorMw);
            }
        }
        
        log_message('debug', 'Created middleware group with ' . count($group->getMiddlewares()) . ' middlewares');
        return $group;
    }
    
    /**
     * Create callable middleware
     */
    protected function createCallableMiddleware(callable $callable) {
        // Define inline callable middleware class
        return new class($callable) implements MiddlewareInterface {
            protected $callable;
            
            public function __construct(callable $callable) {
                $this->callable = $callable;
            }
            
            public function handle($request, $response, $next, $params = []) {
                return call_user_func($this->callable, $request, $response, $next, $params);
            }
            
            public function __toString() {
                return 'CallableMiddleware';
            }
        };
    }
    
    /**
     * Create error middleware untuk handling resolution errors
     */
    protected function createErrorMiddleware(string $errorMessage, $originalMiddleware, \Throwable $exception = null): MiddlewareInterface {
        return new class($errorMessage, $originalMiddleware, $exception) implements MiddlewareInterface {
            protected $errorMessage;
            protected $originalMiddleware;
            protected $exception;
            
            public function __construct(string $errorMessage, $originalMiddleware, \Throwable $exception = null) {
                $this->errorMessage = $errorMessage;
                $this->originalMiddleware = $originalMiddleware;
                $this->exception = $exception;
            }
            
            public function handle($request, $response, $next, $params = []) {
                log_message('error', 'Error middleware executed: ' . $this->errorMessage);
                
                // Jika ada exception, log lebih detail
                if ($this->exception) {
                    log_message('error', 'Original error: ' . $this->exception->getMessage());
                }
                
                // Lanjut ke middleware berikutnya
                return $next($request, $response, $params);
            }
            
            public function __toString() {
                return 'ErrorMiddleware';
            }
        };
    }
    
    /**
     * Build a framework built-in middleware that needs constructor
     * dependencies injected by the registry itself.
     *
     * Returns null for every other class so the caller falls back to the
     * plain `new $class()` instantiation path.
     *
     * @param string $class Fully-qualified class name
     * @return MiddlewareInterface|null
     */
    protected function buildBuiltIn(string $class): ?MiddlewareInterface
    {
        if (is_a($class, \Kodhe\Framework\Http\Middleware\Routing\ThrottleRequests::class, true)) {
            return new \Kodhe\Framework\Http\Middleware\Routing\ThrottleRequests(
                new \Kodhe\Framework\Http\Routing\RateLimiter($this->defaultCacheStore())
            );
        }

        // ApiAuth's validator is app-specific (token storage lookup) and
        // cannot be auto-wired here; without one it rejects every request
        // with 401 — safe-by-default. Apps should register their own alias
        // or instantiate it directly with a validator callable.
        return null;
    }

    /**
     * Resolve a key/value cache store for auto-built RateLimiter instances.
     *
     * Order of preference:
     *   1. Store set explicitly via setDefaultCacheStore()
     *   2. A CodeIgniter cache handler ($this->cache) if the app assigned one
     *   3. A minimal in-memory array store (per-process; suitable for dev
     *      and single-server deployments until an app wires a real backend)
     *
     * @return object get/set/delete-capable store
     */
    protected function defaultCacheStore(): object
    {
        if ($this->cacheStore !== null) {
            return $this->cacheStore;
        }

        if (isset($this->cache) && is_object($this->cache)
            && method_exists($this->cache, 'get') && method_exists($this->cache, 'set')) {
            return $this->cache;
        }

        return new class {
            /** @var array<string, mixed> */
            private array $data = [];

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
    }

    /**
     * Inject the cache store used when the registry auto-builds a
     * RateLimiter for the 'throttle' middleware. Call this once during
     * bootstrap to share a persistent backend (file/redis/CI cache).
     */
    public function setDefaultCacheStore(object $store): void
    {
        $this->cacheStore = $store;
    }

    /**
     * Get unique key untuk middleware
     */
    protected function getMiddlewareKey($middleware) {
        if (is_string($middleware)) {
            return 'string:' . $middleware;
        }
        
        if (is_array($middleware)) {
            return 'array:' . md5(serialize($middleware));
        }
        
        if (is_object($middleware)) {
            if ($middleware instanceof MiddlewareInterface) {
                return 'object:' . get_class($middleware) . ':' . spl_object_hash($middleware);
            }
            return 'object:' . get_class($middleware);
        }
        
        return 'unknown:' . gettype($middleware);
    }
    
    /**
     * Get all registered groups
     */
    public function getGroups() {
        return $this->groups;
    }
    
    /**
     * Get all registered aliases
     */
    public function getAliases() {
        return $this->aliases;
    }
    
    /**
     * Check if a group exists
     */
    public function hasGroup($groupName) {
        return isset($this->groups[$groupName]);
    }
    
    /**
     * Check if an alias exists
     */
    public function hasAlias($alias) {
        return isset($this->aliases[$alias]);
    }
    
    /**
     * Clear cache (untuk testing/debugging)
     */
    public function clearCache() {
        $this->resolvedCache = [];
        $this->resolvingStack = [];
    }
}
