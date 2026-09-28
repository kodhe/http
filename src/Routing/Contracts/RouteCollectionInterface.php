<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing\Contracts;

use Kodhe\Framework\Http\Request;

/**
 * RouteCollectionInterface - Collection of routes contract
 * 
 * Defines the interface for route collection management.
 */
interface RouteCollectionInterface
{
    /**
     * Add a route to the collection
     */
    public function add(RouteInterface $route): void;

    /**
     * Get all routes
     */
    public function getRoutes(): array;

    /**
     * Get route by name
     */
    public function getByName(string $name): ?RouteInterface;

    /**
     * Match request to a route
     */
    public function match(Request $request): ?RouteInterface;

    /**
     * Cache routes
     */
    public function cache(): bool;

    /**
     * Load routes from cache
     */
    public function loadFromCache(): bool;

    /**
     * Clear route cache
     */
    public function clearCache(): bool;
}
