"""
DocTrack TTS — voix wolof de l'assistant (texte → voix).

Hébergé gratuitement sur un Hugging Face Space (Docker, CPU).
Modèle : Adia_TTS de CONCREE (Apache 2.0), un réseau Parler-TTS affiné sur
40 heures de voix wolof. Il GÉNÈRE un signal audio nouveau à partir du texte :
ce ne sont pas des enregistrements collés bout à bout.

    GET  /            → état du service (modèle chargé ?)
    POST /synthesize  → {"text": "..."} → fichier audio (MP3 par défaut)

Aucun texte ni audio n'est conservé.

Sur le processeur gratuit, compter 30 s à 2 min par phrase : le service sert à
PRÉPARER les phrases de l'assistant une fois pour toutes
(php artisan doctrack:assistant-voices), pas à parler en direct.
"""

import os
import re
import subprocess
import tempfile
import threading
import time

from fastapi import FastAPI, Header, HTTPException
from fastapi.responses import Response
from pydantic import BaseModel, Field

MODEL_ID = os.getenv("MODEL_ID", "CONCREE/Adia_TTS")
API_KEY = os.getenv("DOCTRACK_API_KEY", "")  # clé partagée avec Laravel (AI_TTS_TOKEN)
# Description de la voix (en anglais, comme pendant l'entraînement du modèle).
VOICE = os.getenv("VOICE_DESCRIPTION", "A clear and educational voice, with a flow adapted to learning")
# Même graine pour chaque phrase : la voix reste la même d'une phrase à l'autre.
SEED = int(os.getenv("VOICE_SEED", "42"))
MAX_CHARS = int(os.getenv("MAX_CHARS", "600"))      # texte entier accepté par requête
# Le modèle accepte 200 caractères, mais au-delà d'une dizaine de secondes d'audio il s'arrête souvent
# avant la fin de la phrase : on lui donne des segments courts (une phrase à la fois).
SEGMENT_CHARS = int(os.getenv("SEGMENT_CHARS", "110"))
MAX_NEW_TOKENS = int(os.getenv("MAX_NEW_TOKENS", "2500"))   # ≈ 29 s d'audio : ne coupe plus une phrase longue
MIN_SECONDS_PER_CHAR = 0.045                         # en dessous, la phrase a été coupée : on recommence
RETRIES = 3
PAUSE_MS = 250                                       # silence entre deux segments

app = FastAPI(title="DocTrack TTS", version="1.0")

_model = None
_tokenizer = None
_description_tokenizer = None
_error = None
_lock = threading.Lock()


def _load():
    """Chargement en arrière-plan : le Space répond tout de suite à /."""
    global _model, _tokenizer, _description_tokenizer, _error
    try:
        import torch
        from parler_tts import ParlerTTSForConditionalGeneration
        from transformers import AutoTokenizer

        started = time.time()
        torch.set_num_threads(max(1, os.cpu_count() or 1))
        model = ParlerTTSForConditionalGeneration.from_pretrained(MODEL_ID).to("cpu")
        model.eval()
        tokenizer = AutoTokenizer.from_pretrained(MODEL_ID)
        # Parler-TTS multilingue : la description de la voix a son propre tokenizer (celui de l'encodeur de texte).
        try:
            description_tokenizer = AutoTokenizer.from_pretrained(model.config.text_encoder._name_or_path)
        except Exception:  # noqa: BLE001
            description_tokenizer = tokenizer
        with _lock:
            _model, _tokenizer, _description_tokenizer = model, tokenizer, description_tokenizer
        print(f"Modèle {MODEL_ID} chargé en {time.time() - started:.1f} s", flush=True)
    except Exception as exc:  # noqa: BLE001
        _error = repr(exc)
        print(f"Échec du chargement : {_error}", flush=True)


if os.getenv("DOCTRACK_TTS_NO_LOAD") != "1":  # désactivé pendant les tests
    threading.Thread(target=_load, daemon=True).start()


# ── Préparation du texte ─────────────────────────────────────────────

_UNITS = ["tus", "benn", "ñaar", "ñett", "ñeent", "juróom", "juróom-benn", "juróom-ñaar", "juróom-ñett", "juróom-ñeent"]
# Prononciations à forcer (le modèle lit le wolof, pas les sigles ni les mots étrangers).
_SPOKEN = {
    "doctrack": "Dok Trak",
    "sms": "ès-èm-ès",
    "qr": "ki-èr",
    "cni": "sé-èn-i",
}


