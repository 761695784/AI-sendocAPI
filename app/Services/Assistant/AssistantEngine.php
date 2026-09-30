<?php

namespace App\Services\Assistant;

use App\Models\AssistantConversation;
use App\Models\DeclarationDePerte;
use App\Models\DocumentMatch;
use App\Models\DocumentType;
use App\Models\Organization;
use App\Models\Territory;
use App\Models\User;
use App\Services\Ai\IntentClassifier;
use App\Services\Collections\NearestStructureFinder;
use App\Services\Declarations\OnlineDeclarationService;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cerveau de l'assistant : dialogue guidé, étape par étape.
 *
 *  - Il comprend par mots-clés (Lexicon) ; un modèle de langage n'intervient
 *    qu'en secours, pour proposer une intention (jamais pour agir).
 *  - Il récapitule et demande « oui / non » avant toute action.
 *  - Il réutilise les services DocTrack existants (déclaration en ligne,
 *    structure la plus proche…) : mêmes règles que les autres canaux.
 */
class AssistantEngine
{
    public const MAIN_INTENTS = ['declare_loss', 'report_found', 'track_status', 'where_to_go', 'what_to_bring', 'proxy', 'human', 'help'];

    private AssistantConversation $conv;

    private ?User $user;

    private array $ctx;

    private AssistantReply $reply;

    public function __construct(
        private Lexicon $lexicon,
        private Phrasebook $phrases,
        private IntentClassifier $classifier,
        private OnlineDeclarationService $declarations,
        private NearestStructureFinder $structures,
        private Notifier $notifier,
    ) {}

    /**
     * @param  array{latitude?: ?float, longitude?: ?float}  $ctx
     */
    public function handle(AssistantConversation $conv, string $text, ?User $user, array $ctx = []): AssistantReply
    {
        $this->conv  = $conv;
        $this->user  = $user;
        $this->ctx   = $ctx;
        $this->reply = new AssistantReply();
        $text = trim($text);

        if ($conv->flow && $conv->isExpired()) {
            $conv->startFlow(null);
            $this->say('expired');
        }

        if ($text === '') {
            $this->welcome();

            return $this->finish();
        }

        if ($this->handleCommand($text)) {
            return $this->finish();
        }

        if ($lang = $this->lexicon->detectLanguage($text)) {
            $conv->language = $lang;
        }

        if ($conv->flow && $conv->step && $this->handleStep($text)) {
            $conv->misunderstood = 0;

            return $this->finish();
        }

        $this->handleTopLevel($text);

        return $this->finish();
    }

    // ── Commandes globales ───────────────────────────────────────────

    private function handleCommand(string $text): bool
    {
        $command = $this->lexicon->command($text);

        switch ($command) {
            case 'lang_wo':
            case 'lang_fr':
                $this->conv->language = $command === 'lang_wo' ? 'wo' : 'fr';
                $this->say('language_switched');
                $this->conv->flow && $this->conv->step ? $this->reask() : $this->mainMenu('menu');

                return true;

            case 'cancel':
                $this->conv->startFlow(null);
                $this->say('cancelled');
                $this->mainButtons();

                return true;

            case 'restart':
                $this->conv->startFlow(null);
                $this->welcome();

                return true;
        }

        return false;
    }

    // ── Niveau principal ─────────────────────────────────────────────

    private function handleTopLevel(string $text): void
    {
        $detected = $this->lexicon->detectIntent($text);
        $intent   = $detected['score'] > 0 ? $detected['intent'] : null;
        $docType  = $this->lexicon->documentType($text);

        if (! $intent && ! $docType) {
            $suggestion = $this->classifier->classify($text, self::MAIN_INTENTS, DocumentType::pluck('TypeName')->all());
            $intent  = $suggestion['intent'] ?? null;
            $docType = ! empty($suggestion['document_type']) ? DocumentType::where('TypeName', $suggestion['document_type'])->first() : null;
            if ($suggestion) {
                $this->reply->meta['classifier'] = $suggestion;
            }
        }

        // « Ma CNI » seul : on suppose une perte (cas le plus fréquent), on récapitulera.
        if (! $intent && $docType) {
            $intent = 'declare_loss';
        }

        if (! $intent) {
            $this->misunderstood();

            return;
        }

        $this->conv->misunderstood = 0;
        $this->reply->intent = $intent;

        match ($intent) {
            'declare_loss'  => $this->startLoss($docType),
            'report_found'  => $this->startFound($docType),
            'track_status'  => $this->trackStatus(),
            'where_to_go'   => $this->whereToGo(),
            'what_to_bring' => $this->info('what_to_bring_info'),
            'proxy'         => $this->info('proxy_info'),
            'human'         => $this->handoff(),
            'greet'         => $this->welcome(),
            'thanks'        => $this->say('thanks_reply'),
            default         => $this->mainMenu('menu'),
        };
    }

