<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing\Contracts;

use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;

/**
 * ControllerExecutorInterface - Controller execution contract
 * 
 * Defines the interface for executing controllers.
 */
interface ControllerExecutorInterface
{
    /**
     * Execute a controller action
     * 
     * @param string $controller Controller class name (FQCN)
     * @param string $method Method to call
     * @param array $parameters Parameters to pass
     * @param Request $request The HTTP request
     * @param Response $response The HTTP response
     * @return mixed Execution result
     */
    public function execute(
        string $controller,
        string $method,
        array $parameters = [],
        ?Request $request = null,
        ?Response $response = null
    ): mixed;

    /**
     * Check if controller exists and is callable
     */
    public function canExecute(string $controller, string $method): bool;
}
