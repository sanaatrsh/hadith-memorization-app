<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            return ApiResponse::error('This action is unauthorized.', 403);
        }

        if (! $user->is_active) {
            return ApiResponse::error('Your account is inactive.', 403);
        }

        return $next($request);
    }
}