def wolof_number(n: int) -> str:
    """Nombre en toutes lettres (0 à 999 999), système décimal wolof : fukk, téeméer, junni."""
    if n < 10:
        return _UNITS[n]
    if n < 20:
        return "fukk" if n == 10 else f"fukk ak {_UNITS[n - 10]}"
    if n < 100:
        tens, rest = divmod(n, 10)
        head = f"{_UNITS[tens]}-fukk"
        return head if rest == 0 else f"{head} ak {_UNITS[rest]}"
    if n < 1000:
        hundreds, rest = divmod(n, 100)
        head = "téeméer" if hundreds == 1 else f"{_UNITS[hundreds]} téeméer"
        return head if rest == 0 else f"{head} ak {wolof_number(rest)}"
    thousands, rest = divmod(n, 1000)
    head = "junni" if thousands == 1 else f"{wolof_number(thousands)} junni"
    return head if rest == 0 else f"{head} ak {wolof_number(rest)}"


def _spell_digits(match: re.Match) -> str:
    digits = match.group(0)
    # Numéros longs (téléphone, référence) : chiffre par chiffre ; sinon le nombre en toutes lettres.
    if len(digits) > 6 or (len(digits) > 1 and digits.startswith("0")):
        return ", ".join(_UNITS[int(d)] for d in digits)
    return wolof_number(int(digits))


def normalize(text: str) -> str:
    """Texte prêt à être lu : sigles prononcés, chiffres en lettres, espaces et ponctuation propres."""
    text = text.replace("’", "'").replace(" ", " ").replace(" ", " ")
    text = re.sub(r"[«»\"“”]", "", text)
    text = re.sub(r"\b\w+\b", lambda m: _SPOKEN.get(m.group(0).lower(), m.group(0)), text)
    text = re.sub(r"(?<=\d)[ .](?=\d{3}\b)", "", text)          # 3 000 → 3000
    text = re.sub(r"\d+", _spell_digits, text)
    text = re.sub(r"\s+([,.;:!?])", r"\1", text)                 # « Lan nga bëgg def ? » → « def? »
    text = re.sub(r"\s+", " ", text).strip()
    # Sans ponctuation finale, le modèle ne « pose » pas la voix et avale la dernière syllabe.
    return text if not text or text[-1] in ".!?…" else text + "."


def split_segments(text: str, limit: int = SEGMENT_CHARS) -> list[str]:
    """Découpe aux frontières naturelles (phrases, puis virgules, puis mots) sans dépasser la limite du modèle."""
    sentences = [s.strip() for s in re.split(r"(?<=[.!?…])\s+", text) if s.strip()]
    pieces: list[str] = []
    for sentence in sentences:
        if len(sentence) <= limit:
            pieces.append(sentence)
            continue
        current = ""
        for part in re.split(r"(?<=[,;:])\s+", sentence):
            while len(part) > limit:                             # très long sans virgule : coupe au dernier mot
                cut = part.rfind(" ", 0, limit)
                cut = cut if cut > 0 else limit
                if current:
                    pieces.append(current)
                    current = ""
                pieces.append(part[:cut].strip())
                part = part[cut:].strip()
            if current and len(current) + 1 + len(part) > limit:
                pieces.append(current)
                current = part
            else:
                current = f"{current} {part}".strip()
        if current:
            pieces.append(current)
    # Regroupe les phrases courtes voisines : moins d'appels au modèle, enchaînement plus naturel.
    merged: list[str] = []
    for piece in pieces:
        if merged and len(merged[-1]) + 1 + len(piece) <= limit:
            merged[-1] = f"{merged[-1]} {piece}"
        else:
            merged.append(piece)
    return merged


# ── Synthèse ─────────────────────────────────────────────────────────

def _generate_once(segment: str, description: str, seed: int):
    import numpy as np
    import torch

    torch.manual_seed(seed)
    desc = _description_tokenizer(description, return_tensors="pt")
    prompt = _tokenizer(segment, return_tensors="pt")
    with torch.inference_mode():
        audio = _model.generate(
            input_ids=desc.input_ids,
            attention_mask=desc.attention_mask,
            prompt_input_ids=prompt.input_ids,
            prompt_attention_mask=prompt.attention_mask,
            do_sample=True,
            temperature=0.8,
            top_k=50,
            repetition_penalty=1.2,
            max_new_tokens=MAX_NEW_TOKENS,
        )
    wave = audio.cpu().numpy().squeeze().astype(np.float32)
    peak = float(np.max(np.abs(wave))) if wave.size else 0.0
    return wave / peak * 0.95 if peak > 0 else wave


