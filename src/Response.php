<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http;

use Kodhe\Framework\Exceptions\BaseException;
use Kodhe\Framework\Exceptions\Http\HttpException;

/**
 * Core Response
 */
class Response
{
    protected $body = '';

    protected $status = 200;

    protected $headers = array();

    protected $compress = false;

    /**
     * @var bool Whether to send response as JSON
     */
    protected $jsonResponse = false;

    /**
     * Create response from exception
     *
     * @param BaseException $exception
     * @param bool $debug
     * @return static
     */
    public static function fromException(BaseException $exception, bool $debug = false)
    {
        $response = new static();
        
        // Set status code
        $response->setStatus($exception->getHttpStatusCode());
        
        // Set headers from exception if available
        if ($exception instanceof HttpException) {
            $headers = $exception->getHeaders();
            foreach ($headers as $name => $value) {
                $response->setHeader($name, $value);
            }
        }
        
        // Prepare response data
        $data = [
            'error' => [
                'code' => $exception->getErrorCode(),
                'message' => $exception->getMessage(),
                'data' => $exception->getData()
            ]
        ];
        
        // Add debug info if enabled
        if ($debug) {
            $data['debug'] = [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTrace()
            ];
        }
        
        // Set JSON response
        $response->json($data);
        
        return $response;
    }

    /**
     * Get response status
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Get response body
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * Get response headers
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * Set response body
     */
    public function setBody($str)
    {
        if (is_array($str)) {
            return $this->json($str);
        }

        $this->body = $str;
        $this->jsonResponse = false;

        // A JSON Content-Type may linger from an earlier json() call on the
        // same instance (e.g. a closure route echoed text after a middleware
        // wrote a JSON error envelope). Drop it so plain/HTML bodies are not
        // mislabeled — otherwise clients run JSON.parse on "coba data" and
        // get "SyntaxError: JSON.parse: unexpected character at line 1
        // column 1 of the JSON data".
        if ($this->hasHeader('Content-Type')) {
            $current = (string) $this->getHeader('Content-Type');

            if (stripos($current, 'application/json') !== false) {
                $this->removeHeader('Content-Type');
            }
        }
    }

    /**
     * Set JSON response (alias of json())
     *
     * @param mixed $data
     * @return self
     */
    public function setJson($data)
    {
        return $this->json($data);
    }

    /**
     * Set JSON response
     *
     * @param mixed $data
     * @return self
     */
    public function json($data)
    {
        $this->body = json_encode($data);
        $this->setHeader('Content-Type', 'application/json; charset=UTF-8');
        $this->jsonResponse = true;
        
        return $this;
    }

    /**
     * Set JSON pretty response
     *
     * @param mixed $data
     * @return self
     */
    public function jsonPretty($data)
    {
        $this->body = json_encode($data, JSON_PRETTY_PRINT);
        $this->setHeader('Content-Type', 'application/json; charset=UTF-8');
        $this->jsonResponse = true;
        
        return $this;
    }

    /**
     * Append body
     */
    public function appendBody($str)
    {
        $this->body .= $str;
    }

    /**
     * Check if header exists
     */
    public function hasHeader($header)
    {
        return array_key_exists($header, $this->headers);
    }

    /**
     * Get header value
     */
    public function getHeader($header)
    {
        if ($this->hasHeader($header)) {
            return $this->headers[$header];
        }

        return null;
    }

    /**
     * Set response header
     */
    public function setHeader($header, $value = null)
    {
        if (! isset($value)) {
            list($header, $value) = explode(':', $header, 2);
        }

        $this->headers[trim($header)] = trim($value);
    }

    /**
     * Remove header
     *
     * @param string $header
     * @return self
     */
    public function removeHeader($header)
    {
        if ($this->hasHeader($header)) {
            unset($this->headers[$header]);
        }
        
        return $this;
    }

