<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing;

use Kodhe\Framework\Config\Config;
use Kodhe\Framework\Exceptions\Http\{
    NotFoundException,
    BadRequestException,
    ForbiddenException
};
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Support\Facades\Facade;

/**
 * Unified Routing Manager dengan Hybrid Support
 */
class RoutingManager
{
    /**
     * @var RouterInterface Current router instance
     */
    protected $router;
    

    /**
     * @var array Router configuration
     */
    protected $configuration = [];
    
    /**
     * @var array Route cache
     */
    protected $routeCache = [];
    
    protected $router_ready = false;
    
    /**
     * Constructor
     */
    public function __construct(?Router $router = null)
    {
        $this->router = $router ?? new Router();
        $this->overrideRoutingConfig();
        $this->initializeConfig();
    }
    
    
    /**
     * Initialize router configuration
     */
    protected function initializeConfig(): void
    {
        $this->configuration = [
            'enable_query_strings' => defined('REQ') && REQ === 'CP',
            'controller_suffix' => '',
            'default_controller' => 'Welcome',
            'default_method' => 'index',
            'modules' => [],
            'auto_detect_namespace' => true,
            'allow_namespace_in_routes' => true,
            'enable_modern_routing' => true,
            'cache_routes' => ENVIRONMENT === 'production',
            'namespaces' => [
                'app' => 'App\\Controllers\\',
                'kodhe' => 'Kodhe\Framework\Controllers\\',
                'modules' => 'App\\Modules\\'
            ],
            'router_type' => 'hybrid',
            '404_override' => '',
            'show_404_on_missing' => true,
            'default_404_controller' => 'FileNotFound',
            'default_404_namespace' => 'Kodhe\Framework\Controllers\\Error\\',
            'enable_auto_route' => true
        ];
    }
    
    /**
     * Override routing
     */
    public function overrideRouting(array $routing): void
    {
        if (!$this->router_ready) {
            $this->initializeRouter();
        }
        
        if (method_exists($this->router, '_set_overrides')) {
            $this->router->_set_overrides($routing);
        }
    }
    
    
    /**
     * Initialize router
     */
    protected function initializeRouter(): void
    {
        if (method_exists($this->router, '_set_routing')) {
            try {
                $this->router->_set_routing();
                $this->router_ready = true;
            } catch (\TypeError $e) {
                $this->router_ready = false;
            }
        }
    }
    
    /**
     * Set routing config for CP
     */
    public function overrideRoutingConfig(): void
    {
        $routing_config = [
            'directory_trigger' => 'D',
            'controller_trigger' => 'C',
            'function_trigger' => 'M',
            'enable_query_strings' => defined('REQ') && REQ == 'CP'
        ];

        $this->overrideConfig($routing_config);
    }

    /**
     * Override config
     */
    public function overrideConfig(array $config): void
    {
        if (isset($GLOBALS['CFG']) && method_exists($GLOBALS['CFG'], '_assign_to_config')) {
            $GLOBALS['CFG']->_assign_to_config($config);
        }
    }

