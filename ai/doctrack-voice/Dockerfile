# DocTrack Voice — Hugging Face Space (SDK Docker, matériel gratuit « CPU basic »)
FROM python:3.11-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg libsndfile1 build-essential cmake zlib1g-dev libbz2-dev liblzma-dev \
    && rm -rf /var/lib/apt/lists/*

# Hugging Face exécute le conteneur avec l'utilisateur 1000.
RUN useradd -m -u 1000 user
ENV HOME=/home/user \
    XDG_CACHE_HOME=/home/user/.cache \
    FAIRSEQ2_CACHE_DIR=/home/user/.cache/fairseq2 \
    PYTHONUNBUFFERED=1
WORKDIR /home/user/app

# PyTorch version CPU (bien plus léger) — omnilingual-asr / fairseq2 0.6 exigent torch 2.8.0.
RUN pip install --no-cache-dir torch==2.8.0 torchaudio==2.8.0 --index-url https://download.pytorch.org/whl/cpu

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY --chown=user app.py .
USER user

# Télécharge le modèle pendant la construction : démarrage plus rapide après une mise en veille.
ARG MODEL_CARD=omniASR_CTC_300M_v2
ENV MODEL_CARD=${MODEL_CARD}
RUN python -c "from omnilingual_asr.models.inference.pipeline import ASRInferencePipeline as P; P(model_card='${MODEL_CARD}')" || echo "Téléchargement reporté au démarrage"

EXPOSE 7860
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "7860"]
