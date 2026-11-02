import json, subprocess, os, sys, numpy as np, soundfile as sf
sys.path.insert(0, os.path.dirname(__file__))
from script import S, VOICES, CHORUS
from kokoro_onnx import Kokoro
D = os.path.dirname(os.path.abspath(__file__)); AD = D + "/audio"
SR = 24000; FPS = 30
k = Kokoro("./tts/kokoro-v1.0.onnx", "./tts/voices-v1.0.bin")
cache = {}

def tts(sp, text, idx):
    voice, speed, semi, keepf = VOICES[sp]
    raw = f"{AD}/{idx:02d}_{sp}_raw.wav"; out = f"{AD}/{idx:02d}_{sp}.wav"
    t = text.replace("’", "'")
    s, sr = k.create(t, voice=voice, speed=speed, lang="it")
    sf.write(raw, s, sr)
    flt = f"rubberband=pitch={2**(semi/12):.5f}" + (":formant=preserved" if keepf else "")
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", raw, "-af",
        flt + ",silenceremove=start_periods=1:start_threshold=-45dB,areverse,silenceremove=start_periods=1:start_threshold=-45dB,areverse",
        "-ar", str(SR), "-ac", "1", out], check=True)
    a, _ = sf.read(out); return a

def chorus(sp, text, idx):
    parts = [tts(v, text, idx * 100 + j) for j, v in enumerate(CHORUS[sp])]
    n = max(len(p) for p in parts) + int(0.08 * SR)
    mix = np.zeros(n)
    rng = np.random.default_rng(idx)
    for p in parts:
        off = int(rng.uniform(0, 0.06) * SR); mix[off:off + len(p)] += p
    return mix / len(parts) * 2.2

timeline = []; segs = []; t = 0.0
for i, e in enumerate(S):
    e = dict(e)
    if e["type"] == "line":
        a = chorus(e["speaker"], e["text"], i) if e["speaker"] in CHORUS else tts(e["speaker"], e["text"], i)
        a = a / (np.max(np.abs(a)) + 1e-9) * 0.9
        hop = SR // FPS
        env = [float(np.sqrt(np.mean(a[j:j + hop] ** 2))) for j in range(0, len(a), hop)]
        m = max(env) or 1; e["env"] = [round(min(1, v / m * 1.4), 3) for v in env]
        e["start"] = t + 0.15; e["end"] = e["start"] + len(a) / SR
        segs.append((e["start"], a)); t = e["end"] + 0.35
    else:
        e["start"] = t; e["end"] = t + e["dur"]; t = e["end"]
    timeline.append(e)
total = t + 0.3
voice = np.zeros(int(total * SR) + SR)
for st, a in segs:
    o = int(st * SR); voice[o:o + len(a)] += a
sf.write(AD + "/voice.wav", voice, SR)
json.dump({"total": total, "fps": FPS, "items": timeline}, open(D + "/timeline.json", "w"), ensure_ascii=False)
print("total", round(total, 1), "s")
