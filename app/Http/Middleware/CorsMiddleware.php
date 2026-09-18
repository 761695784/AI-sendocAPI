<?php

namespace App\Http\Middleware;

use Closure;

class CorsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    // public function handle($request, Closure $next)
    // {
    //     $response = $next($request);

    //     $response->headers->set('Access-Control-Allow-Origin', 'http://localhost:4200',);
    //     // $response->headers->set('Access-Control-Allow-Origin', '*'); // Autoriser toutes les origines
    //     $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
    //     $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');

    //     return $response;
    // }
    public function handle($request, Closure $next)
{
    $response = $next($request);

    $response->headers->set('Access-Control-Allow-Origin', 'http://localhost:4200');
    $response->headers->set('Access-Control-Allow-Methods', 'POST, GET, OPTIONS, PUT, DELETE');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With');
    $response->headers->set('Access-Control-Allow-Credentials', 'true'); // Ajoutez cette ligne

    return $response;
}
}
