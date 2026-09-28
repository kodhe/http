<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http;

use Kodhe\Framework\Exceptions\Http\BadRequestException;
use Kodhe\Framework\Exceptions\Http\MethodNotAllowedException;

/**
 * Core Request
 */
class Request
{
    protected $get;
    protected $post;
    protected $cookies;
    protected $files;
    protected $environment;
    
    /**
     * @var Uri Current URI object
     */
    protected $uri;

    /**
     * @var array Raw input data
     */
    protected $rawBody;

    /**
     * @var array Parsed JSON body
     */
    protected $jsonBody;

    /**
     * @var array Allowed HTTP methods for current request
     */
    protected $allowedMethods = [];

    /**
     * @var array Request attributes (set by middleware, routing, ...)
     */
    protected $attributes = [];

    public function __construct($get, $post, $cookies, $files, $environment)
    {
        $this->get = $this->trimInput($get);
        $this->post = $this->trimInput($post);
        $this->cookies = $cookies;
        $this->files = $files;
        $this->environment = $environment;
        $this->rawBody = [];
        $this->jsonBody = null;
        
        // Initialize URI
        $this->initializeUri();
        
        // Parse raw body
        $this->parseBody();
    }

    /**
     * Build request from php globals
     *
     * @return static
     */
    public static function fromGlobals()
    {
        $environment = $_SERVER + $_ENV;

        return new static($_GET, $_POST, $_COOKIE, $_FILES, $environment);
    }
    
    /**
     * Initialize URI object
     */
    protected function initializeUri()
    {
        $scheme = $this->isEncrypted() ? 'https' : 'http';
        $host = $this->server('HTTP_HOST', 'localhost');
        $port = $this->server('SERVER_PORT', 80);
        $path = $this->server('REQUEST_URI', '/');
        $query = $this->server('QUERY_STRING', '');
        
        // Parse path to remove query string
        $parsed_path = parse_url($path, PHP_URL_PATH);
        if ($parsed_path !== null) {
            $path = $parsed_path;
        }
        
        $this->uri = new Uri($scheme, $host, $port, $path, $query);
    }
    
    /**
     * Parse request body
     */
    protected function parseBody()
    {
        $contentType = $this->server('CONTENT_TYPE', '');
        
        if ($this->isPost() || $this->isPut() || $this->isPatch()) {
            $rawBody = file_get_contents('php://input');
            
            if (strpos($contentType, 'application/json') !== false) {
                $this->jsonBody = json_decode($rawBody, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw BadRequestException::invalidParameters([
                        'json' => ['Invalid JSON format: ' . json_last_error_msg()]
                    ]);
                }
                $this->rawBody = $this->jsonBody;
            } elseif (strpos($contentType, 'application/x-www-form-urlencoded') !== false ||
                     strpos($contentType, 'multipart/form-data') !== false) {
                parse_str($rawBody, $this->rawBody);
            }
        }
    }
    
    /**
     * Get the URI object
     *
     * @return Uri
     */
    public function getUri()
    {
        return $this->uri;
    }

    /**
     * Get a get value
     *
     * @param String|null $key the name of the get value
     * @param Mixed $default Value to return if $key doesn't exist
     * @return Mixed The get value [or $default]
     */
    public function get($key = null, $default = null)
    {
        return ($key) ? $this->fetch('get', $key, $default): $this->get;
    }

    /**
     * Get a post value
     *
     * @param String|null $key the name of the post value
     * @param Mixed $default Value to return if $key doesn't exist
     * @return Mixed The post value [or $default]
     */
    public function post($key = null, $default = null)
    {
        return ($key) ? $this->fetch('post', $key, $default) : $this->post;
    }

    /**
     * Get raw body data
     *
     * @param String|null $key the name of the value
     * @param Mixed $default Value to return if $key doesn't exist
     * @return Mixed The value [or $default]
     */
    public function input($key = null, $default = null)
    {
        if ($key) {
            if (is_array($this->rawBody) && array_key_exists($key, $this->rawBody)) {
                return $this->rawBody[$key];
            }
            return $default;
        }
        
        return $this->rawBody;
    }

    /**
     * Get JSON body data
     *
     * @param String|null $key the name of the value
     * @param Mixed $default Value to return if $key doesn't exist
     * @return Mixed The value [or $default]
     */
    public function json($key = null, $default = null)
    {
        if ($key) {
            if (is_array($this->jsonBody) && array_key_exists($key, $this->jsonBody)) {
                return $this->jsonBody[$key];
            }
            return $default;
        }
        
        return $this->jsonBody;
    }

