import base64
import hmac
import io
import os
from datetime import datetime, timezone

import face_recognition
import numpy as np
import requests
from fastapi import FastAPI, Header
from fastapi.responses import JSONResponse
from PIL import Image, UnidentifiedImageError

from anti_spoof import check_liveness

app = FastAPI(title="Zentrix Face Recognition", docs_url=None, redoc_url=None)

MAX_IMAGE_BYTES = 8 * 1024 * 1024
MATCH_THRESHOLD = 0.50


class FaceSpoofDetected(Exception):
    pass


def response_error(message: str, status_code: int = 400):
    return JSONResponse(
        status_code=status_code,
        content={"success": False, "message": message},
    )


def authorize(token: str | None):
    expected = os.getenv("FACE_SERVICE_TOKEN", "")
    return bool(expected) and bool(token) and hmac.compare_digest(expected, token)


def supabase_config():
    url = os.getenv("SUPABASE_URL", "").rstrip("/")
    key = os.getenv("SUPABASE_SERVICE_ROLE_KEY", "")
    if not url or not key:
        raise RuntimeError("Supabase service credentials are not configured.")
    return url, key


def supabase_headers(key: str, prefer: str = "return=representation"):
    return {
        "apikey": key,
        "Authorization": f"Bearer {key}",
        "Content-Type": "application/json",
        "Prefer": prefer,
    }


