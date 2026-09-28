<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing\Contracts;

/**
 * RouteRegistrarInterface - Route registration contract
 * 
 * Defines the interface for route registration.
 */
interface RouteRegistrarInterface
{
    /**
     * Register a GET route
     */
    public function get(string $uri, $action): RouteInterface;

    /**
     * Register a POST route
     */
    public function post(string $uri, $action): RouteInterface;

    /**
     * Register a PUT route
     */
    public function put(string $uri, $action): RouteInterface;

    /**
     * Register a PATCH route
     */
    public function patch(string $uri, $action): RouteInterface;

    /**
     * Register a DELETE route
     */
    public function delete(string $uri, $action): RouteInterface;

    /**
     * Register a route for multiple HTTP methods
     */
    public function match(array $methods, string $uri, $action): RouteInterface;

    /**
     * Create a route group
     */
    public function group(array $attributes, callable $callback): void;

    /**
     * Register a resource route
     */
    public function resource(string $name, string $controller, array $options = []): void;

    /**
     * Register an API resource route
     */
    public function apiResource(string $name, string $controller, array $options = []): void;

    /**
     * Set route namespace
     */
    public function setNamespace(string $namespace): self;

    /**
     * Get current namespace
     */
    public function getNamespace(): string;
}
