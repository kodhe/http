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
use Kodhe\Framework\Support\Modules;

class Router extends LegacyRouter
{

    protected $collection;

    public $module = '';
    protected $located = 0;
    
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

         parent::__construct();

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


    /**
     * Load routes from config files (MODERN)
     */
    protected function _load_routes(): void
    {
        // ===== MODERN ROUTES ONLY =====
        // Coba load dari cache dulu
        if ($this->collection->loadFromCache()) {
            log_message('debug', 'Modern routes loaded from cache');
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
        
        log_message('debug', 'Modern routes loaded from files');
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

    /**
     * Match request to route (MODERN)
     */
    public function matchRequest(Request $request): ?array
    {
        // Collection bisa belum terinisialisasi (mis. instance Router dibuat
        // tanpa memanggil constructor penuh). Fall back ke collection global
        // yang dipakai Route::addRoute() supaya closure route tetap match.
        $collection = $this->collection ?? Route::getCollection();

        if ($collection === null) {
            return null;
        }

        $route = $collection->match($request);

        if (!$route) {
            return null;
        }

        // Extract routing information
        $action = $route->getAction();
        $parameters = $route->getParameters();

        // Determine controller and method
        if ($action instanceof \Closure) {
            $class = 'Closure';
            $method = '__invoke';
            $type = 'closure';
        } elseif (is_string($action)) {
            if (strpos($action, '@') !== false) {
                list($class, $method) = explode('@', $action, 2);
            } else {
                $class = $action;
                $method = 'index';
            }
            $type = 'controller';
        } elseif (is_array($action)) {
            @list($class, $method) = $action;
            $type = 'controller';
        } else {
            return null;
        }

        $routing = [
            'class' => $class,
            'method' => $method,
            'segments' => array_values($parameters),
            'type' => 'modern',
            'source' => 'modern_router',
            'route' => $route,
            'parameters' => $parameters,
            'middleware' => $route->getMiddleware(),
            'namespace' => $route->getNamespace()
        ];

        return $routing;
    }

    /**
     * Execute route (MODERN)
     */
    public function execute(array $routing, Request $request, Response $response): mixed
    {
        if (!isset($routing['route']) || !$routing['route'] instanceof RouteItem) {
            throw new BadRequestException('ModernRouter: Invalid route for execution');
        }

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

    /**
     * Clear route cache (MODERN)
     */
    public function clearCache(): void
    {
        $this->collection->clearCache();
    }

    /**
     * Get all routes (for debugging) (MODERN)
     */
    public function getRoutes(): array
    {
        return $this->collection->getRoutes();
    }   

    protected function _set_request(array $segments = []): void
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
    }

    protected function tryParseNamespaceSegments(array &$segments): bool
    {
        $parsedSegments = $this->parseNamespaceSegments($segments);
        
        if ($this->located > 0 && !empty($this->class)) {
            return true;
        }
        
        if ($parsedSegments !== $segments && !empty($parsedSegments)) {
            $segments = $parsedSegments;
        }
        
        return false;
    }

    protected function processRegularRouting(array $segments): void
    {
        $locatedSegments = $this->locate($segments);
        
        if ($this->shouldShow404()) {
            $this->handle404();
            return;
        }
        
        $this->processLocatedSegments($locatedSegments, $segments);
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

    protected function _set_default_controller(): void
    {
        if ($this->trySetNamespaceDefaultController()) {
            return;
        }
        
        if (empty($this->directory)) {
            if (!empty($this->default_controller)) {
                $this->_set_module_path($this->default_controller);
            }
        }
        
        parent::_set_default_controller();
        
        if (empty($this->class)) {
            $this->handle404Override();
        }
    }

    protected function trySetNamespaceDefaultController(): bool
    {
        if (empty($this->default_controller) || strpos($this->default_controller, '\\') === false) {
            return false;
        }
        
        $segments = $this->parseNamespaceSegments([$this->default_controller]);
        
        if ($this->located > 0 && !empty($this->class)) {
            return true;
        }
        
        return false;
    }

    protected function handle404Override(): void
    {
        $override = $this->routes['404_override'] ?? '';
        
        $this->resetRequestState();
        
        if ($this->tryNamespace404Override($override)) {
            return;
        }
        
        $this->processRegular404Override($override);
    }

    protected function tryNamespace404Override(string $override): bool
    {
        if (empty($override) || strpos($override, '\\') === false) {
            return false;
        }
        
        $segments = $this->parseNamespaceSegments([$override]);
        
        if ($this->located > 0 && !empty($this->class)) {
            return true;
        }
        
        return false;
    }

    protected function processRegular404Override(string $override): void
    {
        if (empty($override)) {
            throw NotFoundException::endpoint();
        }
        
        $routeSegments = explode('/', trim($override, '/'));
        
        if (empty($routeSegments)) {
            return;
        }
        
        $this->located = 0;
        $located = $this->locate($routeSegments);
        
        if ($this->located > 0) {
            $this->processSegments($located);
        } else {
            $this->processSegments($routeSegments);
        }
    }

    protected function locate(array $segments): array
    {
        $this->located = 0;
        $segments = $this->applyModuleRoutes($segments);
        
        if ($this->shouldReturnEmptySegments($segments)) {
            return $this->handleNoSegments();
        }
        
        $parsed = $this->parseSegments($segments);
        
        $result = $this->locateInModules($parsed);
        if ($result !== null) {
            return $result;
        }
        
        $result = $this->locateInControllers($parsed);
        if ($result !== null) {
            return $result;
        }
        
        return $this->handleNotFound($segments);
    }

    protected function shouldReturnEmptySegments(array $segments): bool
    {
        return empty($segments) || empty($segments[0]);
    }

    protected function applyModuleRoutes(array $segments): array
    {
        if (!isset($segments[0])) {
            return $segments;
        }
        
        $route = implode('/', $segments);
        $routes = Modules::parse_routes($segments[0], $route);
        
        if ($routes) {
            $segments = $routes;
        }
        
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

    public function fetch_module(): string
    {
        return $this->module ?? '';
    }

    public function set_class($class): void
    {
        parent::set_class($class);
    }

    /**
     * Set default controller
     */
    public function setDefaultController(?string $controller = null): void
    {
        if ($controller !== null) {
            $this->default_controller = $controller;
        }

        $this->_set_default_controller();
    }

    /**
     * Set translate URI dashes
     */
    public function setTranslateUriDashes(bool $value): void
    {
        $this->translate_uri_dashes = $value;
    }

    protected function isValidController(array $segments): bool
    {
        $originalLocated = $this->located;
        
        $testSegments = $segments;
        $this->locate($testSegments);
        
        $found = !empty($testSegments) && $this->located > 0;
        $this->located = $originalLocated;
        
        return $found;
    }

    protected function set404Override(): void
    {
        $this->handle404Override();
    }

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
     * Implement RouterInterface
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
            'type' => 'legacy',
            'source' => 'legacy_router'
        ];
    }
    

    /**
     * Generate URL for named route (implementation for RouterInterface)
     * Note: Legacy router doesn't support named routes, so this is a basic implementation
     */
    public function url(string $name, array $parameters = []): string
    {
        // Cek jika ada named routes di config
        $namedRoutes = config_item('named_routes') ?? [];
        
        if (isset($namedRoutes[$name])) {
            $uri = $namedRoutes[$name];
            
            foreach ($parameters as $key => $value) {
                $uri = str_replace('{' . $key . '}', $value, $uri);
            }
            
            $uri = preg_replace('/\{[^}]+\}/', '', $uri);
            $uri = rtrim($uri, '/');
            
            return site_url($uri);
        }
        
        // Jika name mengandung titik, konversi ke path
        if (strpos($name, '.') !== false) {
            $path = str_replace('.', '/', $name);
            return site_url($path);
        }
        
        return site_url($name);
    }

}
