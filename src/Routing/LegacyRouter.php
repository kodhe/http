<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing;

use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;
use Kodhe\Framework\Support\Legacy\URI;

// The case-insensitive path helpers (app_path_in(), app_config_folder(),
// app_folder(), app_controller_file()) are plain global functions defined
// in framework/src/Support/app_path.php. This package may be loaded via
// composer autoload "files", but projects that include the router through
// a manual loader must still find them, so we guarantee availability here.
if ( ! function_exists('app_path_in'))
{
    foreach (array(
        // Monorepo layout: ../../framework/src/Support/app_path.php
        dirname(__DIR__, 3).'/framework/src/Support/app_path.php',
        // Installed as standalone package alongside kodhe/framework-core
        dirname(__DIR__, 4).'/kodhe/framework/framework/src/Support/app_path.php',
        dirname(__DIR__, 4).'/framework/src/Support/app_path.php',
    ) as $__app_path_helper) {
        if (is_file($__app_path_helper)) {
            require_once $__app_path_helper;
            break;
        }
    }
}
unset($__app_path_helper);

class LegacyRouter
{
    public $routes = [];
    public $class = '';
    public $method = 'index';
    public $directory;
    public $default_controller;
    public $translate_uri_dashes = false;
    public $enable_query_strings = false;
    public URI $uri;

    /**
     * @var array Supported HTTP methods for routing
     */
    protected $supportedMethods = ['get', 'post', 'put', 'delete', 'patch', 'options', 'head'];

    /**
     * @var array Current route match info
     */
    protected $currentRoute = [];

    
    public function __construct($routing = null)
    {
        
        $this->uri = new URI();

        $this->enable_query_strings = (!is_cli() && app()->config->item('enable_query_strings') === true);
        
        // Load routes
        $this->_load_routes();
        
        // PERBAIKAN: Pastikan $routing tidak null
        if (is_array($routing) && isset($routing['directory'])) {
            $this->set_directory($routing['directory']);
        }
        
        $this->_set_routing();
        
        // PERBAIKAN: Tambah null check
        if (is_array($routing)) {
            if (!empty($routing['controller'])) {
                $this->set_class($routing['controller']);
            }
            if (!empty($routing['function'])) {
                $this->set_method($routing['function']);
            }
        }
        
        log_message('info', 'Router Class Initialized');
    }
    
    /**
     * Load routes from config files (LEGACY ONLY)
     */
    protected function _load_routes(): void
    {
        $route = [];
        
        // Load main routes (case-insensitive: config/ or Config/, routes.php case too)
        $routesFile = app_path_in(APPPATH.app_config_folder(), 'routes.php');
        if (file_exists($routesFile)) {
            include($routesFile);
        }

        // Load environment routes (e.g. Config/development/routes.php)
        $envFile = app_path_in(APPPATH.app_config_folder(), ENVIRONMENT.'/routes.php');
        if (is_dir(app_path_in(APPPATH.app_config_folder(), ENVIRONMENT)) && file_exists($envFile)) {
            include($envFile);
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
            
            // Process routes with HTTP method support
            $this->routes = $this->parseRoutes($route);
            
        } else {
            // Jika tidak ada route config, set default
            $this->default_controller = 'welcome';
            $this->routes = [];
        }
        
        log_message('debug', 'Legacy routes loaded: ' . count($this->routes));
    }

    /**
     * Parse routes to support HTTP method syntax (LEGACY)
     * 
     * @param array $routes
     * @return array
     */
    protected function parseRoutes(array $routes): array
    {
        $parsed = [];
        $httpMethod = $this->getHttpMethod();

        foreach ($routes as $key => $value) {
            // Jika key adalah array (sintaks dengan method HTTP)
            if (is_array($value)) {
                // Cek apakah ini sintaks method HTTP
                $hasMethodKey = false;
                foreach ($this->supportedMethods as $method) {
                    if (isset($value[$method])) {
                        $hasMethodKey = true;
                        // Jika method sesuai dengan request, gunakan route ini
                        if ($method === $httpMethod) {
                            $parsed[$key] = $value[$method];
                        }
                        break;
                    }
                }
                // Jika tidak ada method yang cocok, skip
                if ($hasMethodKey) {
                    continue;
                }
            }
            
            // Route biasa (tanpa method)
            $parsed[$key] = $value;
        }

        return $parsed;
    }

    /**
     * Get current HTTP method
     * 
     * @return string
     */
    protected function getHttpMethod(): string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        
        // Check for method override
        if ($method === 'POST') {
            if (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
                $method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
            } elseif (isset($_POST['_method'])) {
                $method = strtoupper($_POST['_method']);
            }
        }
        
