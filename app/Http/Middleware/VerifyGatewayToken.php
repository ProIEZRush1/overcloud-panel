<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyGatewayToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('overcloud.gateway.token');

        if (! $expected || ! hash_equals($expected, (string) $request->header('X-Gateway-Token'))) {
            abort(401, 'Invalid gateway token');
        }

        return $next($request);
    }
}
