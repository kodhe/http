<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing\Contracts;

use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;

/**
 * RouterInterface - Main router contract
 * 
 * Defines the interface for routing implementations.
 * Supports both modern and legacy routing patterns.
 */
interface RouterInterface
{
    /**
     * Match request to route
     * 
     * @param Request $request The HTTP request to match
     * @return array|null Routing information or null if no match
     */
    public function matchRequest(Request $request): ?array;

    /**
     * Get routing information
     * 
     * @return array|null Current routing information
     */
    public function getRouting(): ?array;

    /**
     * Execute route (for modern routers)
     * 
     * @param array $routing Routing information
     * @param Request $request The HTTP request
     * @param Response $response The HTTP response
     * @return mixed Route execution result
     */
    public function execute(array $routing, Request $request, Response $response): mixed;

    /**
     * Generate URL for named route
     * 
     * @param string $name Route name
     * @param array $parameters Route parameters
     * @return string Generated URL
     */
    public function url(string $name, array $parameters = []): string;

    /**
     * Legacy methods (for backward compatibility with CodeIgniter 3)
     */
    public function _set_routing();
    public function fetch_class();
    public function fetch_method();
    public function fetch_directory();
    public function set_class($class);
    public function set_method($method);
}
