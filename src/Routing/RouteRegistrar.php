<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing;

class RouteRegistrar
{
    /**
     * @var array Route attributes
     */
    protected $attributes = [];

    /**
     * Constructor
     */
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    /**
     * Set route prefix
     */
    public function prefix(string $prefix): self
    {
        $this->attributes['prefix'] = $prefix;
        return $this;
    }

    /**
     * Set route middleware
     */
    public function middleware($middleware): self
    {
        $this->attributes['middleware'] = is_array($middleware) 
            ? $middleware 
            : func_get_args();
        return $this;
    }

    /**
     * Set route namespace
     */
    public function namespace(string $namespace): self
    {
        $this->attributes['namespace'] = $namespace;
        return $this;
    }

    /**
     * Set route name
     */
    public function name(string $name): self
    {
        $this->attributes['as'] = $name;
        return $this;
    }

    /**
     * Set parameter patterns
     */
    public function where(array $where): self
    {
        $this->attributes['where'] = $where;
        return $this;
    }

    /**
     * Register routes with attributes
     */
    public function group(callable $callback): void
    {
        Route::group($this->attributes, $callback);
    }

    /**
     * Create resource routes
     */
    public function resource(string $name, string $controller, array $options = []): void
    {
        Route::resource($name, $controller, array_merge($options, $this->attributes));
    }

    /**
     * Create API resource routes
     */
    public function apiResource(string $name, string $controller, array $options = []): void
    {
        Route::apiResource($name, $controller, array_merge($options, $this->attributes));
    }

    /**
     * Magic method for HTTP verbs
     */
    public function __call($method, $parameters)
    {
        if (in_array(strtoupper($method), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'ANY'])) {
            return $this->registerRoute(strtoupper($method), ...$parameters);
        }

        throw new \BadMethodCallException("Method [{$method}] does not exist.");
    }

    /**
     * Register a route with current attributes
     */
    protected function registerRoute(string $method, string $uri, $action): RouteItem
    {
        // Apply current attributes
        $route = Route::{$method}($uri, $action);
        
        // Apply middleware if set
        if (!empty($this->attributes['middleware'])) {
            $route->middleware($this->attributes['middleware']);
        }
        
        // Apply name if set
        if (!empty($this->attributes['as'])) {
            $route->name($this->attributes['as']);
        }
        
        return $route;
    }
}
