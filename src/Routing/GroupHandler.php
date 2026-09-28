<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing;

/**
 * Group Handler untuk manage nested route groups
 */
class GroupHandler
{
    /**
     * @var array Group stack untuk nested groups
     */
    protected $groupStack = [];

    /**
     * @var array Current group attributes
     */
    protected $currentAttributes = [];

    /**
     * @var array Default group attributes
     */
    protected $defaultAttributes = [
        'prefix' => '',
        'middleware' => [],
        'namespace' => '',
        'as' => '',
        'where' => [],
        'subdomain' => null,
        'domain' => null,
        'api_version' => null,
        'api_headers' => [],
        'api_deprecated' => false,
        'api_sunset' => null,
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->reset();
    }

    /**
     * Start a new group
     */
    public function startGroup(array $attributes): void
    {
        // Push current attributes ke stack
        $this->groupStack[] = $this->currentAttributes;
        
        // Merge dengan attributes sebelumnya untuk nested groups
        $mergedAttributes = $this->mergeWithParent($attributes);
        
        // Set sebagai current attributes
        $this->currentAttributes = $mergedAttributes;
    }

    /**
     * End current group
     */
    public function endGroup(): void
    {
        // Pop dari stack (restore previous)
        if (!empty($this->groupStack)) {
            $this->currentAttributes = array_pop($this->groupStack);
        } else {
            $this->currentAttributes = $this->defaultAttributes;
        }
    }

    /**
     * Merge attributes dengan parent group
     */
    protected function mergeWithParent(array $attributes): array
    {
        $parent = $this->currentAttributes;
        $merged = array_merge($this->defaultAttributes, $attributes);
        
        // Combine prefix
        if (!empty($attributes['prefix']) && !empty($parent['prefix'])) {
            $merged['prefix'] = rtrim($parent['prefix'], '/') . '/' . 
                               ltrim($attributes['prefix'], '/');
        } elseif (empty($attributes['prefix']) && !empty($parent['prefix'])) {
            $merged['prefix'] = $parent['prefix'];
        }
        
        // Combine middleware
        if (!empty($parent['middleware'])) {
            $currentMiddleware = (array) ($attributes['middleware'] ?? []);
            $previousMiddleware = (array) $parent['middleware'];
            $merged['middleware'] = array_merge($previousMiddleware, $currentMiddleware);
        }
        
        // Combine namespace
        if (!empty($attributes['namespace']) && !empty($parent['namespace'])) {
            $merged['namespace'] = rtrim($parent['namespace'], '\\') . '\\' .
                                  ltrim($attributes['namespace'], '\\');
        } elseif (empty($attributes['namespace']) && !empty($parent['namespace'])) {
            $merged['namespace'] = $parent['namespace'];
        }
        
        // Combine name prefix
        if (!empty($attributes['as']) && !empty($parent['as'])) {
            $merged['as'] = $parent['as'] . $attributes['as'];
        } elseif (empty($attributes['as']) && !empty($parent['as'])) {
            $merged['as'] = $parent['as'];
        }
        
        // Combine where constraints
        if (!empty($parent['where'])) {
            $currentWhere = (array) ($attributes['where'] ?? []);
            $previousWhere = (array) $parent['where'];
            $merged['where'] = array_merge($previousWhere, $currentWhere);
        }
        
        // Inherit subdomain jika tidak di-override
        if (empty($attributes['subdomain']) && !empty($parent['subdomain'])) {
            $merged['subdomain'] = $parent['subdomain'];
        }
        
        // Inherit domain jika tidak di-override
        if (empty($attributes['domain']) && !empty($parent['domain'])) {
            $merged['domain'] = $parent['domain'];
        }
        
        // Inherit API attributes jika tidak di-override
        if (empty($attributes['api_version']) && !empty($parent['api_version'])) {
            $merged['api_version'] = $parent['api_version'];
            $merged['api_headers'] = $parent['api_headers'] ?? [];
            $merged['api_deprecated'] = $parent['api_deprecated'] ?? false;
            $merged['api_sunset'] = $parent['api_sunset'] ?? null;
        }
        
        return $merged;
    }

    /**
     * Apply current group attributes ke route
     */
    public function applyToRoute(string &$uri, array &$middleware, string &$namespace): void
    {
        // Apply prefix
        if (!empty($this->currentAttributes['prefix'])) {
            $uri = trim($this->currentAttributes['prefix'], '/') . '/' . trim($uri, '/');
        }
        
        // Apply middleware
        if (!empty($this->currentAttributes['middleware'])) {
            $middleware = array_merge($middleware, (array) $this->currentAttributes['middleware']);
        }
        
        // Apply namespace
        if (!empty($this->currentAttributes['namespace'])) {
            $namespace = $this->currentAttributes['namespace'];
        }
    }

