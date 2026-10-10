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

        $node = $this->resolveNode($header);

        if (! $node) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->attributes->set('redi_node', $node);

        return $next($request);
    }

    protected function resolveNode(string $token): ?RediNode
    {
        if (preg_match('/^(\d+)\.(.+)$/', $token, $m)) {
            $candidate = RediNode::enabled()->find((int) $m[1]);
            if ($candidate && $candidate->verifyApiToken($token)) {
                return $candidate;
            }
        }

        foreach (RediNode::enabled()->get() as $candidate) {
            if ($candidate->verifyApiToken($token)) {
                return $candidate;
            }
        }

        return null;
    }
}
