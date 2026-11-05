<?php

namespace App\Http\Middleware;

use Closure;

class RequestLog
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if ($request->method() === 'POST') {
            info('Admin POST request', [
                'action' => $request->route() ? $request->route()->getActionName() : null,
                'actor_id' => $request->input('user.id'),
                'target_id' => $request->input('id'),
            ]);
        };
        return $next($request);
    }
}
