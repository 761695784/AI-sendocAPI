<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_swagger_page_and_spec_are_served(): void
    {
        $this->get('/api/documentation/v2')->assertOk()->assertSee('swagger-ui')->assertSee('openapi.yaml');
        $spec = $this->get('/api/documentation/v2/openapi.yaml')->assertOk();
        $this->assertStringContainsString('openapi: 3.0.3', $spec->getFile()->getContent());
    }

    public function test_every_v2_route_is_documented(): void
    {
        $spec = file_get_contents(base_path('docs/api/doctrack-v2.openapi.yaml'));

        $missing = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($r) => '/' . $r->uri())
            ->filter(fn ($uri) => str_starts_with($uri, '/api/v2/'))
            ->map(fn ($uri) => preg_replace('/\{(organization|declaration|document|match|collection|restitution|charge|message|delivery|report|membership)\}/', '{uuid}', $uri))
            ->map(fn ($uri) => str_replace('{organizationType}', '{code}', $uri))
            ->unique()
            ->reject(fn ($uri) => str_contains($spec, "\n  {$uri}:"))
            ->values()->all();

        $this->assertSame([], $missing, 'Routes V2 absentes de la documentation : ' . implode(', ', $missing));
    }

    public function test_docs_can_be_disabled(): void
    {
        config(['doctrack.api_docs_enabled' => false]);
        $this->get('/api/documentation/v2')->assertNotFound();
    }
}
