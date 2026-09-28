<?php

declare(strict_types=1);

use Kodhe\Framework\Http\JsonResponse;

if (!function_exists('respond')) {
    /**
     * Global helper: build a JSON response without touching the view layer
     *
     * @param mixed $data
     * @param int   $status
     * @param array $meta
     * @return JsonResponse
     */
    function respond($data = null, int $status = 200, array $meta = []): JsonResponse
    {
        return (new JsonResponse())->success($data, $status, $meta);
    }
}

if (!function_exists('respond_error')) {
    /**
     * Global helper: build a JSON error response
     *
     * @param string $message
     * @param int    $status
     * @param string $code
     * @param array  $data
     * @return JsonResponse
     */
    function respond_error(string $message, int $status = 400, string $code = 'BAD_REQUEST', array $data = []): JsonResponse
    {
        return JsonResponse::error($message, $status, $code, $data);
    }
}
