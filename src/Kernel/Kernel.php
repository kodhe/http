<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Kernel;

use Kodhe\Framework\Cli\Cli;
use Kodhe\Framework\Error\CPException;
use Kodhe\Framework\Error\FileNotFound;
use Kodhe\Framework\Container\Container;
use Kodhe\Framework\Foundation\Service\ServiceLocator;
use Kodhe\Framework\Foundation\Service\ServiceManager;
use Kodhe\Framework\Http\Middleware\MiddlewareInterface;
use Kodhe\Framework\Http\Middleware\MiddlewareRegistry;
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;
use Kodhe\Framework\Http\Routing\ControllerExecutor;
use Kodhe\Framework\Http\Routing\ModernRouter;
use Kodhe\Framework\Http\Routing\Router;
use Kodhe\Framework\Http\Routing\RoutingManager;
use Kodhe\Framework\Support\Facades\Facade;

/**
 * Core Application Kernel
 */
class Kernel
{
    /**
     * @var bool Application done booting?
     */
    protected $booted = false;

    /**
     * @var bool Application started?
     */
    protected $running = false;

    /**
     * @var Application Application instance
     */
    protected $servicemanager = null;

    /**
     * @var Facade Facade instance
     */
    protected Facade $facade;

    /**
     * @var Container Dependency injection container
     */
    protected $container;

    /**
     * @var RoutingManager Routing manager
     */
    protected $routingManager;

    /**
     * @var ControllerExecutor Controller executor
     */
    protected $controllerExecutor;

    /**
     * @var ModernRouter Modern router instance
     */
    protected $modernRouter;

    protected $router_ready = false;
    /**
     * Constructor
     */
    public function __construct(Container $container = null)
    {
        // Initialize container
        $this->container = $container ?? new Container();
        $this->facade = Facade::getInstance();


        if (method_exists($this->container, 'setThrowOnDuplicate')) {
            $this->container->setThrowOnDuplicate(false);
        }       
    }

    /**
     * Boot the servicemanager
     */
    public function boot()
    {
        // Call pre_system hook
        $this->callHook('pre_system');
        $this->setTimeLimit(300);

        $this->servicemanager = $this->loadApplicationCore();
        // Set class aliases
        $this->servicemanager->setClassAliases();

        $this->startBenchmark();
        
        $this->initializeFacade();
        $this->initializeRoutingComponents();

        $this->includeBaseController();
        $this->booted = true;

        register_shutdown_function([$this, 'shutdown']);
    }




    /**
     * Initialize routing components using container
     */
    protected function initializeRoutingComponents(): void
    {
        log_message('debug', 'Initializing routing components');
        
        // Register RoutingManager with modern router support
        $this->container->registerSingleton('RoutingManager', function ($di) {
            // Buat router legacy sebagai fallback
            $legacyRouter = new Router();
            
            // Buat routing manager dengan konfigurasi
            $manager = new RoutingManager();
            
            // Configure untuk mendukung modern routing
            $config = [
                'enable_modern_routing' => true,
                'cache_routes' => ENVIRONMENT === 'production',
                'router_type' => 'hybrid', // Mendukung kedua tipe
            ];
            
            $manager->setConfig($config);
            
            // Clear cache di development
            if (ENVIRONMENT !== 'production') {
                $manager->clearCache();
            }
            
            return $manager;
        });

        // Register ControllerExecutor dengan semua dependencies
        $this->container->registerSingleton('ControllerExecutor', function ($di) {
            $facade = $this->facade;
            $routingManager = $di->make('RoutingManager');
            
            $executor = new ControllerExecutor($facade, $routingManager);
            
 
            
            // Set container reference
            $executorReflection = new \ReflectionClass($executor);
            if ($executorReflection->hasProperty('container')) {
                $containerProp = $executorReflection->getProperty('container');
                $containerProp->setAccessible(true);
                $containerProp->setValue($executor, $di);
            }
            
            return $executor;
        });

        // Get instances from container
        $this->routingManager = $this->container->make('RoutingManager');
        $this->controllerExecutor = $this->container->make('ControllerExecutor');

        // Register these instances for easy access
        $this->container->register('routing.manager', $this->routingManager);
        $this->container->register('controller.executor', $this->controllerExecutor);
        $this->container->register('modern.router', $this->modernRouter);
        
        log_message('debug', 'Routing components initialized');
    }

