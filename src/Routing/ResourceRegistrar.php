<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing;
// Buat class ResourceRegistrar:
class ResourceRegistrar
{
    protected $name;
    protected $controller;
    protected $isApi;
    protected $options = [];
    
    public function __construct(string $name, string $controller, bool $isApi = false)
    {
        $this->name = $name;
        $this->controller = $controller;
        $this->isApi = $isApi;
    }
    
    public function only(array $methods): self
    {
        $this->options['only'] = $methods;
        $this->register();
        return $this;
    }
    
    public function except(array $methods): self
    {
        $this->options['except'] = $methods;
        $this->register();
        return $this;
    }
    
    public function names(array $names): self
    {
        $this->options['names'] = $names;
        $this->register();
        return $this;
    }
    
    public function parameters(array $parameters): self
    {
        $this->options['parameters'] = $parameters;
        $this->register();
        return $this;
    }
    
    public function middleware($middleware): self
    {
        $this->options['middleware'] = is_array($middleware) ? $middleware : func_get_args();
        $this->register();
        return $this;
    }
    
    protected function register(): void
    {
        if ($this->isApi) {
            Route::apiResource($this->name, $this->controller, $this->options);
        } else {
            Route::resource($this->name, $this->controller, $this->options);
        }
    }
}