    /**
     * Get all input data
     *
     * @return array
     */
    public function all()
    {
        return array_merge(
            $this->get,
            $this->post,
            $this->rawBody
        );
    }

    /**
     * Get a cookie value
     *
     * @param String|null $key the name of the cookie value
     * @param Mixed $default Value to return if $key doesn't exist
     * @return Mixed The cookie value [or $default]
     */
    public function cookie($key = null, $default = null)
    {
        return ($key) ? $this->fetch('cookies', $key, $default) : $this->cookies;
    }

    /**
     * Get a file value
     *
     * @param String|null $key the name of the file value
     * @param Mixed $default Value to return if $key doesn't exist
     * @return Mixed The file value [or $default]
     */
    public function file($key = null, $default = null)
    {
        return ($key) ? $this->fetch('files', $key, $default) : $this->files;
    }

    /**
     * Get a server value
     *
     * @param String|null $key the name of the server value
     * @param Mixed $default Value to return if $key doesn't exist
     * @return Mixed The server value [or $default]
     */
    public function server($key = null, $default = null)
    {
        return ($key) ? $this->fetch('environment', $key, $default) : $this->environment;
    }

    /**
     * Get a header
     *
     * @param String $key the name of the header
     * @param Mixed $default Value to return if header doesn't exist
     * @return Mixed The header value [or $default]
     */
    public function header($name, $default = null)
    {
        $name = str_replace('-', '_', strtoupper($name));

        // Try different header formats
        $serverKeys = [
            'HTTP_' . $name,
            $name,
            'REDIRECT_HTTP_' . $name
        ];

        foreach ($serverKeys as $serverKey) {
            if (array_key_exists($serverKey, $this->environment)) {
                return $this->environment[$serverKey];
            }
        }

        return $default;
    }

    /**
     * All parsed request headers (normalized)
     *
     * Scans the environment for HTTP_* keys and returns a map of
     * lowercase header names to their values. REDIRECT_HTTP_* variants
     * (common on CGI/FastCGI setups) are normalized as well.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [];

        foreach ($this->environment as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            } elseif (str_starts_with($key, 'REDIRECT_HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 14)));
                if (!isset($headers[$name])) {
                    $headers[$name] = (string) $value;
                }
            }
        }

        // Some servers expose Content-Type / Content-Length without the HTTP_ prefix
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (array_key_exists($key, $this->environment) && !isset($headers[$name])) {
                $headers[$name] = (string) $this->environment[$key];
            }
        }

        return $headers;
    }

    /**
     * Get the request username
     *
     * @return String username
     */
    public function username()
    {
        return $this->server('PHP_AUTH_USER')
            ?: $this->server('REMOTE_USER')
            ?: $this->server('AUTH_USER');
    }

    /**
     * Get the request password
     *
     * @return String password
     */
    public function password()
    {
        return $this->server('PHP_AUTH_PW')
            ?: $this->server('REMOTE_PASSWORD')
            ?: $this->server('AUTH_PASSWORD');
    }

    /**
     * Get the request protocol
     *
     * @return String Request protocol and version
     */
    public function protocol()
    {
        return $this->server('SERVER_PROTOCOL', 'HTTP/1.1');
    }

    /**
     * Get the request method
     *
     * @return String Request method
     */
    public function method()
    {
        $method = strtoupper($this->server('REQUEST_METHOD', 'GET'));
        
        // Check for method override via header or POST parameter
        if ($method === 'POST') {
            $overrideMethod = strtoupper($this->server('HTTP_X_HTTP_METHOD_OVERRIDE', ''));
            if ($overrideMethod && in_array($overrideMethod, ['PUT', 'DELETE', 'PATCH'])) {
                $method = $overrideMethod;
            } elseif ($this->post('_method')) {
                $method = strtoupper($this->post('_method'));
            }
        }
        
        return $method;
    }

    /**
     * Validate HTTP method
     *
     * @param array $allowedMethods
     * @throws MethodNotAllowedException
     */
    public function validateMethod(array $allowedMethods)
    {
        $this->allowedMethods = $allowedMethods;
        
        if (!in_array($this->method(), $allowedMethods)) {
            throw new MethodNotAllowedException($allowedMethods);
        }
    }

