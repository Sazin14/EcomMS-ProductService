<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Service-to-service calls into ProductService must send X-Internal-Key = INTERNAL_SERVICE_KEY (see config/internal.php). */
class VerifyServiceKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('internal.key');

        if ($expected === '' || ! hash_equals($expected, (string) $request->header('X-Internal-Key'))) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
