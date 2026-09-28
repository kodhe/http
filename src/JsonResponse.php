<?php

declare(strict_types=1);

namespace Kodhe\Framework\Http;

use JsonSerializable;
use Kodhe\Framework\Exceptions\BaseException;

/**
 * JSON Response
 *
 * First-class wrapper around Response for building REST API replies
 * without any view layer. Produces a consistent envelope:
 *
 *   { "success": true,  "data": {...}, "meta": {...} }
 *   { "success": false, "error": { "code": "...", "message": "...", "status": 4xx, "data": {...} } }
 */
class JsonResponse extends Response implements JsonSerializable
{
    /**
     * JSON encode flags (override to tweak encoding)
     */
    protected int $encodingOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @var array Response payload (envelope)
     */
    protected array $payload = [];

    /**
     * Create a new JSON response
     *
     * @param mixed $data    Success payload or full envelope
     * @param int   $status  HTTP status code
     * @param array $headers Extra headers
     */
    public function __construct($data = null, int $status = 200, array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }

        if ($data instanceof self || $data instanceof Response) {
            // Re-wrap an existing response body
            $decoded = json_decode((string) $data->getBody(), true);
            $this->setPayload(is_array($decoded) ? $decoded : ['data' => $decoded]);
        } elseif (is_array($data) && array_key_exists('success', $data)) {
            $this->setPayload($data);
        } elseif ($data === null) {
            $this->success(null);
        } else {
            $this->success($data);
        }

        $this->setStatus($status);
    }

    /**
     * Static factory
     *
     * @param mixed $data
     * @param int   $status
     * @param array $headers
     * @return static
     */
    public static function make($data = null, int $status = 200, array $headers = []): self
    {
        return new static($data, $status, $headers);
    }

    /**
     * Successful response factory
     *
     * @param mixed $data
     * @param int   $status
     * @param array $meta
     * @return static
     */
    public static function ok($data = null, int $status = 200, array $meta = []): self
    {
        return (new static())->success($data, $status, $meta);
    }

    /**
     * Created (201) response factory
     *
     * @param mixed $data
     * @return static
     */
    public static function created($data = null): self
    {
        return (new static())->success($data, 201);
    }

    /**
     * No content (204) response factory
     *
     * @return static
     */
    public static function noContent(): self
    {
        return (new static())->success(null, 204);
    }

    /**
     * Error response factory
     *
     * @param string $message
     * @param int    $status
     * @param string $code
     * @param array  $data
     * @return static
     */
    public static function error(string $message, int $status = 400, string $code = 'BAD_REQUEST', array $data = []): self
    {
        return (new static())->fail($message, $status, $code, $data);
    }

    /**
     * Not found (404) response factory
     *
     * @param string $message
     * @return static
     */
    public static function notFound(string $message = 'Resource not found'): self
    {
        return (new static())->fail($message, 404, 'NOT_FOUND');
    }

    /**
     * Validation failed (422) response factory
     *
     * @param array  $errors Field => messages map
     * @param string $message
     * @return static
     */
    public static function validationFailed(array $errors, string $message = 'The given data was invalid.'): self
    {
        return (new static())->fail($message, 422, 'VALIDATION_ERROR', ['errors' => $errors]);
    }

    /**
     * Build a JSON response from an exception
     *
     * Signature-compatible with Response::fromException(), but always
     * returns a JsonResponse carrying the exception's error envelope.
     *
     * @param BaseException $exception
     * @param bool          $debug     Ignored (kept for compatibility)
     * @return static
     */
    public static function fromException(BaseException $exception, bool $debug = false): self
    {
        $response = new static();
        $response->setPayload($exception->toArray());
        $response->setStatus($exception->getHttpStatusCode());

        foreach ($exception->getHeaders() as $name => $value) {
            $response->setHeader($name, $value);
        }

        return $response;
    }

    /**
     * Set a successful payload into the envelope
     *
     * @param mixed $data
     * @param int   $status
     * @param array $meta Optional metadata (pagination, etc.)
     * @return self
     */
    public function success($data = null, int $status = 200, array $meta = []): self
    {
        $this->payload = [
            'success' => true,
            'data'    => $data,
        ];

        if (!empty($meta)) {
            $this->payload['meta'] = $meta;
        }

        $this->setStatus($status);
        $this->writePayload();

        return $this;
    }

    /**
     * Set an error payload into the envelope
     *
     * @param string $message
     * @param int    $status
     * @param string $code
     * @param array  $data
     * @return self
     */
    public function fail(string $message, int $status = 400, string $code = 'BAD_REQUEST', array $data = []): self
    {
        $error = [
            'code'    => $code,
            'message' => $message,
            'status'  => $status,
        ];

        if (!empty($data)) {
            $error['data'] = $data;
        }

        $this->payload = [
            'success' => false,
            'error'   => $error,
        ];

        $this->setStatus($status);
        $this->writePayload();

        return $this;
    }

    /**
     * Replace the whole envelope payload
     *
     * @param array $payload
     * @return self
     */
    public function setPayload(array $payload): self
    {
        $this->payload = $payload;
        $this->writePayload();

        return $this;
    }

    /**
     * Get the envelope payload
     *
     * @return array
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Add/replace a key in the envelope
     *
     * @param string $key
     * @param mixed  $value
     * @return self
     */
    public function with(string $key, $value): self
    {
        $this->payload[$key] = $value;
        $this->writePayload();

        return $this;
    }

    /**
     * Attach meta information (merged into existing meta)
     *
     * @param array $meta
     * @return self
     */
    public function withMeta(array $meta): self
    {
        $this->payload['meta'] = array_merge($this->payload['meta'] ?? [], $meta);
        $this->writePayload();

        return $this;
    }

    /**
     * Set custom JSON encoding options
     *
     * @param int $options
     * @return self
     */
    public function setEncodingOptions(int $options): self
    {
        $this->encodingOptions = $options;
        $this->writePayload();

        return $this;
    }

    /**
     * Decode the current payload back to an array
     *
     * @return mixed
     */
    public function decode()
    {
        return json_decode($this->getBody(), true);
    }

    /**
     * JsonSerializable implementation
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->payload;
    }

    /**
     * Encode the envelope into the response body
     */
    protected function writePayload(): void
    {
        $this->body = json_encode($this->payload, $this->encodingOptions);
        $this->setHeader('Content-Type', 'application/json; charset=UTF-8');
        $this->jsonResponse = true;
    }
}
