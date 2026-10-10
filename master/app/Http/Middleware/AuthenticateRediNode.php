<?php

namespace App\Http\Middleware;

use App\Models\RediNode;
use Closure;
use Illuminate\Http\Request;

class AuthenticateRediNode
{
    public function handle(Request $request, Closure $next)
    {
        $header = $request->bearerToken();
        if (! $header) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $node = null;
        foreach (RediNode::enabled()->get() as $candidate) {
            if ($candidate->verifyApiToken($header)) {
                $node = $candidate;
                break;
            }
        }

        if (! $node) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->attributes->set('redi_node', $node);

        return $next($request);
    }
}
