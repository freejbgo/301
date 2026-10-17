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
        $input = $request->all();
        if (array_key_exists('version', $input)) {
            $version = RediAgentVersionGate::sanitize($input['version']);
        } elseif ($node) {
            $version = RediAgentVersionGate::sanitize($node->agent_version);
        } else {
            $version = null;
        }

        if ($reject = RediAgentVersionGate::rejectResponse($version)) {
            return $reject;
        }

        return $next($request);
    }
}
