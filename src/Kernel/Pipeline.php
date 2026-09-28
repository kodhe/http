<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Kernel;

use Kodhe\Framework\Exceptions\BaseException;
use Kodhe\Framework\Exceptions\Http\HttpException;
use Kodhe\Framework\Http\Middleware\MiddlewareGroup;
use Kodhe\Framework\Http\Middleware\MiddlewareInterface;
use Kodhe\Framework\Http\Middleware\MiddlewareRegistry;
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;
use Throwable;

class Pipeline
{
    protected $middlewares = [];
    protected $handler;
    protected $request;
    protected $response;
    
    /**
     * @var MiddlewareRegistry|null
     */
    protected $registry = null;
    
    /**
     * @var bool Whether exception handling is enabled
     */
    protected $exceptionHandling = true;
    
    /**
     * @var callable|null Custom exception handler
     */
    protected $exceptionHandler = null;
    
    public function __construct(Request $request = null, Response $response = null)
    {
        $this->request = $request ?: Request::fromGlobals();
        $this->response = $response ?: new Response();
        
        $this->handler = function($request, $response, $params) {
            if (function_exists('log_message')) {
                log_message('debug', 'Default pipeline handler called');
            }
            return $response;
        };
    }
    
    /**
     * Pipe middleware (bisa string, array, atau object)
     */
    public function pipe($middleware)
    {
        $this->middlewares[] = $middleware;
        if (function_exists('log_message')) {
            log_message('debug', 'Middleware added to pipeline: ' . $this->getMiddlewareDescription($middleware));
        }
        return $this;
    }
    
    /**
     * Pipe multiple middlewares sekaligus
     */
    public function pipeMany(array $middlewares)
    {
        foreach ($middlewares as $middleware) {
            $this->pipe($middleware);
        }
        return $this;
    }
    
    /**
     * Set controller handler
     */
    public function setHandler(callable $handler)
    {
        $this->handler = $handler;
        if (function_exists('log_message')) {
            log_message('debug', 'Pipeline handler set');
        }
        return $this;
    }
    
    /**
     * Enable exception handling
     */
    public function enableExceptionHandling(): self
    {
        $this->exceptionHandling = true;
        return $this;
    }
    
    /**
     * Disable exception handling
     */
    public function disableExceptionHandling(): self
    {
        $this->exceptionHandling = false;
        return $this;
    }
    
    /**
     * Set custom exception handler
     */
    public function setExceptionHandler(callable $handler): self
    {
        $this->exceptionHandler = $handler;
        return $this;
    }
    
    /**
     * Run pipeline
     */
    public function run(array $params = [])
    {
        if (function_exists('log_message')) {
            log_message('debug', 'MiddlewarePipeline::run() started with ' . count($this->middlewares) . ' middlewares');
            
            // Debug: list semua middlewares
            foreach ($this->middlewares as $index => $mw) {
                log_message('debug', "Pipeline middleware [{$index}]: " . $this->getMiddlewareDescription($mw));
            }
        }
        
        if (empty($this->middlewares)) {
            if (function_exists('log_message')) {
                log_message('debug', 'No middlewares in pipeline, calling handler directly');
            }
            return call_user_func($this->handler, $this->request, $this->response, $params);
        }
        
        // Build pipeline
        $pipeline = $this->buildPipeline();
        
        // Execute
        try {
            $response = call_user_func($pipeline, $this->request, $this->response, $params);
            
            // Pastikan response adalah Response object
            if (!$response instanceof Response) {
                if (function_exists('log_message')) {
                    log_message('debug', 'Pipeline did not return Response, creating new Response');
                }
                $newResponse = clone $this->response;
                
                if ($response !== null) {
                    if (is_array($response)) {
                        $newResponse->setBody(json_encode($response));
                        $newResponse->setHeader('Content-Type', 'application/json');
                    } else {
                        $newResponse->setBody((string)$response);
                    }
                }
                
                $response = $newResponse;
            }
            
            if (function_exists('log_message')) {
                log_message('debug', 'MiddlewarePipeline::run() completed successfully');
            }
            return $response;
            
        } catch (Throwable $e) {
            return $this->handlePipelineException($e, $params);
        }
    }
    