def decode_face_image(image_data: str):
    if not isinstance(image_data, str) or not image_data:
        raise ValueError("A camera image is required.")

    encoded = image_data.split(",", 1)[1] if image_data.startswith("data:") and "," in image_data else image_data
    if len(encoded) > (MAX_IMAGE_BYTES * 4 // 3 + 16):
        raise ValueError("The camera image is too large. Please try again.")

    try:
        raw = base64.b64decode(encoded, validate=True)
        if not raw or len(raw) > MAX_IMAGE_BYTES:
            raise ValueError("The camera image is empty or too large.")
        with Image.open(io.BytesIO(raw)) as source:
            source = source.convert("RGB")
            if source.width < 160 or source.height < 160:
                raise ValueError("The camera image is too small. Move closer and try again.")
            source.thumbnail((1600, 1600))
            image = np.asarray(source)
    except (ValueError, UnidentifiedImageError) as error:
        raise ValueError("The camera image could not be read. Please capture it again.") from error
    except Exception as error:
        raise ValueError("The camera image could not be processed.") from error

    locations = face_recognition.face_locations(image, number_of_times_to_upsample=1, model="hog")
    if len(locations) == 0:
        raise ValueError("No face was detected. Center your face in the camera and try again.")
    if len(locations) > 1:
        raise ValueError("More than one face was detected. Make sure only you are in the camera view.")

    try:
        liveness = check_liveness(image, locations[0])
    except Exception as error:
        raise RuntimeError("The anti-spoofing check is unavailable. Please try again shortly.") from error

    if not liveness["is_real"]:
        raise FaceSpoofDetected("The camera image could not pass the live-face check. Use your camera directly and try again.")

    encodings = face_recognition.face_encodings(
        image,
        known_face_locations=locations,
        num_jitters=1,
        model="small",
    )
    if len(encodings) != 1:
        raise ValueError("A clear face scan could not be created. Please try again.")

    descriptor = encodings[0].astype(float).tolist()
    if len(descriptor) != 128 or not np.all(np.isfinite(descriptor)):
        raise ValueError("The face scan could not be processed. Please try again.")
    return descriptor


def save_profile(participant_id: str, descriptor: list[float]):
    url, key = supabase_config()
    endpoint = f"{url}/rest/v1/participant_face_profiles"
    payload = {
        "participant_id": participant_id,
        "face_descriptor": descriptor,
        "consent_given": True,
        "consent_given_at": datetime.now(timezone.utc).isoformat(),
        "updated_at": datetime.now(timezone.utc).isoformat(),
        "face_model": "dlib_face_recognition_v1",
    }
    result = requests.post(
        endpoint,
        headers=supabase_headers(key, "return=representation"),
        json=payload,
        timeout=20,
    )
    if result.status_code == 409:
        raise ValueError("A face is already registered to this account. Contact an administrator if it needs to be reset.")
    if not result.ok:
        raise RuntimeError("The face registration could not be saved. Please try again.")
    return True


def get_profile(participant_id: str):
    url, key = supabase_config()
    endpoint = (
        f"{url}/rest/v1/participant_face_profiles"
        f"?participant_id=eq.{participant_id}"
        "&select=participant_id,face_descriptor,face_front_descriptor,face_left_descriptor,face_right_descriptor,consent_given,face_model"
    )
    result = requests.get(endpoint, headers=supabase_headers(key), timeout=20)
    if not result.ok:
        raise RuntimeError("The registered face could not be loaded. Please try again.")
    rows = result.json()
    if not rows:
        return None
    return rows[0]


def decode_angle_images(angle_images: dict):
    if not isinstance(angle_images, dict):
        raise ValueError("Front, left, and right face images are required.")

    descriptors = {}
    for angle in ("front", "left", "right"):
        image_data = angle_images.get(angle)
        if not isinstance(image_data, str) or not image_data:
            raise ValueError(f"The {angle} face image is missing.")
        descriptors[angle] = decode_face_image(image_data)

    return descriptors


def save_multi_angle_profile(participant_id: str, descriptors: dict, upgrade_legacy: bool = False):
    url, key = supabase_config()
    endpoint = f"{url}/rest/v1/participant_face_profiles"
    now = datetime.now(timezone.utc).isoformat()
    payload = {
        "face_descriptor": descriptors["front"],
        "face_front_descriptor": descriptors["front"],
        "face_left_descriptor": descriptors["left"],
        "face_right_descriptor": descriptors["right"],
        "consent_given": True,
        "consent_given_at": now,
        "updated_at": now,
        "face_model": "dlib_face_recognition_v1",
    }

    if upgrade_legacy:
        update_endpoint = (
            endpoint
            + f"?participant_id=eq.{participant_id}"
            + "&face_front_descriptor=is.null"
            + "&face_left_descriptor=is.null"
            + "&face_right_descriptor=is.null"
        )
        result = requests.patch(
            update_endpoint,
            headers=supabase_headers(key, "return=representation"),
            json=payload,
            timeout=30,
        )
        if result.ok and not result.json():
            raise ValueError("Your face registration changed while you were upgrading. Refresh the page and try again.")
    else:
        payload["participant_id"] = participant_id
        result = requests.post(
            endpoint,
            headers=supabase_headers(key, "return=representation"),
            json=payload,
            timeout=30,
        )

    if result.status_code == 409:
        raise ValueError("A face is already registered to this account. Contact an administrator if it needs to be reset.")
    if not result.ok:
        raise RuntimeError("The three-angle face registration could not be saved. Please try again.")
    return True


def get_multi_angle_profile(participant_id: str):
    url, key = supabase_config()
    endpoint = (
        f"{url}/rest/v1/participant_face_profiles"
        f"?participant_id=eq.{participant_id}"
        "&select=face_front_descriptor,face_left_descriptor,face_right_descriptor,consent_given,face_model"
    )
    result = requests.get(endpoint, headers=supabase_headers(key), timeout=20)
    if not result.ok:
        raise RuntimeError("The registered face could not be loaded. Please try again.")
    rows = result.json()
    if not rows:
        return None
    return rows[0]


def compare_face_descriptor(descriptor: list[float], stored_value):
    stored = np.asarray(stored_value or [], dtype=np.float64)
    submitted = np.asarray(descriptor, dtype=np.float64)
    if stored.shape != (128,) or not np.all(np.isfinite(stored)):
        raise ValueError("The saved face angle is invalid. Please complete face registration again.")
    distance = float(np.linalg.norm(stored - submitted))
    return distance <= MATCH_THRESHOLD


@app.post("/register-multi")
def register_multi(payload: dict, x_face_service_token: str | None = Header(default=None)):
    if not authorize(x_face_service_token):
        return response_error("Face service authorization failed.", 401)

    participant_id = payload.get("participant_id")
    if not isinstance(participant_id, str) or not participant_id.strip():
        return response_error("Participant account is missing.")

    if payload.get("consent_given") is not True:
        return response_error("Participant consent is required.")

    try:
        existing_profile = get_profile(participant_id)
        has_multi_angle_profile = existing_profile and any(
            existing_profile.get(key) is not None
            for key in ("face_front_descriptor", "face_left_descriptor", "face_right_descriptor")
        )
        if has_multi_angle_profile:
            return response_error("A three-angle face is already registered to this account. Contact an administrator if it needs to be reset.", 409)

        descriptors = decode_angle_images(payload.get("angle_images"))
        save_multi_angle_profile(
            participant_id,
            descriptors,
            upgrade_legacy=existing_profile is not None,
        )
        return {
            "success": True,
            "message": "Your front, left, and right face angles have been registered successfully.",
            "registered_angles": ["front", "left", "right"],
        }
    except FaceSpoofDetected as error:
        return response_error(str(error), 403)
    except ValueError as error:
        return response_error(str(error))
    except RuntimeError as error:
        return response_error(str(error), 502)
    except Exception:
        return response_error("Multi-angle face registration is temporarily unavailable. Please try again.", 500)


@app.post("/verify-angle")
def verify_angle(payload: dict, x_face_service_token: str | None = Header(default=None)):
    if not authorize(x_face_service_token):
        return response_error("Face service authorization failed.", 401)

    participant_id = payload.get("participant_id")
    if not isinstance(participant_id, str) or not participant_id.strip():
        return response_error("Participant account is missing.")

    angle = payload.get("angle")
    if angle not in ("front", "left", "right"):
        return response_error("A valid face angle is required.")

    try:
        profile = get_multi_angle_profile(participant_id)
        if not profile:
            return response_error("No face is registered to your account. Please register your face first.", 404)
        if profile.get("face_model") != "dlib_face_recognition_v1":
            return response_error("Your face registration uses an unsupported recognition model. Please register your face again.", 409)
        if profile.get("consent_given") not in (True, "true"):
            return response_error("Your face registration is not active. Please register your face again.", 403)

        descriptor_key = f"face_{angle}_descriptor"
        stored_descriptor = profile.get(descriptor_key)
        if stored_descriptor is None:
            return response_error("Your account does not have a complete three-angle face registration. Please contact an administrator to re-register.", 409)

        descriptor = decode_face_image(payload.get("image_data", ""))
        if not compare_face_descriptor(descriptor, stored_descriptor):
            return response_error(f"The {angle} face angle does not match your registration. Please try again.", 403)

        return {
            "success": True,
            "verified": True,
            "angle": angle,
            "message": f"{angle.capitalize()} face angle verified.",
        }
    except FaceSpoofDetected as error:
        return response_error(str(error), 403)
    except ValueError as error:
        return response_error(str(error))
    except RuntimeError as error:
        return response_error(str(error), 502)
    except Exception:
        return response_error("Face angle verification is temporarily unavailable. Please try again.", 500)


@app.get("/health")
def health():
    return {"status": "ok", "service": "zentrix-face-recognition"}


@app.post("/register")
def register(payload: dict, x_face_service_token: str | None = Header(default=None)):
    if not authorize(x_face_service_token):
        return response_error("Face service authorization failed.", 401)

    participant_id = payload.get("participant_id")
    if not isinstance(participant_id, str) or not participant_id.strip():
        return response_error("Participant account is missing.")

    if payload.get("consent_given") is not True:
        return response_error("Participant consent is required.")

    try:
        if get_profile(participant_id):
            return response_error("A face is already registered to this account. Contact an administrator if it needs to be reset.", 409)
        descriptor = decode_face_image(payload.get("image_data", ""))
        save_profile(participant_id, descriptor)
        return {"success": True, "message": "Your face has been registered to your Zentrix account successfully."}
    except FaceSpoofDetected as error:
        return response_error(str(error), 403)
    except ValueError as error:
        return response_error(str(error))
    except RuntimeError as error:
        return response_error(str(error), 502)
    except Exception:
        return response_error("Face registration is temporarily unavailable. Please try again.", 500)


@app.post("/verify")
def verify(payload: dict, x_face_service_token: str | None = Header(default=None)):
    if not authorize(x_face_service_token):
        return response_error("Face service authorization failed.", 401)

    participant_id = payload.get("participant_id")
    if not isinstance(participant_id, str) or not participant_id.strip():
        return response_error("Participant account is missing.")

    try:
        descriptor = decode_face_image(payload.get("image_data", ""))
        profile = get_profile(participant_id)
        if not profile:
            return response_error("No face is registered to your account. Please register your face first.", 404)
        if profile.get("face_model") != "dlib_face_recognition_v1":
            return response_error("Your previous face registration uses an older recognition model. Please register your face again.", 409)
        if profile.get("consent_given") not in (True, "true"):
            return response_error("Your face registration is not active. Please register your face again.", 403)

        stored = np.asarray(profile.get("face_descriptor", []), dtype=np.float64)
        submitted = np.asarray(descriptor, dtype=np.float64)
        if stored.shape != (128,) or not np.all(np.isfinite(stored)):
            return response_error("Your saved face registration is invalid. Please register your face again.", 409)

        distance = float(np.linalg.norm(stored - submitted))
        if distance > MATCH_THRESHOLD:
            return response_error("Face does not match the face registered to your account. Please try again.", 403)

        return {"success": True, "message": "Face verified."}
    except FaceSpoofDetected as error:
        return response_error(str(error), 403)
    except ValueError as error:
        return response_error(str(error))
    except RuntimeError as error:
        return response_error(str(error), 502)
    except Exception:
        return response_error("Face verification is temporarily unavailable. Please try again.", 500)
