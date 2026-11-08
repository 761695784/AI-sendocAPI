<?php

/*
|--------------------------------------------------------------------------
| Fournisseurs d'IA (facultatifs, remplaçables)
|--------------------------------------------------------------------------
| DocTrack fonctionne SANS eux (assistant guidé par règles, saisie texte).
| Chaque « prise » se branche par le .env, sans changer le code :
|
|  - stt : voix → texte. « none » ou « hf_space » (notre service Python
|          doctrack-voice hébergé gratuitement sur Hugging Face).
|  - tts : texte → voix wolof. « none » ou « hf_space » (service Python
|          doctrack-tts, modèle Adia_TTS, sur Hugging Face).
|  - ocr : lecture des pièces photographiées. « none » ou « hf_space »
|          (service Python doctrack-ocr, RapidOCR, sur Hugging Face).
|  - llm : aide à comprendre les phrases libres que les règles ne
|          reconnaissent pas. « none », « gemini » ou « openai_compatible »
|          (Groq, OpenRouter, Ollama en local…).
|
| Confidentialité : le modèle de langage ne reçoit que la phrase à classer,
| chiffres masqués. Pas de données réelles de citoyens sur une formule
| gratuite (elle peut servir à entraîner le modèle du fournisseur).
*/

return [

    'stt' => [
        'provider' => env('AI_STT_PROVIDER', 'none'),

        'hf_space' => [
            // Ex. https://majeli-doctrack-voice.hf.space
            'url'     => env('AI_STT_URL'),
            // Jeton Hugging Face si le Space est privé.
            'token'   => env('AI_STT_TOKEN'),
            // Le premier appel réveille le Space gratuit : prévoir large.
            'timeout' => (int) env('AI_STT_TIMEOUT', 90),
        ],

        // Codes de langue attendus par le service (Omnilingual ASR).
        'languages' => ['fr' => 'fra_Latn', 'wo' => 'wol_Latn'],

        'max_upload_kb' => 5120,
        'mimes'         => ['webm', 'ogg', 'oga', 'opus', 'mp3', 'mpga', 'wav', 'm4a', 'mp4', 'aac', 'weba'],
    ],

    /*
    | Texte → voix (réponses parlées de l'assistant en wolof) : « none » ou
    | « hf_space » (service Python doctrack-tts : Adia_TTS de CONCREE, Apache 2.0,
    | réseau entraîné sur 40 h de voix wolof). Trop lent pour parler en direct sur
    | un processeur gratuit : les phrases fixes sont préparées une fois
    | (php artisan doctrack:assistant-voices) puis servies comme des fichiers.
    | Le français n'est pas lu par ce modèle.
    */
    'tts' => [
        'provider' => env('AI_TTS_PROVIDER', 'none'),

        'hf_space' => [
            // Ex. https://majeli-doctrack-tts.hf.space
            'url'     => env('AI_TTS_URL'),
            'token'   => env('AI_TTS_TOKEN'),
            // Réveil du Space + 30 s à 2 min de calcul par phrase sur processeur gratuit.
            'timeout' => (int) env('AI_TTS_TIMEOUT', 300),
        ],

        'languages' => ['wo'],
        // Limite du service (le texte est découpé en segments de 200 caractères côté Python).
        'max_chars' => 600,
    ],

    // Lecture des pièces (OCR) : « none » ou « hf_space » (service doctrack-ocr, RapidOCR).
    'ocr' => [
        'provider' => env('AI_OCR_PROVIDER', 'none'),

        'hf_space' => [
            'url'     => env('AI_OCR_URL'),
            'token'   => env('AI_OCR_TOKEN'),
            'timeout' => (int) env('AI_OCR_TIMEOUT', 90),
        ],

        'max_upload_kb'     => 8192,
        // Durée de vie d'un scan non publié (l'image est ensuite supprimée).
        'scan_ttl_minutes'  => (int) env('AI_OCR_SCAN_TTL', 60),
        // Photo publiée SANS passer par le scan : lire quand même pour masquer le numéro.
        'mask_on_upload'    => (bool) env('AI_OCR_MASK_ON_UPLOAD', false),
    ],

    /*
    | Rédaction assistée des rapports d'activité (synthèse, commentaires par
    | structure, recommandations, résumé anglais). Ne reçoit QUE des chiffres
    | agrégés ; chaque chiffre du texte est vérifié ; validation par le super
    | admin avant envoi (par défaut). Plusieurs fournisseurs peuvent être
    | enchaînés : si le premier échoue, le suivant prend le relais, puis
    | l'analyse par règles.
    | Par défaut : Groq (gpt-oss-120b), puis le second modèle Groq (gpt-oss-20b,
    | quota par minute séparé). Vérification : php artisan doctrack:report-ai-check
    */
    'reports' => [
        'enabled'            => (bool) env('AI_REPORTS_ENABLED', false),
        'providers'          => array_filter(array_map('trim', explode(',', (string) env('AI_REPORTS_PROVIDERS', 'groq,groq_fast')))),
        'require_validation' => (bool) env('AI_REPORTS_REQUIRE_VALIDATION', true),
        // Effectifs plus petits masqués (« <3 ») avant l'envoi.
        'min_count'          => 3,
        'timeout'            => (int) env('AI_REPORTS_TIMEOUT', 90),
        'temperature'        => 0.2,
        // gpt-oss « réfléchit » avant d'écrire : ces jetons comptent dans le plafond.
        'max_tokens'         => (int) env('AI_REPORTS_MAX_TOKENS', 3500),
        // Quota par minute atteint : le rapport mensuel (tâche planifiée, commande) n'est pas pressé, il peut attendre
        // plus longtemps que l'assistant. Depuis l'écran (bouton « Régénérer »), l'attente reste celle de retry_after_max.
        'retry_after_max'    => (float) env('AI_REPORTS_RETRY_AFTER_MAX', 30),
        // Nouvelles tentatives si le texte contient un chiffre absent des données.
        'max_attempts'       => 2,
    ],

    // Attente maximale (secondes) quand un fournisseur demande de patienter (erreur 429).
    'retry_after_max' => (float) env('AI_RETRY_AFTER_MAX', 5),

    // Points d'accès compatibles OpenAI (chat/completions).
    'endpoints' => [
        // Alibaba Model Studio, région Singapour. Le quota gratuit des nouveaux comptes a été retiré sur la plupart
        // des modèles le 29/10/2026 (les comptes existants gardent leur reste) : désormais payant.
        'qwen' => [
            'url'   => env('QWEN_API_URL', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1'),
            'key'   => env('QWEN_API_KEY'),
            'model' => env('QWEN_MODEL', 'qwen-plus'),
        ],
        // Z.ai (Zhipu) : GLM-4.7-Flash gratuit.
        'glm' => [
            'url'   => env('GLM_API_URL', 'https://api.z.ai/api/paas/v4'),
            'key'   => env('GLM_API_KEY'),
            'model' => env('GLM_MODEL', 'glm-4.7-flash'),
        ],
        'deepseek' => [
            'url'   => env('DEEPSEEK_API_URL', 'https://api.deepseek.com'),
            'key'   => env('DEEPSEEK_API_KEY'),
            'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        ],
        // Groq : formule gratuite sans carte bancaire (limites par minute et par jour). Recommandé pour l'assistant.
        // Modèles accessibles avec votre clé : php artisan doctrack:assistant-ai-check (liste affichée en cas d'erreur 404).
        // gpt-oss « réfléchit » avant de répondre : effort bas = plus rapide et moins de jetons consommés.
        'groq' => [
            'url'     => env('GROQ_API_URL', 'https://api.groq.com/openai/v1'),
            'key'     => env('GROQ_API_KEY'),
            'model'   => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
            'options' => str_contains((string) env('GROQ_MODEL', 'openai/gpt-oss-120b'), 'gpt-oss')
                ? ['reasoning_effort' => env('GROQ_REASONING_EFFORT', 'low')] : [],
        ],
        // Second modèle Groq, plus léger : son quota par minute est séparé de celui du premier.
        // Placé après « groq » dans ASSISTANT_AI_PROVIDERS, il prend le relais quand le premier est saturé.
        'groq_fast' => [
            'url'     => env('GROQ_API_URL', 'https://api.groq.com/openai/v1'),
            'key'     => env('GROQ_API_KEY'),
            'model'   => env('GROQ_FAST_MODEL', 'openai/gpt-oss-20b'),
            'options' => str_contains((string) env('GROQ_FAST_MODEL', 'openai/gpt-oss-20b'), 'gpt-oss')
                ? ['reasoning_effort' => env('GROQ_REASONING_EFFORT', 'low')] : [],
        ],
        // Google AI Studio (Gemini) par son accès compatible OpenAI. Formule gratuite : les questions
        // peuvent servir à entraîner les modèles de Google (hors Europe) — jamais de données personnelles.
        'gemini' => [
            'url'   => env('GEMINI_OPENAI_URL', 'https://generativelanguage.googleapis.com/v1beta/openai'),
            'key'   => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_CHAT_MODEL', 'gemini-2.5-flash-lite'),
        ],
        'openrouter' => [
            'url'   => env('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1'),
            'key'   => env('OPENROUTER_API_KEY'),
            'model' => env('OPENROUTER_MODEL', 'qwen/qwen3-235b-a22b:free'),
        ],
        // Plus tard : un modèle ouvert hébergé au Sénégal (vLLM / Ollama).
        'local' => [
            'url'   => env('LOCAL_LLM_URL'),
            'key'   => env('LOCAL_LLM_KEY'),
            'model' => env('LOCAL_LLM_MODEL'),
        ],
    ],

    'llm' => [
        'provider' => env('AI_LLM_PROVIDER', 'none'),
        'timeout'  => (int) env('AI_LLM_TIMEOUT', 15),

        'gemini' => [
            'key'   => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'url'   => 'https://generativelanguage.googleapis.com/v1beta/models',
        ],

        'openai_compatible' => [
            // Groq : https://api.groq.com/openai/v1 — Ollama : http://localhost:11434/v1
            'url'   => env('AI_LLM_URL'),
            'key'   => env('AI_LLM_KEY'),
            'model' => env('AI_LLM_MODEL'),
        ],
    ],
];
