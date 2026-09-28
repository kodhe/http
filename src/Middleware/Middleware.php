<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Middleware;

use Kodhe\Framework\Exceptions\BaseException;
use Kodhe\Framework\Exceptions\Http\HttpException;
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;
use Throwable;
abstract class Middleware implements MiddlewareInterface
{
    /**
     * @var Request
     */
    protected $request;
    
    /**
     * @var Response
     */
    protected $response;
    
    /**
     * @var array
     */
    protected $params = [];
    
    /**
     * @var callable|null
     */
    protected $next;
    
    /**
     * @var array|null
     */
    protected $parameters = null;
    
    /**
     * Set parameters
     */
    public function setParameters(array $parameters)
    {
        $this->parameters = $parameters;
    }
    
    /**
     * Get parameters
     */
    public function getParameters()
    {
        return $this->parameters;
    }
    
    /**
     * Handle middleware
     */
    public function handle(Request $request, Response $response, callable $next, array $params = [])
    {
        try {
            log_message('debug', get_class($this) . '::handle() called');
            
            $this->request = $request;
            $this->response = $response;
            $this->params = $params;
            $this->next = $next;
            
            // Jalankan before logic
            $beforeResult = $this->before($request, $response, $params);
            
            // Jika before mengembalikan Response, return langsung (stop pipeline)
            if ($beforeResult instanceof Response) {
                log_message('debug', get_class($this) . '::before() returned Response, stopping pipeline');
                return $beforeResult;
            }
            
            // Jika before mengembalikan data, set sebagai body dan return response
            if ($beforeResult !== null && !$beforeResult instanceof Response) {
                log_message('debug', get_class($this) . '::before() returned data, setting as response body');
                $response->setBody($beforeResult);
                return $response;
            }
            
            log_message('debug', get_class($this) . ' calling next middleware');
            
            // Panggil next middleware/controller.
            // Defensive: some middlewares (e.g. Routing\ThrottleRequests)
            // invoke the next handler as $next($request, $response) — 2 args
            // instead of the full 3-arg contract. Strict closures would then
            // fatal with "Too few arguments to function ...{closure}()".
            // Wrap $next so short calls are normalized before dispatch.
            $normalizedNext = static function ($req = null, $res = null, $prm = []) use ($next, $request, $response, $params) {
                if (!($req instanceof Request)) {
                    $req = $request;
                }
                if (!($res instanceof Response)) {
                    if (is_array($res)) {
                        $prm = array_merge(is_array($prm) ? $prm : [], $res);
                    }
                    $res = $response;
                }
                if (!is_array($prm)) {
                    $prm = [];
                }
                return $next($req, $res, $prm);
            };

            $result = $normalizedNext($request, $response, $params);
            
            // Pastikan result adalah Response object
            if (!$result instanceof Response) {
                log_message('debug', get_class($this) . ' next did not return Response, creating new');
                $newResponse = clone $response;
                if ($result !== null) {
                    if (is_array($result)) {
                        $newResponse->setBody(json_encode($result));
                        $newResponse->setHeader('Content-Type', 'application/json');
                    } else {
                        $newResponse->setBody((string)$result);
                    }
                }
                $result = $newResponse;
            }
            
            // Jalankan after logic
            $afterResult = $this->after($request, $result, $params, $result->getBody());
            
            // Jika after mengembalikan Response, gunakan itu
            if ($afterResult instanceof Response) {
                log_message('debug', get_class($this) . '::after() returned Response');
                return $afterResult;
            }
            
            // Jika after mengembalikan data, set sebagai body
            if ($afterResult !== null && !$afterResult instanceof Response) {
                log_message('debug', get_class($this) . '::after() returned data');
                $result->setBody($afterResult);
            }
            
            return $result;
            
        } catch (HttpException $e) {
            // Re-throw HTTP exceptions
            log_message('warning', get_class($this) . ' caught HttpException: ' . $e->getMessage());
            throw $e;
        } catch (BaseException $e) {
            // Log dan tambahkan middleware context
            log_message('error', get_class($this) . ' caught BaseException: ' . $e->getLogMessage());
            $e->withLogContext(array_merge($e->getLogContext(), [
                'middleware' => get_class($this),
                'params' => $params,
                'request_method' => $request->method(),
                'request_path' => $request->getUri()->getPath()
            ]));
            throw $e;
        } catch (Throwable $e) {
            // Convert unknown exceptions to BaseException
            log_message('error', get_class($this) . ' caught Throwable: ' . $e->getMessage());
            $baseException = new BaseException(
                'Middleware execution failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
            $baseException
                ->withData([
                    'middleware' => get_class($this),
                    'params' => $params,
                    'request_method' => $request->method(),
                    'request_path' => $request->getUri()->getPath()
                ])
                ->withLogContext([
                    'middleware' => get_class($this),
                    'exception_type' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ])
                ->setLogLevel('error');
            
            throw $baseException;
        }
    }
    
    /**
     * Get CI instance helper
     */
    protected function getCI()
    {
        if (function_exists('kodhe')) {
            return kodhe();
        }
        
        if (function_exists('get_instance')) {
            return get_instance();
        }
        
        return null;
    }
    
    /**
     * Get session data secara aman
     */
    protected function session($key = null)
    {
        $ci = $this->getCI();
        
        if ($ci && isset($ci->session)) {
            // Gunakan CI Session library yang sudah diinisialisasi
            if ($key) {
                return $ci->session->userdata($key);
            }
            return $ci->session;
        }
        
        // Untuk native session, gunakan dengan hati-hati
        // Jangan start session jika middleware lain sudah memulai
        if (session_status() === PHP_SESSION_NONE) {
            // Cek apakah kita boleh start session
            $this->startNativeSessionSafely();
        }
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            if ($key) {
                return $_SESSION[$key] ?? null;
            }
            return $_SESSION;
        }
        
        return null;
    }
    