    private function misunderstood(): void
    {
        $this->conv->misunderstood = min(255, $this->conv->misunderstood + 1);
        $this->reply->intent = 'unknown';

        if ($this->conv->misunderstood >= config('assistant.max_misunderstood', 2)) {
            $this->say('offer_human');
            $this->reply->buttons([
                $this->phrases->button('human', $this->lang()),
                $this->phrases->button('declare_loss', $this->lang()),
                $this->phrases->button('report_found', $this->lang()),
                $this->phrases->button('track_status', $this->lang()),
            ]);

            return;
        }

        $this->say('not_understood');
        $this->mainButtons();
    }

    // ── Étapes d'un parcours ─────────────────────────────────────────

    /** @return bool false = réponse non comprise à cette étape (on tente le niveau principal). */
    private function handleStep(string $text): bool
    {
        $step = $this->conv->step;

        switch ($step) {
            case 'doc_type':
                $type = $this->lexicon->documentType($text);
                if (! $type) {
                    // Changement d'avis (« en fait j'ai trouvé une carte ») ?
                    $detected = $this->lexicon->detectIntent($text);
                    if ($detected['score'] >= 2 && $detected['intent'] !== $this->conv->flow) {
                        return false;
                    }
                    $suggestion = $this->classifier->classify($text, [], DocumentType::pluck('TypeName')->all());
                    $type = ! empty($suggestion['document_type']) ? DocumentType::where('TypeName', $suggestion['document_type'])->first() : null;
                }
                if (! $type) {
                    $this->say('doc_type_unknown');
                    $this->docTypeButtons();

                    return true;
                }
                $this->setDocType($type);
                break;

            case 'full_name':
                [$first, $last] = $this->lexicon->parseFullName($text);
                if (! $first || ! $last) {
                    $this->say('ask_full_name_again');

                    return true;
                }
                $this->conv->setSlot('first_name', $first);
                $this->conv->setSlot('last_name', $last);
                break;

            case 'first_name':
                if (! $name = $this->lexicon->parseName($text)) {
                    $this->reask();

                    return true;
                }
                $this->conv->setSlot('first_name', $name);
                $this->conv->setSlot('last_name', null);
                $this->ask('last_name');

                return true;

            case 'last_name':
                if (! $name = $this->lexicon->parseName($text)) {
                    $this->reask();

                    return true;
                }
                $this->conv->setSlot('last_name', $name);
                break;

            case 'doc_number':
                if ($this->lexicon->isSkip($text) || $this->lexicon->isNo($text)) {
                    $this->conv->setSlot('doc_number', '');
                } elseif ($number = $this->lexicon->docNumber($text)) {
                    $this->conv->setSlot('doc_number', $number);
                } else {
                    $this->reask();

                    return true;
                }
                break;

            case 'place':
                if ($this->lexicon->isSkip($text) && $this->conv->flow === 'declare_loss') {
                    $this->conv->setSlot('place', '');
                } else {
                    $this->conv->setSlot('place', mb_substr(trim($text), 0, 150));
                    $this->conv->setSlot('territory_id', $this->resolveTerritory($text)?->id);
                }
                break;

            case 'consent':
                $answer = $this->lexicon->answer($text);
                if (! $answer) {
                    $this->say('answer_yes_no');
                    $this->yesNoButtons();

                    return true;
                }
                $this->conv->setSlot('consent', $answer === 'yes');
                break;

            case 'confirm':
                $answer = $this->lexicon->answer($text);
                if ($answer === 'yes') {
                    $this->conv->flow === 'declare_loss' ? $this->executeLoss() : $this->executeFound();

                    return true;
                }
                if ($answer === 'no') {
                    $this->ask('fix');

                    return true;
                }
                $this->say('answer_yes_no');
                $this->yesNoButtons();

                return true;

            case 'fix':
                if (! $this->applyFix($text)) {
                    $this->reask();
                }

                return true;

            case 'location':
                $this->nearestFromText($text);

                return true;

            default:
                return false;
        }

        $this->next();

        return true;
    }

