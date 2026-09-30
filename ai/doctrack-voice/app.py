"""
DocTrack Voice — service de reconnaissance vocale (français + wolof).

Hébergé gratuitement sur un Hugging Face Space (Docker, CPU).
Modèle : Omnilingual ASR de Meta (Apache 2.0), 1 600+ langues dont le wolof.

    GET  /            → état du service (modèle chargé ?)
    POST /transcribe  → fichier audio (+ lang) → {"text": "...", ...}

Aucun audio n'est conservé : le fichier est converti, transcrit puis supprimé.
"""

import os
import subprocess
import tempfile
import threading
import time

from fastapi import FastAPI, File, Form, Header, HTTPException, UploadFile

MODEL_CARD = os.getenv("MODEL_CARD", "omniASR_CTC_300M_v2")
API_KEY = os.getenv("DOCTRACK_API_KEY", "")          # facultatif : clé partagée avec Laravel (AI_STT_TOKEN)
MAX_SECONDS = float(os.getenv("MAX_SECONDS", "39"))  # limite du modèle : < 40 s
MAX_BYTES = 8 * 1024 * 1024

app = FastAPI(title="DocTrack Voice", version="1.0")

_pipeline = None
_error = None
_lock = threading.Lock()


def _load():
    """Chargement en arrière-plan : le Space répond tout de suite à /."""
    global _pipeline, _error
    try:
        from omnilingual_asr.models.inference.pipeline import ASRInferencePipeline

        started = time.time()
        pipe = ASRInferencePipeline(model_card=MODEL_CARD)
        with _lock:
            _pipeline = pipe
        print(f"Modèle {MODEL_CARD} chargé en {time.time() - started:.1f} s", flush=True)
    except Exception as exc:  # noqa: BLE001
        _error = repr(exc)
        print(f"Échec du chargement : {_error}", flush=True)


threading.Thread(target=_load, daemon=True).start()


@app.get("/")
def health():
    return {
        "service": "doctrack-voice",
        "model": MODEL_CARD,
        "loaded": _pipeline is not None,
        "error": _error,
    }


def _to_wav(src: str, dst: str) -> float:
    """Convertit n'importe quel format (webm, ogg WhatsApp, m4a…) en WAV 16 kHz mono."""
    subprocess.run(
        ["ffmpeg", "-nostdin", "-loglevel", "error", "-y", "-i", src,
         "-t", str(MAX_SECONDS), "-ac", "1", "-ar", "16000", dst],
        check=True,
    )
    probe = subprocess.run(
        ["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", dst],
        capture_output=True, text=True, check=True,
    )
    return float(probe.stdout.strip() or 0)


@app.post("/transcribe")
async def transcribe(
    file: UploadFile = File(...),
    lang: str = Form("wol_Latn"),
    authorization: str = Header(default=""),
):
    if API_KEY and authorization != f"Bearer {API_KEY}":
        raise HTTPException(status_code=401, detail="Clé invalide")
    if _pipeline is None:
        raise HTTPException(status_code=503, detail=_error or "Modèle en cours de chargement, réessayez dans une minute")

    data = await file.read()
    if not data:
        raise HTTPException(status_code=422, detail="Fichier vide")
    if len(data) > MAX_BYTES:
        raise HTTPException(status_code=413, detail="Fichier trop volumineux")

    with tempfile.TemporaryDirectory() as tmp:
        src = os.path.join(tmp, "input")
        wav = os.path.join(tmp, "audio.wav")
        with open(src, "wb") as fh:
            fh.write(data)
        try:
            duration = _to_wav(src, wav)
        except subprocess.CalledProcessError:
            raise HTTPException(status_code=422, detail="Format audio illisible")

        started = time.time()
        # Les modèles CTC ignorent la langue ; les modèles LLM s'en servent.
        with _lock:
            text = _pipeline.transcribe([wav], lang=[lang], batch_size=1)[0]

    return {
        "text": (text or "").strip(),
        "lang": lang,
        "model": MODEL_CARD,
        "duration_s": round(duration, 2),
        "elapsed_s": round(time.time() - started, 2),
    }
