<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing;

use Kodhe\Framework\Cache\Contracts\CacheInterface;

class RateLimiter
{
    /**
     * @var mixed Cache instance (CacheInterface or any key/value store
     *            exposing get/set/delete — e.g. the kodhe/cache package)
     */
    protected $cache;
    
    /**
     * @var array Rate limit hits
     */
    protected $hits = [];
    
    /**
     * @var array Default configurations
     */
    protected $defaults = [
        'max_attempts' => 60,
        'decay_minutes' => 1,
        'prefix' => 'rate_limit:',
    ];
    
    public function __construct($cache, array $config = [])
    {
        $this->cache = $cache;
        $this->defaults = array_merge($this->defaults, $config);
    }
    
    /**
     * Determine if too many attempts
     */
    public function tooManyAttempts(string $key, int $maxAttempts = null): bool
    {
        $maxAttempts = $maxAttempts ?? $this->defaults['max_attempts'];
        $attempts = $this->attempts($key);
        
        return $attempts >= $maxAttempts;
    }
    
    /**
     * Get number of attempts
     */
    public function attempts(string $key): int
    {
        $cacheKey = $this->defaults['prefix'] . $key . ':attempts';
        return (int) $this->cacheGet($cacheKey, 0);
    }
    
    /**
     * Increment attempts
     */
    public function hit(string $key, int $decaySeconds = null): int
    {
        $decaySeconds = $decaySeconds ?? ($this->defaults['decay_minutes'] * 60);
        $cacheKey = $this->defaults['prefix'] . $key . ':attempts';
        
        $attempts = $this->attempts($key) + 1;
        $this->cacheSet($cacheKey, $attempts, $decaySeconds);
        
        // Store reset time
        $resetKey = $this->defaults['prefix'] . $key . ':reset';
        $this->cacheSet($resetKey, time() + $decaySeconds, $decaySeconds);
        
        $this->hits[$key] = $attempts;
        
        return $attempts;
    }
    
    /**
     * Get available time in seconds
     */
    public function availableIn(string $key): int
    {
        $resetKey = $this->defaults['prefix'] . $key . ':reset';
        $resetTime = (int) $this->cacheGet($resetKey, 0);
        
        return max(0, $resetTime - time());
    }
    
    /**
     * Get remaining attempts
     *
     * @param string   $key
     * @param int|null $maxAttempts
     * @param int|null $attempts    Pre-computed attempt count (snapshot);
     *                              when null the current store value is read.
     */
    public function remaining(string $key, ?int $maxAttempts = null, ?int $attempts = null): int
    {
        $maxAttempts = $maxAttempts ?? $this->defaults['max_attempts'];
        $attempts = $attempts ?? $this->attempts($key);
        
        return max(0, $maxAttempts - $attempts);
    }
    
    /**
     * Get limit headers
     *
     * @param string   $key
     * @param int|null $maxAttempts
     * @param int|null $attempts    Snapshot of the attempt count taken right
     *                              after hit(), so concurrent traffic on
     *                              other buckets cannot skew the numbers.
     */
    public function getHeaders(string $key, ?int $maxAttempts = null, ?int $attempts = null): array
    {
        $maxAttempts = $maxAttempts ?? $this->defaults['max_attempts'];
        $remaining = $this->remaining($key, $maxAttempts, $attempts);
        $resetTime = time() + $this->availableIn($key);
        
        return [
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => $remaining,
            'X-RateLimit-Reset' => $resetTime,
        ];
    }
    
    /**
     * Reset attempts for a key
     */
    public function reset(string $key): void
    {
        $cacheKey = $this->defaults['prefix'] . $key . ':attempts';
        $resetKey = $this->defaults['prefix'] . $key . ':reset';
        
        $this->cacheForget($cacheKey);
        $this->cacheForget($resetKey);
        
        unset($this->hits[$key]);
    }
    
    /**
     * Clear all rate limits
     */
    public function clear(): void
    {
        foreach (array_keys($this->hits) as $key) {
            $this->reset($key);
        }
        $this->hits = [];
    }
    
    /**
     * Create rate limiter for route
     */
    public function forRoute(string $routeKey, string $identifier): string
    {
        return $routeKey . ':' . $identifier;
    }

    // ------------------------------------------------------------------
    // Cache adapter layer
    //
    // The kodhe/cache package exposes get($id)/save($id, $data, $ttl)
    // while PSR-16-style stores expose get($key, $default)/set/delete.
    // These small adapters keep RateLimiter agnostic to either shape so
    // any key/value store can back the throttle buckets.
    // ------------------------------------------------------------------

    /**
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    protected function cacheGet(string $key, $default = null)
    {
        if ($this->cache instanceof CacheInterface) {
            $value = $this->cache->get($key);

            return $value === false ? $default : $value;
        }

        return $this->cache->get($key, $default);
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @param int    $ttlSeconds
     * @return void
     */
    protected function cacheSet(string $key, $value, int $ttlSeconds): void
    {
        if ($this->cache instanceof CacheInterface) {
            $this->cache->save($key, $value, $ttlSeconds);

            return;
        }

        $this->cache->set($key, $value, $ttlSeconds);
    }

    /**
     * @param string $key
     * @return void
     */
    protected function cacheForget(string $key): void
    {
        if (method_exists($this->cache, 'delete')) {
            $this->cache->delete($key);

            return;
        }

        if (method_exists($this->cache, 'forget')) {
            $this->cache->forget($key);
        }
    }
}