    /** Pose la prochaine question du parcours, ou le récapitulatif. */
    private function next(): void
    {
        $s = $this->conv->slots ?? [];

        if (empty($s['doc_type_id'])) {
            $this->ask('doc_type');
        } elseif (empty($s['first_name']) || empty($s['last_name'])) {
            $this->ask(empty($s['first_name']) ? 'full_name' : 'last_name');
        } elseif ($this->conv->flow === 'declare_loss' && ! array_key_exists('doc_number', $s)) {
            $this->ask('doc_number');
        } elseif (! array_key_exists('place', $s)) {
            $this->ask('place');
        } elseif ($this->conv->flow === 'report_found' && ! array_key_exists('consent', $s)) {
            $this->ask('consent');
        } else {
            $this->ask('confirm');
        }
    }

    private function ask(string $step): void
    {
        $this->conv->step = $step;
        $lost = $this->conv->flow === 'declare_loss';

        switch ($step) {
            case 'doc_type':
                $this->say($lost ? 'ask_doc_type_lost' : 'ask_doc_type_found');
                $this->docTypeButtons();
                break;
            case 'full_name':
                $this->say($lost ? 'ask_owner_name' : 'ask_owner_name_found');
                break;
            case 'first_name':
                $this->say('ask_first_name');
                break;
            case 'last_name':
                $this->say('ask_last_name');
                break;
            case 'doc_number':
                $this->say('ask_doc_number');
                $this->reply->buttons([$this->phrases->button('skip', $this->lang())]);
                break;
            case 'place':
                $this->say($lost ? 'ask_lost_place' : 'ask_found_place');
                if ($lost) {
                    $this->reply->buttons([$this->phrases->button('skip', $this->lang())]);
                }
                break;
            case 'consent':
                $this->say('ask_collect_consent');
                $this->yesNoButtons();
                break;
            case 'confirm':
                $this->summary();
                $this->yesNoButtons();
                break;
            case 'fix':
                $this->say('what_to_fix');
                $buttons = ['fix_type', 'fix_name'];
                if ($lost) {
                    $buttons[] = 'fix_number';
                }
                $buttons[] = 'fix_place';
                $buttons[] = 'cancel';
                $this->reply->buttons(array_map(fn ($b) => $this->phrases->button($b, $this->lang()), $buttons));
                break;
            case 'location':
                $this->say('ask_location');
                break;
        }
    }

    /** Répète la question en cours. */
    private function reask(): void
    {
        $this->ask($this->conv->step);
    }

    private function summary(): void
    {
        $s = $this->conv->slots ?? [];
        $lang = $this->lang();
        $params = [
            'document_type' => $s['doc_type_name'] ?? '',
            'first_name'    => $s['first_name'] ?? '',
            'last_name'     => $s['last_name'] ?? '',
        ];

        if ($this->conv->flow === 'declare_loss') {
            $number = $s['doc_number'] ?? '';
            $params['number'] = $number !== ''
                ? ($lang === 'wo' ? ', nimero bu jeexe ci ' : ', numéro se terminant par ') . substr($number, -4)
                : '';
            $params['place'] = ($s['place'] ?? '') !== ''
                ? ($lang === 'wo' ? ', réer ca ' : ', perdue à ') . $s['place']
                : '';
            $this->say('confirm_loss', $params);
        } else {
            $params['place'] = ($s['place'] ?? '') !== '' ? $s['place'] : '—';
            $params['consent'] = ($s['consent'] ?? false)
                ? ($lang === 'wo' ? 'waaw' : 'oui')
                : ($lang === 'wo' ? 'déedéet' : 'non');
            $this->say('confirm_found', $params);
        }
    }

