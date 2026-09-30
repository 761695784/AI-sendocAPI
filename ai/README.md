# DocTrack — Intelligence artificielle (Phase 8)

Ce dossier regroupe tout ce qui tourne **hors de Laravel** pour l'IA de DocTrack.
Tout est **gratuit** au départ ; chaque brique peut être remplacée plus tard sans toucher au code (réglages dans `.env`).

```
Citoyen (texte ou vocal, français / wolof)
   │
   ▼
API Laravel ─ /api/v2/assistant/*        ← assistant guidé (règles + mots-clés FR/WO)
   │   │                                   récapitule et demande « oui » avant d'agir
   │   ├─ voix → texte : Hugging Face Space « doctrack-voice » (Omnilingual ASR, Meta)
   │   ├─ secours pour phrases libres : modèle de langage gratuit (facultatif)
   │   └─ voix wolof des réponses : MP3 générés à l'avance sur Colab (xTTS-v2-wolof)
   ▼
Services DocTrack existants (déclaration, structure la plus proche, suivi…)
```

| Dossier | Contenu | Où ça tourne |
|---|---|---|
| `doctrack-voice/` | Service de transcription (FastAPI + Omnilingual ASR) | Hugging Face Space gratuit (CPU) |
| `doctrack-ocr/` | Lecture des pièces photographiées (FastAPI + RapidOCR / PaddleOCR) | Hugging Face Space gratuit (CPU) |
| `doctrack-ocr/specimens/make_specimens.py` | Génère des pièces **fictives** (CNI CEDEAO recto/verso, passeport, photos de travers) pour tester | Colab ou tout PC avec Python |
| `notebooks/01_banc_essai_transcription.ipynb` | Compare les modèles sur vos vocaux (WER, CER, mots-clés) | Google Colab (GPU gratuit) |
| `notebooks/02_generer_voix_wolof.ipynb` | Génère les MP3 wolof des phrases de l'assistant | Google Colab (GPU gratuit) |
| `corpus/transcriptions_modele.csv` | Modèle du fichier de « bonnes réponses » du banc d'essai | — |

---

## Étape 1 — Collecter les vocaux (Majeli)

Décrire la situation à la personne et la laisser parler **naturellement** (wolof, français ou mélange), sans lire de texte.
2 à 5 personnes par situation, profils variés (âge, sexe, Dakar / régions). **Accord des personnes**, noms et numéros **inventés**.

1. Tu as perdu ta carte d'identité au marché, tu veux la déclarer.
2. Tu as perdu ton passeport et ton permis dans un taxi.
3. Tu as trouvé une carte d'identité par terre, tu veux la rendre.
4. Tu veux savoir où en est ta déclaration.
5. On t'a dit que ta pièce est retrouvée : où dois-tu aller ?
6. Que faut-il apporter pour récupérer la pièce ?
7. Ton frère peut-il la récupérer à ta place ?
8. Donne ton nom complet et ton numéro de téléphone (inventés).
9. Réponds « oui », « non », « je ne sais pas ».
10. Dis le nom de ton quartier ou de ta ville.

Nom des fichiers : `S03_P2_wolof.ogg` (situation, personne, langue). Les vocaux WhatsApp conviennent.
Écrire dans `transcriptions.csv` ce que la personne a **réellement** dit (voir `corpus/transcriptions_modele.csv`), puis tout mettre dans `corpus.zip`.

## Étape 2 — Banc d'essai (Colab)

