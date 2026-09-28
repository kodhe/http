<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Middleware;

use Kodhe\Framework\Http\JsonResponse;
use Kodhe\Framework\Http\Request;
use Kodhe\Framework\Http\Response;

/**
 * API Authentication Middleware
 *
 * Closes the gap where REST API endpoints were reachable without any
 * authentication. Attach it to api routes (globally via a middleware group,
 * per-route, or as the base guard of RESTController subclasses) and every
 * request must present a valid token / API key before the controller runs.
 *
 * Credentials are accepted from (in order):
 *   1. Authorization header:  "Bearer <token>"  or  "Token <key>"  or  "<scheme> <value>"
 *   2. A configurable query-string parameter (disabled by default)
 *   3. A configurable extra header name (e.g. X-API-KEY)
 *
 * Token validation is delegated to a pluggable callable so the middleware
 * stays storage-agnostic — wire it to kodhe/auth, a database lookup, an
 * OAuth resource server, a static key store, ... anything.
 *
 *   $middleware = new ApiAuth(function (string $token, Request $request) {
 *       return $userProvider->retrieveByAccessToken($token); // array|null
 *   });
 *
 * When validation succeeds, the authenticated principal is exposed to the
 * rest of the pipeline via request attributes:
 *   - $request->getAttribute('auth.user')  the validated principal
 *   - $request->getAttribute('auth.id')    best-effort identifier (email/username/id)
 *
 * On failure a JSON error envelope (401 UNAUTHENTICATED / 403 INSUFFICIENT_SCOPE)
 * is returned immediately; the downstream handler never executes.
 */
class ApiAuth implements MiddlewareInterface
{
    /**
     * @var callable(string $credential, Request $request): array|object|string|null|false
     *      Return a truthy principal (array/object/string) on success, null/false to reject.
     *      Throw Kodhe\Framework\Exceptions\Auth\AuthenticationException (or return an
     *      array with a 'message'/'status' key) to customize the rejection.
     */
    protected $validator;

    /**
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * Error messages keyed by failure reason
     *
     * @var array<string, string>
     */
    protected array $messages = [
        'missing'     => 'Authentication required: no token provided.',
        'invalid'     => 'Invalid authentication token.',
        'expired'     => 'Authentication token has expired.',
        'forbidden'   => 'The provided token does not grant access to this endpoint.',
    ];

    /**
     * @param callable|null $validator fn(string $credential, Request $request): ?array
     *                                 Null creates a disabled instance (pass-through);
     *                                 use setValidator() before relying on protection.
     * @param array $config {
     *     @var string $header            Header carrying credentials (default: Authorization)
     *     @var array  $schemes           Accepted Authorization schemes (default: ['bearer','token'])
     *                                    An empty array accepts any "scheme value" pair.
     *     @var string $queryKey          Query param fallback, '' disables it (default: '')
     *     @var string $apiKeyHeader      Extra header checked for a raw key, '' disables (default: 'X-API-Key')
     *     @var array  $optional          Paths that skip authentication (exact match, case-insensitive)
     *     @var array  $scopes            Required scopes; the validator result must carry them
     *                                    under 'scopes' (list) or 'scope' (space-delimited string)
     * }
     */
    public function __construct(?callable $validator = null, array $config = [])
    {
        $this->validator = $validator;

        $this->config = array_merge([
            'header'       => 'Authorization',
            'schemes'      => ['bearer', 'token'],
            'queryKey'     => '',
            'apiKeyHeader' => 'X-API-Key',
            'optional'     => [],
            'scopes'       => [],
        ], $config);
    }

    /**
     * Inject / replace the credential validator
     *
     * @param callable $validator
     * @return self
     */
    public function setValidator(callable $validator): self
    {
        $this->validator = $validator;

        return $this;
    }

    /**
     * Override one of the built-in error messages
     *
     * @param string $key     missing|invalid|expired|forbidden
     * @param string $message
     * @return self
     */
    public function setMessage(string $key, string $message): self
    {
        if (array_key_exists($key, $this->messages)) {
            $this->messages[$key] = $message;
        }

        return $this;
    }