    /**
     * Start native session dengan setting yang aman
     */
    protected function startNativeSessionSafely()
    {
        // Cek jika headers sudah dikirim
        if (headers_sent()) {
            log_message('error', 'Cannot start session: headers already sent');
            return false;
        }
        
        // Gunakan session name yang unik
        $sessionName = 'APP_SESSION_' . substr(md5(__FILE__), 0, 8);
        
        // Set secure session settings
        session_name($sessionName);
        
        // Set cookie parameters
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        $httponly = true;
        
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => $_SERVER['HTTP_HOST'] ?? '',
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => 'Strict'
        ]);
        
        // Start session (never re-start an active one: that triggers
        // "save handler cannot be changed" style warnings)
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        return session_start();
    }
    
    /**
     * Continue to next middleware
     * 
     * @throws Throwable
     */
    protected function next()
    {
        if ($this->next) {
            return call_user_func($this->next, $this->request, $this->response, $this->params);
        }
        
        return null;
    }
    
    /**
     * Before hook (compatible dengan FilterInterface)
     * 
     * @throws Throwable
     */
    public function before($request, $response, $arguments = null)
    {
        log_message('debug', get_class($this) . '::before() - override this method');
        return null;
    }
    
    /**
     * After hook (compatible dengan FilterInterface)
     * 
     * @throws Throwable
     */
    public function after($request, $response, $arguments = null, $controllerResult = null)
    {
        log_message('debug', get_class($this) . '::after() - override this method');
        return $controllerResult;
    }
    
    /**
     * Helper methods untuk response
     */
    protected function redirect($url, $statusCode = 302)
    {
        app()->load->helper('url');

        $response = new Response();
        $response->setHeader('Location', site_url($url));
        $response->setStatus($statusCode);
        return $response;
    }
    
    protected function json($data, $statusCode = 200)
    {
        $response = new Response();
        $response->setStatus($statusCode);
        $response->setBody(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $response->setHeader('Content-Type', 'application/json; charset=UTF-8');
        return $response;
    }
    
    protected function abort($message = '', $statusCode = 403)
    {
        $response = new Response();
        $response->setStatus($statusCode);
        $response->setBody($message);
        return $response;
    }
    
    /**
     * Throw HTTP exception
     */
    protected function throwHttpException(\Throwable $exception): void
    {
        if ($exception instanceof HttpException) {
            throw $exception;
        }
        
        // Convert to HttpException
        $httpException = new HttpException($exception->getMessage(), $exception->getCode(), $exception);
        $httpException->setHttpStatusCode(500);
        
        throw $httpException;
    }
}
