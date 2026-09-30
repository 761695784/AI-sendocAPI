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
