<?php

namespace Tests\Feature\FoundDocuments;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Support\SenegalGazetteer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Organizations\OrganizationTestCase;

/**
 * Suppression définitive d'un document enregistré au guichet + carte des publications par département.
 */
class FoundDocumentDeletionAndMapTest extends OrganizationTestCase
{
    private DocumentType $cni;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->cni = DocumentType::factory()->create(['TypeName' => 'Carte nationale d\'identité']);
    }

    private function deposit(User $agent): string
    {
        return $this->asUser($agent)->postJson('/api/v2/found-documents', [
            'document_type_id' => $this->cni->id,
            'first_name'       => 'Awa',
            'last_name'        => 'Ndiaye',
            'found_place'      => 'Marché Sandaga, Dakar',
        ])->assertCreated()->json('uuid');
    }

    public function test_agent_deletes_a_counter_document_for_good(): void
    {
        $agent = $this->agent($this->commissariatA);
        $uuid  = $this->deposit($agent);

        $this->asUser($agent)->deleteJson('/api/v2/found-documents/' . $uuid)->assertOk();

        $this->assertNull(Document::withTrashed()->where('uuid', $uuid)->first());
    }

    public function test_another_structure_cannot_delete_it(): void
    {
        $uuid = $this->deposit($this->agent($this->commissariatA));

        $this->asUser($this->agent($this->commissariatB))->deleteJson('/api/v2/found-documents/' . $uuid)->assertForbidden();
        $this->assertNotNull(Document::where('uuid', $uuid)->first());
    }

    public function test_a_document_whose_owner_is_identified_is_kept(): void
    {
        $agent = $this->agent($this->commissariatA);
        $uuid  = $this->deposit($agent);
        Document::where('uuid', $uuid)->update(['lifecycle_status' => Document::LIFECYCLE_MATCHED]);

        $this->asUser($agent)->deleteJson('/api/v2/found-documents/' . $uuid)->assertStatus(409);
        $this->assertNotNull(Document::where('uuid', $uuid)->first());
    }

    public function test_places_are_recognised_without_geocoding(): void
    {
        $this->assertSame('Dakar', SenegalGazetteer::locate('Marché Sandaga, Dakar Plateau'));
        $this->assertSame('Keur Massar', SenegalGazetteer::locate('Keur Massar, Dakar'));
        $this->assertSame('Mbacké', SenegalGazetteer::locate('Touba'));
        $this->assertSame('Saint-Louis', SenegalGazetteer::locate('saint louis'));
        $this->assertNull(SenegalGazetteer::locate('Lieu inconnu'));
        $this->assertSame('Ziguinchor', SenegalGazetteer::nearest(12.58, -16.27));
        $this->assertNull(SenegalGazetteer::nearest(48.85, 2.35));
    }

    public function test_map_counts_publications_per_department(): void
    {
        Cache::flush();
        Document::factory()->count(2)->create(['Location' => 'Sandaga, Dakar', 'document_type_id' => $this->cni->id]);
        Document::factory()->create(['Location' => 'Touba', 'document_type_id' => $this->cni->id]);
        Document::factory()->create(['Location' => 'Lieu inconnu', 'document_type_id' => $this->cni->id]);

        $response = $this->getJson('/api/lieu')->assertOk()->assertHeader('X-Unlocated', '1');

        $byName = collect($response->json())->keyBy('name');
        $this->assertSame(2, $byName['Dakar']['publications']);
        $this->assertSame(1, $byName['Mbacké']['publications']);
        $this->assertSame('Diourbel', $byName['Mbacké']['region']);
    }
}
