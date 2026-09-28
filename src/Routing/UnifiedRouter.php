<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing;

use Kodhe\Framework\Exceptions\Http\{
    NotFoundException,
    BadRequestException,
    ForbiddenException
};
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;
use Kodhe\Framework\Support\Facades\Facade;
use Kodhe\Framework\Support\Modules;

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

/**
 * Unified Router - Menggabungkan Legacy dan Modern Routing
 */
class UnifiedRouter
{
    // =========== LEGACY PROPERTIES ===========
    public $routes = [];
    public $class = '';
    public $method = 'index';
    public $directory = '';
    public $default_controller = 'Welcome';
    public $translate_uri_dashes = false;
    public $enable_query_strings = false;
    public $uri;
    public $module = '';
    protected $located = 0;
    
    // =========== MODERN PROPERTIES ===========
    /**
     * @var RouteCollection
     */
    protected $collection;
    
    /**
     * @var array Current routing
     */
    protected $routing = [];
    
    
    // =========== SHARED CONSTANTS ===========
    protected const CONTROLLER_SEPARATORS = ['::', '@', '/'];
    protected const DEFAULT_CONTROLLER_NAMES = ['Home', 'Index', 'Main', 'Welcome'];
    protected const URI_DASH_REPLACEMENT_RANGE = [0, 1, 2];
    
    // =========== CONFIGURATION ===========
    protected $config = [
        'enable_modern_routing' => true,
        'enable_legacy_routing' => true,
        'prefer_modern' => true, // Modern first, legacy fallback
        'cache_routes' => ENVIRONMENT === 'production',
        'auto_detect_namespace' => true,
        'allow_namespace_in_routes' => true,
        'controller_suffix' => '',
        'default_404_controller' => 'FileNotFound',
        'default_404_namespace' => 'Kodhe\Framework\Controllers\\Error\\'
    ];
    
