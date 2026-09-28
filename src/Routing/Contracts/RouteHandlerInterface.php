<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing\Contracts;

/**
 * RouteHandlerInterface - Route handler contract
 * 
 * Defines the interface for handling route execution.
 */
interface RouteHandlerInterface
{
    /**
     * Handle route execution
     * 
     * @param RouteInterface $route The route to handle
     * @param array $parameters Route parameters
     * @return mixed Handler result
     */
    public function handle(RouteInterface $route, array $parameters = []): mixed;

    /**
     * Check if handler can process the route
     */
    public function supports(RouteInterface $route): bool;
}
