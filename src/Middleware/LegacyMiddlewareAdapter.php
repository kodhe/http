<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Middleware;

use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;

/**
 * Legacy Middleware Adapter
 *
 * Bridges app middlewares written in the older Laravel-style contract, e.g.
 *
 *     use Kodhe\Framework\Middleware\Middleware;            // legacy namespace
 *     class ApiVersionMiddleware extends Middleware
 *     {
 *         public function handle(Request $request, \Closure $next, string $param)
 *         { ... }
 *     }
 *
 * to the framework's current MiddlewareInterface contract:
 *
 *     handle(Request $request, Response $response, callable $next, array $params = [])
 *
 * Without this adapter such classes fail at resolve time (class not found /
 * does not implement MiddlewareInterface), and their invocations fail with
 * "Too few arguments to function ...{closure}(), 2 passed ... and exactly 3
 * expected" style arity errors inside MiddlewareGroup pipelines.
 */
class LegacyMiddlewareAdapter implements MiddlewareInterface
{
    /**
     * @var object The wrapped legacy middleware instance
     */
    protected $middleware;

    /**
     * @var array Parameters parsed from the alias string ("mw:p1,p2")
     */
    protected $params = [];

    public function __construct(object $middleware, array $params = [])
    {
        $this->middleware = $middleware;
        $this->params = $params;
    }

    /**
     * Get the wrapped legacy middleware instance
     */
    public function getWrapped()
    {
        return $this->middleware;
    }

    /**
     * Forward parameter setting to the wrapped middleware when supported
     */
    public function setParameters(array $parameters)
    {
        $this->params = $parameters;

        if (method_exists($this->middleware, 'setParameters')) {
            $this->middleware->setParameters($parameters);
        }
    }

    /**
     * Handle the request — adapt the pipeline call to the legacy signature.
     */
    public function handle($request = null, $response = null, $next = null, $params = [])
    {
        // Normalize flexible call conventions (same tolerance as ThrottleRequests).
        if (!($request instanceof Request)) {
            $request = Request::fromGlobals();
        }

        if (is_callable($response) && !($response instanceof Response)) {
            // handle($request, $next, $params) convention
            if (is_array($next)) {
                $params = $next;
            }
            $next = $response;
            $response = new Response();
        } elseif (!($response instanceof Response)) {
            $response = new Response();
        }

        if (is_array($next)) {
            $params = $next;
            $next = null;
        }

        if (!is_array($params)) {
            $params = [];
        }

        if (!is_callable($next)) {
            $next = static function ($req = null, $res = null, $prm = []) use ($response) {
                return $res instanceof Response ? $res : $response;
            };
        }

        // Prefer the framework contract when the wrapped class actually
        // implements it (e.g. loaded via a legacy class_alias shim that
        // points at Kodhe\Framework\Http\Middleware\Middleware).
        if ($this->middleware instanceof MiddlewareInterface) {
            return $this->middleware->handle($request, $response, $next, $params);
        }

        // Legacy Laravel-style: handle($request, $next[, ...$params])
        return $this->middleware->handle($request, $next, ...$params);
    }
}