    /**
     * Handle pipeline exception
     */
    protected function handlePipelineException(Throwable $e, array $params): Response
    {
        if (function_exists('log_message')) {
            log_message('error', 'Middleware pipeline error: ' . $e->getMessage());
        }
        
        // Jika custom exception handler diset, gunakan itu
        if ($this->exceptionHandler && is_callable($this->exceptionHandler)) {
            try {
                if (function_exists('log_message')) {
                    log_message('debug', 'Using custom exception handler');
                }
                return call_user_func($this->exceptionHandler, $e, $this->request, $this->response, $params);
            } catch (Throwable $handlerError) {
                if (function_exists('log_message')) {
                    log_message('error', 'Custom exception handler failed: ' . $handlerError->getMessage());
                }
                // Fallback ke default handler
            }
        }
        
        // Jika exception handling disabled, re-throw
        if (!$this->exceptionHandling) {
            if (function_exists('log_message')) {
                log_message('debug', 'Exception handling disabled, re-throwing exception');
            }
            throw $e;
        }
        
        // Handle exception based on type
        if ($e instanceof BaseException) {
            if (function_exists('log_message')) {
                log_message('error', 'Handling BaseException: ' . $e->getLogMessage());
            }
            return $this->handleBaseException($e);
        }
        
        if ($e instanceof HttpException) {
            if (function_exists('log_message')) {
                log_message('warning', 'Handling HttpException: ' . $e->getMessage());
            }
            return $this->handleHttpException($e);
        }
        
        // Convert unknown exception to BaseException
        if (function_exists('log_message')) {
            log_message('error', 'Converting unknown exception to BaseException');
        }
        $baseException = new BaseException(
            'Internal server error: ' . $e->getMessage(),
            $e->getCode(),
            $e
        );
        $baseException
            ->withData([
                'pipeline_middleware_count' => count($this->middlewares),
                'request_method' => $this->request->method(),
                'request_path' => $this->request->getUri()->getPath()
            ])
            ->withLogContext([
                'exception_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'pipeline_error' => true
            ])
            ->setLogLevel('error');
        
        return $this->handleBaseException($baseException);
    }
    