    /**
     * Handle the request
     *
     * Signature-compatible with MiddlewareInterface::handle(); also tolerates
     * the Laravel-style pipeline invocation handle($request, $next, ...$params)
     * used by some route middlewares.
     *
     * @param Request        $request
     * @param Response|callable $response Response instance or $next callable
     * @param callable|array $next        $next callable or params array
     * @param array          $params
     * @return Response|mixed
     */
    public function handle(Request $request, $response, $next = null, array $params = [])
    {
        // Normalize the two supported call conventions.
        if (is_callable($response) && !$response instanceof Response) {
            // handle($request, $next, $params)
            $params = is_array($next) ? $next : [];
            $next   = $response;
            $response = new JsonResponse();
        } elseif (!is_callable($next)) {
            // handle($request, $response, $params)
            if (is_array($next)) {
                $params = $next;
            }
            $next = static function (Request $req, Response $res) {
                return $res;
            };
        }

        $config = array_merge($this->config, $params);

        // No validator wired -> fail closed unless explicitly optional path.
        if ($this->isOptional($request, $config['optional'])) {
            return $next($request, $response);
        }

        if ($this->validator === null) {
            return $this->unauthorized(
                $response,
                'API authentication is misconfigured: no validator registered.',
                'SERVER_MISCONFIGURATION',
                500
            );
        }

        $credential = $this->extractCredential($request, $config);

        if ($credential === null || $credential === '') {
            return $this->unauthorized($response, $this->messages['missing'], 'UNAUTHENTICATED', 401, $config);
        }

        $principal = null;

        try {
            $principal = $this->validate($request, $credential);
        } catch (ApiAuthFailure $failure) {
            return $this->unauthorized(
                $response,
                $failure->getMessage(),
                $failure->errorCode,
                $failure->httpStatus,
                $config,
                $failure->details
            );
        }

        if ($principal === null) {
            return $this->unauthorized($response, $this->messages['invalid'], 'UNAUTHENTICATED', 401, $config);
        }

        if (!$this->hasRequiredScopes($principal, $config['scopes'])) {
            return $this->unauthorized(
                $response,
                $this->messages['forbidden'],
                'INSUFFICIENT_SCOPE',
                403,
                $config
            );
        }

        // Expose the authenticated principal downstream.
        $this->decorateRequest($request, $principal);

        return $next($request, $response);
    }

    // ------------------------------------------------------------------
    // Credential extraction
    // ------------------------------------------------------------------

    /**
     * Pull the credential out of the request
     *
     * @param Request $request
     * @param array   $config
     * @return string|null
     */
    protected function extractCredential(Request $request, array $config): ?string
    {
        // 1. Authorization header
        $header = (string) ($request->header($config['header']) ?? '');

        if ($header !== '') {
            if (!empty($config['schemes'])) {
                foreach ((array) $config['schemes'] as $scheme) {
                    if (preg_match('/^' . preg_quote((string) $scheme, '/') . '\s+(.+)$/i', $header, $m)) {
                        return trim($m[1]);
                    }
                }
            } else {
                // Any "Scheme value" form accepted
                if (preg_match('/^\S+\s+(.+)$/', $header, $m)) {
                    return trim($m[1]);
                }
            }

            // Raw value in the Authorization header (no scheme)
            if (!preg_match('/^\S+\s+\S+$/', $header)) {
                return trim($header);
            }
        }

        // 2. Dedicated API-key header (e.g. X-API-Key)
        if (!empty($config['apiKeyHeader'])) {
            $apiKey = $request->header((string) $config['apiKeyHeader']);
            if ($apiKey !== null && trim((string) $apiKey) !== '') {
                return trim((string) $apiKey);
            }
        }

        // 3. Query-string fallback (off by default — tokens in URLs leak into logs)
        if (!empty($config['queryKey'])) {
            $queryValue = $request->get((string) $config['queryKey']);
            if (is_string($queryValue) && $queryValue !== '') {
                return trim($queryValue);
            }
        }

        return null;
    }

