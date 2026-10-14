---
title: DocTrack TTS
emoji: 🔊
colorFrom: indigo
colorTo: yellow
sdk: docker
app_port: 7860
pinned: false
license: apache-2.0
short_description: Voix wolof de l'assistant DocTrack (Adia_TTS)
---

# DocTrack TTS

Voix wolof de l'assistant DocTrack, avec **Adia_TTS** de CONCREE (licence Apache 2.0) :
un réseau Parler-TTS affiné sur 40 heures de voix wolof, qui **génère** un signal audio nouveau à partir d'un texte.

- `GET /` : état du service (`loaded: true` quand le modèle est prêt).
- `POST /synthesize` (JSON) : `{"text": "Salaam aleekum…", "format": "mp3"}` → fichier audio.
  En-têtes de réponse : `X-Duration-S` (durée de l'audio), `X-Elapsed-S` (temps de calcul), `X-Segments`.

Le service prépare le texte avant de le lire : « DocTrack » est prononcé « Dok Trak », les nombres sont écrits en lettres,
et un texte long est découpé en segments courts (110 caractères, aux phrases puis aux virgules) recollés avec une courte pause. Un segment dont l'audio est trop court pour son texte (phrase coupée avant la fin) est régénéré avec une autre graine.

Aucun texte ni audio n'est conservé.

## À savoir

- **Lent sur le processeur gratuit** (30 s à 2 min par phrase) : on s'en sert pour préparer les phrases fixes de l'assistant
  une fois pour toutes (`php artisan doctrack:assistant-voices`), pas pour parler en direct. Avec une carte graphique
  (Space payant ou Colab), la même phrase prend 2 à 5 secondes.
- Le modèle lit mal les sigles, les mots français et les longs numéros : garder des phrases wolof simples.
- La voix est fixée par `VOICE_SEED` : même graine = même voix d'une phrase à l'autre.

## Réglages (Settings → Variables and secrets)

| Nom | Rôle | Défaut |
|---|---|---|
| `DOCTRACK_API_KEY` (secret) | Clé partagée avec Laravel (`AI_TTS_TOKEN`). Recommandé. | vide = ouvert |
| `VOICE_DESCRIPTION` | Description de la voix, en anglais | `A clear and educational voice, with a flow adapted to learning` |
| `VOICE_SEED` | Graine : changer pour essayer une autre voix (puis tout régénérer) | `42` |
| `MAX_CHARS` | Longueur maximale d'un texte | `600` |

Côté DocTrack (`.env`) :

```
AI_TTS_PROVIDER=hf_space
AI_TTS_URL=https://<votre-compte>-doctrack-tts.hf.space
AI_TTS_TOKEN=<même valeur que DOCTRACK_API_KEY>
```
