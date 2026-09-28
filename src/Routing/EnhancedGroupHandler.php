<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Routing;

/**
 * Enhanced Group Handler dengan support domain extensions
 */
class EnhancedGroupHandler extends GroupHandler
{
    /**
     * @var array Domain patterns untuk matching
     */
    protected $domainPatterns = [];
    
    /**
     * @var array Registered TLDs
     */
    protected $allowedTlds = ['com', 'net', 'org', 'edu', 'gov', 'mil', 'int', 
                              'id', 'co.id', 'ac.id', 'sch.id', 'or.id', 'go.id',
                              'uk', 'co.uk', 'org.uk', 'me.uk', 'ltd.uk',
                              'au', 'com.au', 'org.au', 'net.au', 'edu.au', 'gov.au',
                              'de', 'fr', 'it', 'es', 'nl', 'be', 'ch', 'at', 'dk',
                              'se', 'no', 'fi', 'pl', 'cz', 'sk', 'hu', 'ro', 'bg',
                              'gr', 'tr', 'ru', 'ua', 'by', 'kz', 'ge', 'am', 'az',
                              'il', 'sa', 'ae', 'qa', 'om', 'kw', 'bh', 'jo', 'lb',
                              'eg', 'ma', 'tn', 'dz', 'ly', 'sd', 'so', 'et', 'ke',
                              'tz', 'ug', 'rw', 'bi', 'cd', 'cg', 'ao', 'mz', 'mw',
                              'zm', 'zw', 'na', 'bw', 'sz', 'ls', 'gh', 'ng', 'sn',
                              'ci', 'ml', 'gn', 'bf', 'ne', 'tg', 'bj', 'cm', 'td',
                              'cf', 'gq', 'ga', 'cg', 'st', 'gw', 'cv', 'mr', 'dj',
                              'er', 'km', 'mu', 'sc', 're', 'yt', 'pm', 'wf', 'tf',
                              'va', 'sm', 'li', 'mc', 'ad', 'mt', 'cy', 'is', 'ie',
                              'im', 'gg', 'je', 'fo', 'gl', 'sx', 'bq', 'cw', 'aw',
                              'sr', 'gf', 'gp', 'mq', 'bl', 'mf', 'yt', 're', 'pm',
                              'wf', 'pf', 'nc', 'vu', 'nf', 'tk', 'fm', 'mh', 'pw',
                              'ws', 'ki', 'tv', 'nr', 'to', 'sb', 'vu', 'fj', 'pg',
                              'sb', 'ki', 'tv', 'nr', 'to', 'ws', 'fm', 'mh', 'pw',
                              'ck', 'nu', 'tk', 'wf', 'as', 'gu', 'mp', 'pr', 'vi',
                              'ai', 'vg', 'ky', 'bm', 'tc', 'ms', 'kn', 'dm', 'lc',
                              'vc', 'ag', 'bb', 'gd', 'tt', 'jm', 'bs', 'cu', 'do',
                              'ht', 'jm', 'pr', 'bz', 'cr', 'sv', 'gt', 'hn', 'ni',
                              'pa', 'mx', 'ca', 'us'];
    
    /**
     * Start domain group dengan pattern matching
     */
    public function startDomainGroup(string $domainPattern, array $attributes = []): void
    {
        // Parse domain pattern
        $parsed = $this->parseDomainPattern($domainPattern);
        
        // Merge domain attributes
        $domainAttributes = array_merge($parsed, [
            'domain_pattern' => $domainPattern,
            'domain_full' => $domainPattern,
        ]);
        
        // Start group dengan combined attributes
        $allAttributes = array_merge($domainAttributes, $attributes);
        $this->startGroup($allAttributes);
    }
    
