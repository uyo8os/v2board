<?php

namespace App\Console\Commands;

use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Foundation\Console\RouteListCommand as BaseRouteListCommand;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'route:list')]
class RouteListCommand extends BaseRouteListCommand
{
    protected function getMiddleware($route)
    {
        if (!is_a($route->getControllerClass() ?? '', Controller::class, true)) {
            return parent::getMiddleware($route);
        }

        // Legacy HTTP constructors validate request tokens. Listing routes must not
        // execute those checks; HTTP dispatch still uses the original controllers.
        // Constructor-added application middleware is not included in this listing.
        return (new Collection($this->router->resolveMiddleware(
            $route->middleware(), $route->excludedMiddleware()
        )))
            ->map(fn ($middleware) => $middleware instanceof Closure ? 'Closure' : $middleware)
            ->implode("\n");
    }
}