    private function applyFix(string $text): bool
    {
        $norm = TextNormalizer::normalize($text);
        $has = fn (array $words) => (bool) array_filter($words, fn ($w) => preg_match('/(^|\s)' . $w . '(\s|$)/', $norm));

        if ($type = $this->lexicon->documentType($text)) {
            $this->setDocType($type);
            $this->next();

            return true;
        }

        $slots = $this->conv->slots ?? [];
        if ($has(['piece', 'type', 'kayit', 'document'])) {
            unset($slots['doc_type_id'], $slots['doc_type_name']);
            $this->conv->slots = $slots;
            $this->ask('doc_type');
        } elseif ($has(['nom', 'prenom', 'tur', 'sant', 'name'])) {
            unset($slots['first_name'], $slots['last_name']);
            $this->conv->slots = $slots;
            $this->ask('first_name');
        } elseif ($has(['numero', 'nimero', 'number', 'num'])) {
            unset($slots['doc_number']);
            $this->conv->slots = $slots;
            $this->ask('doc_number');
        } elseif ($has(['lieu', 'endroit', 'place', 'berab', 'adresse', 'quartier'])) {
            unset($slots['place'], $slots['territory_id']);
            $this->conv->slots = $slots;
            $this->ask('place');
        } else {
            return false;
        }

        return true;
    }

    // ── Déclaration de perte ─────────────────────────────────────────

    private function startLoss(?DocumentType $type): void
    {
        if (! $this->user) {
            $this->say('login_required');
            $this->reply->card = ['type' => 'login_required', 'data' => ['then' => 'declare_loss']];
            $this->mainButtons();

            return;
        }
        $this->conv->startFlow('declare_loss');
        if ($type) {
            $this->setDocType($type);
        }
        $this->next();
    }

    private function executeLoss(): void
    {
        $s = $this->conv->slots ?? [];
        $type = DocumentType::find($s['doc_type_id'] ?? null);

        if (! $this->user || ! $type) {
            $this->conv->startFlow(null);
            $this->say($this->user ? 'loss_failed' : 'login_required');

            return;
        }

        try {
            [$declaration] = $this->declarations->create($this->user, [
                'Title'             => 'Perte de ' . $type->TypeName,
                'FirstNameInDoc'    => $s['first_name'],
                'LastNameInDoc'     => $s['last_name'],
                'DocIdentification' => ($s['doc_number'] ?? '') !== '' ? $s['doc_number'] : null,
                'document_type_id'  => $type->id,
                'lost_place'        => ($s['place'] ?? '') !== '' ? $s['place'] : null,
            ], ['via' => 'assistant', 'assistant_conversation' => $this->conv->uuid]);

            if (! empty($s['territory_id'])) {
                $declaration->forceFill(['territory_id' => $s['territory_id']])->save();
            }
        } catch (Throwable $e) {
            Log::error('Assistant : déclaration impossible — ' . $e->getMessage());
            $this->say('loss_failed');
            $this->conv->step = 'confirm';
            $this->yesNoButtons();

            return;
        }

        $this->conv->startFlow(null);
        $this->conv->status = AssistantConversation::STATUS_COMPLETED;
        $this->say('loss_created', ['reference' => $declaration->reference]);
        $this->reply->card = ['type' => 'declaration_created', 'data' => ['uuid' => $declaration->uuid, 'reference' => $declaration->reference]];
        $this->reply->meta['declaration_id'] = $declaration->id;
        $this->reply->intent = 'declare_loss';
        $this->reply->buttons([
            $this->phrases->button('track_status', $this->lang()),
            $this->phrases->button('what_to_bring', $this->lang()),
        ]);
    }

    // ── Document trouvé ──────────────────────────────────────────────

    private function startFound(?DocumentType $type): void
    {
        $this->conv->startFlow('report_found');
        if ($type) {
            $this->setDocType($type);
        }
        $this->next();
    }

    /**
     * La publication exige une photo : l'assistant ne crée rien lui-même, il
     * ouvre le formulaire de publication déjà rempli (le trouveur ajoute la photo).
     */
    private function executeFound(): void
    {
        $s = $this->conv->slots ?? [];
        $territory = ! empty($s['territory_id']) ? Territory::find($s['territory_id']) : null;

        $this->reply->card = [
            'type' => 'open_form',
            'data' => [
                'form'    => 'found_document',
                'prefill' => [
                    'document_type_id' => $s['doc_type_id'] ?? null,
                    'OwnerFirstName'   => $s['first_name'] ?? null,
                    'OwnerLastName'    => $s['last_name'] ?? null,
                    'Location'         => $s['place'] ?? null,
                    'territory_code'   => $territory?->code,
                    'latitude'         => $this->ctx['latitude'] ?? null,
                    'longitude'        => $this->ctx['longitude'] ?? null,
                    'contact_consent'  => (bool) ($s['consent'] ?? false),
                ],
                'login_required' => $this->user === null,
            ],
        ];
        $this->conv->startFlow(null);
        $this->conv->status = AssistantConversation::STATUS_COMPLETED;
        $this->reply->intent = 'report_found';
        $this->say('found_next_step');
    }