Ouvrir `notebooks/01_banc_essai_transcription.ipynb` dans [Google Colab](https://colab.research.google.com)
(Fichier → Importer un notebook), choisir le GPU T4, exécuter les cellules et envoyer `corpus.zip`.
Résultat : `resultats_transcription.csv` + `synthese_transcription.csv` → à partager pour le workplan et pour enrichir les mots-clés.

## Étape 3 — Mettre en ligne le service vocal (Hugging Face)

1. Créer un compte gratuit sur [huggingface.co](https://huggingface.co).
2. **New Space** → nom `doctrack-voice` → SDK **Docker** → matériel **CPU basic (gratuit)** → visibilité **Public**.
3. Envoyer les 4 fichiers du dossier `doctrack-voice/` (onglet *Files* → *Add file* → *Upload files*).
4. *Settings* → *Variables and secrets* → **New secret** `DOCTRACK_API_KEY` = une longue phrase secrète.
5. Attendre la construction (10 à 20 min la première fois), puis ouvrir `https://<compte>-doctrack-voice.hf.space/` :
   `"loaded": true` = prêt.
6. Dans le `.env` de l'API :

```
AI_STT_PROVIDER=hf_space
AI_STT_URL=https://<compte>-doctrack-voice.hf.space
AI_STT_TOKEN=<la même phrase secrète>
```

7. `php artisan config:clear` puis `GET /api/v2/assistant/capabilities` → `"voice_input": true`.

Le Space gratuit s'endort après une période d'inactivité : l'ouvrir quelques minutes avant une démo.

## Étape 4 — Voix wolof des réponses (Colab)

1. `php artisan doctrack:assistant-phrases --lang=wo` → `storage/app/assistant/phrases_wo.json`.
2. Ouvrir `notebooks/02_generer_voix_wolof.ipynb` dans Colab (GPU T4), exécuter, envoyer le JSON.
3. Décompresser `audio_wo.zip` dans `storage/app/assistant/audio/wo/`.

⚠️ **Textes wolof** de `config/assistant.php` : brouillon à faire relire par des locuteurs natifs **avant** de générer l'audio.
⚠️ **Licence** xTTS-v2 : non commerciale (démo et pilote gratuit uniquement).

## Facultatif — modèle de langage de secours

Quand les mots-clés ne suffisent pas, l'assistant peut demander à un modèle de langage de **proposer** une intention
(il ne fait jamais d'action lui-même ; les chiffres sont masqués avant l'envoi).

```
# Google Gemini (formule gratuite : clé sur https://aistudio.google.com)
AI_LLM_PROVIDER=gemini
GEMINI_API_KEY=...

# ou toute API compatible OpenAI (Groq, OpenRouter, Ollama en local)
AI_LLM_PROVIDER=openai_compatible
AI_LLM_URL=https://api.groq.com/openai/v1
AI_LLM_KEY=...
AI_LLM_MODEL=llama-3.1-8b-instant
```

**Confidentialité** : une formule gratuite peut servir à entraîner le modèle du fournisseur → uniquement avec des **données fictives** (démo).
Pour un pilote avec de vrais citoyens : laisser `AI_LLM_PROVIDER=none` ou passer à une offre payante qui garantit la confidentialité.

---

## Scan OCR des pièces trouvées

```
Photo (trouveur) ──► API Laravel  POST /api/v2/ocr/scan
                       │  remet à l'endroit, envoie au Space « doctrack-ocr » (RapidOCR : lit le texte + positions)
                       │  DocumentReader : type, nom, prénoms, numéro(s), dates, sexe
                       │     • zone MRZ (verso CNI, passeport) vérifiée par chiffres de contrôle OACI
                       │     • numéro CEDEAO 17 chiffres : structure comparée à la date de naissance et au sexe
                       │  copie de la photo avec numéros / dates / MRZ MASQUÉS
                       ▼
Formulaire pré-rempli ─► la personne vérifie ─► POST /api/documents + ocr_scan_id
                                                  original privé · copie masquée pour le public · NIN gardé pour les correspondances
```

### Mettre en ligne le service OCR (Hugging Face)

Même procédure que le service vocal (étape 3), avec les fichiers du dossier `doctrack-ocr/`
(`README.md`, `Dockerfile`, `app.py`, `requirements.txt`) dans un Space **Docker** nommé `doctrack-ocr`, matériel **CPU basic**.
Le moteur est léger (≈ 1 à 2 s par photo sur le processeur gratuit, aucune carte graphique nécessaire).

- Secret **`DOCTRACK_API_KEY`** : obligatoire en pratique, ce sont des pièces d'identité.
- `.env` de l'API :

```
AI_OCR_PROVIDER=hf_space
AI_OCR_URL=https://<compte>-doctrack-ocr.hf.space
AI_OCR_TOKEN=<la même phrase secrète>
```

Vérification : `POST /api/v2/ocr/scan` avec une photo de spécimen (`python specimens/make_specimens.py`).

### Tester avec des pièces fictives

`doctrack-ocr/specimens/make_specimens.py` fabrique des cartes et un passeport **inventés** (avec une vraie zone MRZ calculée),
y compris une photo de travers et une photo prise de côté. Les tests automatiques de l'API utilisent de vraies lectures
RapidOCR de ces spécimens (`tests/Fixtures/ocr/`).

**Pour aller plus loin (pilote)** : photographier quelques vraies pièces **avec l'accord écrit** des titulaires,
ne jamais les partager, et mesurer le taux de champs corrects (le journal « Pièce lue par OCR » enregistre les corrections faites
par les utilisateurs : c'est l'indicateur de qualité à suivre).

### Limites connues

- La mise en page réelle de la CNI CEDEAO peut différer des spécimens : les libellés sont réglables dans `config/ocr.php`.
- Les espaces entre prénoms composés se perdent parfois à la lecture de la face avant (« MOUSSAIBRAHIMA ») ; la zone MRZ, elle, les conserve.
- L'algorithme du chiffre de contrôle du numéro CEDEAO n'est pas public : seule sa structure est vérifiée.
- Pièces sans zone MRZ (permis, carte grise) : lecture générale seulement.
