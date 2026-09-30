---
title: DocTrack OCR
emoji: 🪪
colorFrom: green
colorTo: blue
sdk: docker
app_port: 7860
pinned: false
license: apache-2.0
short_description: Lecture du texte des pièces pour DocTrack (RapidOCR)
---

# DocTrack OCR

Lit le texte d'une photo de pièce (carte d'identité CEDEAO, passeport…) pour pré-remplir
la publication d'un document trouvé dans DocTrack. Moteur : **RapidOCR** (modèles PaddleOCR PP-OCRv4
en ONNX, licence Apache 2.0) — rapide sur le processeur gratuit, sans carte graphique.

- `GET /` : état du service.
- `POST /ocr` : champ `file` (jpeg, png, webp) → lignes de texte, positions, rotation appliquée.

Le service ne fait que **lire** : l'interprétation (type, nom, numéro, zone MRZ et chiffres de contrôle,
masquage du numéro) est faite par DocTrack. **Aucune image n'est conservée.**

## Réglages (Settings → Variables and secrets)

| Nom | Rôle |
|---|---|
| `DOCTRACK_API_KEY` (secret) | Clé partagée avec Laravel (`AI_OCR_TOKEN`). Fortement recommandé : ce sont des pièces d'identité. |

Côté DocTrack (`.env`) :

```
AI_OCR_PROVIDER=hf_space
AI_OCR_URL=https://<votre-compte>-doctrack-ocr.hf.space
AI_OCR_TOKEN=<même valeur que DOCTRACK_API_KEY>
```

Tests : `python specimens/make_specimens.py` génère des pièces **fictives** (aucune vraie pièce n'est nécessaire).
