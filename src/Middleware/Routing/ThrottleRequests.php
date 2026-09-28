<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Middleware\Routing;

use Kodhe\Framework\Exceptions\Http\TooManyRequestsException;
use Kodhe\Framework\Http\Middleware\MiddlewareInterface;
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;
use Kodhe\Framework\Http\Routing\RateLimiter;

/**
 * Rate-limiting middleware.
 *
 * Runs naturally *after* Kodhe\Framework\Http\Middleware\ApiAuth in the
 * pipeline so the authenticated principal is already attached to the
 * request; the client's rate-limit bucket is then keyed on that identity
 * (user / API key owner) instead of a shared IP or session, which keeps
 * per-client limits accurate behind NATs and proxies.
 *
 * Identity resolution order:
 *   1. auth.id attribute set by ApiAuth            -> "api:<identifier>"
 *   2. $request->user() (session-based web auth)   -> "user:<id>"
 *   3. active PHP session                          -> "session:<id>"
 *   4. client IP                                   -> "ip:<addr>"
 *
 * On breach it throws TooManyRequestsException (HTTP 429) carrying a
 * Retry-After header; otherwise it decorates the response with the
 * standard X-RateLimit-* headers.
 */
class ThrottleRequests implements MiddlewareInterface
{
    /**
     * @var RateLimiter Rate limiter instance
     */
    protected $limiter;

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * Handle an incoming request.
     *
     * Signature-compatible with MiddlewareInterface::handle() so it can be
     * pushed through the same pipeline as every other middleware, while
     * still accepting the extra throttling parameters ($maxAttempts,
     * $decayMinutes, $key) via the $params bag:
     *
     *   $pipeline->send([['name' => ThrottleRequests::class,
     *                     'parameters' => [60, 1]]])
     *
     * @param Request        $request
     * @param Response|callable $response Response instance or $next callable (Laravel-style)
     * @param callable|array $next        $next callable or params array
     * @param array          $params
     * @return Response|mixed
     *
     * @throws TooManyRequestsException
     */
    public function handle(Request $request, $response, $next = null, array $params = [])
    {
        // Normalize the two supported call conventions.
        if (is_callable($response) && !$response instanceof Response) {
            // handle($request, $next, $params)
            $params   = is_array($next) ? $next : [];
            $next     = $response;
            $response = new Response();
        } elseif (!is_callable($next)) {
            // handle($request, $response, $params)
            if (is_array($next)) {
                $params = $next;
            }
            $next = static function (Request $req, Response $res) {
                return $res;
            };
        }

        // Accept both positional-style params ([max, decay, key]) and named keys.
        $maxAttempts   = (int) ($params['max_attempts'] ?? $params[0] ?? 60);
        $decaySeconds  = isset($params['decay_seconds'])
            ? (int) $params['decay_seconds']
            : (int) (($params['decay_minutes'] ?? $params[1] ?? 1) * 60);
        $customKey     = $params['key'] ?? $params[2] ?? null;

        $route = $request->getAttribute('route');

        // Generate rate limit key
        $limitKey = $this->resolveRequestSignature($request, $route, $customKey);

        // Check rate limit. The bucket TTL is (re)started by the very first
        // hit, so remaining quota must be measured against the attempts
        // already stored — not including the request currently in flight.
        $currentAttempts = $this->limiter->attempts($limitKey);

        if ($currentAttempts >= $maxAttempts) {
            $retryAfter = max(1, $this->limiter->availableIn($limitKey));

            throw TooManyRequestsException::create($retryAfter);
        }

        // Increment attempts
        $this->limiter->hit($limitKey, $decaySeconds);

        // Snapshot the post-hit state *before* running the pipeline: the
        // shared limiter may be used by other clients (or even other
        // buckets) while $next executes, so re-reading it afterwards could
        // report stale/wrong numbers for this request's bucket.
        $headers = $this->limiter->getHeaders($limitKey, $maxAttempts, $currentAttempts + 1);

        // Get response.
        //
        // Forward the full ($request, $response, $params) triple to the next
        // handler. Calling $next() with only two arguments used to fatal with
        // "Too few arguments to function ...{closure}(), 2 passed ... and
        // exactly 3 expected" whenever a strict 3-arg closure sat downstream
        // (e.g. the Kernel's controller-handler closure inside an 'api'
        // middleware group). The surrounding Pipeline/MiddlewareGroup closures
        // are tolerant today, but passing all three args keeps this middleware
        // compatible with any next handler regardless of its arity.
        $result = $next($request, $response, $params);

        // Add rate limit headers
        if ($result instanceof Response) {
            foreach ($headers as $name => $value) {
                $result->setHeader($name, (string) $value);
            }

            $result->setHeader('Retry-After', (string) $decaySeconds);
        }

        return $result;
    }

    /**
     * Resolve request signature for rate limiting
     *
     * @param Request     $request
     * @param mixed       $route
     * @param string|null $customKey
     * @return string
     */
    protected function resolveRequestSignature(Request $request, $route = null, ?string $customKey = null): string
    {
        if ($customKey !== null && $customKey !== '') {
            return 'custom:' . $customKey;
        }

        $identifier = $this->getRequestIdentifier($request);

        if ($route && method_exists($route, 'getRouteKey')) {
            return $this->limiter->forRoute($route->getRouteKey(), $identifier);
        }

        // Fallback: global limiter bucket per client identity
        return 'global:' . $identifier;
    }

    /**
     * Get identifier for the requesting client.
     *
     * Authenticated API principals (attached by ApiAuth) win over session
     * users, which win over IPs — this is what makes per-key throttling
     * work once ApiAuth runs earlier in the pipeline.
     *
     * @param Request $request
     * @return string
     */
    protected function getRequestIdentifier(Request $request): string
    {
        // 1. API principal from ApiAuth (works for tokens without sessions)
        $authId = $request->getAttribute('auth.id');

        if (is_string($authId) && $authId !== '') {
            return 'api:' . $authId;
        }

        // 2. Session-authenticated web user, when the app exposes one
        if (method_exists($request, 'user')) {
            $user = $request->user();

            if ($user !== null) {
                $userId = is_object($user) ? ($user->id ?? null) : (is_array($user) ? ($user['id'] ?? null) : $user);

                if ($userId !== null && $userId !== '') {
                    return 'user:' . $userId;
                }
            }
        }

        // 3. Active PHP session
        if (session_id() !== '') {
            return 'session:' . session_id();
        }

        // 4. Client IP
        return 'ip:' . ($request->ip() ?: 'unknown');
    }
}