    /**
     * Is this a POST request?
     *
     * @return 	boolean
     */
    public function isPost()
    {
        return ($this->method() == 'POST');
    }

    /**
     * Is this a GET request?
     *
     * @return 	boolean
     */
    public function isGet()
    {
        return ($this->method() == 'GET');
    }

    /**
     * Is this a PUT request?
     *
     * @return 	boolean
     */
    public function isPut()
    {
        return ($this->method() == 'PUT');
    }

    /**
     * Is this a DELETE request?
     *
     * @return 	boolean
     */
    public function isDelete()
    {
        return ($this->method() == 'DELETE');
    }

    /**
     * Is this a PATCH request?
     *
     * @return 	boolean
     */
    public function isPatch()
    {
        return ($this->method() == 'PATCH');
    }

    /**
     * Is this a HEAD request?
     *
     * @return 	boolean
     */
    public function isHead()
    {
        return ($this->method() == 'HEAD');
    }

    /**
     * Is this an OPTIONS request?
     *
     * @return 	boolean
     */
    public function isOptions()
    {
        return ($this->method() == 'OPTIONS');
    }

    /**
     * Get the request body as string
     *
     * @return String Request body
     */
    public function body()
    {
        return file_get_contents('php://input');
    }

    /**
     * Is ajax request?
     *
     * Test to see if a request contains the HTTP_X_REQUESTED_WITH header
     *
     * @return 	boolean
     */
    public function isAjax()
    {
        return strtolower($this->header('X-Requested-With', '')) === 'xmlhttprequest';
    }

    /**
     * Is https request?
     *
     * @return bool Is https request?
     */
    public function isEncrypted()
    {
        if (strcasecmp($this->server('HTTPS', ''), 'on') == 0) {
            return true;
        }
        if (strcasecmp($this->server('REQUEST_SCHEME', ''), 'https') == 0) {
            return true;
        }
        if (strcasecmp($this->server('HTTP_X_FORWARDED_PROTO', ''), 'https') == 0) {
            return true;
        }
        if ($this->server('HTTP_X_FORWARDED_SSL', '') === 'on') {
            return true;
        }

        return false;
    }

    /**
     * Is a safe request
     *
     * @see RFC2616
     * @return bool Is safe request method?
     */
    public function isSafe()
    {
        return in_array(
            $this->method(),
            array('GET', 'HEAD', 'OPTIONS', 'TRACE')
        );
    }

    /**
     * Check if request has certain content type
     *
     * @param string $type
     * @return bool
     */
    public function isContentType($type)
    {
        $contentType = $this->header('Content-Type', '');
        return strpos($contentType, $type) !== false;
    }

    /**
     * Set a key and value on the current request method
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set($key, $value)
    {
        $method = strtolower($this->method());
        $this->$method[$key] = $this->trimInput($value);
        ${"_{$this->method()}"}[$key] = $value;
    }

    /**
     * Set a request attribute (metadata injected by middleware / routing)
     *
     * @param string $key
     * @param mixed  $value
     * @return self
     */
    public function setAttribute(string $key, $value)
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Get one or all request attributes
     *
     * @param string|null $key Null returns the whole attribute bag
     * @param mixed       $default
     * @return mixed
     */
    public function getAttribute(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->attributes;
        }

        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : $default;
    }

    /**
     * Get client IP address
     *
     * @return string
     */
    public function ip()
    {
        $keys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($keys as $key) {
            if ($ip = $this->server($key)) {
                // Handle multiple IPs in X-Forwarded-For
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Helper method to get with default
     *
     * @param String $arr Class array name
     * @param String $key Key to grab from the array
     * @param Mixed $default Value to return if $key doesn't exist
     * @return Mixed $key value or $default
     */
    protected function fetch($arr, $key, $default)
    {
        if (array_key_exists($key, $this->$arr)) {
            $source = $this->$arr;

            return $source[$key];
        }

        return $default;
    }

    /**
     * Helper method for recursively trimming nested input values
     *
     * @param mixed $input
     * @return mixed
     */
    protected function trimInput($input)
    {
        if (is_array($input)) {
            return array_map([$this, 'trimInput'], $input);
        }

        return is_string($input) ? trim($input) : $input;
    }

    /**
     * Get allowed HTTP methods
     *
     * @return array
     */
    public function getAllowedMethods()
    {
        return $this->allowedMethods;
    }
}

// EOF