    /**
     * Resolve routing dengan hybrid approach
     */
    public function resolve(Request $request): array
    {
        $uri = $this->getRequestUri($request);
        $cacheKey = md5($uri . '_hybrid');
        
        // Check cache
        if (isset($this->routeCache[$cacheKey]) && $this->configuration['cache_routes']) {
            return $this->routeCache[$cacheKey];
        }
        
        // Coba modern routing pertama jika dienable
        if ($this->configuration['enable_modern_routing']) {
            $modernRouting = $this->resolveFromModern($request);
        
            if ($this->isValidRouting($modernRouting)) {
                // Enrich modern routing
                $modernRouting = $this->enrichModernRouting($modernRouting);
                $modernRouting['router_type'] = 'modern';

                // Closure routes dieksekusi langsung oleh RouteItem::run()
                // lewat Router::execute(), jadi tidak perlu validasi
                // controller class — kembalikan apa adanya.
                if ($this->isClosureRouting($modernRouting)) {
                    if ($this->configuration['cache_routes']) {
                        $this->routeCache[$cacheKey] = $modernRouting;
                    }

                    return $modernRouting;
                }
            
                // Cache result
                if ($this->configuration['cache_routes']) {
                    $this->routeCache[$cacheKey] = $modernRouting;
                }
                
                return $modernRouting;
            }
        }
        
        // Fallback ke legacy routing
        $routing = $this->resolveFromLegacy($request);

        
        if (!$this->isValidRouting($routing)) {
            $routing = $this->analyzeUri($uri);
        }
        
        // Jika routing masih tidak valid atau controller tidak ditemukan
        if (!$this->isValidRouting($routing) || !$this->controllerExists($routing)) {
            // Cek apakah ada custom 404 override dari konfigurasi
            $override404 = $this->get404Override();
            if ($override404) {
                return $this->parse404Override($override404);
            }
            
            // Return default 404 routing
            return $this->getErrorRouting();
        }
        
        // Enrich routing details
        $routing = $this->enrichRoutingDetails($routing);
        
        // Validasi apakah controller dan method benar-benar ada
        if (!$this->validateRoutingExists($routing)) {
            return $this->getErrorRouting();
        }
        
        // Add request method
        $routing['method_http'] = $request->method();
        $routing['router_type'] = 'legacy';
        
        // Cache result
        if ($this->configuration['cache_routes']) {
            $this->routeCache[$cacheKey] = $routing;
        }
        
        return $routing;
    }
    

    /**
     * Cek apakah controller exists
     */
    protected function controllerExists(array $routing): bool
    {
        if ($routing['type'] === 'modern') {
            return isset($routing['fqcn']) && class_exists($routing['fqcn']);
        }

        if ($routing['type'] === 'legacy' && isset($routing['file'])) {
            return file_exists($routing['file']);
        }

        $file = $this->locateLegacyControllerFile($routing);
        return $file !== null && file_exists($file);
    }
    
    /**
     * Validasi apakah routing benar-benar exists
     */
    protected function validateRoutingExists(array $routing): bool
    {
        if ($routing['type'] === 'modern') {
            if (!isset($routing['fqcn']) || !class_exists($routing['fqcn'])) {
                return false;
            }
            
            $method = strtolower($routing['method'] ?? 'index');
            $methods = get_class_methods($routing['fqcn']);
            
            return in_array('_remap', $methods) || 
                   in_array($method, array_map('strtolower', $methods)) ||
                   method_exists($routing['fqcn'], '__call');
        }

        if ($routing['type'] === 'legacy') {
            $className = ucfirst($routing['class']);
            
            // Check for controller suffix
            if (isset($this->configuration['controller_suffix'])) {
                $suffix = $this->configuration['controller_suffix'];
                $suffixedName = $className . $suffix;
                
                if (class_exists($suffixedName)) {
                    $className = $suffixedName;
                }
            }
            
            if (!class_exists($className)) {
                // Load controller file jika belum
                if (isset($routing['file']) && file_exists($routing['file'])) {
                    require_once $routing['file'];
                    
                    if (!class_exists($className)) {
                        return false;
                    }
                } else {
                    return false;
                }
            }
            
            $method = strtolower($routing['method']);
            $methods = get_class_methods($className);
            
            return in_array('_remap', $methods) || 
                   in_array($method, array_map('strtolower', $methods)) ||
                   method_exists($className, '__call');
        }

        return false;
    }
    
    /**
     * Get 404 override dari konfigurasi
     */
    protected function get404Override(): ?string
    {
        // Coba dari konfigurasi routing
        if (!empty($this->configuration['404_override'])) {
            return $this->configuration['404_override'];
        }
        
        // Coba dari konfigurasi global CI jika ada
        if (isset($GLOBALS['CFG'])) {
            $config = $GLOBALS['CFG'];
            if (isset($config->config['404_override'])) {
                return $config->config['404_override'];
            }
        }
        
        return null;
    }
    
