<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing\Contracts;

/**
 * RouteInterface - Individual route contract
 * 
 * Defines the interface for a single route item.
 */
interface RouteInterface
{
    /**
     * Get the URI pattern
     */
    public function getUri(): string;

    /**
     * Get the HTTP method
     */
    public function getMethod(): string;

    /**
     * Get the route action (controller, closure, etc.)
     */
    public function getAction();

    /**
     * Get the route name
     */
    public function getName(): ?string;

    /**
     * Set the route name
     */
    public function name(string $name): self;

    /**
     * Get middleware array
     */
    public function getMiddleware(): array;

    /**
     * Add middleware to route
     */
    public function middleware($middleware): self;

    /**
     * Get route namespace
     */
    public function getNamespace(): string;

    /**
     * Get route parameters after matching
     */
    public function getParameters(): array;

    /**
     * Check if URI matches this route
     */
    public function matches(string $uri): bool;

    /**
     * Generate URL for this route
     */
    public function url(array $parameters = []): string;
}