    // =========== CONSTRUCTOR ===========
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->config, $config);
        
        // Initialize legacy components
        $this->uri = new \Kodhe\Framework\Support\Legacy\URI();
        $this->enable_query_strings = (!is_cli() && app()->config->item('enable_query_strings') === true);
        
        // Initialize modern components
        $this->collection = new RouteCollection();
        Route::setCollection($this->collection);
        
        // Load routes
        $this->_load_routes();
        
        // Initialize modules
        Modules::init();
        
        log_message('info', 'UnifiedRouter Class Initialized');
    }
    
    // =========== CONFIGURATION METHODS ===========
    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }
    
    public function getConfig(): array
    {
        return $this->config;
    }
    
    // =========== ROUTE REGISTRATION (MODERN) ===========
    /**
     * Register a GET route
     */
    public function get(string $uri, $action): RouteItem
    {
        return $this->addRoute('GET', $uri, $action);
    }
    
    /**
     * Register a POST route
     */
    public function post(string $uri, $action): RouteItem
    {
        return $this->addRoute('POST', $uri, $action);
    }
    
    /**
     * Register a PUT route
     */
    public function put(string $uri, $action): RouteItem
    {
        return $this->addRoute('PUT', $uri, $action);
    }
    
    /**
     * Register a PATCH route
     */
    public function patch(string $uri, $action): RouteItem
    {
        return $this->addRoute('PATCH', $uri, $action);
    }
    
    /**
     * Register a DELETE route
     */
    public function delete(string $uri, $action): RouteItem
    {
        return $this->addRoute('DELETE', $uri, $action);
    }
    
    /**
     * Register a route for any HTTP method
     */
    public function any(string $uri, $action): RouteItem
    {
        return $this->addRoute('ANY', $uri, $action);
    }
    
    /**
     * Register a route for multiple HTTP methods
     */
    public function match(array $methods, string $uri, $action): RouteItem
    {
        $route = null;
        
        foreach ($methods as $method) {
            $method = strtoupper($method);
            if ($route === null) {
                $route = $this->addRoute($method, $uri, $action);
            } else {
                $this->addRoute($method, $uri, $action);
            }
        }
        
        return $route;
    }
    
    /**
     * Create a route group
     */
    public function group(array $attributes, callable $callback): void
    {
        $groupHandler = Route::getGroupHandlerInstance();
        
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
     * Create module route group
     */
    public function module(string $module, callable $callback, array $attributes = []): void
    {
        $defaultAttributes = [
            'prefix' => $module,
            'namespace' => 'App\\Modules\\' . ucfirst($module) . '\\Controllers\\',
            'as' => $module . '.',
            'module' => $module
        ];
        
        $groupAttributes = array_merge($defaultAttributes, $attributes);
        
        $this->group($groupAttributes, $callback);
    }
    
    /**
     * Add route dengan support untuk nested groups
     */
    protected function addRoute(string $method, string $uri, $action): RouteItem
    {
        $groupHandler = Route::getGroupHandlerInstance();
        
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
            $generatedName = $this->generateRouteName($routeUri);
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
        
        // Add to collection
        $this->collection->add($routeItem);
        
        // Jika itu ANY route, add ke semua methods
        if ($method === 'ANY') {
            foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] as $httpMethod) {
                $this->collection->add($routeItem);
            }
        }
        
        return $routeItem;
    }
    
    /**
     * Generate route name dari URI
     */
    protected function generateRouteName(string $uri): string
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
    
    // =========== ROUTE LOADING ===========
    /**
     * Load routes from config files (LEGACY + MODERN)
     */
    protected function _load_routes(): void
    {
        $route = [];
        
        // ===== LEGACY ROUTES =====
        // Load main routes
        if (file_exists($routesFile = app_config_file_in(APPPATH, 'routes.php'))) {
            include($routesFile);
        }

        // Load environment routes
        if (file_exists($routesFile = app_config_file_in(APPPATH, ENVIRONMENT.'/routes.php'))) {
            include($routesFile);
        }

        // Validate & get reserved routes
        if (isset($route) && is_array($route)) {
            // Set default controller dengan fallback
            if (isset($route['default_controller'])) {
                $this->default_controller = $route['default_controller'];
            } else {
                // Default fallback jika tidak ada di config
                $this->default_controller = 'welcome';
            }
            
            // Set translate uri dashes
            if (isset($route['translate_uri_dashes'])) {
                $this->translate_uri_dashes = $route['translate_uri_dashes'];
            }
            
            // Remove reserved keys
            unset($route['default_controller'], $route['translate_uri_dashes']);
            $this->routes = $route;
        } else {
            // Jika tidak ada route config, set default
            $this->default_controller = 'welcome';
        }
        
        // ===== MODERN ROUTES =====
        // Coba load dari cache dulu
        if ($this->collection->loadFromCache()) {
            log_message('debug', 'Routes loaded from cache');
            return;
        }
        
        $routeFiles = [];

        // Tambahkan route files dari semua lokasi module
        $moduleLocations = Modules::folders();
        foreach ($moduleLocations as $location) {
            $modules = $this->scanModulesInLocation($location);
            
            foreach ($modules as $module) {
                // Set module context
                $this->module = $module;
                
                // Load module routes
                $moduleWebRoutes = $location . $module . '/routes/web.php';
                if (file_exists($moduleWebRoutes)) {
                    $routeFiles[] = $moduleWebRoutes;
                }
                
                $moduleApiRoutes = $location . $module . '/routes/api.php';
                if (file_exists($moduleApiRoutes)) {
                    $routeFiles[] = $moduleApiRoutes;
                }
                
                // Reset module context
                $this->module = '';
            }
        }

        // Add base routes
        $routeFiles[] = APPPATH . 'routes/api.php';
        $routeFiles[] = APPPATH . 'routes/console.php';
        $routeFiles[] = APPPATH . 'routes/web.php';
 
        // Load semua file route
        foreach ($routeFiles as $file) {
            if (file_exists($file)) {
                require $file;
            }
        }

        // Cache routes
        if ($this->config['cache_routes']) {
            $this->collection->cache();
        }
        
        log_message('debug', 'Routes loaded from files');
    }
    
    /**
     * Scan modules dalam sebuah lokasi
     */
    protected function scanModulesInLocation(string $location): array
    {
        $modules = [];
        
        if (!is_dir($location)) {
            return $modules;
        }
        
        // Scan directory untuk module
        $items = scandir($location);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $modulePath = $location . $item;
            if (is_dir($modulePath)) {
                $modules[] = $item;
            }
        }
        
        return $modules;
    }
    
    // =========== ROUTE RESOLUTION ===========
    /**
     * Unified route resolution - mencoba modern dulu, lalu legacy
     */
    public function resolve(Request $request): array
    {
        // Priority 1: Modern routing
        if ($this->config['enable_modern_routing'] && $this->config['prefer_modern']) {
            $modernRouting = $this->resolveModern($request);
            if ($this->isValidRouting($modernRouting)) {
                return $this->enrichRouting($modernRouting, 'modern');
            }
        }
        
        // Priority 2: Legacy routing
        if ($this->config['enable_legacy_routing']) {
            $legacyRouting = $this->resolveLegacy($request);
            if ($this->isValidRouting($legacyRouting)) {
                //return $this->enrichRouting($legacyRouting, 'legacy');
            }
        }
        
        // Priority 3: Modern routing sebagai fallback
        if ($this->config['enable_modern_routing'] && !$this->config['prefer_modern']) {
            $modernRouting = $this->resolveModern($request);
            if ($this->isValidRouting($modernRouting)) {
                return $this->enrichRouting($modernRouting, 'modern');
            }
        }
        
        // Priority 4: Default controller
        $defaultRouting = $this->resolveDefault();
        
        if ($this->isValidRouting($defaultRouting)) {
            return $this->enrichRouting($defaultRouting, 'legacy');
        }
        
        // Priority 5: 404
        return $this->getErrorRouting();
    }
    
    /**
     * Resolve modern route
     */
    protected function resolveModern(Request $request): array
    {
        try {
            $route = $this->collection->match($request);
            
            if (!$route) {
                return [];
            }
            
            // Extract routing information
            $action = $route->getAction();
            $parameters = $route->getParameters();
            
            // Determine controller and method
            if ($action instanceof \Closure) {
                $class = 'Closure';
                $method = '__invoke';
            } elseif (is_string($action)) {
                if (strpos($action, '@') !== false) {
                    list($class, $method) = explode('@', $action, 2);
                } else {
                    $class = $action;
                    $method = 'index';
                }
            } elseif (is_array($action)) {
                @list($class, $method) = $action;
            } else {
                return [];
            }
            
            if(strpos($class, '\\') === false && $route->getNamespace()) {
                $class = $route->getNamespace().'\\'.ucfirst($class);
            }
            
            $routing = [
                'class' => $class,
                'method' => $method,
                'segments' => array_values($parameters),
                'type' => 'modern',
                'source' => 'modern',
                'route' => $route,
                'parameters' => $parameters,
                'middleware' => $route->getMiddleware(),
                'namespace' => $route->getNamespace(),
                '_route_item' => $route
            ];
            
            // Extract module dari route
            $routing['module'] = $this->extractModuleFromRoute($route, $routing);
            $this->module = $routing['module'];
            
            return $routing;
            
        } catch (\Exception $e) {
            log_message('error', 'Modern routing error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Resolve legacy route
     */
    protected function resolveLegacy(Request $request): array
    {
        try {
            // Setup legacy routing
            $this->_set_routing();
            
            $directory = $this->fetch_directory();
            $class = $this->fetch_class();
            $method = $this->fetch_method();
            
            if (empty($class)) {
                return [];
            }
            
            $segments = [];
            if (!empty($this->uri->rsegment_array())) {
                $source = $this->uri->rsegment_array();
                $startIndex = (count($source) > 2) ? 2 : 1;
                $segments = array_slice($source, $startIndex);
                
                if (isset($segments[0]) && $segments[0] === $method) {
                    array_shift($segments);
                }
            }
            
            return [
                'directory' => $directory,
                'class' => $class,
                'method' => $method,
                'segments' => $segments,
                'module' => $this->module,
                'type' => 'legacy',
                'source' => 'legacy'
            ];
            
        } catch (\Exception $e) {
            log_message('error', 'Legacy routing error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Resolve default controller
     */
    protected function resolveDefault(): array
    {
        $this->_set_default_controller();
        
        return [
            'directory' => $this->fetch_directory(),
            'class' => $this->fetch_class(),
            'method' => $this->fetch_method(),
            'segments' => [],
            'module' => $this->module,
            'type' => 'legacy',
            'source' => 'default'
        ];
    }
    
    /**
     * Enrich routing dengan informasi tambahan
     */
    protected function enrichRouting(array $routing, string $type): array
    {
        $routing['type'] = $type;
        $routing['router_type'] = $type;
        
        // Extract module jika belum ada
        if (empty($routing['module'])) {
            $routing['module'] = $this->extractModuleFromRouting($routing);
            $this->module = $routing['module'];
        }
        
        // Set module di Modules registry
        if (!empty($routing['module']) && method_exists('Modules', 'setCurrentModule')) {
            Modules::setCurrentModule($routing['module']);
        }
        
        // Untuk modern routing, tambahkan FQCN
        if ($type === 'modern' && !empty($routing['class']) && empty($routing['fqcn'])) {
            if (strpos($routing['class'], '\\') !== false) {
                $routing['fqcn'] = $routing['class'];
            } elseif (!empty($routing['namespace'])) {
                $routing['fqcn'] = rtrim($routing['namespace'], '\\') . '\\' . $routing['class'];
            }
        }

        // Tambahkan FQCN untuk tipe routing lain (mis. 'error' dari getErrorRouting)
        // agar ControllerExecutor::handle404() bisa mengeksekusi controller 404
        // via executeModernController(), bukan melempar NotFoundException mentah.
        if ($type !== 'modern' && !empty($routing['class']) && empty($routing['fqcn'])) {
            if (strpos($routing['class'], '\\') !== false) {
                $routing['fqcn'] = $routing['class'];
            } elseif (!empty($routing['namespace'])) {
                $routing['fqcn'] = rtrim($routing['namespace'], '\\') . '\\' . $routing['class'];
            }
        }
        
        // Untuk legacy routing, tambahkan file path
        if ($type === 'legacy' && !empty($routing['class']) && empty($routing['file'])) {
            $routing['file'] = $this->locateLegacyControllerFile($routing);
        }
        
        return $routing;
    }
    
    /**
     * Extract module dari RouteItem
     */
    protected function extractModuleFromRoute(RouteItem $route, array $routing): string
    {
        // Priority 1: Dari namespace route
        $namespace = $route->getNamespace();
        if (!empty($namespace)) {
            if (preg_match('#Modules\\\([^\\\]+)#i', $namespace, $matches)) {
                $module = strtolower($matches[1]);
                if (Modules::moduleExists($module)) {
                    return $module;
                }
            }
        }
        
        // Priority 2: Dari controller class
        $class = $routing['class'] ?? '';
        if (!empty($class)) {
            if (preg_match('#Modules\\\([^\\\]+)#i', $class, $matches)) {
                $module = strtolower($matches[1]);
                if (Modules::moduleExists($module)) {
                    return $module;
                }
            }
        }
        
        // Priority 3: Dari URI pattern
        $uri = $route->getUri();
        if (preg_match('#^/([^/]+)/#', $uri, $matches)) {
            $module = $matches[1];
            if (Modules::moduleExists($module)) {
                return $module;
            }
        }
        
        // Priority 4: Dari route group attributes
        $groupAttributes = Route::getGroupAttributes();
        if (!empty($groupAttributes['module'])) {
            return $groupAttributes['module'];
        }
        
        return '';
    }
    
    /**
     * Extract module dari routing
     */
    protected function extractModuleFromRouting(array $routing): string
    {
        // Priority 1: Dari routing itu sendiri
        if (!empty($routing['module'])) {
            return $routing['module'];
        }
        
        // Priority 2: Dari directory
        if (!empty($routing['directory'])) {
            // Pattern: modules/{module}/controllers/
            if (preg_match('#modules/([^/]+)/controllers/#', $routing['directory'], $matches)) {
                $module = $matches[1];
                if (Modules::moduleExists($module)) {
                    return $module;
                }
            }
            
            // Pattern: ../modules/{module}/controllers/
            if (preg_match('#\.\./modules/([^/]+)/controllers/#', $routing['directory'], $matches)) {
                $module = $matches[1];
                if (Modules::moduleExists($module)) {
                    return $module;
                }
            }
        }
        
        // Priority 3: Dari namespace
        if (!empty($routing['namespace'])) {
            if (preg_match('#(?:App\\\|Kodhe\\\)Modules\\\([^\\\]+)#i', $routing['namespace'], $matches)) {
                $module = strtolower($matches[1]);
                if (Modules::moduleExists($module)) {
                    return $module;
                }
            }
        }
        
        // Priority 4: Dari class name
        if (!empty($routing['class'])) {
            if (strpos($routing['class'], '\\') !== false) {
                if (preg_match('#Modules\\\([^\\\]+)#i', $routing['class'], $matches)) {
                    $module = strtolower($matches[1]);
                    if (Modules::moduleExists($module)) {
                        return $module;
                    }
                }
            }
        }
        
        return '';
    }
    
    /**
     * Locate legacy controller file
     */
    protected function locateLegacyControllerFile(array $routing): ?string
    {
        // Jika ada module, coba cari via Modules
        if (!empty($routing['module'])) {
            $controllerFile = Modules::file_path($routing['module'], 'controllers', $routing['class']);
            if ($controllerFile) {
                return $controllerFile;
            }
        }
        
        $basePath = resolve_path(APPPATH, 'controllers/');
        
        // Without suffix
        $filePath = $basePath . 
            ($routing['directory'] ?? '') . 
            ucfirst($routing['class']) . '.php';
        
        if (file_exists($filePath)) {
            return $filePath;
        }
        
        // With suffix
        $suffix = $this->config['controller_suffix'] ?? '';
        if ($suffix) {
            $filePathWithSuffix = $basePath . 
                ($routing['directory'] ?? '') . 
                ucfirst($routing['class']) . $suffix . '.php';
            
            if (file_exists($filePathWithSuffix)) {
                return $filePathWithSuffix;
            }
        }

        return null;
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
        $controller = $this->config['default_404_controller'];
        $namespace = $this->config['default_404_namespace'];
        
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
    
    // =========== LEGACY METHODS (Untuk Compatibility) ===========
    /**
     * Legacy _set_routing method
     */
    public function _set_routing()
    {
        // Routes sudah diload di constructor
        
        if ($this->enable_query_strings)
        {
            // If the directory is set at this time, it means an override exists, so skip the checks
            if ( ! isset($this->directory))
            {
                $_d = app()->config->item('directory_trigger');
                $_d = isset($_GET[$_d]) ? trim($_GET[$_d], " \t\n\r\0\x0B/") : '';

                if ($_d !== '')
                {
                    $this->uri->filter_uri($_d);
                    $this->set_directory($_d);
                }
            }

            $_c = trim(app()->config->item('controller_trigger'));
            if ( ! empty($_GET[$_c]))
            {
                $this->uri->filter_uri($_GET[$_c]);
                $this->set_class($_GET[$_c]);

                $_f = trim(app()->config->item('function_trigger'));
                if ( ! empty($_GET[$_f]))
                {
                    $this->uri->filter_uri($_GET[$_f]);
                    $this->set_method($_GET[$_f]);
                }

                $this->uri->_set_rsegments(array(
                    1 => $this->class,
                    2 => $this->method
                ));
            }
            else
            {
                $this->_set_default_controller();
            }

            return;
        }

        // Is there anything to parse?
        if ($this->uri->uri_string !== '')
        {
            $this->_parse_routes();
        }
        else
        {
            $this->_set_default_controller();
        }
    }
    
    protected function _set_default_controller()
    {
        log_message('debug', '_set_default_controller called');
        log_message('debug', 'Default controller config: ' . $this->default_controller);
        log_message('debug', 'Current directory: ' . $this->directory);
        
        if (empty($this->default_controller))
        {
            log_message('error', 'Default controller is empty');
            
            // Coba dapatkan dari config
            $default = app()->config->item('default_controller');
            if (!empty($default)) {
                $this->default_controller = $default;
                log_message('debug', 'Using default controller from config: ' . $default);
            } else {
                // Fallback ke welcome
                $this->default_controller = 'welcome';
                log_message('debug', 'Using fallback default controller: welcome');
            }
        }

        // Is the method being specified?
        $class = $this->default_controller;
        $method = 'index';
        
        if (sscanf($this->default_controller, '%[^/]/%s', $class, $method) !== 2)
        {
            // Default method is index
            $method = 'index';
        }
        
        // Clean class name
        $class = str_replace('.php', '', $class);
        
        log_message('debug', 'Parsed default controller - Class: ' . $class . ', Method: ' . $method);
        log_message('debug', 'Looking for controller: ' . APPPATH.'controllers/'.$this->directory.ucfirst($class).'.php');

        // Check if controller file exists
        $controller_file = APPPATH.'controllers/'.$this->directory.ucfirst($class).'.php';
        if ( ! file_exists($controller_file))
        {
            log_message('error', 'Controller file not found: ' . $controller_file);
            
            // Coba dengan suffix
            $suffix = app()->config->item('controller_suffix');
            if ($suffix) {
                $controller_file_with_suffix = APPPATH.'controllers/'.$this->directory.ucfirst($class).$suffix.'.php';
                log_message('debug', 'Trying with suffix: ' . $controller_file_with_suffix);
                
                if (file_exists($controller_file_with_suffix)) {
                    $controller_file = $controller_file_with_suffix;
                    $class = $class . $suffix;
                    log_message('debug', 'Found controller with suffix: ' . $controller_file);
                }
            }
            
            // Jika masih tidak ditemukan, coba lowercase
            if (!file_exists($controller_file)) {
                $controller_file_lower = APPPATH.'controllers/'.$this->directory.strtolower($class).'.php';
                log_message('debug', 'Trying lowercase: ' . $controller_file_lower);
                
                if (file_exists($controller_file_lower)) {
                    $controller_file = $controller_file_lower;
                    $class = strtolower($class);
                    log_message('debug', 'Found lowercase controller: ' . $controller_file);
                }
            }
            
            // Jika tetap tidak ditemukan, show error
            if (!file_exists($controller_file)) {
                log_message('error', 'Default controller not found after all attempts');
                
                // Jangan langsung show_error, biarkan system handle 404
                $this->class = 'Kodhe\Framework\Controllers\Error\FileNotFound';
                $this->method = 'index';
                return;
            }
        }

        $this->set_class($class);
        $this->set_method($method);

        // Assign routed segments, index starting from 1
        $this->uri->_set_rsegments(array(
            1 => $class,
            2 => $method
        ));

        log_message('debug', 'Default controller set: ' . $class . '::' . $method . '()');
    }
    
    protected function _parse_routes()
    {
        $uri = implode('/', $this->uri->segment_array());

        $http_verb = isset($_SERVER['REQUEST_METHOD']) ? strtolower($_SERVER['REQUEST_METHOD']) : 'cli';

        foreach ($this->routes as $key => $val)
        {
            if (is_array($val))
            {
                $val = array_change_key_case($val, CASE_LOWER);
                if (isset($val[$http_verb]))
                {
                    $val = $val[$http_verb];
                }
                else
                {
                    continue;
                }
            }

            $key = str_replace(array(':any', ':num'), array('[^/]+', '[0-9]+'), $key);

            if (preg_match('#^'.$key.'$#', $uri, $matches))
            {
                if ( ! is_string($val) && is_callable($val))
                {
                    array_shift($matches);

                    $val = call_user_func_array($val, $matches);
                }
                elseif (strpos($val, '$') !== FALSE && strpos($key, '(') !== FALSE)
                {
                    $val = preg_replace('#^'.$key.'$#', $val, $uri);
                }

                $this->_set_request(explode('/', $val));
                return;
            }
        }

        $this->_set_request(array_values($this->uri->segment_array()));
    }
    
    protected function _set_request(array $segments = [])
    {
        $segments = $this->normalizeSegments($segments);
        
        if ($this->shouldHandleDefaultController($segments)) {
            $this->handleDefaultController();
            return;
        }
        
        $this->resetRequestState();
        
        if ($this->tryParseNamespaceSegments($segments)) {
            return;
        }
        
        $this->processRegularRouting($segments);
    }
    
    protected function handleDefaultController(): void
    {
        $this->_set_default_controller();
    }

    protected function resetRequestState(): void
    {
        $this->located = 0;
        $this->class = '';
        $this->method = '';
        $this->module = '';
    }
    
    protected function tryParseNamespaceSegments(array &$segments): bool
    {
        $parsedSegments = $this->parseNamespaceSegments($segments);
        
        if ($this->located > 0 && !empty($this->class)) {
            // Set module dari namespace jika ada
            $this->extractModuleFromNamespace();
            return true;
        }
        
        if ($parsedSegments !== $segments && !empty($parsedSegments)) {
            $segments = $parsedSegments;
        }
        
        return false;
    }
    
    protected function processRegularRouting(array $segments): void
    {
        // Apply module routes terlebih dahulu
        $segments = $this->applyModuleRoutes($segments);
        
        $locatedSegments = $this->locate($segments);
        
        if ($this->shouldShow404()) {
            $this->handle404();
            return;
        }
        
        $this->processLocatedSegments($locatedSegments, $segments);
        
        // Extract module setelah routing ditemukan
        $this->extractModule();
    }
    
    protected function handle404(): void
    {
        if (!empty($this->routes['404_override'])) {
            $this->handle404Override();
        } 
    }
    
    protected function processLocatedSegments(array $locatedSegments, array $originalSegments): void
    {
        if (!empty($locatedSegments)) {
            $this->processSegments($locatedSegments);
        } else {
            $this->processSegments($originalSegments);
        }
    }
    
    protected function normalizeSegments(array $segments): array
    {
        if ($this->translate_uri_dashes !== true) {
            return $segments;
        }
        
        foreach (self::URI_DASH_REPLACEMENT_RANGE as $index) {
            if (isset($segments[$index])) {
                $segments[$index] = str_replace('-', '_', $segments[$index]);
            }
        }
        
        return $segments;
    }
    
    protected function shouldHandleDefaultController(array $segments): bool
    {
        return empty($segments) || (count($segments) === 1 && empty($segments[0]));
    }
    
    protected function shouldShow404(): bool
    {
        return $this->located == -1;
    }
    
    protected function processSegments(array $segments): void
    {
        if (!empty($this->class)) {
            return;
        }
        
        if (count($segments) === 1 && $this->located === 1) {
            $segments[1] = 'index';
        }
        
        $this->set_class($segments[0]);
        $this->set_method($segments[1] ?? 'index');
        
        $this->uri->_set_rsegments($this->prepareRsegments($segments));
    }
    
    protected function prepareRsegments(array $segments): array
    {
        array_unshift($segments, null);
        unset($segments[0]);
        return $segments;
    }
    
    protected function parseNamespaceSegments(array $segments): array
    {
        if (empty($segments[0]) || strpos($segments[0], '\\') === false) {
            return $segments;
        }
        
        $fullPath = $segments[0];
        $method = 'index';
        $controllerClass = $fullPath;
        
        foreach (self::CONTROLLER_SEPARATORS as $separator) {
            $pos = strrpos($fullPath, $separator);
            if ($pos !== false) {
                $controllerClass = substr($fullPath, 0, $pos);
                $method = substr($fullPath, $pos + strlen($separator));
                break;
            }
        }
        
        if (class_exists($controllerClass)) {
            $this->setNamespaceController($controllerClass, $method, $segments);
            return [$controllerClass, $method];
        }
        
        return $segments;
    }
    
    protected function setNamespaceController(string $controllerClass, string $method, array $segments): void
    {
        $this->set_class($controllerClass);
        $this->set_method($method);
        
        $rsegments = [
            1 => $controllerClass,
            2 => $method
        ];
        
        $params = array_slice($segments, 1);
        if (!empty($params)) {
            $index = 3;
            foreach ($params as $param) {
                $rsegments[$index++] = $param;
            }
        }
        
        $this->uri->_set_rsegments($rsegments);
        
        $this->located = 1;
    }
    
    /**
     * Apply module routes dengan Modules class
     */
    protected function applyModuleRoutes(array $segments): array
    {
        if (!isset($segments[0])) {
            return $segments;
        }
        
        $route = implode('/', $segments);
        $firstSegment = $segments[0];
        
        // Gunakan Modules::parse_routes untuk module routing
        $parsed = Modules::parse_routes($firstSegment, $route);
        
        if ($parsed && is_array($parsed)) {
            log_message('debug', "Module route matched: {$route} -> " . implode('/', $parsed));
            
            // Set module dari parsed route
            if (!empty($parsed[0])) {
                $this->module = $parsed[0];
            }
            
            return $parsed;
        }
        
        return $segments;
    }
    
    protected function locate(array $segments): array
    {
        $this->located = 0;
        $this->module = ''; // Reset module
        
        // Apply module routes
        $segments = $this->applyModuleRoutes($segments);
        
        if ($this->shouldReturnEmptySegments($segments)) {
            return $this->handleNoSegments();
        }
        
        $parsed = $this->parseSegments($segments);
        
        $result = $this->locateInModules($parsed);
        if ($result !== null) {
            // Module ditemukan, set module property
            if (!empty($this->module)) {
                // Module sudah diset di locateInModules
            } elseif (!empty($parsed['module']) && Modules::moduleExists($parsed['module'])) {
                $this->module = $parsed['module'];
            }
            return $result;
        }
        
        $result = $this->locateInControllers($parsed);
        if ($result !== null) {
            $this->extractModuleFromDirectory();
            return $result;
        }
        
        return $this->handleNotFound($segments);
    }
    
    protected function shouldReturnEmptySegments(array $segments): bool
    {
        return empty($segments) || empty($segments[0]);
    }
    
    protected function parseSegments(array $segments): array
    {
        $params = [];
        if (count($segments) > 3) {
            $params = array_slice($segments, 3);
        }
        
        return [
            'module' => $segments[0] ?? null,
            'controller' => $segments[1] ?? null,
            'method' => $segments[2] ?? null,
            'params' => $params
        ];
    }
    
    protected function handleNoSegments(): array
    {
        $this->located = -1;
        return [];
    }
    
    protected function locateInModules(array $parsed): ?array
    {
        foreach (Modules::$locations as $location => $offset) {
            $result = $this->checkModuleLocation($location, $offset, $parsed);
            if ($result !== null) {
                return $result;
            }
        }
        
        return null;
    }
    
    protected function checkModuleLocation(string $location, string $offset, array $parsed): ?array
    {
        $source = $location . $parsed['module'] . '/controllers/';
        
        if (!is_dir($source)) {
            return null;
        }
        
        // Set module property
        $this->module = $parsed['module'];
        $this->directory = $offset . $parsed['module'] . '/controllers/';
        
        if (empty($parsed['controller'])) {
            return $this->handleModuleOnly($source, $parsed);
        }
        
        return $this->handleModuleWithController($source, $parsed);
    }
    
    protected function handleModuleOnly(string $source, array $parsed): ?array
    {
        $moduleController = $source . ucfirst($parsed['module']) . '.php';
        if (is_file($moduleController)) {
            $this->located = 1;
            return [$parsed['module'], 'index'];
        }
        
        $homeController = $source . 'Home' . '.php';
        if (is_file($homeController)) {
            $this->located = 1;
            return ['Home', 'index'];
        }
        
        return null;
    }
    
    protected function handleModuleWithController(string $source, array $parsed): ?array
    {
        $controllerFile = $source . ucfirst($parsed['controller']) . '.php';
    
        if (is_file($controllerFile)) {
            $this->located = 2;
            
            return $this->buildReturnArray(
                $parsed['controller'], 
                $parsed['method'], 
                $parsed['params']
            );
        }
        
        $moduleController = $source . ucfirst($parsed['module']) . '.php';
        if (is_file($moduleController) && $this->isValidMethod($parsed['module'], $parsed['controller'])) {
            $this->located = 1;
            return $this->buildReturnArray($parsed['module'], $parsed['controller'], $parsed['params'], $parsed['method']);
        }
        
        return $this->checkModuleSubfolders($source, $parsed);
    }
    
    protected function checkModuleSubfolders(string $source, array $parsed): ?array
    {
        $subfolderController = $source . $parsed['controller'] . '/' . ucfirst($parsed['controller']) . '.php';
        if (is_file($subfolderController)) {
            $this->directory .= $parsed['controller'] . '/';
            $this->located = 2;
            return $this->buildReturnArray($parsed['controller'], $parsed['method'], $parsed['params']);
        }
        
        $subfolders = glob($source . '*/', GLOB_ONLYDIR);
        foreach ($subfolders as $subfolder) {
            $subfolderName = basename($subfolder);
            $subController = $subfolder . ucfirst($parsed['controller']) . '.php';
            
            if (is_file($subController)) {
                $this->directory .= $subfolderName . '/';
                $this->located = 2;
                return $this->buildReturnArray($parsed['controller'], $parsed['method'], $parsed['params']);
            }
        }
        
        return null;
    }
    
    protected function locateInControllers(array $parsed): ?array
    {
        $controllersPath = resolve_path(APPPATH, 'controllers');
        
        return $this->checkRootController($controllersPath, $parsed)
            ?? $this->checkControllerSubfolder($controllersPath, $parsed)
            ?? $this->checkDirectoryControllerPattern($controllersPath, $parsed);
    }
    
    protected function checkRootController(string $controllersPath, array $parsed): ?array
    {
        $rootController = $controllersPath . ucfirst($parsed['module']) . '.php';
        
        if (!is_file($rootController)) {
            return null;
        }
        
        $this->located = 1;
        
        if (!empty($parsed['controller'])) {
            if ($this->isValidMethod($parsed['module'], $parsed['controller'])) {
                return $this->buildReturnArray($parsed['module'], $parsed['controller'], $parsed['params'], $parsed['method']);
            }
        }
        
        return $this->buildReturnArray($parsed['module'], 'index', $parsed['params'], $parsed['controller']);
    }
    
    protected function checkControllerSubfolder(string $controllersPath, array $parsed): ?array
    {
        $subfolderPath = $controllersPath . $parsed['module'] . '/';
        
        if (!is_dir($subfolderPath)) {
            return null;
        }
        
        $this->directory = $parsed['module'] . '/';
        
        if (empty($parsed['controller'])) {
            return $this->findDefaultInSubfolder($subfolderPath, $parsed);
        }
        
        return $this->findControllerInSubfolder($subfolderPath, $parsed);
    }
    
    protected function findDefaultInSubfolder(string $subfolderPath, array $parsed): ?array
    {
        foreach (self::DEFAULT_CONTROLLER_NAMES as $default) {
            $defaultController = $subfolderPath . $default . '.php';
            if (is_file($defaultController)) {
                $this->located = 1;
                return $this->buildReturnArray($default, 'index', $parsed['params']);
            }
        }
        
        return null;
    }
    
    protected function findControllerInSubfolder(string $subfolderPath, array $parsed): ?array
    {
        $controllerFile = $subfolderPath . ucfirst($parsed['controller']) . '.php';
        if (is_file($controllerFile)) {
            $this->located = 2;
            return $this->buildReturnArray($parsed['controller'], $parsed['method'], $parsed['params']);
        }
        
        $lowerController = $subfolderPath . strtolower($parsed['controller']) . '.php';
        if (is_file($lowerController)) {
            $this->located = 2;
            return $this->buildReturnArray($parsed['controller'], $parsed['method'], $parsed['params']);
        }
        
        return $this->checkNestedSubfolder($subfolderPath, $parsed);
    }
    
    protected function checkNestedSubfolder(string $subfolderPath, array $parsed): ?array
    {
        $nestedPath = $subfolderPath . $parsed['controller'] . '/';
        
        if (!is_dir($nestedPath)) {
            return null;
        }
        
        $searchNames = array_merge([ucfirst($parsed['controller'])], self::DEFAULT_CONTROLLER_NAMES);
        
        foreach ($searchNames as $name) {
            $nestedController = $nestedPath . $name . '.php';
            if (is_file($nestedController)) {
                $this->directory .= $parsed['controller'] . '/';
                $this->located = 3;
                return $this->buildReturnArray($name, $parsed['method'], $parsed['params']);
            }
        }
        
        return null;
    }
    
    protected function checkDirectoryControllerPattern(string $controllersPath, array $parsed): ?array
    {
        if (empty($parsed['controller']) || !empty($this->directory)) {
            return null;
        }
        
        $dirs = glob($controllersPath . '*/', GLOB_ONLYDIR);
        
        foreach ($dirs as $dir) {
            $dirName = basename($dir);
            $controllerFile = $dir . ucfirst($parsed['controller']) . '.php';
            
            if (is_file($controllerFile)) {
                $this->directory = $dirName . '/';
                $this->located = 2;
                return $this->buildReturnArray($parsed['controller'], $parsed['method'], $parsed['params']);
            }
        }
        
        return null;
    }
    
    protected function handleNotFound(array $segments): array
    {
        $this->located = -1;
        return $segments;
    }
    
    protected function buildReturnArray(string $controller, ?string $method, array $params = [], ?string $extraMethod = null): array
    {
        $returnArray = [$controller, $method ?: 'index'];
        
        if ($extraMethod !== null && $extraMethod !== 'index') {
            $returnArray[] = $extraMethod;
        }
        
        if (!empty($params)) {
            $returnArray = array_merge($returnArray, $params);
        }
        
        return $returnArray;
    }
    
    protected function isValidMethod(string $class, string $method): bool
    {
        return true;
    }
    
    /**
     * Extract module dari namespace controller
     */
    protected function extractModuleFromNamespace(): void
    {
        if (empty($this->class)) {
            return;
        }
        
        // Coba extract dari class name jika namespace
        if (strpos($this->class, '\\') !== false) {
            if (preg_match('#(?:App\\\|Kodhe\\\)Modules\\\([^\\\]+)#i', $this->class, $matches)) {
                $module = strtolower($matches[1]);
                if (Modules::moduleExists($module)) {
                    $this->module = $module;
                    return;
                }
            }
        }
    }
    
    /**
     * Extract module dari directory path
     */
    protected function extractModuleFromDirectory(): void
    {
        if (empty($this->directory)) {
            return;
        }
        
        // Pattern: modules/{module}/controllers/
        if (preg_match('#modules/([^/]+)/controllers/#', $this->directory, $matches)) {
            $module = $matches[1];
            if (Modules::moduleExists($module)) {
                $this->module = $module;
                return;
            }
        }
        
        // Pattern: ../modules/{module}/controllers/
        if (preg_match('#\.\./modules/([^/]+)/controllers/#', $this->directory, $matches)) {
            $module = $matches[1];
            if (Modules::moduleExists($module)) {
                $this->module = $module;
                return;
            }
        }
        
        // Pattern: {module}/ (subfolder di controllers/)
        if (preg_match('#^([^/]+)/#', $this->directory, $matches)) {
            $module = $matches[1];
            if (Modules::moduleExists($module)) {
                $this->module = $module;
                return;
            }
        }
    }
    
    /**
     * Extract module dari routing
     */
    protected function extractModule(): void
    {
        // Priority 1: Coba dari class namespace
        $this->extractModuleFromNamespace();
        
        // Priority 2: Coba dari directory
        if (empty($this->module)) {
            $this->extractModuleFromDirectory();
        }
        
        // Priority 3: Coba dari URI segments
        $segmentArray = $this->uri->segment_array();
        if (empty($this->module) && !empty($segmentArray[0])) {
            $firstSegment = $segmentArray[0];
            if (Modules::moduleExists($firstSegment)) {
                $this->module = $firstSegment;
            }
        }
        
        // Set module di Modules registry jika ada method
        if (!empty($this->module) && method_exists('Modules', 'setCurrentModule')) {
            Modules::setCurrentModule($this->module);
        }
    }
    
    // =========== PUBLIC GETTER METHODS ===========
    public function set_class($class): void
    {
        // Clean class name
        $class = str_replace(array('/', '.'), '', $class);
        
        $suffix = app()->config->item('controller_suffix');
        if ($suffix && strpos($class, $suffix) === FALSE)
        {
            // Cek jika file dengan suffix ada
            $controller_file = APPPATH.'controllers/'.$this->directory.ucfirst($class).$suffix.'.php';
            if (file_exists($controller_file)) {
                $class .= $suffix;
                log_message('debug', 'Adding suffix to class: ' . $class);
            }
        }

        $this->class = $class;
        log_message('debug', 'Class set to: ' . $this->class);
    }
    
    public function set_method($method): void
    {
        $this->method = $method;
        log_message('debug', 'Method set to: ' . $this->method);
    }
    
    public function set_directory($dir, $append = FALSE): void
    {
        if ($append !== TRUE OR empty($this->directory))
        {
            $this->directory = str_replace('.', '', trim($dir, '/')).'/';
        }
        else
        {
            $this->directory .= str_replace('.', '', trim($dir, '/')).'/';
        }
        
        log_message('debug', 'Directory set to: ' . $this->directory);
    }
    
    public function fetch_class(): string
    {
        return $this->class;
    }
    
    public function fetch_method(): string
    {
        return $this->method;
    }
    
    public function fetch_directory(): string
    {
        return $this->directory;
    }
    
    public function fetch_module(): string
    {
        return $this->module;
    }
    
    /**
     * Set module secara manual
     */
    public function setModule(string $module): void
    {
        $this->module = $module;
        
        // Update Modules registry
        if (method_exists('Modules', 'setCurrentModule')) {
            Modules::setCurrentModule($module);
        }
    }
    
    // =========== ROUTE EXECUTION ===========
    /**
     * Execute route
     */
    public function execute(array $routing, Request $request, Response $response): mixed
    {
        if ($routing['type'] === 'modern' && isset($routing['route']) && $routing['route'] instanceof RouteItem) {
            return $this->executeModernRoute($routing, $request, $response);
        }
        
        // Legacy execution akan ditangani oleh ControllerExecutor
        return null;
    }
    
    /**
     * Execute modern route
     */
    protected function executeModernRoute(array $routing, Request $request, Response $response): mixed
    {
        /** @var RouteItem $route */
        $route = $routing['route'];

        // Execute the route
        $result = $route->run($request, $response);
        
        // If result is routing info (for controllers), return it
        if (is_array($result) && isset($result['type'])) {
            return $result;
        }
        
        // If result is already a response, return it
        if ($result instanceof Response) {
            return $result;
        }
        
        // Otherwise, set result as response body
        $response->setBody((string)$result);
        return $response;
    }
    
    // =========== UTILITY METHODS ===========
    /**
     * Generate URL for named route
     */
    public function url(string $name, array $parameters = []): string
    {
        $route = $this->collection->getByName($name);
        
        if (!$route) {
            throw NotFoundException::resource('route', $name);
        }
        
        return $route->url($parameters);
    }
    
    /**
     * Clear route cache
     */
    public function clearCache(): void
    {
        $this->collection->clearCache();
    }
    
    /**
     * Get all routes (for debugging)
     */
    public function getRoutes(): array
    {
        return $this->collection->getRoutes();
    }
    
    /**
     * Get route collection
     */
    public function getCollection(): RouteCollection
    {
        return $this->collection;
    }
    
    /**
     * Get routing info untuk compatibility
     */
    public function getRouting(): ?array
    {
        $directory = $this->fetch_directory();
        $class = $this->fetch_class();
        $method = $this->fetch_method();
        
        if (empty($class)) {
            return null;
        }
        
        $segments = [];
        $rsegments = $this->uri->rsegment_array();
        if (!empty($rsegments)) {
            $source = $rsegments;
            $startIndex = (count($source) > 2) ? 2 : 1;
            $segments = array_slice($source, $startIndex);
            
            if (isset($segments[0]) && $segments[0] === $method) {
                array_shift($segments);
            }
        }
        
        return [
            'directory' => $directory,
            'class' => $class,
            'method' => $method,
            'segments' => $segments,
            'module' => $this->fetch_module(),
            'type' => 'legacy',
            'source' => 'legacy'
        ];
    }
    
    /**
     * Override untuk handle null route
     */
    public function _set_module_path(string &$_route = ''): void
    {
        if (empty($_route)) {
            return;
        }
        
        $_route = (string)$_route;
        
        $parsed = sscanf($_route, '%[^/]/%[^/]/%[^/]/%s', $module, $directory, $class, $method);
        
        if ($this->locate([$module, $directory, $class])) {
            $_route = $this->rebuildRoute($parsed, $module, $directory, $class, $method);
        }
    }
    
    protected function rebuildRoute(int $parsedCount, ?string $module, ?string $directory, ?string $class, ?string $method): string
    {
        switch ($parsedCount) {
            case 1: 
                return $module . '/index';
            case 2: 
                return ($this->located < 2) ? $module . '/' . $directory : $directory . '/index';
            case 3: 
                return ($this->located == 2) ? $directory . '/' . $class : $class . '/index';
            case 4: 
                return ($this->located == 3) ? $class . '/' . $method : $method . '/index';
            default:
                return '';
        }
    }
    
    /**
     * Untuk compatibility dengan CI 3
     */
    public function _set_overrides(array $routing): void
    {
        if (isset($routing['directory'])) {
            $this->set_directory($routing['directory']);
        }
        
        if (isset($routing['controller'])) {
            $this->set_class($routing['controller']);
        }
        
        if (isset($routing['function'])) {
            $this->set_method($routing['function']);
        }
    }
    
    /**
     * Match request to route (untuk compatibility)
     */
    public function matchRequest(Request $request): ?array
    {
        return $this->resolve($request);
    }
}
