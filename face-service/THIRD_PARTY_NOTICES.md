Third-party model notice

Zentrix Face Service uses MiniFASNetV2 ONNX weights from:
https://github.com/yakhyo/face-anti-spoofing

The upstream face-anti-spoofing repository is licensed under Apache License 2.0.
The model file is downloaded at runtime from the upstream weights release and is verified
against its SHA-256 digest before ONNX Runtime loads it.

Model source:
https://github.com/yakhyo/face-anti-spoofing/releases/tag/weights

The anti-spoofing model is a probabilistic presentation-attack detector. It is not a
guarantee of liveness and must be evaluated against the cameras, lighting, printed photos,
screens, and replay attacks expected in the deployment environment.
