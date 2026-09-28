<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http\Controllers;

use Kodhe\Framework\Exceptions\BaseException;
use Kodhe\Framework\Http\JsonResponse;
use Kodhe\Framework\Http\Request;

/**
 * REST Controller
 *
 * Abstract base class for JSON-only (view-less) REST API controllers.
 * Pair it with Route::apiResource('posts', PostController::class) which maps:
 *
 *   GET    /posts          -> index()
 *   POST   /posts          -> store()
 *   GET    /posts/{post}   -> show($id)
 *   PUT    /posts/{post}   -> update($id)
 *   PATCH  /posts/{post}   -> patch($id)  (partial update, delegates to update by default)
 *   DELETE /posts/{post}   -> destroy($id)
 *
 * Subclasses override the resource() hook (or the individual methods) and
 * get consistent JSON envelopes plus response helpers for free. No view
 * rendering is ever performed by this controller.
 */
abstract class RESTController extends Controller
{
    /**
     * Human readable resource name used in default messages
     *
     * @var string
     */
    protected string $resourceName = 'resource';

    /**
     * Current request instance (lazily resolved from globals)
     *
     * @var Request|null
     */
    protected ?Request $request = null;

    /**
     * Default JSON encoding flags for all responses of this controller
     *
     * @var int
     */
    protected int $jsonEncodingFlags = 0;

