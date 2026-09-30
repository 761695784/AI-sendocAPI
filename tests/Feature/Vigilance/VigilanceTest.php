<?php

namespace Tests\Feature\Vigilance;

use App\Jobs\SendSmsJob;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\InboxMessage;
use App\Models\User;
use App\Models\VigilanceAlert;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Organizations\OrganizationTestCase;

/**
 * Vigilance : doublons et signaux de fraude, relus par un responsable.
 */
class VigilanceTest extends OrganizationTestCase
{
    private DocumentType $cni;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Bus::fake([SendSmsJob::class]);
        $this->cni = DocumentType::factory()->create(['TypeName' => "Carte nationale d'identité"]);
    }

    private function deposit(User $agent, array $data = [])
    {
        return $this->asUser($agent)->postJson('/api/v2/found-documents', array_merge([
            'document_type_id' => $this->cni->id,
            'first_name'       => 'Awa',
            'last_name'        => 'Ndiaye',
        ], $data))->assertCreated();
    }

    private function photo(string $name = 'cni_recto.jpg'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img') . '.jpg';
        copy(base_path("tests/Fixtures/ocr/{$name}"), $tmp);

        return new UploadedFile($tmp, $name, 'image/jpeg', null, true);
    }

    private function publish(User $finder, array $data = [], string $photo = 'cni_recto.jpg')
    {
        return $this->asUser($finder)->post('/api/documents', array_merge([
            'image'            => $this->photo($photo),
            'OwnerFirstName'   => 'Awa',
            'OwnerLastName'    => 'Ndiaye',
            'Location'         => 'Dakar',
            'document_type_id' => $this->cni->id,
        ], $data), ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_same_number_deposited_twice_is_a_duplicate(): void
    {
        $agent = $this->agent($this->commissariatB);
        $this->deposit($agent, ['doc_number' => '1 234 5678 90123']);
        $this->deposit($agent, ['doc_number' => '1234567890123', 'first_name' => 'Awa Marie']);

        $alert = VigilanceAlert::sole();
        $this->assertSame('duplicate_document', $alert->kind);
        $this->assertSame(VigilanceAlert::LEVEL_WARNING, $alert->level);
        $this->assertSame($this->commissariatB->id, $alert->organization_id);
        $this->assertSame('même numéro de pièce', $alert->details['reason']);
    }

    public function test_same_holder_and_birth_date_is_a_duplicate(): void
    {
        $this->deposit($this->agent($this->commissariatA), ['birth_date' => '1990-05-12']);
        $this->deposit($this->agent($this->commissariatB), ['first_name' => 'awa', 'last_name' => 'Ndiay', 'birth_date' => '1990-05-12']);
        $this->deposit($this->agent($this->commissariatB), ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'birth_date' => '1991-01-01']);

        $this->assertSame(['même titulaire (nom, prénom, date de naissance)'],
            VigilanceAlert::pluck('details')->pluck('reason')->all());
    }

    public function test_same_photo_for_another_name_is_a_high_alert(): void
    {
        $admin = $this->admin();
        $this->publish($this->citizen());
        $this->publish($this->citizen(), ['OwnerFirstName' => 'Moussa', 'OwnerLastName' => 'Diop']);
        $this->publish($this->citizen(), ['OwnerFirstName' => 'Fatou', 'OwnerLastName' => 'Sow'], 'cni_verso.jpg');   // autre photo : rien

        $alert = VigilanceAlert::sole();
        $this->assertSame('reused_photo', $alert->kind);
        $this->assertSame(VigilanceAlert::LEVEL_HIGH, $alert->level);
        $this->assertNull($alert->organization_id);
        // Alerte haute sans structure : boîte de réception du super admin.
        $this->assertSame($admin->id, InboxMessage::where('event', 'vigilance.alert')->value('user_id'));
    }

    public function test_changing_a_number_read_in_the_mrz_is_flagged(): void
    {
        config(['ai.ocr.provider' => 'hf_space', 'ai.ocr.hf_space.url' => 'https://doctrack-ocr.hf.space']);
        Http::fake(['doctrack-ocr.hf.space/ocr' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/ocr/cni_verso.jpg.json')), true))]);
        $finder = $this->citizen();
        $scan = $this->asUser($finder)->post('/api/v2/ocr/scan', ['image' => $this->photo('cni_verso.jpg')], ['Accept' => 'application/json'])->assertCreated();

        $this->asUser($finder)->post('/api/documents', [
            'ocr_scan_id'       => $scan->json('scan_id'),
            'OwnerFirstName'    => 'Awa',
            'OwnerLastName'     => 'Ndiaye',
            'DocIdentification' => '20119850512009999',     // numéro modifié
            'document_type_id'  => $this->cni->id,
            'Location'          => 'Dakar',
        ], ['Accept' => 'application/json'])->assertCreated();

        $alert = VigilanceAlert::where('kind', 'ocr_inconsistency')->sole();
        $this->assertSame(VigilanceAlert::LEVEL_HIGH, $alert->level);
        $this->assertTrue($alert->details['number_changed']);
    }

    public function test_declarations_duplicates_and_many_identities(): void
    {
        $user = $this->citizen();
        $declare = fn ($first, $last) => $this->asUser($user)->postJson('/api/declarations', [
            'Title' => 'Perte', 'FirstNameInDoc' => $first, 'LastNameInDoc' => $last, 'document_type_id' => $this->cni->id,
        ])->assertCreated();

        $declare('Awa', 'Ndiaye');
        $declare('Awa', 'Ndiaye');
        $this->assertSame(['duplicate_declaration'], VigilanceAlert::pluck('kind')->all());

        $declare('Moussa', 'Diop');
        $declare('Fatou', 'Sow');
        $declare('Ibrahima', 'Fall');
        $many = VigilanceAlert::where('kind', 'many_identities')->sole();
        $this->assertSame($user->id, $many->subject_id);
        $this->assertSame(4, $many->details['identities']);
    }

    public function test_serial_finder_is_noted(): void
    {
        $finder = $this->citizen();
        foreach (['Awa', 'Moussa', 'Fatou', 'Ibrahima', 'Aminata'] as $i => $first) {
            $this->publish($finder, ['OwnerFirstName' => $first, 'OwnerLastName' => 'Test' . $i], $i % 2 ? 'cni_verso.jpg' : 'cni_recto.jpg');
        }

        $this->assertSame(1, VigilanceAlert::where('kind', 'serial_finder')->where('level', 'info')->count());
    }

    public function test_managers_review_alerts_of_their_structure(): void
    {
        $agent = $this->agent($this->commissariatB);
        $this->deposit($agent, ['doc_number' => '1234567890123']);
        $this->deposit($agent, ['doc_number' => '1234567890123']);
        $alert = VigilanceAlert::sole();

        $manager = $this->manager($this->commissariatB);
        $this->asUser($manager)->getJson('/api/v2/vigilance/alerts')->assertOk()
            ->assertJsonPath('data.0.uuid', $alert->uuid)
            ->assertJsonPath('data.0.label', 'Document en double')
            ->assertJsonPath('summary.warning', 1);

        // Autre structure : rien. Agent : ne tranche pas.
        $this->asUser($this->manager($this->commissariatA))->getJson('/api/v2/vigilance/alerts')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($agent)->postJson("/api/v2/vigilance/alerts/{$alert->uuid}/dismiss", ['note' => 'Pas un doublon'])->assertNotFound();

        $this->asUser($manager)->postJson("/api/v2/vigilance/alerts/{$alert->uuid}/dismiss", ['note' => 'Deux cartes distinctes vérifiées'])
            ->assertOk()->assertJsonPath('status', 'dismissed');
        $this->asUser($manager)->postJson("/api/v2/vigilance/alerts/{$alert->uuid}/confirm", ['note' => 'Finalement si'])->assertStatus(409);
    }

    public function test_confirmed_online_duplicate_archives_the_newer_publication(): void
    {
        $this->publish($this->citizen(), ['DocIdentification' => '1234567890123']);
        $this->publish($this->citizen(), ['DocIdentification' => '1234567890123'], 'cni_verso.jpg');
        $alert = VigilanceAlert::where('kind', 'duplicate_document')->sole();

        $this->asUser($this->admin())->postJson("/api/v2/vigilance/alerts/{$alert->uuid}/confirm", ['note' => 'Même carte publiée deux fois'])
            ->assertOk()->assertJsonPath('status', 'confirmed');

        [$older, $newer] = Document::orderBy('id')->get()->all();
        $this->assertSame(Document::LIFECYCLE_PUBLISHED, $older->lifecycle_status);
        $this->assertSame(Document::LIFECYCLE_ARCHIVED, $newer->fresh()->lifecycle_status);
    }
}