    /**
     * Parse domain pattern dengan TLD support
     */
    protected function parseDomainPattern(string $pattern): array
    {
        $result = [
            'full_domain' => $pattern,
            'host' => $pattern,
            'port' => null,
            'subdomain' => null,
            'domain' => null,
            'tld' => null,
            'is_wildcard' => false,
            'has_tld_constraint' => false,
            'tld_pattern' => null,
        ];
        
        // Extract port
        if (strpos($pattern, ':') !== false) {
            list($host, $port) = explode(':', $pattern, 2);
            $result['host'] = $host;
            $result['port'] = $port;
        }
        
        $host = $result['host'];
        
        // Check for wildcard
        if ($host === '*' || $host === '{wildcard}' || strpos($host, '{') !== false) {
            $result['is_wildcard'] = true;
            $result['subdomain'] = $host;
            
            // Extract domain dan TLD dari pattern jika ada
            if (preg_match('/\{(.*?)\}\.(.*)/', $host, $matches)) {
                $result['domain'] = $matches[2];
                $result['tld'] = $this->extractTld($matches[2]);
            }
            
            return $result;
        }
        
        // Parse domain parts
        $parts = explode('.', $host);
        
        if (count($parts) === 1) {
            // Single part: example
            $result['domain'] = $parts[0];
        } elseif (count($parts) === 2) {
            // Two parts: example.com
            $result['domain'] = $parts[0];
            $result['tld'] = $parts[1];
            $result['has_tld_constraint'] = true;
        } elseif (count($parts) > 2) {
            // Multiple parts: admin.example.com
            $result['subdomain'] = $parts[0];
            $result['domain'] = $parts[1];
            
            // Get TLD (bisa multi-part seperti co.id)
            $tldParts = array_slice($parts, 2);
            $result['tld'] = implode('.', $tldParts);
            $result['has_tld_constraint'] = true;
        }
        
        return $result;
    }
    
    /**
     * Extract TLD dari domain string
     */
    protected function extractTld(string $domain): ?string
    {
        $parts = explode('.', $domain);
        
        if (count($parts) === 1) {
            return null;
        }
        
        // Coba match dengan known TLDs
        $possibleTlds = [];
        
        // Check multi-part TLDs first
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $tldCandidate = implode('.', array_slice($parts, $i));
            
            if (in_array($tldCandidate, $this->allowedTlds)) {
                return $tldCandidate;
            }
            
            $possibleTlds[] = $tldCandidate;
        }
        
        // Return last part sebagai fallback
        return end($parts);
    }
    
    /**
     * Match request domain dengan domain patterns
     */
    public function matchDomain(string $requestHost): ?array
    {
        $matchedGroups = [];
        
        foreach ($this->groupStack as $group) {
            if (!empty($group['domain_pattern'])) {
                if ($this->domainMatchesPattern($requestHost, $group['domain_pattern'])) {
                    $matchedGroups[] = $group;
                }
            }
        }
        
        // Juga check current group
        if (!empty($this->currentAttributes['domain_pattern'])) {
            if ($this->domainMatchesPattern($requestHost, $this->currentAttributes['domain_pattern'])) {
                $matchedGroups[] = $this->currentAttributes;
            }
        }
        
        return !empty($matchedGroups) ? end($matchedGroups) : null;
    }
    
    /**
     * Check if domain matches pattern
     */
    protected function domainMatchesPattern(string $domain, string $pattern): bool
    {
        // Exact match
        if ($domain === $pattern) {
            return true;
        }
        
        // Wildcard match
        if ($pattern === '*' || $pattern === '{wildcard}') {
            return true;
        }
        
        // Pattern dengan placeholder
        if (strpos($pattern, '{') !== false) {
            $regexPattern = preg_quote($pattern, '#');
            $regexPattern = str_replace(['\{', '\}'], ['{', '}'], $regexPattern);
            $regexPattern = preg_replace('/\{[^}]+\}/', '([^\.]+)', $regexPattern);
            $regexPattern = '#^' . $regexPattern . '$#';
            
            return preg_match($regexPattern, $domain) === 1;
        }
        
        // Subdomain match
        $patternParts = explode('.', $pattern);
        $domainParts = explode('.', $domain);
        
        if (count($patternParts) !== count($domainParts)) {
            return false;
        }
        
        $partCount = count($patternParts);
        for ($i = 0; $i < $partCount; $i++) {
            if ($patternParts[$i] !== '*' && $patternParts[$i] !== $domainParts[$i]) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate TLD
     */
    public function validateTld(string $tld): bool
    {
        return in_array(strtolower($tld), $this->allowedTlds);
    }
    
    /**
     * Add custom TLD
     */
    public function addTld(string $tld): void
    {
        $tld = strtolower($tld);
        if (!in_array($tld, $this->allowedTlds)) {
            $this->allowedTlds[] = $tld;
        }
    }
    
    /**
     * Get all allowed TLDs
     */
    public function getAllowedTlds(): array
    {
        return $this->allowedTlds;
    }
    
    /**
     * Set allowed TLDs
     */
    public function setAllowedTlds(array $tlds): void
    {
        $this->allowedTlds = array_map('strtolower', $tlds);
    }
}