def is_complete(segment: str, seconds: float) -> bool:
    """Une phrase lue trop vite pour sa longueur a été coupée avant la fin."""
    return seconds >= max(0.6, len(segment) * MIN_SECONDS_PER_CHAR)


def _generate(segment: str, description: str, seed: int):
    """Génère un segment ; s'il est coupé avant la fin, recommence avec une autre graine et garde le plus complet."""
    rate = _model.config.sampling_rate
    best = None
    for attempt in range(RETRIES):
        wave = _generate_once(segment, description, seed + attempt)
        if best is None or len(wave) > len(best):
            best = wave
        if is_complete(segment, len(wave) / rate):
            break
    return best


def _encode(wav_path: str, fmt: str) -> bytes:
    """Retire les silences de début et de fin, puis encode (MP3 mono 64 kbit/s ou WAV)."""
    out = wav_path.replace(".wav", f".out.{fmt}")
    # Début : silence retiré. Fin : seuil très bas (une dernière syllabe douce n'est pas un silence) + courte respiration.
    head = "silenceremove=start_periods=1:start_threshold=-45dB:start_silence=0.08"
    tail = "silenceremove=start_periods=1:start_threshold=-60dB:start_silence=0.25"
    filters = f"{head},areverse,{tail},areverse,apad=pad_dur=0.2"
    codec = ["-codec:a", "libmp3lame", "-b:a", "64k"] if fmt == "mp3" else []
    subprocess.run(
        ["ffmpeg", "-nostdin", "-loglevel", "error", "-y", "-i", wav_path, "-af", filters, "-ac", "1", *codec, out],
        check=True,
    )
    with open(out, "rb") as fh:
        return fh.read()


class SynthesisRequest(BaseModel):
    text: str = Field(..., min_length=1)
    lang: str = "wo"
    format: str = "mp3"
    description: str | None = None
    seed: int | None = None


@app.get("/")
def health():
    return {
        "service": "doctrack-tts",
        "model": MODEL_ID,
        "loaded": _model is not None,
        "error": _error,
        "languages": ["wo"],
        "max_chars": MAX_CHARS,
    }


@app.post("/synthesize")
def synthesize(body: SynthesisRequest, authorization: str = Header(default="")):
    if API_KEY and authorization != f"Bearer {API_KEY}":
        raise HTTPException(status_code=401, detail="Clé invalide")
    if body.lang != "wo":
        raise HTTPException(status_code=422, detail="Seul le wolof est pris en charge par ce modèle")
    if body.format not in ("mp3", "wav"):
        raise HTTPException(status_code=422, detail="Format : mp3 ou wav")
    text = normalize(body.text)
    if not text:
        raise HTTPException(status_code=422, detail="Texte vide")
    if len(text) > MAX_CHARS:
        raise HTTPException(status_code=413, detail=f"Texte trop long ({len(text)} caractères, {MAX_CHARS} au plus)")
    if _model is None:
        raise HTTPException(status_code=503, detail=_error or "Modèle en cours de chargement, réessayez dans une minute")

    import numpy as np
    import soundfile as sf

    started = time.time()
    segments = split_segments(text)
    rate = _model.config.sampling_rate
    pause = np.zeros(int(rate * PAUSE_MS / 1000), dtype=np.float32)
    waves = []
    with _lock:  # une génération à la fois : le processeur gratuit n'a que 2 cœurs
        for index, segment in enumerate(segments):
            if index:
                waves.append(pause)
            waves.append(_generate(segment, body.description or VOICE, body.seed if body.seed is not None else SEED))
    wave = np.concatenate(waves)

    with tempfile.TemporaryDirectory() as tmp:
        wav_path = os.path.join(tmp, "voice.wav")
        sf.write(wav_path, wave, rate)
        data = _encode(wav_path, body.format)

    return Response(
        content=data,
        media_type="audio/mpeg" if body.format == "mp3" else "audio/wav",
        headers={
            "X-Model": MODEL_ID,
            "X-Segments": str(len(segments)),
            "X-Duration-S": f"{len(wave) / rate:.2f}",
            "X-Elapsed-S": f"{time.time() - started:.2f}",
            "Cache-Control": "no-store",
        },
    )
