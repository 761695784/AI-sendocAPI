---
title: DocTrack Voice
emoji: 🎙️
colorFrom: green
colorTo: yellow
sdk: docker
app_port: 7860
pinned: false
license: apache-2.0
short_description: Reconnaissance vocale français + wolof pour DocTrack
---

# DocTrack Voice

Service de reconnaissance vocale de l'assistant DocTrack (français et wolof),
basé sur **Omnilingual ASR** de Meta (licence Apache 2.0, 1 600+ langues dont le wolof).

- `GET /` : état du service (`loaded: true` quand le modèle est prêt).
- `POST /transcribe` : champ `file` (audio : webm, ogg WhatsApp, mp3, wav, m4a…), champ `lang`
  (`wol_Latn` ou `fra_Latn`) → `{"text": "...", "model": "...", "duration_s": 4.2, "elapsed_s": 1.8}`.

Aucun audio n'est conservé. Vocaux de moins de 40 secondes.

## Réglages (Settings → Variables and secrets)

| Nom | Rôle | Défaut |
|---|---|---|
| `DOCTRACK_API_KEY` (secret) | Clé partagée avec Laravel (`AI_STT_TOKEN`). Recommandé. | vide = ouvert |
| `MODEL_CARD` | Modèle : `omniASR_CTC_300M_v2` (rapide) ou `omniASR_CTC_1B_v2` (plus précis, plus lent) | `omniASR_CTC_300M_v2` |

Côté DocTrack (`.env`) :

```
AI_STT_PROVIDER=hf_space
AI_STT_URL=https://<votre-compte>-doctrack-voice.hf.space
AI_STT_TOKEN=<même valeur que DOCTRACK_API_KEY>
```

Le Space gratuit se met en veille après une période sans utilisation : la première
requête qui suit peut prendre une à deux minutes (Laravel attend jusqu'à 90 s).
