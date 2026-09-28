<?php

declare(strict_types=1);

namespace Kodhe\Framework\Middleware;

use Kodhe\Framework\Http\Middleware\Middleware as HttpMiddleware;

/**
 * Legacy middleware base class shim.
 *
 * App middlewares historically imported Kodhe\Framework\Middleware\Middleware
 * (a namespace that no longer exists in the PSR-4 layout — the real base is
 * Kodhe\Framework\Http\Middleware\Middleware). The missing class made every
 * such middleware fail at registry resolve time, surfacing as 500
 * INTERNAL_ERROR "Middleware group execution failed" on routes using the
 * 'api' group ([api, throttle:60,1]).
 *
 * This file registers a class_alias so both names refer to the same base
 * class. It must be loaded via composer autoload "files".
 */
if (!class_exists(Middleware::class, false)) {
    class_alias(HttpMiddleware::class, Middleware::class);
}