    /**
     * Apply group name prefix ke route name
     */
    public function applyNamePrefix(string $baseName): string
    {
        if (!empty($this->currentAttributes['as'])) {
            return $this->currentAttributes['as'] . $baseName;
        }
        
        return $baseName;
    }

    /**
     * Apply group constraints ke route
     */
    public function applyConstraints(RouteItem $routeItem): void
    {
        if (!empty($this->currentAttributes['where'])) {
            foreach ((array) $this->currentAttributes['where'] as $param => $pattern) {
                $routeItem->where($param, $pattern);
            }
        }
    }

    /**
     * Apply subdomain ke route
     */
    public function applySubdomain(RouteItem $routeItem): void
    {
        if (!empty($this->currentAttributes['subdomain'])) {
            $routeItem->subdomain($this->currentAttributes['subdomain']);
        }
    }

    /**
     * Apply API attributes ke route
     */
    public function applyApiAttributes(RouteItem $routeItem): void
    {
        if (!empty($this->currentAttributes['api_version'])) {
            $apiVersion = $this->currentAttributes['api_version'];
            $apiHeaders = $this->currentAttributes['api_headers'] ?? [];
            
            // Add API version middleware
            $routeItem->middleware('api.version:' . $apiVersion);
            
            // Add API headers middleware
            foreach ($apiHeaders as $header => $value) {
                $routeItem->middleware('api.header:' . $header . ':' . $value);
            }
            
            // Add deprecated warning
            if (!empty($this->currentAttributes['api_deprecated'])) {
                $deprecatedMiddleware = 'api.deprecated';
                if (!empty($this->currentAttributes['api_sunset'])) {
                    $deprecatedMiddleware .= ':' . $this->currentAttributes['api_sunset'];
                }
                $routeItem->middleware($deprecatedMiddleware);
            }
        }
    }

    /**
     * Get current group attributes
     */
    public function getCurrentAttributes(): array
    {
        return $this->currentAttributes;
    }

    /**
     * Check if currently in a group
     */
    public function inGroup(): bool
    {
        return !empty($this->groupStack) || !empty(array_filter($this->currentAttributes));
    }

    /**
     * Get group stack depth
     */
    public function getDepth(): int
    {
        return count($this->groupStack);
    }

    /**
     * Get full stack untuk debugging
     */
    public function getStack(): array
    {
        return $this->groupStack;
    }

    /**
     * Reset all groups
     */
    public function reset(): void
    {
        $this->groupStack = [];
        $this->currentAttributes = $this->defaultAttributes;
    }

    /**
     * Get combined prefix dari semua groups
     */
    public function getCombinedPrefix(): string
    {
        $prefixes = [];
        
        // Collect dari stack
        foreach ($this->groupStack as $group) {
            if (!empty($group['prefix'])) {
                $prefixes[] = trim($group['prefix'], '/');
            }
        }
        
        // Add current prefix
        if (!empty($this->currentAttributes['prefix'])) {
            $prefixes[] = trim($this->currentAttributes['prefix'], '/');
        }
        
        // Filter empty values
        $prefixes = array_filter($prefixes);
        
        if (empty($prefixes)) {
            return '';
        }
        
        return implode('/', $prefixes);
    }

    /**
     * Get combined middleware dari semua groups
     */
    public function getCombinedMiddleware(): array
    {
        $middlewares = [];
        
        // Collect dari stack
        foreach ($this->groupStack as $group) {
            if (!empty($group['middleware'])) {
                $middlewares = array_merge($middlewares, (array) $group['middleware']);
            }
        }
        
        // Add current middleware
        if (!empty($this->currentAttributes['middleware'])) {
            $middlewares = array_merge($middlewares, (array) $this->currentAttributes['middleware']);
        }
        
        // Remove duplicates
        return array_unique($middlewares);
    }

    /**
     * Get combined namespace dari semua groups
     */
    public function getCombinedNamespace(): string
    {
        $namespaces = [];
        
        // Collect dari stack
        foreach ($this->groupStack as $group) {
            if (!empty($group['namespace'])) {
                $namespaces[] = trim($group['namespace'], '\\');
            }
        }
        
        // Add current namespace
        if (!empty($this->currentAttributes['namespace'])) {
            $namespaces[] = trim($this->currentAttributes['namespace'], '\\');
        }
        
        // Filter empty values
        $namespaces = array_filter($namespaces);
        
        if (empty($namespaces)) {
            return '';
        }
        
        return implode('\\', $namespaces) . '\\';
    }

    /**
     * Debug info
     */
    public function debug(): array
    {
        return [
            'current_attributes' => $this->currentAttributes,
            'stack_depth' => $this->getDepth(),
            'combined_prefix' => $this->getCombinedPrefix(),
            'combined_middleware' => $this->getCombinedMiddleware(),
            'combined_namespace' => $this->getCombinedNamespace(),
            'in_group' => $this->inGroup(),
        ];
    }
}