    /**
     * Initialize facade and services with container support
     */
    protected function initializeFacade(): void
    {
        $this->facade->set('load', $this->container->make('load'));
        $this->facade->set('input', $this->container->make('input'));
        $this->facade->set('hooks', $this->container->make('hooks'));
        $this->facade->set('lang', $this->container->make('lang'));
        $this->facade->set('config', $this->container->make('config'));
        $this->facade->set('router', $this->container->make('router'));
        $this->facade->set('uri', $this->container->make('uri'));
        $this->facade->set('output', $this->container->make('output'));
        $this->facade->set('utf8', $this->container->make('utf8'));
        $this->facade->set('security', $this->container->make('security'));
        $this->facade->set('benchmark', $this->container->make('benchmark'));
        

        // Register modern router di facade jika belum ada
        if (!$this->facade->has('route')) {
            $this->facade->set('route', $this->modernRouter);
        }

        $this->container->make('load')->setFacade($this->facade);
        // Also make container available through facade
        $this->facade->set('di', $this->container);
    }

    /**
     * Run servicemanager with request
     */
    public function run(Request $request)
    {
        if (!$this->booted) {
            throw new \Exception('Application must be booted before running.');
        }

        $this->running = true;
        $servicemanager = $this->servicemanager;

        // Set request
        $servicemanager->setRequest($request);

        // Register request in container
        $this->container->register('Request', $request);

        // Handle CLI requests
        if (defined('REQ') && REQ === 'CLI') {
            return $this->bootCli();
        }

        // Handle boot only mode
        if (defined('BOOT_ONLY')) {
            return $this->bootOnly($request);
        }

        // Call pre_controller hook
        $this->callHook('pre_controller');

        // Resolve routing using routing manager dari container
        $routingManager = $this->container->make('RoutingManager');
        $routing = $routingManager->resolve($request);

        log_message('debug', 'Resolved routing: ' . print_r($routing, true));

        // Register routing in container
        $this->container->register('current.routing', $routing);

        // Set response
        $response = new Response();
        $servicemanager->setResponse($response);
        $this->container->register('Response', $response);

        // Jalankan middleware pipeline jika routing modern
        if (isset($routing['type']) && $routing['type'] === 'modern') {
            $middlewareResponse = $this->runMiddlewarePipeline($servicemanager, $routing);
            
            if ($middlewareResponse !== null) {
                log_message('debug', 'Middleware pipeline completed');
                // Return response dari middleware
                return $middlewareResponse;
            }
        }

        // Execute controller menggunakan controller executor
        $controllerExecutor = $this->container->make('ControllerExecutor');
        $controllerExecutor->execute($routing);

        // Call hooks
        $this->callHook('post_controller_constructor');
        $this->enableProfiler();
        $this->callHook('post_controller');
        $this->callHook('display_override');

        return $servicemanager->getResponse();
    }

