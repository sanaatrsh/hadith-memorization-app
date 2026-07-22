<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts Swagger UI / OpenAPI docs access.
 *
 * - local & testing: open (developer convenience).
 * - other environments: authenticated admins only.
 *
 * No hardcoded passwords are used.
 */
class EnsureSwaggerAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local', 'testing')) {
            return $next($request);
        }

        abort_unless(
            auth()->check() && auth()->user()?->isAdmin(),
            403,
            'Documentation is not available.'
        );

        return $next($request);
    }
}
