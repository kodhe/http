<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Middleware\Routing;

use Kodhe\Framework\Http\Request;

class SubdomainMiddleware
{
    public function handle(Request $request, \Closure $next, string $subdomain = null)
    {
        $currentSubdomain = $this->extractSubdomain($request);
        
        // Validate subdomain
        if ($subdomain && $subdomain !== '*' && $subdomain !== '{wildcard}') {
            if ($currentSubdomain !== $subdomain) {
                // Redirect to correct subdomain or show error
                if (config('subdomain.redirect', true)) {
                    return $this->redirectToSubdomain($request, $subdomain);
                }
                
                abort(404, 'Subdomain not found');
            }
        }
        
        // Set subdomain in request for later use
        $request->setAttribute('subdomain', $currentSubdomain);
        
        return $next($request);
    }
    
    /**
     * Extract subdomain from request
     */
    protected function extractSubdomain(Request $request): ?string
    {
        $host = $request->getUri()->getHost();
        
        // Remove port if exists
        if (strpos($host, ':') !== false) {
            $host = explode(':', $host)[0];
        }
        
        $parts = explode('.', $host);
        
        // Check if we have a subdomain (at least 3 parts)
        if (count($parts) >= 3) {
            // First part is subdomain
            return $parts[0];
        }
        
        return null;
    }
    
    /**
     * Redirect to correct subdomain
     */
    protected function redirectToSubdomain(Request $request, string $subdomain): Response
    {
        $uri = $request->getUri();
        $host = $uri->getHost();
        
        // Replace or add subdomain
        $parts = explode('.', $host);
        
        if (count($parts) >= 3) {
            // Replace existing subdomain
            $parts[0] = $subdomain;
        } else {
            // Add subdomain
            array_unshift($parts, $subdomain);
        }
        
        $newHost = implode('.', $parts);
        $uri->setHost($newHost);
        
        $response = new Response();
        $response->redirect($uri->toString(), 301);
        
        return $response;
    }
}