    /**
     * Sets the status
     *
     * @param int $status The status code
     * @return self
     */
    public function setStatus($status)
    {
        if (is_numeric($status)) {
            $this->status = (int)$status;
            return $this;
        }

        throw new \InvalidArgumentException("setStatus expects a number");
    }

    /**
     * Redirect to URL
     *
     * @param string $url
     * @param int $statusCode
     * @return self
     */
    public function redirect($url, $statusCode = 302)
    {
        $this->setStatus($statusCode);
        $this->setHeader('Location', $url);
        
        return $this;
    }

    /**
     * Send response
     */
    public function send($fastcgi = true)
    {
        // Handle empty response
        if (empty($this->body) && $this->status === 204) {
            $this->setHeader('Content-Length', '0');
        }
        
        // Set status header
        $this->setStatusHeader();
        
        // Send headers
        $this->sendHeaders();
        
        // Send body
        $this->sendBody();
        
        // Exit to prevent further output
        if (function_exists('fastcgi_finish_request') && $fastcgi) {
            fastcgi_finish_request();
        }
    }

    /**
     * Send response and exit
     */
    public function sendAndExit()
    {
        $this->send();
        exit;
    }

    /**
     * Enable compression
     */
    public function enableCompression()
    {
        if ($this->supportsCompression()) {
            $this->compress = true;
        }
        
        return $this;
    }

    /**
     * Disable compression
     */
    public function disableCompression()
    {
        $this->compress = false;
        return $this;
    }

    /**
     * Check if compression is supported
     */
    public function supportsCompression()
    {
        return (
            $this->clientSupportsCompression() &&
            $this->serverSupportsCompression()
        );
    }

    /**
     * Check if compression is enabled
     */
    public function compressionEnabled()
    {
        return $this->compress == true && $this->status != 304;
    }

    /**
     * Set status header
     */
    protected function setStatusHeader()
    {

        if (headers_sent()) {
            return; // Jangan coba set header jika sudah dikirim
        }

        $statusCodes = [
            100 => 'Continue',
            101 => 'Switching Protocols',
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            203 => 'Non-Authoritative Information',
            204 => 'No Content',
            205 => 'Reset Content',
            206 => 'Partial Content',
            300 => 'Multiple Choices',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            305 => 'Use Proxy',
            307 => 'Temporary Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            407 => 'Proxy Authentication Required',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            411 => 'Length Required',
            412 => 'Precondition Failed',
            413 => 'Request Entity Too Large',
            414 => 'Request-URI Too Long',
            415 => 'Unsupported Media Type',
            416 => 'Requested Range Not Satisfiable',
            417 => 'Expectation Failed',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            505 => 'HTTP Version Not Supported'
        ];

        $protocol = isset($_SERVER['SERVER_PROTOCOL']) ? 
                   $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
        
        $statusText = isset($statusCodes[$this->status]) ? 
                     $statusCodes[$this->status] : '';
        
        header("$protocol {$this->status} {$statusText}", true, $this->status);
    }

    /**
     * Send headers
     */
    protected function sendHeaders()
    {
        if (headers_sent() || empty($this->headers)) {
            return;
        }    
            
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}", true);
        }
    }

    /**
     * Send body
     */
    protected function sendBody()
    {
        if ($this->compressionEnabled()) {
            ob_start('ob_gzhandler');
        }

        echo $this->body;
        
        if ($this->compressionEnabled()) {
            ob_end_flush();
        }
    }

    /**
     * Check if client supports compression
     */
    protected function clientSupportsCompression()
    {
        $header = 'HTTP_ACCEPT_ENCODING';

        return (
            isset($_SERVER[$header]) &&
            strpos($_SERVER[$header], 'gzip') !== false
        );
    }

    /**
     * Check if server supports compression
     */
    protected function serverSupportsCompression()
    {
        $zlib_enabled = (bool) @ini_get('zlib.output_compression');

        return $zlib_enabled == false && extension_loaded('zlib');
    }

    /**
     * Check if response is JSON
     *
     * @return bool
     */
    public function isJson()
    {
        return $this->jsonResponse;
    }
}

// EOF
