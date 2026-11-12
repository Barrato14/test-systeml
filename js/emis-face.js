(function () {
    const MODEL_URL = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.15/model/';
    let stream = null;
    let latestDescriptor = null;
    let detectionTimer = null;
    let stopped = false;

    async function start(video, statusElement, onStateChange) {
        stopped = false;
        latestDescriptor = null;

        function setStatus(message, type) {
            if (statusElement) {
                statusElement.textContent = message;
                statusElement.className = 'alert alert-' + (type || 'info') + ' text-center';
            }
            if (typeof onStateChange === 'function') {
                onStateChange({ ready: !!latestDescriptor, message: message });
            }
        }

        if (!window.faceapi) {
            setStatus('Face recognition library could not be loaded. Refresh the page and try again.', 'danger');
            throw new Error('Face recognition library unavailable.');
        }

        setStatus('Loading face detection models...', 'info');
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
            faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
            faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL)
        ]);

        setStatus('Requesting camera permission...', 'info');
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
            audio: false
        });
        video.srcObject = stream;
        await video.play();

        setStatus('Camera ready. Keep only your face in view and look directly at the camera.', 'warning');

        async function detect() {
            if (stopped || video.readyState < 2) return;

            try {
                const results = await faceapi
                    .detectAllFaces(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 }))
                    .withFaceLandmarks()
                    .withFaceDescriptors();

                if (results.length === 1) {
                    const box = results[0].detection.box;
                    const faceArea = box.width * box.height;
                    const frameArea = video.videoWidth * video.videoHeight;
                    const faceRatio = frameArea ? faceArea / frameArea : 0;

                    if (faceRatio < 0.055) {
                        latestDescriptor = null;
                        setStatus('Move a little closer to the camera.', 'warning');
                    } else {
                        latestDescriptor = Array.from(results[0].descriptor);
                        setStatus('One face detected. Face scan ready.', 'success');
                    }
                } else {
                    latestDescriptor = null;
                    setStatus(results.length === 0 ? 'No face detected. Center your face in the camera.' : 'More than one face detected. Make sure you are alone in the camera view.', 'warning');
                }
            } catch (error) {
                latestDescriptor = null;
                setStatus('Face detection error. Please keep the camera open and try again.', 'danger');
            }
        }

        await detect();
        detectionTimer = window.setInterval(detect, 500);
        return true;
    }

    function getDescriptor() {
        return latestDescriptor ? latestDescriptor.slice() : null;
    }

    function stop() {
        stopped = true;
        latestDescriptor = null;
        if (detectionTimer) {
            window.clearInterval(detectionTimer);
            detectionTimer = null;
        }
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }
    }

    window.EMISFace = { start, getDescriptor, stop };
    window.addEventListener('beforeunload', stop);
})();