    // ── Suivi et orientation ─────────────────────────────────────────

    private function trackStatus(): void
    {
        if (! $this->user) {
            $this->say('login_required');
            $this->reply->card = ['type' => 'login_required', 'data' => ['then' => 'track_status']];

            return;
        }

        $declarations = DeclarationDePerte::with('documentType')
            ->where('user_id', $this->user->id)
            ->latest('id')->take(3)->get();

        if ($declarations->isEmpty()) {
            $this->say('status_none');
            $this->reply->buttons([$this->phrases->button('declare_loss', $this->lang()), $this->phrases->button('report_found', $this->lang())]);

            return;
        }

        $this->say('status_intro');
        foreach ($declarations as $d) {
            $label = config("assistant.statuses.{$d->status}.{$this->lang()}", $d->status);
            $this->reply->add($this->phrases->say('status_line', $this->lang(), [
                'reference'     => $d->reference,
                'document_type' => $d->documentType?->TypeName ?? '—',
                'name'          => trim($d->FirstNameInDoc . ' ' . $d->LastNameInDoc),
                'status'        => $label,
            ]));
        }
        $this->reply->buttons([$this->phrases->button('where_to_go', $this->lang()), $this->phrases->button('what_to_bring', $this->lang())]);
    }

    private function whereToGo(): void
    {
        if ($this->user) {
            $declarationIds = DeclarationDePerte::where('user_id', $this->user->id)->pluck('id');
            $match = DocumentMatch::with(['document.custodyOrganization', 'declaration'])
                ->whereIn('declaration_de_perte_id', $declarationIds)
                ->where('status', DocumentMatch::STATUS_CONFIRMED)
                ->whereHas('document', fn ($q) => $q->whereNotIn('lifecycle_status', ['restituted', 'archived', 'destroyed']))
                ->latest('id')->first();

            if ($match && $match->document) {
                $typeName = DocumentType::find($match->document->document_type_id)?->TypeName ?? '';
                if ($structure = $match->document->custodyOrganization) {
                    $this->say('where_custody', $this->structureParams($structure) + [
                        'document_type' => $typeName,
                        'reference'     => $match->declaration?->reference ?? '',
                    ]);
                    $this->reply->card = ['type' => 'structure', 'data' => $this->structureCard($structure)];
                    $this->reply->buttons([$this->phrases->button('what_to_bring', $this->lang())]);

                    return;
                }
                $this->say('where_with_finder', ['document_type' => $typeName]);

                return;
            }

            if ($declarationIds->isNotEmpty()) {
                $this->askLocation('where_not_found_yet');

                return;
            }
        }

        $this->askLocation('ask_location');
    }

    private function askLocation(string $phrase): void
    {
        if (isset($this->ctx['latitude'], $this->ctx['longitude'])) {
            $this->answerNearest($this->structures->find((float) $this->ctx['latitude'], (float) $this->ctx['longitude'], null));

            return;
        }
        $this->conv->startFlow('nearest', 'location');
        $this->say($phrase);
    }

    private function nearestFromText(string $text): void
    {
        $territory = $this->resolveTerritory($text);
        if (! $territory) {
            $this->say('location_unknown');

            return;
        }
        $this->conv->startFlow(null);
        $this->answerNearest($this->structures->find(null, null, $territory->id));
    }

    private function answerNearest(?array $found): void
    {
        $this->conv->startFlow(null);
        $structure = $found['organization'] ?? null;
        if (! $structure instanceof Organization) {
            $this->say('no_structure');

            return;
        }
        $this->say('nearest_structure', $this->structureParams($structure));
        $this->reply->card = ['type' => 'structure', 'data' => $this->structureCard($structure) + ['distance_km' => $found['distance_km'] ?? null]];
        $this->reply->buttons([$this->phrases->button('what_to_bring', $this->lang())]);
    }

