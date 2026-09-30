"""
DocTrack OCR — lecture du texte d'une photo de pièce (CNI, passeport…).

Hébergé gratuitement sur un Hugging Face Space (Docker, CPU).
Moteur : RapidOCR (modèles PaddleOCR PP-OCRv4 en ONNX, licence Apache 2.0),
rapide sur processeur, sans carte graphique.

    GET  /     → état du service
    POST /ocr  → image → lignes de texte + positions (+ rotation appliquée)

Le service ne fait QUE lire le texte. L'interprétation (type de pièce, nom,
numéro, zone MRZ, contrôles, masquage) est faite par DocTrack (Laravel),
où les règles sont testées. Aucune image n'est conservée.
"""

import io
import os
import time

import numpy as np
from fastapi import FastAPI, File, Header, HTTPException, UploadFile
from PIL import Image, ImageOps

API_KEY = os.getenv("DOCTRACK_API_KEY", "")
MAX_SIDE = int(os.getenv("MAX_SIDE", "1600"))
MAX_BYTES = 10 * 1024 * 1024

app = FastAPI(title="DocTrack OCR", version="1.0")
_engine = None


def engine():
    global _engine
    if _engine is None:
        from rapidocr_onnxruntime import RapidOCR

        _engine = RapidOCR()
    return _engine


@app.on_event("startup")
def warm_up():
    engine()


@app.get("/")
def health():
    return {"service": "doctrack-ocr", "engine": "rapidocr-onnxruntime (PP-OCRv4)", "loaded": _engine is not None}


def _read(img: Image.Image):
    # RapidOCR attend une image BGR (convention OpenCV).
    result, _ = engine()(np.ascontiguousarray(np.array(img)[:, :, ::-1]))
    lines = []
    for box, text, conf in result or []:
        lines.append({
            "text": str(text),
            "confidence": round(float(conf), 3),
            "box": [[round(float(x), 1), round(float(y), 1)] for x, y in box],
        })
    return lines


def _score(lines):
    """Qualité d'une lecture : texte lu × confiance, pénalisé si les lignes sont verticales."""
    total, vertical = 0.0, 0
    for line in lines:
        (x0, y0), (x1, y1), _, (x3, y3) = line["box"]
        width = ((x1 - x0) ** 2 + (y1 - y0) ** 2) ** 0.5
        height = ((x3 - x0) ** 2 + (y3 - y0) ** 2) ** 0.5
        if height > width * 1.3 and len(line["text"]) > 2:
            vertical += 1
        total += len(line["text"]) * line["confidence"]
    return total * (0.3 if lines and vertical > len(lines) / 2 else 1.0), vertical


@app.post("/ocr")
async def ocr(file: UploadFile = File(...), authorization: str = Header(default="")):
    if API_KEY and authorization != f"Bearer {API_KEY}":
        raise HTTPException(status_code=401, detail="Clé invalide")

    data = await file.read()
    if not data:
        raise HTTPException(status_code=422, detail="Fichier vide")
    if len(data) > MAX_BYTES:
        raise HTTPException(status_code=413, detail="Image trop volumineuse")

    try:
        img = ImageOps.exif_transpose(Image.open(io.BytesIO(data))).convert("RGB")
    except Exception:  # noqa: BLE001
        raise HTTPException(status_code=422, detail="Image illisible")

    scale = min(1.0, MAX_SIDE / max(img.size))
    if scale < 1.0:
        img = img.resize((round(img.width * scale), round(img.height * scale)))

    started = time.time()
    best = {"rotation": 0, "lines": _read(img), "img": img}
    best_score, vertical = _score(best["lines"])

    # Photo prise de côté : on essaie les deux quarts de tour.
    if vertical > len(best["lines"]) / 2 or best_score < 20:
        for rotation in (90, 270):
            turned = img.rotate(-rotation, expand=True)  # sens horaire
            lines = _read(turned)
            score, _ = _score(lines)
            if score > best_score * 1.15:
                best, best_score = {"rotation": rotation, "lines": lines, "img": turned}, score

    lines = sorted(best["lines"], key=lambda l: (round(l["box"][0][1] / 12), l["box"][0][0]))
    return {
        "engine": "rapidocr-ppocrv4",
        "rotation": best["rotation"],          # degrés, sens horaire, appliqués à l'image reçue
        "width": best["img"].width,            # dimensions de l'image lue (après rotation/réduction)
        "height": best["img"].height,
        "scale": round(scale, 4),
        "lines": lines,
        "elapsed_s": round(time.time() - started, 2),
    }