    /**
     * Parse 404 override string ke routing array
     */
    protected function parse404Override(string $override): array
    {
        $parts = explode('/', $override);
        
        $controller = ucfirst($parts[0]);
        $method = $parts[1] ?? 'index';
        
        $routing = [
            'class' => $controller,
            'method' => $method,
            'segments' => [],
            'source' => '404_override',
            'uri' => '404'
        ];
        
        // Tentukan tipe controller
        $routing['type'] = $this->determineControllerType($routing);
        
        if ($routing['type'] === 'modern') {
            // Untuk modern controller
            $routing['namespace'] = $this->determineNamespace($routing);
            $routing['fqcn'] = $this->determineFullyQualifiedClassName($routing);
        } else {
            // Untuk legacy controller
            $routing['file'] = $this->locateLegacyControllerFile($routing);
        }
        
        $routing['method_valid'] = $this->validateMethod($routing);
        $routing['is_404'] = true;
        
        return $routing;
    }
    
    /**
     * Enrich modern routing dengan semua detail yang diperlukan
     */
    protected function enrichModernRouting(array $routing): array
    {
        // Ensure all required keys exist
        $defaults = [
            'segments' => [],
            'type' => 'modern',
            'method_valid' => true,
            'source' => 'modern'
        ];
        
        $routing = array_merge($defaults, $routing);

        // Closure route: tidak ada controller yang perlu di-resolve.
        if ($this->isClosureRouting($routing)) {
            return $routing;
        }

        // Determine fqcn jika belum ada
        if (!isset($routing['fqcn']) && isset($routing['class'])) {
            if (strpos($routing['class'], '\\') !== false) {
                $routing['fqcn'] = $routing['class'];
            } else {
                $namespace = $routing['namespace'] ?? $this->determineNamespace($routing);
                $className = ucfirst($routing['class']);
                
                // Build FQCN
                if (!empty($namespace)) {
                    $routing['fqcn'] = rtrim($namespace, '\\') . '\\' . $className;
                } else {
                    $routing['fqcn'] = $className;
                }
                
                // Try with suffix jika class tidak ada
                if (!class_exists($routing['fqcn'])) {
                    $routing['fqcn'] .= $this->configuration['controller_suffix'];
                }
            }
        }
        
        // Validate method
        if (isset($routing['fqcn']) && class_exists($routing['fqcn'])) {
            $routing['method_valid'] = $this->validateMethod($routing);
        }
        
        return $routing;
    }

    /**
     * Deteksi routing yang action-nya berupa Closure (route fungsional).
     *
     * Closure routes tidak memiliki controller class, sehingga tidak boleh
     * divalidasi lewat class_exists() — jika tidak, route seperti:
     *
     *   Route::get('ping', function () { ... })->name('api.ping');
     *
     * akan dianggap Not Found.
     */
    protected function isClosureRouting(array $routing): bool
    {
        if (($routing['action_type'] ?? null) === 'closure') {
            return true;
        }

        if (($routing['type'] ?? null) !== 'modern') {
            return false;
        }

        if (($routing['class'] ?? null) === 'Closure' && ($routing['method'] ?? null) === '__invoke') {
            return true;
        }

        $route = $routing['route'] ?? $routing['_route_item'] ?? null;

        if ($route instanceof RouteItem && $route->getAction() instanceof \Closure) {
            return true;
        }

        return false;
    }

    protected function getRequestUri(Request $request): string
    {
        $uri = $request->getUri();
        
        if ($uri instanceof \Kodhe\Framework\Http\Uri) {
            $path = $uri->getPath();
        } elseif (is_string($uri)) {
            $parsed = parse_url($uri);
            $path = $parsed['path'] ?? '/';
        } else {
            $path = '/';
        }
        
        // Normalize path
        $path = trim($path, '/');
        return $path === '' ? '/' : '/' . $path;
    }

    /**
     * Resolve dari modern router
     */
    protected function resolveFromModern(Request $request): array
    {
        try {
            $routing = $this->router->matchRequest($request);

            if ($routing) {
                // Simpan modern router instance untuk eksekusi
                $routing['_router'] = $this->router;
                
                // Simpan route item jika ada
                if (isset($routing['route']) && $routing['route'] instanceof RouteItem) {
                    $routing['_route_item'] = $routing['route'];
                }
                
                return $routing;
            }
        } catch (\Exception $e) {
            // Continue to legacy routing
        }
        
        return [];
    }

