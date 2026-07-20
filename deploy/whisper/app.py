"""
API HTTP mínima para transcripción con faster-whisper.
Escucha solo en loopback; el RIS nube la consume vía AI_TRANSCRIPTION_WHISPER_URL.
"""

from __future__ import annotations

import os
import tempfile
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from faster_whisper import WhisperModel

MODEL_SIZE = os.getenv("WHISPER_MODEL", "small")
DEVICE = os.getenv("WHISPER_DEVICE", "cpu")
COMPUTE_TYPE = os.getenv("WHISPER_COMPUTE_TYPE", "int8")
BEAM_SIZE = int(os.getenv("WHISPER_BEAM_SIZE", "5"))

_model: WhisperModel | None = None


def get_model() -> WhisperModel:
    global _model
    if _model is None:
        _model = WhisperModel(
            MODEL_SIZE,
            device=DEVICE,
            compute_type=COMPUTE_TYPE,
        )
    return _model


@asynccontextmanager
async def lifespan(_: FastAPI):
    # Precarga en arranque para el primer request no espere la descarga del modelo.
    get_model()
    yield


app = FastAPI(title="RIS Faster-Whisper", version="1.0.0", lifespan=lifespan)


@app.get("/health")
def health():
    return {
        "ok": True,
        "provider": "faster-whisper",
        "model": MODEL_SIZE,
        "device": DEVICE,
        "compute_type": COMPUTE_TYPE,
        "model_loaded": _model is not None,
    }


@app.post("/v1/audio/transcriptions")
async def transcribe(
    file: UploadFile = File(...),
    language: str | None = Form(default="es"),
):
    if not file.filename:
        raise HTTPException(status_code=422, detail="Archivo de audio requerido.")

    suffix = Path(file.filename).suffix or ".webm"
    raw = await file.read()
    if not raw:
        raise HTTPException(status_code=422, detail="Audio vacío.")

    tmp_path = None
    try:
        with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
            tmp.write(raw)
            tmp_path = tmp.name

        model = get_model()
        lang = (language or "").strip() or None
        segments, _info = model.transcribe(
            tmp_path,
            language=lang,
            beam_size=BEAM_SIZE,
            vad_filter=True,
        )
        text = " ".join(seg.text.strip() for seg in segments if seg.text).strip()
        if not text:
            raise HTTPException(status_code=422, detail="No se obtuvo texto del audio.")
        return {"text": text, "provider": "faster-whisper", "model": MODEL_SIZE}
    except HTTPException:
        raise
    except Exception as exc:  # noqa: BLE001 — superficie clara al RIS
        raise HTTPException(status_code=500, detail=f"Error Whisper: {exc}") from exc
    finally:
        if tmp_path and os.path.exists(tmp_path):
            try:
                os.unlink(tmp_path)
            except OSError:
                pass