    /**
     * Set the current request (dependency injection friendly, mainly for tests)
     *
     * @param Request $request
     * @return self
     */
    public function setRequest(Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    /**
     * Get the current request, resolving from PHP globals when not injected
     *
     * @return Request
     */
    public function getRequest(): Request
    {
        if ($this->request === null) {
            $this->request = Request::fromGlobals();
        }

        return $this->request;
    }

    // ---------------------------------------------------------------------
    // Resource endpoints - override in subclasses
    // ---------------------------------------------------------------------

    /**
     * Handle a resource request based on the HTTP verb
     *
     * Convenience entry point when a single route catches all verbs.
     *
     * @param mixed $id
     * @return JsonResponse
     */
    public function handle($id = null): JsonResponse
    {
        $method = strtoupper((string) $this->getRequest()->method());

        return match ($method) {
            'GET'    => $id === null ? $this->index() : $this->show($id),
            'POST'   => $this->store(),
            'PUT'    => $this->update($id),
            'PATCH'  => $this->patch($id),
            'DELETE' => $this->destroy($id),
            default  => $this->methodNotAllowed(),
        };
    }

    /**
     * GET /resource - list all items
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        return $this->notImplemented('index');
    }

    /**
     * POST /resource - create a new item
     *
     * @return JsonResponse
     */
    public function store(): JsonResponse
    {
        return $this->notImplemented('store');
    }

    /**
     * GET /resource/{id} - show a single item
     *
     * @param mixed $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        return $this->notImplemented('show');
    }

    /**
     * PUT /resource/{id} - replace an item
     *
     * @param mixed $id
     * @return JsonResponse
     */
    public function update($id): JsonResponse
    {
        return $this->notImplemented('update');
    }

    /**
     * PATCH /resource/{id} - partially update an item
     *
     * Defaults to full update(); override for true partial semantics.
     *
     * @param mixed $id
     * @return JsonResponse
     */
    public function patch($id): JsonResponse
    {
        return $this->update($id);
    }

    /**
     * DELETE /resource/{id} - remove an item
     *
     * @param mixed $id
     * @return JsonResponse
     */
    public function destroy($id): JsonResponse
    {
        return $this->notImplemented('destroy');
    }

    // ---------------------------------------------------------------------
    // Response helpers
    // ---------------------------------------------------------------------

    /**
     * 200 OK success response
     *
     * @param mixed $data
     * @param array $meta
     * @return JsonResponse
     */
    protected function respond($data = null, array $meta = []): JsonResponse
    {
        return $this->makeResponse($data, 200, $meta);
    }

    /**
     * 201 Created success response
     *
     * @param mixed $data
     * @return JsonResponse
     */
    protected function respondCreated($data = null): JsonResponse
    {
        return $this->makeResponse($data, 201);
    }

    /**
     * 204 No Content success response
     *
     * @return JsonResponse
     */
    protected function respondNoContent(): JsonResponse
    {
        return $this->makeResponse(null, 204);
    }

    /**
     * Error response
     *
     * @param string $message
     * @param int    $status
     * @param string $code
     * @param array  $data
     * @return JsonResponse
     */
    protected function respondError(
        string $message,
        int $status = 400,
        string $code = 'BAD_REQUEST',
        array $data = []
    ): JsonResponse {
        return $this->failResponse($message, $status, $code, $data);
    }

    /**
     * 404 Not Found error response
     *
     * @param mixed $id
     * @return JsonResponse
     */
    protected function respondNotFound($id = null): JsonResponse
    {
        $message = $id === null
            ? ucfirst($this->resourceName) . ' not found'
            : ucfirst($this->resourceName) . " [{$id}] not found";

        return $this->failResponse($message, 404, 'NOT_FOUND');
    }

    /**
     * 401 Unauthorized error response
     *
     * Pairs with the ApiAuth middleware for controllers that prefer to
     * answer with the JSON envelope instead of throwing.
     *
     * @param string $message
     * @param string $code
     * @return JsonResponse
     */
    protected function respondUnauthorized(
        string $message = 'Authentication required.',
        string $code = 'UNAUTHENTICATED'
    ): JsonResponse {
        return $this->failResponse($message, 401, $code);
    }

    /**
     * 405 Method Not Allowed error response
     *
     * @param array $allowed
     * @return JsonResponse
     */
    protected function methodNotAllowed(array $allowed = []): JsonResponse
    {
        $response = $this->failResponse('Method not allowed', 405, 'METHOD_NOT_ALLOWED');

        if (!empty($allowed)) {
            $response->setHeader('Allow', implode(', ', $allowed));
        }

        return $response;
    }

    /**
     * 422 Validation error response
     *
     * @param array  $errors Field => messages map
     * @param string $message
     * @return JsonResponse
     */
    protected function respondValidation(array $errors, string $message = 'The given data was invalid.'): JsonResponse
    {
        return $this->failResponse($message, 422, 'VALIDATION_ERROR', ['errors' => $errors]);
    }

    /**
     * Build an error response from an exception
     *
     * @param BaseException|\Throwable $exception
     * @return JsonResponse
     */
    protected function respondException(\Throwable $exception): JsonResponse
    {
        if ($exception instanceof BaseException) {
            return $this->applyEncoding($this->decorate(JsonResponse::fromException($exception)));
        }

        return $this->failResponse(
            $exception->getMessage() !== '' ? $exception->getMessage() : 'Internal server error',
            500,
            'INTERNAL_ERROR'
        );
    }

    /**
     * Placeholder response for endpoints the subclass did not implement
     *
     * @param string $action
     * @return JsonResponse
     */
    protected function notImplemented(string $action): JsonResponse
    {
        return $this->failResponse(
            sprintf('Action [%s] is not implemented by %s.', $action, static::class),
            501,
            'NOT_IMPLEMENTED'
        );
    }

    /**
     * Build a success JsonResponse
     *
     * @param mixed $data
     * @param int   $status
     * @param array $meta
     * @return JsonResponse
     */
    protected function makeResponse($data = null, int $status = 200, array $meta = []): JsonResponse
    {
        $response = new JsonResponse();

        return $this->applyEncoding($this->decorate($response->success($data, $status, $meta)));
    }

    /**
     * Build an error JsonResponse
     *
     * @param string $message
     * @param int    $status
     * @param string $code
     * @param array  $data
     * @return JsonResponse
     */
    protected function failResponse(string $message, int $status, string $code, array $data = []): JsonResponse
    {
        $response = new JsonResponse();

        return $this->applyEncoding($this->decorate($response->fail($message, $status, $code, $data)));
    }

    /**
     * Hook to add per-controller metadata (e.g. API version, links)
     *
     * @param JsonResponse $response
     * @return JsonResponse
     */
    protected function decorate(JsonResponse $response): JsonResponse
    {
        return $response;
    }

    /**
     * Apply the controller-wide JSON encoding flags
     *
     * @param JsonResponse $response
     * @return JsonResponse
     */
    protected function applyEncoding(JsonResponse $response): JsonResponse
    {
        if ($this->jsonEncodingFlags !== 0) {
            $response->setEncodingOptions(
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | $this->jsonEncodingFlags
            );
        }

        return $response;
    }
}
