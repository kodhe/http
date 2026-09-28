<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Middleware;

use Kodhe\Framework\Exceptions\BaseException;
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;
use Throwable;

/**
 * Middleware Group
 * 
 * Digunakan untuk grouping multiple middlewares
 */
class MiddlewareGroup implements MiddlewareInterface
{
    protected $middlewares = [];
    
    public function __construct(array $middlewares = [])
    {
        $this->middlewares = $middlewares;
    }
    
    /**
     * Add middleware to group
     */
    public function add($middleware)
    {
        $this->middlewares[] = $middleware;
        log_message('debug', 'Middleware added to group: ' . $this->getMiddlewareDescription($middleware));
        return $this;
    }
    
    /**
     * Handle middleware group
     */
    public function handle(Request $request, Response $response, callable $next, array $params = [])
    {
        try {
            log_message('debug', 'MiddlewareGroup::handle() called with ' . count($this->middlewares) . ' middlewares');
            
            // Build pipeline dari middlewares dalam group.
            //
            // The destination handler ($next, supplied by Pipeline/Kernel) is
            // wrapped in a tolerant closure first: middlewares inside this
            // group may invoke it with fewer arguments than its signature
            // requires -- e.g. Routing\ThrottleRequests calling
            // $next($request, $response) against the Kernel's strict
            // function ($request, $response, $params) handler closure, which
            // fatals with "Too few arguments to function
            // Kodhe\Framework\Http\Kernel\{closure}(), 2 passed ... and
            // exactly 3 expected" and surfaces as INTERNAL_ERROR
            // "Middleware group execution failed".
            $destination = $next;
            $pipeline = function ($req = null, $res = null, $hParams = []) use ($destination, $request, $response) {
                if (!($req instanceof Request)) {
                    $req = $request;
                }

                if (!($res instanceof Response)) {
                    if (is_array($res)) {
                        $hParams = array_merge(is_array($hParams) ? $hParams : [], $res);
                    }
                    $res = $response;
                }

                if (!is_array($hParams)) {
                    $hParams = [];
                }

                return $destination($req, $res, $hParams);
            };

            foreach (array_reverse($this->middlewares) as $middleware) {
                // The inner "next" closure must tolerate being called with
                // fewer arguments than its full signature. Some middlewares
                // (e.g. Routing\ThrottleRequests) invoke the next handler as
                // $next($request, $response) -- 2 args instead of 3 -- which
                // otherwise fatals with "Too few arguments to function
                // ...{closure}(), 2 passed ... and exactly 3 expected".
                $pipeline = function ($req = null, $res = null, $params = []) use ($middleware, $pipeline, $request, $response) {
                    log_message('debug', 'MiddlewareGroup executing: ' . $this->getMiddlewareDescription($middleware));

                    // Normalize missing/odd arguments so downstream closures
                    // and the final handler always receive a complete triple.
                    if (!is_array($params)) {
                        $params = [];
                    }

                    if ($req === null) {
                        $req = $request;
                    }

                    if (!($res instanceof Response)) {
                        // Called as $next($request, $params) or with no response:
                        // fall back to the group's own response instance.
                        if (is_array($res)) {
                            $params = array_merge($params, $res);
                        }
                        $res = $response;
                    }

                    if ($middleware instanceof MiddlewareInterface) {
                        return $middleware->handle($req, $res, $pipeline, $params);
                    }

                    log_message('error', 'Invalid middleware in group');
                    return $pipeline($req, $res, $params);
                };
            }

            return call_user_func($pipeline, $request, $response, $params);
            
        } catch (BaseException $e) {
            log_message('error', 'MiddlewareGroup caught BaseException: ' . $e->getLogMessage());
            $e->withLogContext(array_merge($e->getLogContext(), [
                'middleware_group' => true,
                'group_middleware_count' => count($this->middlewares)
            ]));
            throw $e;
        } catch (Throwable $e) {
            log_message('error', 'MiddlewareGroup caught Throwable: ' . $e->getMessage());
            $baseException = new BaseException(
                'Middleware group execution failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
            $baseException
                ->withData([
                    'middleware_group' => true,
                    'middleware_count' => count($this->middlewares),
                    'request_method' => $request->method(),
                    'request_path' => $request->getUri()->getPath()
                ])
                ->withLogContext([
                    'exception_type' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ])
                ->setLogLevel('error');
            
            throw $baseException;
        }
    }
    
    /**
     * Get all middlewares in group
     */
    public function getMiddlewares()
    {
        return $this->middlewares;
    }
    
    /**
     * Check if group is empty
     */
    public function isEmpty()
    {
        return empty($this->middlewares);
    }
    
    /**
     * Get middleware description
     */
    protected function getMiddlewareDescription($middleware)
    {
        if (is_object($middleware)) {
            return get_class($middleware);
        }
        
        return gettype($middleware);
    }
}
