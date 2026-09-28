<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Middleware\Routing;

use Kodhe\Framework\Exceptions\ApiDeprecatedException;
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;

class ApiVersionMiddleware
{
    public function handle(Request $request, \Closure $next, string $version, string $deprecated = null, string $sunset = null)
    {
        // Check if accessing deprecated version
        if ($deprecated === 'true' || $deprecated === '1') {
            $alternative = null;
            
            // Try to find alternative version
            $currentVersion = Route::getCurrentApiVersion();
            if ($currentVersion && $currentVersion !== $version) {
                $alternative = $this->getAlternativeUrl($request, $currentVersion);
            }
            
            // Throw exception if strict mode
            if (config('api.strict_deprecation', false)) {
                throw new ApiDeprecatedException(
                    "API version {$version} is deprecated",
                    $sunset,
                    $alternative
                );
            }
        }
        
        $response = $next($request);
        
        // Add version headers
        if ($response instanceof Response) {
            $response->setHeader('X-API-Version', $version);
            
            if ($deprecated === 'true' || $deprecated === '1') {
                $warningMsg = "299 - \"Version {$version} is deprecated\"";
                $response->setHeader('Warning', $warningMsg);
                
                if ($sunset) {
                    $response->setHeader('Sunset', $sunset);
                }
                
                if (isset($alternative)) {
                    $response->setHeader('Link', '<' . $alternative . '>; rel="successor-version"');
                }
            }
        }
        
        return $response;
    }
    
    /**
     * Generate alternative URL
     */
    protected function getAlternativeUrl(Request $request, string $newVersion): string
    {
        $path = $request->getUri()->getPath();
        
        // Replace version in path
        $pattern = '#/api/v\d+(\.\d+)?/#';
        $replacement = "/api/v{$newVersion}/";
        
        $newPath = preg_replace($pattern, $replacement, $path);
        
        // Build full URL
        $scheme = $request->isSecure() ? 'https' : 'http';
        $host = $request->getUri()->getHost();
        
        return "{$scheme}://{$host}{$newPath}";
    }
}
