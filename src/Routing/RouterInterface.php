<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing;

use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;

interface RouterInterface
{
    /**
     * Match request to route
     */
    public function matchRequest(Request $request): ?array;

    /**
     * Get routing information
     */
    public function getRouting(): ?array;

    /**
     * Execute route (for modern routers)
     */
    public function execute(array $routing, Request $request, Response $response): mixed;

    /**
     * Generate URL for named route (optional)
     */
    public function url(string $name, array $parameters = []): string;
    
    /**
     * Legacy methods (for backward compatibility)
     */
    public function _set_routing();
    public function fetch_class();
    public function fetch_method();
    public function fetch_directory();
    public function set_class($class);
    public function set_method($method);
}