    /**
     * Run middleware pipeline untuk routing modern
     */
    protected function runMiddlewarePipeline($servicemanager, $routing)
    {
        // Hanya jalankan middleware untuk routing modern
        if (!isset($routing['type']) || $routing['type'] !== 'modern') {
            log_message('debug', 'Skipping middleware for non-modern routing');
            return null;
        }
        
        log_message('debug', '=== START MIDDLEWARE PIPELINE ===');
        
        // Get request and response dari container
        $request = $this->container->make('Request');
        $response = $this->container->make('Response');
        
        // Disable throw on duplicate untuk sementara
        $originalThrowSetting = true;
        if (method_exists($this->container, 'getThrowOnDuplicate')) {
            $originalThrowSetting = $this->container->getThrowOnDuplicate();
        }
        
        try {
            // Create pipeline
            $pipeline = new Pipeline($request, $response);
            
            // Tambahkan global middlewares
            $this->addGlobalMiddlewares($pipeline);
            
            // Tambahkan route-specific middlewares jika ada
            if (isset($routing['middleware']) && !empty($routing['middleware'])) {
                log_message('debug', 'Route has middleware: ' . print_r($routing['middleware'], true));
                $this->addRouteMiddlewares($pipeline, $routing['middleware']);
            } else {
                log_message('debug', 'Route has no middleware');
            }
            
            // Set handler
            $pipeline->setHandler(function ($request, $response, $params) use ($routing, $servicemanager) {
                log_message('debug', '=== MIDDLEWARE HANDLER CALLED ===');
                
                // Update response di servicemanager
                $servicemanager->setResponse($response);
                
                // Eksekusi controller
                $controllerExecutor = $this->container->make('ControllerExecutor');
                $controllerExecutor->execute($routing);
                
                // Return response dari servicemanager
                return $servicemanager->getResponse();
            });
            
            // Jalankan pipeline
            $pipelineResponse = $pipeline->run($routing['segments']);
            
            if ($pipelineResponse instanceof Response) {
                log_message('debug', '=== PIPELINE RETURNED RESPONSE ===');
                
                // Update response di container menggunakan set() (replace jika ada)
                if (method_exists($this->container, 'set')) {
                    $this->container->set('Response', $pipelineResponse);
                } elseif (method_exists($this->container, 'replace')) {
                    $this->container->replace('Response', $pipelineResponse);
                } else {
                    // Fallback: langsung assign ke registry
                    $this->container->register('Response', $pipelineResponse);
                }
                
                // Update di servicemanager
                $servicemanager->setResponse($pipelineResponse);
                
                // Restore throw setting
                if (method_exists($this->container, 'setThrowOnDuplicate')) {
                    $this->container->setThrowOnDuplicate($originalThrowSetting);
                }
                
                return $pipelineResponse;
            }
            
            log_message('debug', '=== PIPELINE RETURNED NULL ===');
            
            // Restore throw setting
            if (method_exists($this->container, 'setThrowOnDuplicate')) {
                $this->container->setThrowOnDuplicate($originalThrowSetting);
            }
            
            return null;
            
        } catch (\Exception $e) {
            // Restore throw setting jika error
            if (method_exists($this->container, 'setThrowOnDuplicate')) {
                $this->container->setThrowOnDuplicate($originalThrowSetting);
            }
            
            log_message('error', 'Middleware pipeline error: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());
            
            // Fallback
            log_message('debug', 'Falling back to direct controller execution');
            return null;
        }
    }

    /**
     * Execute single middleware
     */
    protected function executeMiddleware($middleware, $request, $response, $next)
    {
        $registry = new MiddlewareRegistry();
        $resolved = $registry->resolve($middleware);
        
        if ($resolved instanceof MiddlewareInterface) {
            log_message('debug', 'Executing middleware: ' . get_class($resolved));
            return $resolved->handle($request, $response, $next, []);
        }
        
        log_message('error', 'Cannot execute middleware: ' . print_r($middleware, true));
        return $next($request, $response);
    }
    /**
     * Add route-specific middlewares
     */
    protected function addRouteMiddlewares(Pipeline $pipeline, $middlewares)
    {
        // Normalize input
        if (is_string($middlewares)) {
            // Bisa berupa single middleware atau comma-separated list
            if (strpos($middlewares, ',') !== false) {
                $middlewares = array_map('trim', explode(',', $middlewares));
            } else {
                $middlewares = [$middlewares];
            }
        }
        
        if (!is_array($middlewares)) {
            log_message('error', 'Route middlewares must be string or array');
            return;
        }
        
        log_message('debug', 'Adding ' . count($middlewares) . ' route middlewares');
        
        foreach ($middlewares as $index => $middleware) {
            log_message('debug', "Route middleware [{$index}]: " . 
                (is_string($middleware) ? $middleware : gettype($middleware)));
            
            // Jika middleware adalah array, pipe sebagai inline group
            if (is_array($middleware)) {
                log_message('debug', "Piping array as inline group with " . count($middleware) . " items");
                foreach ($middleware as $mw) {
                    $pipeline->pipe($mw);
                }
            } else {
                $pipeline->pipe($middleware);
            }
        }
    }
    
    /**
     * Add global middlewares
     */
    protected function addGlobalMiddlewares(Pipeline $pipeline)
    {
        // Load global middlewares dari config
        $configPath = $this->getConfigPath() . 'middleware.php';
        
        log_message('debug', 'Looking for middleware config at: ' . $configPath);
        
        if (file_exists($configPath)) {
            $config = require $configPath;
            log_message('debug', 'Middleware config loaded successfully');
            
            if (isset($config['global']) && is_array($config['global'])) {
                log_message('debug', 'Found ' . count($config['global']) . ' global middlewares');
                
                foreach ($config['global'] as $index => $middleware) {
                    log_message('debug', "Adding global middleware [{$index}]: " . $middleware);
                    $pipeline->pipe($middleware);
                }
            } else {
                log_message('debug', 'No global middlewares found in config');
            }
            
            // Log groups dan aliases untuk debugging
            if (isset($config['aliases'])) {
                log_message('debug', 'Available aliases: ' . implode(', ', array_keys($config['aliases'])));
            }
            
            if (isset($config['groups'])) {
                log_message('debug', 'Available groups: ' . implode(', ', array_keys($config['groups'])));
            }
            
        } else {
            log_message('warning', 'Middleware config file not found at: ' . $configPath);
        }
    }

    /**
     * Include base controller
     */
    public function includeBaseController(): void
    {
  
        if (!class_exists(\CI_Controller::class, false) && 
            class_exists(\Kodhe\Framework\Http\Controllers\BaseController::class)) {
            class_alias(\Kodhe\Framework\Http\Controllers\BaseController::class, 'CI_Controller');
        }
        
        $subclassPrefix = $this->container->make('config')->item('subclass_prefix') ?? 'MY_';
        $controllerFile = resolve_path(APPPATH, 'Core') . $subclassPrefix . 'Controller.php';
        
        if (file_exists($controllerFile) && !class_exists('App\\' . $subclassPrefix . 'Controller', false)) {
            require_once $controllerFile;
        }
    }

    /**
     * Start benchmark
     */
    protected function startBenchmark(): void
    { 
        $BM = $this->container->make('benchmark');
        $BM->mark('total_execution_time_start');
    }


    /**
     * Boot CLI
     */
    protected function bootCli()
    {
        $this->includeBaseController();
        
        $cli = new Cli();
        $cli->process();
        die();
    }

    /**
     * Boot only mode
     */
    protected function bootOnly(Request $request)
    {
        if (defined('INSTALLER') && INSTALLER) {
            $routing = [
                'class' => 'wizard',
                'method' => 'index',
                'segments' => [],
                'type' => 'legacy',
                'source' => 'installer'
            ];
            
            $controllerExecutor = $this->container->make('ControllerExecutor');
            $controllerExecutor->execute($routing);
            return;
        }

        $this->includeBaseController();
        
        if (class_exists('\BaseController')) {
            \BaseController::setFacade($this->getFacade());
            new \BaseController();
        }
    }

    /**
     * Enable profiler
     */
    protected function enableProfiler()
    {
        if (function_exists('get_instance')) {
            $CI = get_instance();
            // $CI->output->enable_profiler();
        }
    }

    /**
     * Load servicemanager core with container integration
     */
    public function loadApplicationCore()
    {
        if (!is_null($this->servicemanager)) {
            return $this->servicemanager;
        }

        $dependencies = $this->container;
        
        // Register container itself for self-reference
        $dependencies->registerSingleton('di', $dependencies);

        $providers = new ServiceLocator($dependencies);
        $servicemanager = new ServiceManager($dependencies, $providers);

        // Register servicemanager in container
        $dependencies->registerSingleton('ServiceManager', $servicemanager);

        // Resolve framework Setup.php (supports src/ and legacy Framework/ layouts)
        $setup = $this->resolveFrameworkSetup();
        $provider = $servicemanager->addProvider(
            $setup['path'],
            $setup['file'],
            'kodhe'
        );

        if ($provider === null) {
            throw new \RuntimeException(
                'Unable to load framework Setup provider. Tried paths related to SYSPATH / kodhe/framework package. '
                . 'Ensure kodhe/framework is installed and Setup.php exists under src/Config/ or Framework/Config/.'
            );
        }

        $provider->setConfigPath($this->getConfigPath());

        $dependencies->register('kodhe', $servicemanager->get('kodhe'));

        // Application Provider
        $appProvider = $servicemanager->addProvider(
            $this->getConfigPath(),
            'app.php',
            'appication'
        );

        if($servicemanager->has('appication')) {
            $dependencies->register('app', $servicemanager->get('appication'));
        }

        // Register servicemanager dengan pola akses berbeda
        $dependencies->register('App', function ($di, $prefix = null) use ($servicemanager) {
            if (isset($prefix)) {
                return $servicemanager->get($prefix);
            }
            return $servicemanager;
        });

        // Register kernel di container
        $dependencies->registerSingleton('Kernel', $this);

        $this->servicemanager = $servicemanager;

        return $servicemanager;
    }

    /**
     * Get config path
     */


    /**
     * Locate kodhe/framework Setup.php for ServiceProvider.
     *
     * Supports src/Config (new) and Framework/Config (legacy).
     *
     * @return array{path:string,file:string}
     */
    protected function resolveFrameworkSetup(): array
    {
        $candidates = [];

        if (defined('SYSPATH') && SYSPATH) {
            $sys = rtrim(SYSPATH, '/\\') . DIRECTORY_SEPARATOR;
            $candidates[] = [$sys, 'src/Config/Setup.php'];
            $candidates[] = [$sys, 'Framework/Config/Setup.php'];
            $candidates[] = [$sys . 'src' . DIRECTORY_SEPARATOR, 'Config/Setup.php'];
            $candidates[] = [$sys . 'Framework' . DIRECTORY_SEPARATOR, 'Config/Setup.php'];
        }

        if (class_exists(\Kodhe\Framework\Container\Container::class, true)) {
            try {
                $ref = new \ReflectionClass(\Kodhe\Framework\Container\Container::class);
                $containerFile = $ref->getFileName();
                $srcDir = dirname($containerFile, 2);
                $pkgRoot = dirname($srcDir);
                $candidates[] = [$pkgRoot . DIRECTORY_SEPARATOR, 'src/Config/Setup.php'];
                $candidates[] = [$srcDir . DIRECTORY_SEPARATOR, 'Config/Setup.php'];
                $candidates[] = [$pkgRoot . DIRECTORY_SEPARATOR, 'Framework/Config/Setup.php'];
            } catch (\Throwable $e) {
                // ignore
            }
        }

        if (defined('BASEPATH') && BASEPATH) {
            $base = rtrim(BASEPATH, '/\\') . DIRECTORY_SEPARATOR;
            $candidates[] = [$base, 'src/Config/Setup.php'];
            $candidates[] = [$base, 'Framework/Config/Setup.php'];
        }

        foreach ($candidates as $item) {
            $root = $item[0];
            $file = $item[1];
            $full = $root . $file;
            if (is_file($full)) {
                if (function_exists('log_message')) {
                    log_message('debug', 'Framework Setup resolved: ' . $full);
                }
                return [
                    'path' => rtrim($root, '/\\'),
                    'file' => $file,
                ];
            }
        }

        $tried = [];
        foreach ($candidates as $item) {
            $tried[] = $item[0] . $item[1];
        }

        throw new \RuntimeException(
            'Framework Setup.php not found. Tried: ' . implode(', ', array_unique($tried))
        );
    }

    protected function getConfigPath(): string
    {
        return resolve_path(APPPATH, 'config') . DIRECTORY_SEPARATOR;
    }

    /**
     * Set execution time limit
     */
    public function setTimeLimit($t)
    {
        if (function_exists("set_time_limit") && php_sapi_name() !== 'cli') {
            @set_time_limit($t);
        }
    }

    /**
     * Call hook
     */
    protected function callHook($hook)
    {
        if (function_exists('load_class')) {
            $hooks = new \Kodhe\Framework\Support\Legacy\Hooks();
            if ($hooks->call_hook($hook) === false) {
                log_message('debug', "Hook '$hook' tidak ditemukan atau tidak aktif.");
            }
        }
    }

    /**
     * Shutdown process
     */
    public function shutdown()
    {
        $this->callHook('post_system');
    }

    /**
     * Get facade instance
     */
    public function getFacade(): Facade
    {
        return $this->facade;
    }

    /**
     * Get container instance
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Get routing manager instance
     */
    public function getRoutingManager(): RoutingManager
    {
        return $this->routingManager;
    }

    /**
     * Get controller executor instance
     */
    public function getControllerExecutor(): ControllerExecutor
    {
        return $this->controllerExecutor;
    }

    /**
     * Get modern router instance
     */
    public function getModernRouter(): ModernRouter
    {
        return $this->modernRouter;
    }

    public function overrideConfig(array $config): void
    {
        if (isset($this->servicemanager)) {
            // Implementasi override config
        }
    }

    public function overrideRouting(array $routing): void
    {
        if ($this->routingManager) {
            $this->routingManager->overrideRouting($routing);
        }
    }

    /**
     * Check if kernel is booted
     */
    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Check if kernel is running
     */
    public function isRunning(): bool
    {
        return $this->running;
    }
}
