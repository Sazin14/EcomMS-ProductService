<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage on a route:  ->middleware('permission:product.create')
 * Several names mean "any of them":  ->middleware('permission:product.create,category.view')
 */
class CheckPermission
{
    public function handle(
        Request $request,
        Closure $next,
        string ...$permissions
    ): Response {

        $authorization = $request->attributes->get('authorization');

        if (! $authorization) {
            return response()->json([
                'message' => 'Authorization information unavailable.',
            ], 503);
        }

        $granted = $authorization['permissions'] ?? [];

        if (array_intersect($permissions, $granted) === []) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        return $next($request);
    }
}
