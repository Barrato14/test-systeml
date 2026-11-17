(function () {
    let landmarkerPromise = null;

    async function getLandmarker() {
        if (!landmarkerPromise) {
            landmarkerPromise = (async () => {
                const vision = await import("https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14/vision_bundle.mjs");
                const fileset = await vision.FilesetResolver.forVisionTasks(
                    "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14/wasm"
                );
                return vision.FaceLandmarker.createFromOptions(fileset, {
                    baseOptions: {
                        modelAssetPath: "https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task",
                        delegate: "GPU"
                    },
                    runningMode: "VIDEO",
                    numFaces: 2,
                    outputFaceBlendshapes: false,
                    outputFacialTransformationMatrixes: false
                }).catch(async () => vision.FaceLandmarker.createFromOptions(fileset, {
                    baseOptions: {
                        modelAssetPath: "https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task",
                        delegate: "CPU"
                    },
                    runningMode: "VIDEO",
                    numFaces: 2
                }));
            })();
        }
        return landmarkerPromise;
    }

    function poseRatio(landmarks) {
        const left = landmarks[234];
        const right = landmarks[454];
        const nose = landmarks[1];
        const width = right.x - left.x;
        if (!left || !right || !nose || Math.abs(width) < 0.08) return null;
        return (nose.x - left.x) / width;
    }

    async function captureAngle(video, angle, onProgress) {
        if (!video || !video.videoWidth || !video.videoHeight) {
            throw new Error("Camera is not ready. Please allow the camera and try again.");
        }

        const landmarker = await getLandmarker();
        const startedAt = performance.now();
        const timeoutMs = 25000;
        const requiredStableFrames = 8;
        let stableFrames = 0;
        let lastTimestamp = -1;

        return new Promise((resolve, reject) => {
            function inspect() {
                if (performance.now() - startedAt > timeoutMs) {
                    reject(new Error("We couldn't confirm the " + angle + " head position. Follow the instruction and try again."));
                    return;
                }

                if (video.readyState < 2) {
                    requestAnimationFrame(inspect);
                    return;
                }

                const timestamp = Math.max(performance.now(), lastTimestamp + 1);
                lastTimestamp = timestamp;

                try {
                    const result = landmarker.detectForVideo(video, timestamp);
                    const faces = result.faceLandmarks || [];
                    let matched = false;

                    if (faces.length === 1) {
                        const ratio = poseRatio(faces[0]);
                        if (ratio !== null) {
                            if (angle === "front") matched = ratio >= 0.44 && ratio <= 0.56;
                            if (angle === "left") matched = ratio >= 0.59;
                            if (angle === "right") matched = ratio <= 0.41;
                        }
                    }

                    stableFrames = matched ? stableFrames + 1 : 0;
                    const progress = Math.min(100, Math.round(stableFrames / requiredStableFrames * 100));
                    if (typeof onProgress === "function") onProgress(progress, faces.length);

                    if (stableFrames >= requiredStableFrames) {
                        resolve({ verifiedPose: true, progress: 100 });
                        return;
                    }
                } catch (error) {
                    reject(new Error("Live face tracking failed. Please refresh and try again."));
                    return;
                }

                requestAnimationFrame(inspect);
            }

            requestAnimationFrame(inspect);
        });
    }

    window.ZentrixFacePose = { captureAngle };
})();