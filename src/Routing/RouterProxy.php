<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing;

use Kodhe\Framework\Foundation\Kernel;
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;

class RouterProxy implements RouterInterface
{
    private $kernel = null;
    private $legacyRouter = null;
    private $modernRouter = null;
    private $routingManager = null;
    private $routing = null;
    private $isResolved = false;
    
    public function __construct(Kernel $kernel)
    {
        $this->kernel = $kernel;
    }
    
    public function setRouterInstances(Router $legacyRouter, ModernRouter $modernRouter, RoutingManager $routingManager): void
    {
        $this->legacyRouter = $legacyRouter;
        $this->modernRouter = $modernRouter;
        $this->routingManager = $routingManager;
    }
    
    public function setRouting(array $routing): void
    {
        $this->routing = $routing;
        $this->isResolved = true;
    }
    
    public function fetch_class(): string
    {
        return $this->getRouting()['class'] ?? '';
    }
    
    public function fetch_method(): string
    {
        return $this->getRouting()['method'] ?? 'index';
    }
    
    public function fetch_directory(): string
    {
        return $this->getRouting()['directory'] ?? '';
    }
    
    public function getRouting(): ?array
    {
        // Priority 1: Already resolved routing
        if ($this->routing !== null) {
            return $this->routing;
        }
        
        // Priority 2: Get from kernel if running
        if ($this->kernel->isRunning()) {
            try {
                if ($this->routingManager) {
                    $request = $this->kernel->getContainer()->make('Request') ?? Request::createFromGlobals();
                    $this->routing = $this->routingManager->resolve($request);
                    return $this->routing;
                }
            } catch (\Exception $e) {
                // Fall through
            }
        }
        
        // Priority 3: Get from globals
        if (!empty($GLOBALS['_KERNEL_ROUTING'])) {
            $this->routing = $GLOBALS['_KERNEL_ROUTING'];
            return $this->routing;
        }
        
        // Priority 4: Default routing
        return [
            'class' => 'Welcome',
            'method' => 'index',
            'directory' => '',
            'type' => 'legacy',
            'source' => 'router_proxy_default'
        ];
    }
    
    public function matchRequest(Request $request): ?array
    {
        // Delegate to appropriate router
        if ($this->routingManager) {
            return $this->routingManager->resolve($request);
        }
        
        // Try legacy router
        if ($this->legacyRouter) {
            return $this->legacyRouter->matchRequest($request);
        }
        
        return null;
    }
    
    public function execute(array $routing, Request $request, Response $response): mixed
    {
        // Delegate to appropriate router
        if ($routing['type'] === 'modern' && $this->modernRouter) {
            return $this->modernRouter->execute($routing, $request, $response);
        }
        
        if ($this->legacyRouter) {
            return $this->legacyRouter->execute($routing, $request, $response);
        }
        
        throw new \RuntimeException('No router available for execution');
    }
    
    public function url(string $name, array $parameters = []): string
    {
        // Try modern router first
        if ($this->modernRouter) {
            try {
                return $this->modernRouter->url($name, $parameters);
            } catch (\Exception $e) {
                // Fall through
            }
        }
        
        // Try legacy router
        if ($this->legacyRouter) {
            return $this->legacyRouter->url($name, $parameters);
        }
        
        return '#';
    }
    
    // Legacy compatibility methods
    public function _set_routing(): void
    {
        if ($this->legacyRouter) {
            $this->legacyRouter->_set_routing();
        }
    }
    
    public function set_class($class): void
    {
        if ($this->legacyRouter) {
            $this->legacyRouter->set_class($class);
        }
        
        // Update local routing
        if ($this->routing === null) {
            $this->routing = [];
        }
        $this->routing['class'] = $class;
    }
    
    public function set_method($method): void
    {
        if ($this->legacyRouter) {
            $this->legacyRouter->set_method($method);
        }
        
        // Update local routing
        if ($this->routing === null) {
            $this->routing = [];
        }
        $this->routing['method'] = $method;
    }
    
    public function _set_default_controller(): void
    {
        if ($this->legacyRouter) {
            $this->legacyRouter->_set_default_controller();
        }
    }
    
    public function clearCache(): void
    {
        if ($this->modernRouter) {
            $this->modernRouter->clearCache();
        }
        
        if ($this->routingManager) {
            $this->routingManager->clearCache();
        }
    }
    
    public function getRoutes(): array
    {
        if ($this->modernRouter) {
            return $this->modernRouter->getRoutes();
        }
        
        return [];
    }

    public function fetch_module() {

        return 'siilah';
    }
}
