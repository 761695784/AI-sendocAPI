# DocTrack TTS — Hugging Face Space (SDK Docker, matériel gratuit « CPU basic » : 2 cœurs, 16 Go)
FROM python:3.11-slim

RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg libsndfile1 git \
    && rm -rf /var/lib/apt/lists/*

# Hugging Face exécute le conteneur avec l'utilisateur 1000.
RUN useradd -m -u 1000 user
ENV HOME=/home/user \
    HF_HOME=/home/user/.cache/huggingface \
    PYTHONUNBUFFERED=1 \
    TOKENIZERS_PARALLELISM=false
WORKDIR /home/user/app

# PyTorch version CPU (bien plus léger que la version carte graphique).
RUN pip install --no-cache-dir torch --index-url https://download.pytorch.org/whl/cpu

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY --chown=user app.py .
RUN mkdir -p /home/user/.cache && chown -R user:user /home/user
USER user

# Télécharge le modèle (≈ 3,5 Go) pendant la construction : démarrage plus rapide après une mise en veille.
ARG MODEL_ID=CONCREE/Adia_TTS
ENV MODEL_ID=${MODEL_ID}
RUN python -c "from huggingface_hub import snapshot_download as d; d('${MODEL_ID}')" || echo "Téléchargement reporté au démarrage"

EXPOSE 7860
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "7860"]