    /**
     * Run the pluggable validator defensively
     *
     * @param Request $request
     * @param string  $credential
     * @return array|object|string|null Normalized principal or null when rejected
     */
    protected function validate(Request $request, string $credential)
    {
        try {
            $result = call_user_func($this->validator, $credential, $request);
        } catch (\Throwable $e) {
            // AuthenticationException & friends carry their own message/status.
            $status  = method_exists($e, 'getHttpStatusCode') ? (int) $e->getHttpStatusCode() : 401;
            $code    = method_exists($e, 'getErrorCode') ? (string) $e->getErrorCode() : 'UNAUTHENTICATED';
            $message = $e->getMessage() !== '' ? $e->getMessage() : $this->messages['invalid'];

            throw new ApiAuthFailure($message, $code, $status === 403 ? 403 : 401, $e);
        }

        if ($result === false || $result === null || $result === '') {
            return null;
        }

        // Allow validators to signal rejection with details: ['error' => [...]]
        if (is_array($result) && isset($result['error']) && is_array($result['error'])) {
            $error  = $result['error'];
            $status = (int) ($error['status'] ?? 401);

            throw new ApiAuthFailure(
                (string) ($error['message'] ?? $this->messages['invalid']),
                (string) ($error['code'] ?? 'UNAUTHENTICATED'),
                $status === 403 ? 403 : 401,
                null,
                (array) ($error['data'] ?? [])
            );
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Scope checking & request decoration
    // ------------------------------------------------------------------

    /**
     * Verify the principal carries all required scopes
     *
     * @param array|object|string $principal
     * @param array               $required
     * @return bool
     */
    protected function hasRequiredScopes($principal, array $required): bool
    {
        if (empty($required)) {
            return true;
        }

        $granted = [];

        if (is_array($principal)) {
            $candidate = $principal['scopes'] ?? $principal['scope'] ?? [];
        } elseif (is_object($principal)) {
            $candidate = $principal->scopes ?? ($principal->scope ?? []);
        } else {
            $candidate = [];
        }

        if (is_string($candidate)) {
            $granted = preg_split('/[\s,]+/', trim($candidate), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (is_array($candidate)) {
            $granted = array_values(array_filter($candidate, 'is_string'));
        }

        $granted = array_map('strtolower', $granted);

        foreach ((array) $required as $scope) {
            if (!in_array(strtolower((string) $scope), $granted, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Best-effort exposure of the principal on the request
     *
     * @param Request               $request
     * @param array|object|string   $principal
     * @return void
     */
    protected function decorateRequest(Request $request, $principal): void
    {
        $identifier = null;

        if (is_array($principal)) {
            $identifier = $principal['email'] ?? $principal['username'] ?? $principal['identifier']
                ?? $principal['id'] ?? null;
        } elseif (is_object($principal)) {
            $identifier = $principal->email ?? $principal->username ?? $principal->id ?? null;
        } elseif (is_string($principal)) {
            $identifier = $principal;
        }

        if ($identifier !== null) {
            $request->setAttribute('auth.id', (string) $identifier);
        }

        $request->setAttribute('auth.user', $principal);
    }

    // ------------------------------------------------------------------
    // Responses
    // ------------------------------------------------------------------

    /**
     * Whether the current path bypasses authentication
     *
     * @param Request $request
     * @param array   $optional
     * @return bool
     */
    protected function isOptional(Request $request, array $optional): bool
    {
        if (empty($optional)) {
            return false;
        }

        $path = strtolower(trim((string) parse_url($request->server('REQUEST_URI', '/'), PHP_URL_PATH), '/'));

        foreach ($optional as $exempt) {
            if (strtolower(trim((string) $exempt, '/')) === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a JSON error response
     *
     * @param Response $response
     * @param string   $message
     * @param string   $code
     * @param int      $status
     * @param array    $config
     * @return Response
     */
    protected function unauthorized(
        Response $response,
        string $message,
        string $code,
        int $status,
        array $config = [],
        array $details = []
    ) {
        if ($response instanceof JsonResponse) {
            $response->fail($message, $status, $code, $details);
        } else {
            $error = ['code' => $code, 'message' => $message, 'status' => $status];

            if (!empty($details)) {
                $error['data'] = $details;
            }

            $payload = ['success' => false, 'error' => $error];
            $response->setBody(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $response->setHeader('Content-Type', 'application/json; charset=UTF-8');
            $response->setStatus($status);
        }

        if ($status === 401) {
            $scheme = !empty($config['schemes']) ? (string) reset($config['schemes']) : 'Bearer';
            $response->setHeader('WWW-Authenticate', ucfirst($scheme) . ' realm="api"');
        }

        return $response;
    }
}

/**
 * Internal carrier so validate() failures surface with the right status/code.
 * Kept non-final and lightweight; converted to a JSON response by handle().
 */
class ApiAuthFailure extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus,
        ?\Throwable $previous = null,
        public readonly array $details = []
    ) {
        parent::__construct($message, 0, $previous);
    }
}
