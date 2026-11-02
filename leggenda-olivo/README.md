# La leggenda dell'olivo – video animato (9:16)

- `La_leggenda_dell_olivo_9x16_v2.mp4` — video 1080×1920, 30 fps, ~2 min 42 s, inquadrature cinematografiche, voci in italiano (stereo), frasi al centro.
- `sorgenti/` — file per rigenerare il video:
  1. `script.py`: copione e voci dei personaggi.
  2. `build_audio.py`: voci con Kokoro TTS (`pip install kokoro-onnx`; modello in `./tts/`).
  3. `build_mix.py`: musica di lira, effetti sonori e mix.
  4. `scene2.html` + `prims.js` + `render.js` (PAGE=scene2.html): animazione (Playwright) → fotogrammi → ffmpeg.
