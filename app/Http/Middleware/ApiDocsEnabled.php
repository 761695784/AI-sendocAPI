<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Documentation Swagger (V1) : répond « introuvable » quand elle est désactivée
 * (par défaut en production, voir doctrack.api_docs_enabled / API_DOCS_ENABLED).
 */
class ApiDocsEnabled
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('doctrack.api_docs_enabled'), 404);

        return $next($request);
    }
}
