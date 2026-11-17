import hashlib
import os
import tempfile
import threading
from pathlib import Path

import numpy as np
import onnxruntime as ort
from PIL import Image

MODEL_URL = "https://github.com/yakhyo/face-anti-spoofing/releases/download/weights/MiniFASNetV2.onnx"
MODEL_SHA256 = "b32929adc2d9c34b9486f8c4c7bc97c1b69bc0ea9befefc380e4faae4e463907"
MODEL_MAX_BYTES = 10 * 1024 * 1024
MODEL_SCALE = 2.7
INPUT_SIZE = (80, 80)
REAL_CLASS_INDEX = 1
DEFAULT_REAL_THRESHOLD = 0.80

_session = None
_session_lock = threading.Lock()


def _model_path() -> Path:
    configured = os.getenv("FACE_ANTISPOOF_MODEL_PATH", "").strip()
    if configured:
        return Path(configured)
    return Path(os.getenv("FACE_ANTISPOOF_CACHE_DIR", "/tmp/zentrix-face-models")) / "MiniFASNetV2.onnx"


def _download_model(path: Path) -> None:
    import requests

    path.parent.mkdir(parents=True, exist_ok=True)
    response = requests.get(MODEL_URL, stream=True, timeout=(15, 90))
    response.raise_for_status()

    digest = hashlib.sha256()
    total = 0
    fd, temporary_name = tempfile.mkstemp(prefix="minifasnet-", suffix=".onnx", dir=str(path.parent))
    try:
        with os.fdopen(fd, "wb") as output:
            for chunk in response.iter_content(chunk_size=64 * 1024):
                if not chunk:
                    continue
                total += len(chunk)
                if total > MODEL_MAX_BYTES:
                    raise RuntimeError("The anti-spoofing model download exceeded the allowed size.")
                digest.update(chunk)
                output.write(chunk)

        if total == 0 or digest.hexdigest() != MODEL_SHA256:
            raise RuntimeError("The anti-spoofing model failed its integrity check.")

        os.replace(temporary_name, path)
    finally:
        if os.path.exists(temporary_name):
            os.unlink(temporary_name)
        response.close()


def _load_session():
    global _session
    if _session is not None:
        return _session

    with _session_lock:
        if _session is not None:
            return _session

        path = _model_path()
        if not path.is_file():
            _download_model(path)

        digest = hashlib.sha256()
        with path.open("rb") as model_file:
            for chunk in iter(lambda: model_file.read(1024 * 1024), b""):
                digest.update(chunk)
        if digest.hexdigest() != MODEL_SHA256:
            raise RuntimeError("The configured anti-spoofing model failed its integrity check.")

        options = ort.SessionOptions()
        options.intra_op_num_threads = max(1, min(2, os.cpu_count() or 1))
        options.inter_op_num_threads = 1
        options.execution_mode = ort.ExecutionMode.ORT_SEQUENTIAL
        _session = ort.InferenceSession(
            str(path),
            sess_options=options,
            providers=["CPUExecutionProvider"],
        )
        return _session


def _face_crop(image_bgr: np.ndarray, bbox: tuple[int, int, int, int]) -> np.ndarray:
    top, right, bottom, left = bbox
    height, width = image_bgr.shape[:2]
    box_width = max(1, right - left)
    box_height = max(1, bottom - top)
    scale = min((height - 1) / box_height, (width - 1) / box_width, MODEL_SCALE)
    crop_width = box_width * scale
    crop_height = box_height * scale
    center_x = left + box_width / 2
    center_y = top + box_height / 2

    x1 = max(0, int(center_x - crop_width / 2))
    y1 = max(0, int(center_y - crop_height / 2))
    x2 = min(width - 1, int(center_x + crop_width / 2))
    y2 = min(height - 1, int(center_y + crop_height / 2))
    crop = image_bgr[y1:y2 + 1, x1:x2 + 1]

    if crop.size == 0:
        raise ValueError("The face image could not be prepared for liveness checking.")

    resized = Image.fromarray(crop).resize(INPUT_SIZE, Image.Resampling.BILINEAR)
    tensor = np.asarray(resized, dtype=np.float32)
    return np.transpose(tensor, (2, 0, 1))[None, ...]


def check_liveness(image_rgb: np.ndarray, bbox: tuple[int, int, int, int]) -> dict:
    image_bgr = np.ascontiguousarray(image_rgb[:, :, ::-1])
    tensor = _face_crop(image_bgr, bbox)
    session = _load_session()
    input_name = session.get_inputs()[0].name
    output_name = session.get_outputs()[0].name
    logits = np.asarray(session.run([output_name], {input_name: tensor})[0], dtype=np.float64)

    if logits.ndim != 2 or logits.shape[0] != 1 or logits.shape[1] < 2:
        raise RuntimeError("The anti-spoofing model returned an unsupported result.")

    shifted = logits[0] - np.max(logits[0])
    probabilities = np.exp(shifted)
    probabilities /= np.sum(probabilities)
    real_probability = float(probabilities[REAL_CLASS_INDEX])
    threshold = float(os.getenv("FACE_ANTISPOOF_REAL_THRESHOLD", str(DEFAULT_REAL_THRESHOLD)))

    if not 0.0 < threshold < 1.0:
        raise RuntimeError("FACE_ANTISPOOF_REAL_THRESHOLD must be between 0 and 1.")

    return {
        "is_real": real_probability >= threshold,
        "real_probability": real_probability,
        "threshold": threshold,
    }
