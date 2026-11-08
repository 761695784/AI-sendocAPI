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
use App\Support\SenegalGazetteer;
use Illuminate\Support\Collection;
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
    /** Valeur envoyée par le bouton « Partager ma position ». */
    public const POSITION = '__position__';

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
        private FaqResponder $faq,
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

        // Bouton « Partager ma position » (coordonnées envoyées par le navigateur).
        if ($text === self::POSITION) {
            $this->nearestFromText($text);

            return $this->finish();
        }

        // Au milieu d'un parcours : « non, je n'ai rien trouvé… » ou une question d'aide ne sont pas des réponses à l'étape.
        if ($conv->flow && $conv->step && $this->interruptsFlow($text)) {
            return $this->finish();
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
        // « Pourquoi Dieuppeul ? », « il y en a d'autres ? » juste après l'indication d'une structure.
        if (($last = $this->conv->slot('last_nearest')) && $this->asksAboutStructure($text, $last)) {
            $this->explainNearest($last);

            return;
        }

        $detected = $this->lexicon->detectIntent($text);
        $intent   = $detected['score'] > 0 ? $detected['intent'] : null;
        $docType  = $this->lexicon->documentType($text);

        // « Comment savoir que MON document est trouvé ? » : c'est le propriétaire qui parle, pas un trouveur.
        $ownerAsks = $intent === 'report_found' && self::ownerAsksAboutFound($text);
        if ($ownerAsks) {
            $intent = null;
            $detected['score'] = 0;
        }

        // Question générale proche d'une fiche d'aide : réponse de la fiche (« est-ce payant ? », « récépissé ou certificat ? »).
        // Une vraie question l'emporte sur un mot-clé d'action (« que faire si le trouveur demande de l'argent ? »).
        $faq = $this->faq->answerFromFaq($text);
        // Les parcours « déclarer » et « publier » cèdent la place à une question proche d'une fiche ;
        // « où aller », « suivi » et « que faut-il apporter » seulement si la fiche est plus proche que le mot-clé.
        $question    = self::looksLikeQuestion($text);
        $overridable = in_array($intent, ['declare_loss', 'report_found'], true);
        $softIntent  = in_array($intent, ['where_to_go', 'track_status', 'what_to_bring'], true);
        if ($faq && (! $intent
            || ($overridable && (($question && $faq['score'] >= $detected['score']) || $faq['score'] >= $detected['score'] + 2))
            || ($softIntent && $question && $faq['score'] > $detected['score'])
        )) {
            $this->answerFaq($faq);

            return;
        }

        // Propriétaire qui parle d'un document trouvé, sans fiche précise : l'IA, sinon la fiche « être prévenu ».
        if ($ownerAsks) {
            $faq = $this->faq->answerWithAi($text, $this->conv->uuid, $this->ctx['ip'] ?? null)
                ?? $this->faq->entry('rechercher');
            if ($faq) {
                $this->answerFaq($faq);

                return;
            }
        }

        if (! $intent && ! $docType) {
            $suggestion = $this->classifier->classify($text, self::MAIN_INTENTS, DocumentType::pluck('TypeName')->all());
            $intent  = $suggestion['intent'] ?? null;
            $docType = ! empty($suggestion['document_type']) ? DocumentType::where('TypeName', $suggestion['document_type'])->first() : null;
            if ($suggestion) {
                $this->reply->meta['classifier'] = $suggestion;
            }
        }

        // Vraie question à peine reliée à une action (un seul mot-clé, ou seulement un nom de pièce) :
        // on tente d'abord une réponse d'aide (« comment refaire ma carte d'identité ? »).
        $weakAction = ($intent && ! $docType && $detected['score'] <= 1
                && in_array($intent, ['declare_loss', 'report_found', 'where_to_go', 'track_status'], true))
            || (! $intent && $docType);
        if ($question && $weakAction
            && ($faq = $this->faq->answerWithAi($text, $this->conv->uuid, $this->ctx['ip'] ?? null))) {
            $this->answerFaq($faq);

            return;
        }

        // « Ma CNI » seul : on suppose une perte (cas le plus fréquent), on récapitulera.
        if (! $intent && $docType) {
            $intent = 'declare_loss';
        }

        if (! $intent) {
            // Dernier recours : réponse rédigée par l'IA à partir de la base de connaissances (si un fournisseur est configuré).
            if ($faq = $this->faq->answerWithAi($text, $this->conv->uuid, $this->ctx['ip'] ?? null)) {
                $this->answerFaq($faq);

                return;
            }
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
            $found = $this->structures->find((float) $this->ctx['latitude'], (float) $this->ctx['longitude'], null);
            $this->answerNearest($found, $found['reason'] ?? 'gps');

            return;
        }
        $this->conv->startFlow('nearest', 'location');
        $this->say($phrase);
        $this->reply->buttons([$this->positionButton()]);
    }

    /**
     * Lieu donné en texte :
     *  - quartier connu et structure de ce quartier → cette structure ;
     *  - un seul choix dans le secteur → cette structure, en disant qu'elle n'est pas forcément la plus proche ;
     *  - plusieurs structures dans le secteur → on les liste et on demande le quartier (ou la position).
     */
    private function nearestFromText(string $text): void
    {
        if ($text === self::POSITION) {
            if (isset($this->ctx['latitude'], $this->ctx['longitude'])) {
                $found = $this->structures->find((float) $this->ctx['latitude'], (float) $this->ctx['longitude'], null);
                $this->answerNearest($found, $found['reason'] ?? 'gps');

                return;
            }
            $this->conv->startFlow('nearest', 'location');
            $this->say('position_unavailable');
            $this->reply->buttons([$this->positionButton()]);

            return;
        }

        $quarter   = self::quarterIn($text);
        $territory = $this->resolveTerritory($text);
        if (! $territory && $quarter) {
            $territory = Territory::where('level', Territory::LEVEL_DEPARTMENT)->where('name', $quarter['department'])->first();
        }
        if (! $territory) {
            $this->say('location_unknown');
            $this->reply->buttons([$this->positionButton()]);

            return;
        }

        $orgs = $this->structures->inArea($territory->id)['organizations'];
        if ($quarter) {
            $inQuarter = $orgs->first(fn (Organization $o) => str_contains(
                ' ' . TextNormalizer::normalize($o->name . ' ' . $o->address) . ' ',
                ' ' . $quarter['term'] . ' '
            ));
            if ($inQuarter) {
                $this->answerNearest(['organization' => $inQuarter, 'distance_km' => null], 'quarter', $quarter['label'], $orgs);

                return;
            }
        }

        if ($orgs->count() > 1) {
            $this->conv->startFlow('nearest', 'location');
            $this->say('several_structures', ['area' => $territory->name, 'list' => self::listNames($orgs)]);
            $this->reply->buttons([$this->positionButton()]);

            return;
        }

        $this->answerNearest($orgs->isNotEmpty() ? ['organization' => $orgs->first(), 'distance_km' => null] : null, 'area', $territory->name, $orgs);
    }

    private function answerNearest(?array $found, string $basis = 'gps', ?string $area = null, ?Collection $areaOrgs = null): void
    {
        $this->conv->startFlow(null);
        $structure = $found['organization'] ?? null;
        if (! $structure instanceof Organization) {
            $this->say('no_structure');

            return;
        }
        $this->say('nearest_structure', $this->structureParams($structure));
        $this->reply->card = ['type' => 'structure', 'data' => $this->structureCard($structure) + ['distance_km' => $found['distance_km'] ?? null]];

        // Gardé pour répondre à « pourquoi celle-ci ? » ou « il y en a d'autres ? ».
        $others = ($areaOrgs ?? collect())->reject(fn ($o) => $o->id === $structure->id)->values();
        $this->conv->setSlot('last_nearest', [
            'name'   => $structure->name,
            'basis'  => in_array($basis, ['gps', 'quarter'], true) ? $basis : 'area',
            'area'   => $area,
            'km'     => $found['distance_km'] ?? null,
            'others' => $others->take(5)->pluck('name')->all(),
        ]);

        $buttons = [$this->phrases->button('what_to_bring', $this->lang())];
        if ($basis !== 'gps' && $basis !== 'quarter') {
            $buttons[] = $this->positionButton();
        }
        $this->reply->buttons($buttons);
    }

    /** Question sur la structure qui vient d'être indiquée. */
    private function asksAboutStructure(string $text, array $last): bool
    {
        $norm  = TextNormalizer::normalize($text);
        $words = explode(' ', $norm);
        if (preg_match('/\b(d autres|autre structure|autres structures|une autre|plus pres|plus proche|c est loin|trop loin|la plus proche)\b/', $norm)) {
            return true;
        }
        if (! preg_match('/^(pourquoi|pk|pq|pkoi|pourkoi|comment ca)\b/', $norm)) {
            return false;
        }
        $nameWords = array_filter(explode(' ', TextNormalizer::normalize($last['name'] ?? '')), fn ($w) => strlen($w) >= 4);

        return count($words) <= 4
            || array_intersect($words, $nameWords)
            || preg_match('/\b(structure|commissariat|brigade|celle ci|celle la|celui ci|ce choix)\b/', $norm);
    }

    private function explainNearest(array $last): void
    {
        $this->conv->misunderstood = 0;
        $this->reply->intent = 'where_to_go';
        $params = ['structure' => $last['name'], 'area' => (string) ($last['area'] ?? '')];

        if ($last['basis'] === 'gps' && $last['km'] !== null) {
            $this->say('why_gps', $params + ['km' => number_format((float) $last['km'], 1, ',', '')]);
        } elseif ($last['basis'] === 'quarter') {
            $this->say('why_quarter', $params);
        } else {
            $this->say('why_area', $params);
        }
        if (! empty($last['others'])) {
            $this->say('other_structures', ['list' => self::listNames(collect($last['others']))]);
        }

        // On attend un quartier ou la position pour préciser.
        $this->conv->startFlow('nearest', 'location');
        $this->reply->buttons([$this->positionButton(), $this->phrases->button('what_to_bring', $this->lang())]);
    }

    private function positionButton(): array
    {
        return $this->phrases->button('share_position', $this->lang(), self::POSITION);
    }

    /** Quartier ou localité connue citée dans la phrase (le terme le plus long gagne). */
    private static function quarterIn(string $text): ?array
    {
        $norm = ' ' . TextNormalizer::normalize($text) . ' ';
        $best = null;
        foreach (SenegalGazetteer::PLACES as $place => $department) {
            $term = TextNormalizer::normalize($place);
            if ($term !== '' && str_contains($norm, ' ' . $term . ' ') && (! $best || strlen($term) > strlen($best['term']))) {
                $best = ['term' => $term, 'department' => $department, 'label' => mb_convert_case($place, MB_CASE_TITLE)];
            }
        }

        return $best;
    }

    /** « A, B et C » (5 noms au plus). */
    private static function listNames(Collection $items): string
    {
        $names = $items->map(fn ($o) => $o instanceof Organization ? $o->name : (string) $o)->values();
        $shown = $names->take(5)->all();
        $last  = count($shown) > 1 ? array_pop($shown) : null;
        $list  = implode(', ', $shown) . ($last ? ' et ' . $last : '');

        return $names->count() > 5 ? $list . ' (et ' . ($names->count() - 5) . ' autres)' : $list;
    }

    /**
     * Message reçu pendant un parcours qui n'est pas une réponse à l'étape :
     *  - le citoyen dément le parcours (« non, je n'ai rien trouvé ») → parcours annulé, message traité normalement ;
     *  - il pose une question d'aide → réponse, puis la question de l'étape est reposée (le parcours continue).
     */
    private function interruptsFlow(string $text): bool
    {
        if (in_array($this->conv->step, ['fix', 'confirm'], true) && ! self::looksLikeQuestion($text)) {
            return false;
        }
        $norm  = TextNormalizer::normalize($text);
        $words = count(explode(' ', $norm));

        $denies = match ($this->conv->flow) {
            'report_found' => (bool) preg_match('/\b(n ?(ai|a)|j ai) (pas|rien|jamais|aucun\w*) (\w+ )?(trouve|ramasse)/', $norm),
            'declare_loss' => (bool) preg_match('/\b(n ?(ai|a)|j ai) (pas|rien|jamais|aucun\w*) (\w+ )?(perdu|egare)/', $norm),
            default        => false,
        };
        if ($denies) {
            $this->conv->startFlow(null);
            $this->handleTopLevel($text);

            return true;
        }

        if ($words < 4 || ! self::looksLikeQuestion($text)) {
            return false;
        }
        $faq = $this->faq->answerFromFaq($text)
            ?? $this->faq->answerWithAi($text, $this->conv->uuid, $this->ctx['ip'] ?? null);
        if (! $faq) {
            return false;
        }
        $this->answerFaq($faq, false);
        $this->reask();

        return true;
    }

    /** Le propriétaire demande comment savoir si SON document est trouvé (pas un trouveur qui signale). */
    private static function ownerAsksAboutFound(string $text): bool
    {
        $norm = TextNormalizer::normalize($text);

        return (bool) preg_match('/\b(quelqu un|quelqu une|une personne|on|il|elle) (a |m a )?(re)?trouve (mon|ma|mes)\b/', $norm)
            || (bool) preg_match('/\b(mon|ma|mes) (document|carte|piece|passeport|permis|cni|papier)\w*( \w+){0,3} (est|soit|sera|a ete|ont ete|sont|serait) (re)?trouve/', $norm)
            || (bool) preg_match('/\b(si|quand|lorsque) (on|quelqu un|quelqu une|une personne) (le |la |les )?(re)?trouve/', $norm)
            || (bool) preg_match('/\b(n ?(ai|a)|j ai) (pas|rien|jamais) (\w+ )?trouve/', $norm);
    }

    /** Réponse d'aide (fiche, ou IA à partir de la base de connaissances) + liens utiles + menu. */
    private function answerFaq(array $faq, bool $menu = true): void
    {
        $this->conv->misunderstood = 0;
        $this->reply->intent = 'faq';
        $ids = array_column($faq['entries'], 'id');
        $this->reply->add($this->phrases->raw($faq['answer'], 'faq:' . $ids[0]));
        if ($faq['source'] === 'ai') {
            $this->reply->add($this->phrases->raw('Réponse rédigée par l\'IA à partir de l\'aide DocTrack : vérifiez les informations importantes.', 'faq_ai_notice'));
        }

        $links = [];
        foreach ($faq['entries'] as $entry) {
            foreach ($entry['links'] ?? [] as $link) {
                $links[$link['url']] = $link;
            }
        }
        if ($links) {
            $this->reply->card = ['type' => 'links', 'data' => ['links' => array_values($links)]];
        }
        $this->reply->meta['faq'] = array_filter(['source' => $faq['source'], 'entries' => $ids, 'provider' => $faq['provider'] ?? null]);
        if ($menu) {
            $this->mainButtons();
        }
    }

    private static function looksLikeQuestion(string $text): bool
    {
        $norm = TextNormalizer::normalize($text);

        // Début de phrase sans les petits mots d'amorce (« non, … », « bonjour, … », « en fait … »).
        $start = preg_replace('/^((non|oui|ok|d accord|bonjour|salut|svp|mais|euh|en fait|alors|donc|merci) )+/', '', $norm);

        return str_contains($text, '?')
            || (bool) preg_match('/^(comment|pourquoi|combien|quand|ou |est ce|qu est|quel|quelle|quels|quelles|puis je|peut on|peux je|faut il|que faire|a quoi|c est quoi|qui |dois je|est il|y a t il)/', $start)
            || (bool) preg_match('/\b(comment (je|on|savoir|faire)|est ce que|je (veux|voudrais) savoir|j aimerais savoir|c est quoi|qu est ce que)\b/', $norm);
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