        return strtolower($method);
    }
    
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

        // Case-insensitive lookup: works with controllers/ or Controllers/,
        // lowercase or PascalCase files, with or without the suffix.
        $suffix = app()->config->item('controller_suffix');
        $controller_file = app_controller_file($this->directory.$class, (string) $suffix);

        log_message('debug', 'Looking for controller: ' . $controller_file);

        if ( ! file_exists($controller_file))
        {
            log_message('error', 'Default controller not found after all attempts: ' . $controller_file);

            // Jangan langsung show_error, biarkan system handle 404
            $this->class = 'Kodhe\Framework\Controllers\Error\FileNotFound';
            $this->method = 'index';
            return;
        }

        // Adopt the on-disk spelling (e.g. file was found as WelcomeController.php)
        $found = basename($controller_file, '.php');
        if ($found !== $class && strcasecmp($found, $class) === 0)
        {
            $class = $found;
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

    // --------------------------------------------------------------------

    protected function _validate_request($segments)
    {
        $c = count($segments);
        $directory_override = isset($this->directory);

        while ($c-- > 0)
        {
            $probe = $this->translate_uri_dashes === TRUE ? str_replace('-', '_', $segments[0]) : $segments[0];

            // Case-insensitive checks so renamed folders (Controllers/, Admin/)
            // and PascalCase files are found on Linux as well.
            $controller_path = app_controller_file($this->directory.$probe);
            $dir_path = app_path_in(APPPATH.'controllers', $this->directory.$probe);

            if ( ! file_exists($controller_path)
                && $directory_override === FALSE
                && is_dir($dir_path)
            )
            {
                $this->set_directory(array_shift($segments), TRUE);
                continue;
            }

            return $segments;
        }

        // This means that all segments were actually directories
        return $segments;
    }

    /**
     * Parse legacy routes from config (LEGACY)
     */
    protected function _parse_routes()
    {
        $uri = implode('/', $this->uri->segment_array());

        $http_verb = $this->getHttpMethod();

        foreach ($this->routes as $key => $val)
        {
            // Handle array route with HTTP method (LEGACY)
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

    /**
     * Set request segments
     * 
     * PERBAIKAN: Signature harus kompatibel dengan Router::_set_request()
     * 
     * @param array|string $segments
     * @return void
     */
    protected function _set_request(array $segments = [])
    {
        // Jika $segments adalah string, convert ke array
        if (is_string($segments)) {
            $segments = explode('/', trim($segments, '/'));
        }
        
        // Pastikan $segments adalah array
        if (!is_array($segments)) {
            $segments = [];
        }
        
        $segments = $this->_validate_request($segments);
        
        if (count($segments) === 0)
        {
            $this->_set_default_controller();
            return;
        }

        $this->set_class($segments[0]);
        
        if (isset($segments[1]))
        {
            $this->set_method($segments[1]);
        }
        else
        {
            $this->set_method('index');
        }

        // Save segments for later use
        $this->uri->_set_rsegments($segments);
    }


    public function set_class($class)
    {
        log_message('debug', 'set_class called with: ' . $class);
        
        // Clean class name
        $class = str_replace(array('/', '.'), '', $class);
        
        $suffix = app()->config->item('controller_suffix');
        if ($suffix && strpos($class, $suffix) === FALSE)
        {
            // Cek jika file dengan suffix ada (case-insensitive lookup)
            $controller_file = app_controller_file($this->directory.$class, (string) $suffix);
            if (is_file($controller_file)) {
                $class .= $suffix;
                log_message('debug', 'Adding suffix to class: ' . $class);
            }
        }

        $this->class = $class;
        log_message('debug', 'Class set to: ' . $this->class);
    }


    public function fetch_class()
    {
        return $this->class;
    }


    public function set_method($method)
    {
        $this->method = $method;
        log_message('debug', 'Method set to: ' . $this->method);
    }


    public function fetch_method()
    {
        return $this->method;
    }


    public function set_directory($dir, $append = FALSE)
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

    public function fetch_directory()
    {
        return $this->directory;
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
        
        // Original implementation
        $parsed = sscanf($_route, '%[^/]/%[^/]/%[^/]/%s', $module, $directory, $class, $method);
        
        // ... rest of the implementation
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
     * Match request to route (LEGACY)
     */
    public function matchRequest(Request $request): ?array
    {
        // Legacy router uses _set_routing instead of request matching
        $uri = $request->getUri()->getQuery();
        $uri = trim($uri, '/');
        
        // Simulate legacy routing by setting up URI using public method
        // Since _set_uri_string is protected, we need to use reflection or create a new URI instance
        $this->uri = new URI();
        // Force set the uri_string via the public property (which is still public in legacy URI class)
        $this->uri->uri_string = $uri;
        
        // Reset
        $this->class = '';
        $this->method = 'index';
        $this->directory = '';
        
        // Parse routing
        $this->_set_routing();
        
        return $this->getRouting();
    }

    /**
     * Execute route (LEGACY)
     */
    public function execute(array $routing, Request $request, Response $response): mixed
    {
        // Legacy router doesn't execute directly, Kernel handles it
        return $response;
    }

    /**
     * Get routing info (LEGACY)
     */
public function getRouting(): ?array
{
    $rsegments = $this->uri->rsegment_array() ?? [];

    // rsegments legacy berisi:
    // [controller, method, param1, param2, ...]
    // Controller dan method sudah dipisahkan ke class/method.
    // Yang dikirim ke ControllerExecutor hanya parameter method.
    $params = array_slice($rsegments, 2);

    return [
        'class' => $this->class,
        'method' => $this->method,
        'directory' => $this->directory,
        'params' => $params,
        'type' => 'legacy',
        'source' => 'legacy_router'
    ];
}

    /**
     * Check if route exists for current request (LEGACY)
     */
    public function hasRoute(): bool
    {
        return !empty($this->class);
    }

    /**
     * Get route by name (if implemented) (LEGACY)
     */
    public function getRouteByName(string $name): ?array
    {
        // Legacy router doesn't support named routes
        return null;
    }
}
