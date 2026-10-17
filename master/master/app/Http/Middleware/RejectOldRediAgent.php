<?php

namespace App\Http\Middleware;

use App\Models\RediNode;
use App\Support\RediAgentVersionGate;
use Closure;
use Illuminate\Http\Request;

class RejectOldRediAgent
{
    public function handle(Request $request, Closure $next)
    {
        /** @var RediNode|null $node */
        $node = $request->attributes->get('redi_node');
        $version = RediAgentVersionGate::resolveFromInput(
            $request->all(),
            $node?->agent_version
        );

        if ($reject = RediAgentVersionGate::rejectResponse($version)) {
            return $reject;
        }

        return $next($request);
    }
}
