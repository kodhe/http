<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http;

use Kodhe\Framework\Exceptions\Http\BadRequestException;

/**
 * URI class
 */
class Uri
{
    protected $scheme;
    protected $host;
    protected $port;
    protected $path;
    protected $query;
    protected $fragment;
    
    /**
     * Create URI from string
     *
     * @param string $uri
     * @return static
     * @throws BadRequestException
     */
    public static function fromString($uri)
    {
        $parsed = parse_url($uri);
        
        if ($parsed === false) {
            throw new BadRequestException("Invalid URI format: {$uri}");
        }
        
        return new static(
            $parsed['scheme'] ?? 'http',
            $parsed['host'] ?? 'localhost',
            $parsed['port'] ?? null,
            $parsed['path'] ?? '/',
            $parsed['query'] ?? '',
            $parsed['fragment'] ?? ''
        );
    }
    
    /**
     * Constructor
     *
     * @param string $scheme
     * @param string $host
     * @param int $port
     * @param string $path
     * @param string $query
     * @param string $fragment
     */
    public function __construct($scheme = 'http', $host = 'localhost', $port = null, $path = '/', $query = '', $fragment = '')
    {
        $this->scheme = strtolower($scheme);
        $this->host = strtolower($host);
        $this->port = $port;
        $this->path = $this->normalizePath($path);
        $this->query = $query;
        $this->fragment = $fragment;
        
        // Set default port if not provided
        if ($this->port === null) {
            $this->port = $this->scheme === 'https' ? 443 : 80;
        }
    }
    
    /**
     * Normalize path
     *
     * @param string $path
     * @return string
     */
    protected function normalizePath($path)
    {
        // Ensure path starts with /
        if (empty($path) || $path[0] !== '/') {
            $path = '/' . $path;
        }
        
        // Remove multiple slashes
        $path = preg_replace('#/+#', '/', $path);
        
        // Remove directory traversal
        $segments = explode('/', $path);
        $result = [];
        
        foreach ($segments as $segment) {
            if ($segment === '..') {
                array_pop($result);
            } elseif ($segment !== '.' && $segment !== '') {
                $result[] = $segment;
            }
        }
        
        $path = '/' . implode('/', $result);
        if ($path === '') {
            $path = '/';
        }
        
        return $path;
    }
    
    /**
     * Get the scheme (http/https)
     *
     * @return string
     */
    public function getScheme()
    {
        return $this->scheme;
    }
    
    /**
     * Get the host
     *
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }
    
    /**
     * Get the port
     *
     * @return int
     */
    public function getPort()
    {
        return $this->port;
    }
    
    /**
     * Get the path
     *
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }
    
    /**
     * Get the query string
     *
     * @return string
     */
    public function getQuery()
    {
        return $this->query;
    }
    
    /**
     * Get query parameters as array
     *
     * @return array
     */
    public function getQueryParams()
    {
        parse_str($this->query, $params);
        
        return $params;
    }
    
    /**
     * Get query parameter
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getQueryParam($key, $default = null)
    {
        $params = $this->getQueryParams();
        return isset($params[$key]) ? $params[$key] : $default;
    }
    
    /**
     * Get the fragment
     *
     * @return string
     */
    public function getFragment()
    {
        return $this->fragment;
    }
    
    /**
     * Get full URI
     *
     * @return string
     */
    public function getFullUri()
    {
        $uri = $this->scheme . '://' . $this->host;
        
        if (($this->scheme === 'http' && $this->port != 80) || 
            ($this->scheme === 'https' && $this->port != 443)) {
            $uri .= ':' . $this->port;
        }
        
        $uri .= $this->path;
        
        if (!empty($this->query)) {
            $uri .= '?' . $this->query;
        }
        
        if (!empty($this->fragment)) {
            $uri .= '#' . $this->fragment;
        }
        
        return $uri;
    }
    
    /**
     * Get base URL (scheme://host:port)
     *
     * @return string
     */
    public function getBaseUrl()
    {
        $url = $this->scheme . '://' . $this->host;
        
        if (($this->scheme === 'http' && $this->port != 80) || 
            ($this->scheme === 'https' && $this->port != 443)) {
            $url .= ':' . $this->port;
        }
        
        return $url;
    }
    
    /**
     * Get URI segments as array
     *
     * @return array
     */
    public function getSegments()
    {
        $path = trim($this->path, '/');
        if (empty($path)) {
            return [];
        }
        
        return explode('/', $path);
    }
    
    /**
     * Get specific segment
     *
     * @param int $index Segment index (starting from 1)
     * @return string|null
     */
    public function getSegment($index)
    {
        $segments = $this->getSegments();
        $index = (int)$index - 1;
        
        if (isset($segments[$index])) {
            return $segments[$index];
        }
        
        return null;
    }
    
    /**
     * Get number of segments
     *
     * @return int
     */
    public function getSegmentCount()
    {
        return count($this->getSegments());
    }
    
    /**
     * Check if path matches pattern
     *
     * @param string $pattern
     * @return bool
     */
    public function matches($pattern)
    {
        $pattern = preg_quote($pattern, '#');
        $pattern = str_replace('\*', '.*', $pattern);
        
        return preg_match('#^' . $pattern . '$#', $this->path) === 1;
    }
    
    /**
     * String representation
     *
     * @return string
     */
    public function __toString()
    {
        return $this->getFullUri();
    }
}
