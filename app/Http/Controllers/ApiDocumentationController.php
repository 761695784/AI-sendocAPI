<?php

namespace App\Http\Controllers;

/**
 * Documentation de l'API V2 : page Swagger UI + spécification OpenAPI
 * (docs/api/doctrack-v2.openapi.yaml, importable dans Postman).
 */
class ApiDocumentationController extends Controller
{
    public function ui()
    {
        abort_unless(config('doctrack.api_docs_enabled'), 404);

        return view('api-docs.v2', ['specUrl' => url('/api/documentation/v2/openapi.yaml')]);
    }

    public function spec()
    {
        abort_unless(config('doctrack.api_docs_enabled'), 404);

        return response()->file(base_path('docs/api/doctrack-v2.openapi.yaml'), [
            'Content-Type'  => 'application/yaml; charset=UTF-8',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