    private function info(string $phrase): void
    {
        $this->say($phrase);
        $this->mainButtons();
    }

    private function handoff(): void
    {
        if (! $this->user) {
            $this->say('handoff_guest');
            $this->reply->card = ['type' => 'login_required', 'data' => ['then' => 'human']];

            return;
        }

        $lastQuestion = $this->conv->messages()->where('direction', 'in')->latest('id')->value('text');
        $this->notifier->inboxSuperAdmins('assistant.handoff', [
            'name'         => trim($this->user->FirstName . ' ' . $this->user->LastName) ?: 'Citoyen',
            'phone'        => $this->user->Phone ?: '—',
            'language'     => $this->lang() === 'wo' ? 'wolof' : 'français',
            'conversation' => $this->conv->uuid,
            'question'     => mb_substr((string) $lastQuestion, 0, 200),
        ], ['dedupe' => 'assistant-handoff:' . $this->conv->uuid . ':' . now()->format('YmdH')]);

        $this->conv->startFlow(null);
        $this->conv->status = AssistantConversation::STATUS_HANDED_OFF;
        $this->say('handoff_done');
    }

    // ── Outils ───────────────────────────────────────────────────────

    private function welcome(): void
    {
        $this->mainMenu('welcome');
    }

    private function mainMenu(string $phrase): void
    {
        $this->say($phrase);
        $this->mainButtons();
    }

    private function mainButtons(): void
    {
        $this->reply->buttons(array_map(
            fn ($b) => $this->phrases->button($b, $this->lang()),
            ['declare_loss', 'report_found', 'track_status', 'where_to_go', 'what_to_bring']
        ));
    }

    private function docTypeButtons(): void
    {
        $this->reply->buttons(array_map(
            fn ($name) => ['label' => $name, 'value' => $name],
            config('assistant.document_type_buttons', [])
        ));
    }

    private function yesNoButtons(): void
    {
        $this->reply->buttons([$this->phrases->button('yes', $this->lang()), $this->phrases->button('no', $this->lang())]);
    }

    private function setDocType(DocumentType $type): void
    {
        $this->conv->setSlot('doc_type_id', $type->id);
        $this->conv->setSlot('doc_type_name', $type->TypeName);
    }

    private function say(string $key, array $params = []): void
    {
        $this->reply->add($this->phrases->say($key, $this->lang(), $params));
    }

    private function lang(): string
    {
        return in_array($this->conv->language, config('assistant.languages', ['fr', 'wo']), true) ? $this->conv->language : 'fr';
    }

    private function structureParams(Organization $o): array
    {
        return [
            'structure' => $o->name,
            'address'   => $o->address ? ' (' . $o->address . ')' : '',
            'phone'     => $o->phone ? ($this->lang() === 'wo' ? ', tel. ' : ', tél. ') . $o->phone : '',
        ];
    }

    private function structureCard(Organization $o): array
    {
        return [
            'uuid'      => $o->uuid,
            'name'      => $o->name,
            'address'   => $o->address,
            'phone'     => $o->phone,
            'latitude'  => $o->latitude,
            'longitude' => $o->longitude,
        ];
    }

    /** Département (de préférence) ou région cité dans la phrase. */
    private function resolveTerritory(string $text): ?Territory
    {
        $norm = ' ' . TextNormalizer::normalize($text) . ' ';
        $best = null;
        foreach (Territory::whereIn('level', [Territory::LEVEL_DEPARTMENT, Territory::LEVEL_REGION])->get() as $t) {
            $name = TextNormalizer::normalize($t->name);
            if ($name === '' || ! str_contains($norm, ' ' . $name . ' ')) {
                continue;
            }
            $rank = [$t->level === Territory::LEVEL_DEPARTMENT ? 1 : 0, strlen($name)];
            if (! $best || $rank > $best[0]) {
                $best = [$rank, $t];
            }
        }

        return $best[1] ?? null;
    }

    private function finish(): AssistantReply
    {
        $this->conv->last_activity_at = now();
        if ($this->conv->flow && $this->conv->status !== AssistantConversation::STATUS_ACTIVE) {
            $this->conv->status = AssistantConversation::STATUS_ACTIVE;
        }
        $this->conv->save();

        return $this->reply;
    }
}
