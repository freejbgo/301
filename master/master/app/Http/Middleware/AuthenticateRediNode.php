<?php

namespace App\Http\Middleware;

use App\Models\RediNode;
use App\Support\OpsDisplaySanitizer;
use Closure;
use Illuminate\Http\Request;

class AuthenticateRediNode
{
    public function handle(Request $request, Closure $next)
    {
        $header = $request->bearerToken();
        if (! $header || OpsDisplaySanitizer::containsControlChars($header)) {
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
        if (! preg_match('/^(\d+)\.(.+)$/', $token, $m)) {
            return null;
        }

        $nodeId = (int) $m[1];
        if ($nodeId < 1) {
            return null;
        }

        $candidate = RediNode::enabled()->find($nodeId);
        if ($candidate && $candidate->verifyApiToken($token)) {
            return $candidate;
        }

        return null;
    }
}
