<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Middleware;

use Kodhe\Framework\Exceptions\BaseException;
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;
use Throwable;

class CallableMiddleware implements MiddlewareInterface
{
    protected $callable;
    
    public function __construct(callable $callable)
    {
        $this->callable = $callable;
    }
    
    public function handle(Request $request, Response $response, callable $next, array $params = [])
    {
        try {
            log_message('debug', 'CallableMiddleware::handle() executing');
            return call_user_func($this->callable, $request, $response, $next, $params);
        } catch (BaseException $e) {
            log_message('error', 'CallableMiddleware caught BaseException: ' . $e->getLogMessage());
            throw $e;
        } catch (Throwable $e) {
            log_message('error', 'CallableMiddleware caught Throwable: ' . $e->getMessage());
            throw (new BaseException('Middleware execution failed', 0, $e))
                ->withData(['middleware_type' => 'callable', 'callable' => $this->getCallableDescription()])
                ->setLogLevel('error');
        }
    }
    
    /**
     * Get callable description for debugging
     */
    protected function getCallableDescription(): string
    {
        if (is_array($this->callable)) {
            $class = is_object($this->callable[0]) ? get_class($this->callable[0]) : $this->callable[0];
            $method = $this->callable[1];
            return "{$class}::{$method}()";
        }
        
        if ($this->callable instanceof \Closure) {
            $reflection = new \ReflectionFunction($this->callable);
            $file = $reflection->getFileName();
            $line = $reflection->getStartLine();
            return "Closure at {$file}:{$line}";
        }
        
        if (is_string($this->callable)) {
            return "function: {$this->callable}";
        }
        
        return 'unknown callable';
    }
}