    protected function resolveFromLegacy(Request $request): array
    {
        try {
            // Buat instance LegacyRouter terpisah untuk tidak mengganggu router modern
            $legacyRouter = new LegacyRouter();

            // Match request menggunakan legacy router
            $legacyRouting = $legacyRouter->matchRequest($request);

            if ($legacyRouting && !empty($legacyRouting['class'])) {
                return [
                    'directory' => $legacyRouting['directory'] ?? '',
                    'class' => $legacyRouting['class'],
                    'method' => $legacyRouting['method'] ?? 'index',
                    'segments' => $legacyRouting['params'] ?? [],
                    'source' => 'legacy',
                    'type' => 'legacy',
                    'query_params' => $request->get()
                ];
            }
        } catch (\Exception $e) {
            log_message('error', 'Legacy routing error: ' . $e->getMessage());
            // Continue to other methods
        }
     }

    /**
     * Analyze URI for routing
     */
    protected function analyzeUri(string $uri): array
    {
        // Jika auto_route disabled, langsung return 404
        if (!$this->configuration['enable_auto_route']) {
            return $this->getErrorRouting();
        }
        
        // Clean URI
        $uri = trim($uri, '/');
        
        // Remove query string if present
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }
        
        $segments = explode('/', $uri);
        
        $routing = [
            'directory' => '',
            'class' => $this->configuration['default_controller'],
            'method' => $this->configuration['default_method'],
            'segments' => [],
            'source' => 'auto',
            'uri' => $uri
        ];

        // Parse segments
        if (!empty($segments[0])) {
            $routing['class'] = $this->extractControllerFromSegments($segments);
            
            $usedSegments = count(explode('/', $routing['directory'])) + 1;
            
            if (!empty($segments[$usedSegments])) {
                $routing['method'] = $segments[$usedSegments];
                $routing['segments'] = array_slice($segments, $usedSegments + 1);
            } else {
                $routing['segments'] = array_slice($segments, $usedSegments);
            }
        }