    /**
     * Handle BaseException
     */
    protected function handleBaseException(BaseException $e): Response
    {
        $response = clone $this->response;
        $response->setStatus($e->getHttpStatusCode());
        
        // Add headers from exception
        $headers = $e->getHeaders();
        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value);
        }
        
        // Build error response
        $errorData = [
            'error' => [
                'code' => $e->getErrorCode(),
                'message' => $e->getMessage(),
                'status' => $e->getHttpStatusCode()
            ]
        ];
        
        // Add exception data if available
        $exceptionData = $e->getData();
        if (!empty($exceptionData)) {
            $errorData['error']['data'] = $exceptionData;
        }
        
        // Add debug info if in debug mode
        if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production') {
            $errorData['debug'] = [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTrace()
            ];
            
            // Add previous exception if exists
            $previous = $e->getPrevious();
            if ($previous) {
                $errorData['debug']['previous'] = [
                    'exception' => get_class($previous),
                    'message' => $previous->getMessage(),
                    'file' => $previous->getFile(),
                    'line' => $previous->getLine()
                ];
            }
        }
        
        $response->json($errorData);
        return $response;
    }
    
    /**
     * Handle HttpException
     */
    protected function handleHttpException(HttpException $e): Response
    {
        $response = clone $this->response;
        $response->setStatus($e->getHttpStatusCode());
        
        // Add headers from exception
        $headers = $e->getHeaders();
        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value);
        }
        
        // Build error response
        $errorData = [
            'error' => [
                'code' => $e->getErrorCode(),
                'message' => $e->getMessage(),
                'status' => $e->getHttpStatusCode(),
                'status_text' => $e->getStatusText()
            ]
        ];
        
        // Add exception data if available
        $exceptionData = $e->getData();
        if (!empty($exceptionData)) {
            $errorData['error']['data'] = $exceptionData;
        }
        
        $response->json($errorData);
        return $response;
    }
    
    /**
     * Build pipeline dengan middleware resolving
     */
    protected function buildPipeline()
    {
        $pipeline = $this->handler;
        
        // Reverse array untuk membangun pipeline dari dalam ke luar
        foreach (array_reverse($this->middlewares) as $index => $middleware) {
            if (function_exists('log_message')) {
                log_message('debug', "Building pipeline step [{$index}]: " . $this->getMiddlewareDescription($middleware));
            }
            
            $resolved = $this->resolveMiddleware($middleware);
            
            if ($resolved instanceof MiddlewareInterface) {
                // Tolerant closure: some middlewares (e.g. Routing\ThrottleRequests)
                // call the next handler with only 2 args ($next($request, $response)).
                // Normalize short calls so strict downstream closures never fatal
                // with "Too few arguments to function ...{closure}()".
                $pipeline = function($request = null, $response = null, $params = []) use ($resolved, $pipeline, $index) {
                    list($request, $response, $params) = $this->normalizePipelineArgs($request, $response, $params);
                    if (function_exists('log_message')) {
                        log_message('debug', "Executing middleware [{$index}]: " . get_class($resolved));
                    }
                    return $resolved->handle($request, $response, $pipeline, $params);
                };
            } elseif (is_callable($resolved)) {
                $pipeline = function($request = null, $response = null, $params = []) use ($resolved, $pipeline, $index) {
                    list($request, $response, $params) = $this->normalizePipelineArgs($request, $response, $params);
                    if (function_exists('log_message')) {
                        log_message('debug', "Executing callable middleware [{$index}]");
                    }
                    return call_user_func($resolved, $request, $response, $pipeline, $params);
                };
            } else {
                if (function_exists('log_message')) {
                    log_message('error', 'Cannot resolve middleware at index ' . $index . ': ' . $this->getMiddlewareDescription($middleware));
                }
                // Lanjut tanpa middleware ini
                continue;
            }
        }
        
        if (function_exists('log_message')) {
            log_message('debug', 'Pipeline built successfully');
        }
        return $pipeline;
    }

    /**
     * Normalize pipeline closure arguments.
     *
     * Middlewares may invoke the next handler as $next($request, $response),
     * $next($request, $params) or with no args at all. Fill in the missing
     * pieces from the pipeline's own request/response so every step receives
     * a complete (Request, Response, array) triple.
     *
     * @return array{Request, Response, array}
     */
    protected function normalizePipelineArgs($request, $response, $params): array
    {
        if (!($request instanceof Request)) {
            $request = $this->request;
        }

        if (!($response instanceof Response)) {
            if (is_array($response)) {
                $params = array_merge(is_array($params) ? $params : [], $response);
            }
            $response = $this->response;
        }

        if (!is_array($params)) {
            $params = [];
        }

        return [$request, $response, $params];
    }
    
    /**
     * Resolve middleware dari berbagai format
     */
    protected function resolveMiddleware($middleware)
    {
        try {
            // Gunakan registry singleton
            $registry = $this->getRegistry();
            $resolved = $registry->resolve($middleware);
            
            if ($resolved === null) {
                if (function_exists('log_message')) {
                    log_message('error', 'Middleware registry returned null for: ' . $this->getMiddlewareDescription($middleware));
                }
                
                // Jika array, coba sebagai inline group
                if (is_array($middleware)) {
                    if (function_exists('log_message')) {
                        log_message('debug', 'Trying to resolve array as inline group');
                    }
                    $group = new MiddlewareGroup();
                    foreach ($middleware as $mw) {
                        $resolvedMw = $registry->resolve($mw);
                        if ($resolvedMw) {
                            $group->add($resolvedMw);
                        }
                    }
                    if (!$group->isEmpty()) {
                        return $group;
                    }
                }
            } else {
                if (function_exists('log_message')) {
                    log_message('debug', 'Successfully resolved middleware: ' . get_class($resolved));
                }
            }
            
            return $resolved;
            
        } catch (\Exception $e) {
            if (function_exists('log_message')) {
                log_message('error', 'Error resolving middleware: ' . $e->getMessage());
                log_message('error', 'Middleware: ' . $this->getMiddlewareDescription($middleware));
            }
            
            // Throw exception dengan context
            $baseException = new BaseException(
                'Failed to resolve middleware: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
            $baseException
                ->withData([
                    'middleware_description' => $this->getMiddlewareDescription($middleware),
                    'middleware_type' => gettype($middleware)
                ])
                ->withLogContext([
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'middleware_resolution_error' => true
                ])
                ->setLogLevel('error');
            
            throw $baseException;
        }
    }
    
    /**
     * Get registry instance (singleton pattern)
     */
    protected function getRegistry()
    {
        if ($this->registry === null) {
            $this->registry = new MiddlewareRegistry();
        }
        
        return $this->registry;
    }
    
    /**
     * Get middleware description for logging
     */
    protected function getMiddlewareDescription($middleware)
    {
        if (is_string($middleware)) {
            return 'string: ' . $middleware;
        }
        
        if (is_array($middleware)) {
            $count = count($middleware);
            $first = $count > 0 ? (is_string($middleware[0]) ? $middleware[0] : gettype($middleware[0])) : 'empty';
            return "array[{$count} items, first: {$first}]";
        }
        
        if (is_object($middleware)) {
            return 'object: ' . get_class($middleware);
        }
        
        return gettype($middleware);
    }
    
    /**
     * Get all middlewares in pipeline
     */
    public function getMiddlewares()
    {
        return $this->middlewares;
    }
    
    /**
     * Clear all middlewares
     */
    public function clear()
    {
        $this->middlewares = [];
        return $this;
    }
}