        return $routing;
    }

    /**
     * Ekstrak controller dari segments
     */
    protected function extractControllerFromSegments(array $segments): string
    {
        $controllerName = '';
        $directory = '';
        
        // Coba identifikasi namespace dalam URI
        if ($this->configuration['allow_namespace_in_routes']) {
            $potentialPath = '';
            $segmentCount = count($segments);
            
            for ($i = 0; $i < $segmentCount; $i++) {
                $potentialPath .= ($i > 0 ? '/' : '') . $segments[$i];
                $potentialNamespace = str_replace('/', '\\', $potentialPath) . '\\';
                
                // Cek jika namespace ini valid
                if ($this->isValidNamespaceInRoute($potentialNamespace)) {
                    $directory = $potentialPath . '/';
                    $controllerIndex = $i + 1;
                    
                    if (isset($segments[$controllerIndex])) {
                        $controllerName = $segments[$controllerIndex];
                    }
                }
            }
        }
        
        // Jika tidak ditemukan namespace, gunakan pendekatan biasa
        if (empty($controllerName) && !empty($segments[0])) {
            $controllerName = $segments[0];
            $directory = $this->findControllerDirectory($segments);
        }
        
        $this->configuration['_temp_directory'] = $directory;
        
        return ucfirst($controllerName);
    }

    /**
     * Cek apakah namespace dalam route valid
     */
    protected function isValidNamespaceInRoute(string $namespace): bool
    {
        foreach ($this->configuration['namespaces'] as $baseNamespace) {
            if (strpos($namespace, $baseNamespace) === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Temukan direktori controller dari segments
     */
    protected function findControllerDirectory(array $segments): string
    {
        $directory = '';
        $controllerIndex = 0;
        $segmentCount = count($segments);

        for ($i = 0; $i < $segmentCount; $i++) {
            $potentialDir = implode('/', array_slice($segments, 0, $i + 1)) . '/';
            $potentialController = $segments[$i + 1] ?? null;
            
            if ($potentialController && $this->isControllerDirectory($potentialDir)) {
                $directory = $potentialDir;
                $controllerIndex = $i + 1;
            }
        }

        return $directory;
    }

    /**
     * Check if path is a controller directory
     */
    protected function isControllerDirectory(string $path): bool
    {
        $basePath = resolve_path(APPPATH, 'controllers/') . $path;
        return is_dir($basePath);
    }

    /**
     * Enrich routing with additional details
     */
    protected function enrichRoutingDetails(array $routing): array
    {
        // Gunakan directory temporary jika ada
        if (!empty($this->configuration['_temp_directory'])) {
            $routing['directory'] = $this->configuration['_temp_directory'];
            unset($this->configuration['_temp_directory']);
        }
        
        // Determine controller type
        $routing['type'] = $this->determineControllerType($routing);
        
        // Determine namespace
        $routing['namespace'] = $this->determineNamespace($routing);
        
        // Determine fully qualified class name
        $routing['fqcn'] = $this->determineFullyQualifiedClassName($routing);
        
        // Locate controller file
        $routing['file'] = $this->locateControllerFile($routing);
        
        // Validate method existence
        $routing['method_valid'] = $this->validateMethod($routing);
        
        return $routing;
    }

    protected function determineControllerType(array $routing): string
    {
        // Priority 1: Already fully qualified
        if (isset($routing['class']) && str_contains($routing['class'], '\\')) {
            return 'modern';
        }
    
        // Priority 2: Check from action string (if exists)
        if (isset($routing['action']) && is_string($routing['action']) && str_contains($routing['action'], '@')) {
            [$controller, $method] = explode('@', $routing['action'], 2);
            if (str_contains($controller, '\\')) {
                return 'modern';
            }
        }
    
        // Priority 3: Auto-detect jika fitur aktif
        if ($this->configuration['auto_detect_namespace']) {
            $detectedNamespace = $this->autoDetectNamespace($routing);
            if ($detectedNamespace !== null) {
                return 'modern';
            }
            
            // Coba juga dengan controller suffix
            if (isset($routing['class']) && !empty($routing['class'])) {
                $routingWithSuffix = $routing;
                $routingWithSuffix['class'] = ucfirst($routing['class']) . $this->configuration['controller_suffix'];
                
                $detectedNamespaceWithSuffix = $this->autoDetectNamespace($routingWithSuffix);
                if ($detectedNamespaceWithSuffix !== null) {
                    return 'modern';
                }
            }
        }
    
        // Priority 4: Check legacy controller file
        $legacyFile = $this->locateLegacyControllerFile($routing);
        if ($legacyFile && file_exists($legacyFile)) {
            return 'legacy';
        }
    
        return 'unknown';
    }

    /**
     * Clean directory path untuk namespace
     */
    protected function cleanDirectoryPathForNamespace(string $directory): string
    {
        if (empty($directory)) {
            return '';
        }
        
        $directory = str_replace('../', '', $directory);
        
        if (strpos($directory, 'modules/') === 0) {
            $directory = substr($directory, 8);
        }
        
        $segments = explode('/', $directory);
        
        $capitalizedSegments = array_map(function($segment) {
            if (empty($segment)) {
                return $segment;
            }
            return ucfirst($segment);
        }, $segments);
        
        $directory = implode('/', $capitalizedSegments);
        $directory = rtrim($directory, '/');
        
        return $directory;
    }

    /**
     * Determine namespace for controller
     */
    public function determineNamespace(array $routing): string
    {
        // Priority 1: Namespace dari route item (jika ada)
        if (isset($routing['namespace']) && !empty($routing['namespace'])) {
            return $this->normalizeNamespace($routing['namespace']);
        }

        // Priority 2: Jika class sudah fully qualified
        if (isset($routing['class']) && str_contains($routing['class'], '\\')) {
            $namespace = substr($routing['class'], 0, strrpos($routing['class'], '\\'));
            return $namespace;
        }

        // Priority 3: Dari action string (modern routes)
        if (isset($routing['action']) && is_string($routing['action'])) {
            if (str_contains($routing['action'], '@')) {
                [$controller, $method] = explode('@', $routing['action'], 2);
                if (str_contains($controller, '\\')) {
                    $namespace = substr($controller, 0, strrpos($controller, '\\'));
                    return $namespace;
                }
            }
        }

        // Priority 4: Auto-detect namespace jika fitur aktif
        if ($this->configuration['auto_detect_namespace'] && isset($routing['class'])) {
            $detectedNamespace = $this->autoDetectNamespace($routing);
            if ($detectedNamespace !== null) {
                return $detectedNamespace;
            }
        }

        // Priority 5: Default namespace dengan directory jika ada
        $cleanDirectory = $this->cleanDirectoryPathForNamespace($routing['directory'] ?? '');
        $namespacePath = !empty($cleanDirectory) ? str_replace('/', '\\', $cleanDirectory) . '\\' : '';
        $defaultNamespace = $this->configuration['namespaces']['app'] . $namespacePath;
        
        return rtrim($defaultNamespace, '\\');
    }

    /**
     * Auto-detect namespace by checking existing classes
     */
    private function autoDetectNamespace(array $routing): ?string
    {
        if (!isset($routing['class']) || empty($routing['class'])) {
            return null;
        }

        $className = ucfirst($routing['class']);
        $cleanDirectory = $this->cleanDirectoryPathForNamespace($routing['directory'] ?? '');
        
        // Cek dari namespace yang dikonfigurasi
        foreach ($this->configuration['namespaces'] as $namespace) {
            // Coba tanpa directory
            $fullClassName = rtrim($namespace, '\\') . '\\' . $className;
            if (class_exists($fullClassName)) {
                return rtrim($namespace, '\\');
            }
            
            // Coba dengan directory jika ada
            if (!empty($cleanDirectory)) {
                $namespacePath = str_replace('/', '\\', $cleanDirectory) . '\\';
                $fullClassName = rtrim($namespace, '\\') . '\\' . $namespacePath . $className;
                if (class_exists($fullClassName)) {
                    return rtrim($namespace, '\\') . '\\' . rtrim($namespacePath, '\\');
                }
            }
        }
        
        return null;
    }

    /**
     * Normalize namespace (remove trailing slashes, ensure proper format)
     */
    private function normalizeNamespace(string $namespace): string
    {
        $namespace = trim($namespace, '\\');
        return $namespace === '' ? '' : $namespace . '\\';
    }

    protected function determineFullyQualifiedClassName(array $routing): string
    {
        // Already fully qualified
        if (isset($routing['class']) && strpos($routing['class'], '\\') !== false) {
            return $routing['class'];
        }
    
        // Dari modern routing dengan action string
        if (isset($routing['action']) && is_string($routing['action'])) {
            if (strpos($routing['action'], '@') !== false) {
                list($controller, $method) = explode('@', $routing['action'], 2);
                if (strpos($controller, '\\') !== false) {
                    return $controller;
                }
            } else {
                $controller = $routing['action'];
                if (strpos($controller, '\\') !== false) {
                    return $controller;
                }
            }
        }
    
        // Coba build dari namespace dan class
        $namespace = $this->determineNamespace($routing);
        $className = isset($routing['class']) ? ucfirst($routing['class']) : '';
        
        $fqcn = $namespace . $className;
        
        // Try tanpa suffix dulu
        if (class_exists($fqcn)) {
            return $fqcn;
        }
        
        // Try dengan suffix
        $fqcnWithSuffix = $fqcn . $this->configuration['controller_suffix'];
        if (class_exists($fqcnWithSuffix)) {
            return $fqcnWithSuffix;
        }
    
        return $fqcn;
    }

    /**
     * Locate controller file
     */
    protected function locateControllerFile(array $routing): ?string
    {
        if ($routing['type'] === 'modern') {
            try {
                $reflection = new \ReflectionClass($routing['fqcn']);
                return $reflection->getFileName();
            } catch (\ReflectionException $e) {
                return null;
            }
        }

        return $this->locateLegacyControllerFile($routing);
    }

    /**
     * Locate legacy controller file
     */
    protected function locateLegacyControllerFile(array $routing): ?string
    {
        $basePath = resolve_path(APPPATH, 'controllers/');
        
        // Without suffix
        $filePath = $basePath . 
            $routing['directory'] . 
            ucfirst($routing['class']) . '.php';
        
        if (file_exists($filePath)) {
            return $filePath;
        }
        
        // With suffix
        $filePathWithSuffix = $basePath . 
            $routing['directory'] . 
            ucfirst($routing['class']) . 
            $this->configuration['controller_suffix'] . '.php';
        
        if (file_exists($filePathWithSuffix)) {
            return $filePathWithSuffix;
        }

        return null;
    }

    /**
     * Validate method existence
     */
    protected function validateMethod(array $routing): bool
    {
        if ($routing['type'] === 'modern') {
            if (!isset($routing['fqcn']) || !class_exists($routing['fqcn'])) {
                return false;
            }
            
            
            $method = strtolower($routing['method'] ?? 'index');
            $methods = get_class_methods($routing['fqcn']);
            
            return in_array('_remap', $methods) || 
                   in_array($method, array_map('strtolower', $methods)) ||
                   method_exists($routing['fqcn'], '__call');
        }

        if ($routing['type'] === 'legacy' && $routing['file']) {
            $className = ucfirst($routing['class']);
            
            if (isset($this->configuration['controller_suffix'])) {
                $suffixedName = $className . $this->configuration['controller_suffix'];
                if (class_exists($suffixedName)) {
                    $className = $suffixedName;
                }
            }
            
            if (class_exists($className)) {
                $methods = get_class_methods($className);
                $method = strtolower($routing['method']);
                
                return in_array('_remap', $methods) || 
                       in_array($method, array_map('strtolower', $methods)) ||
                       method_exists($className, '__call');
            }
        }
        

        return false;
    }

    /**
     * Check if routing is valid
     */
    protected function isValidRouting(array $routing): bool
    {
        return !empty($routing['class']) && !empty($routing['method']);
    }

    /**
     * Get error routing for 404
     */
    public function getErrorRouting(): array
    {
        $uri = '';
        
        if (isset($GLOBALS['URI'])) {
            $uri = $GLOBALS['URI']->uri_string ?? '';
        }
        
        $get = $_GET;
        unset($get['D'], $get['C'], $get['M'], $get['S']);
        
        $qs = '';
        if (!empty($get)) {
            $qs = '?' . http_build_query($get);
        }
        
        // Gunakan default 404 controller dari konfigurasi
        $controller = $this->configuration['default_404_controller'];
        $namespace = $this->configuration['default_404_namespace'];
        
        return [
            'class' => $controller,
            'method' => 'index',
            'segments' => [$uri . $qs],
            'type' => 'modern',
            'namespace' => $namespace,
            'fqcn' => $namespace . $controller,
            'source' => 'error',
            'uri' => $uri,
            'is_404' => true,
            'method_valid' => true
        ];
    }
    
    /**
     * Get simplified 404 routing untuk legacy compatibility
     */
    public function getLegacy404Routing(): array
    {
        return [
            'directory' => '',
            'class' => 'FileNotFound',
            'method' => 'index',
            'segments' => [],
            'type' => 'legacy',
            'source' => 'legacy_404',
            'file' => null,
            'is_404' => true
        ];
    }

    /**
     * Clear route cache
     */
    public function clearCache(): void
    {
        $this->routeCache = [];
        
        // Clear modern router cache juga
        if ($this->router && method_exists($this->router, 'clearCache')) {
            $this->router->clearCache();
        }
    }

    /**
     * Update configuration
     */
    public function setConfig(array $config): void
    {
        $this->configuration = array_merge($this->configuration, $config);
        $this->clearCache();
    }

    /**
     * Get configuration
     */
    public function getConfig(): array
    {
        return $this->configuration;
    }
    
    /**
     * Get current router instance
     */
    public function getRouter(): RouterInterface
    {
        return $this->router;
    }
    
    
    /**
     * Check if using modern routing
     */
    public function isModernRouting(): bool
    {
        return $this->configuration['enable_modern_routing'];
    }
    
    /**
     * Check if modern routing is enabled
     */
    public function isModernRoutingEnabled(): bool
    {
        return $this->configuration['enable_modern_routing'] ?? false;
    }
}
