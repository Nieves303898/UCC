import json, numpy as np, soundfile as sf
D = __import__("os").path.dirname(__import__("os").path.abspath(__file__)); SR = 24000
tl = json.load(open(D + "/timeline.json")); total = tl["total"]
voice, _ = sf.read(D + "/audio/voice.wav"); N = len(voice)
rng = np.random.default_rng(1)
def pluck(f, dur=1.6, amp=0.3):
    n = int(dur * SR); p = max(2, int(SR / f)); buf = rng.uniform(-1, 1, p)
    for _ in range(4): buf = 0.5 * (buf + np.roll(buf, 1))
    out = np.zeros(n)
    for i in range(n):
        out[i] = buf[i % p]; buf[i % p] = 0.5 * (buf[i % p] + buf[(i + 1) % p]) * 0.996
    att = int(0.008 * SR); out[:att] *= np.linspace(0, 1, att)
    return out * amp
# musica: lira in re dorico
notes = {"D3":146.8,"A3":220,"D4":293.7,"E4":329.6,"F4":349.2,"G4":392,"A4":440,"C5":523.3,"D5":587.3,"C4":261.6,"G3":196,"F3":174.6,"Bb3":233.1}
cache = {}
def P(n, d=1.6, a=0.3):
    key = (n, d, a)
    if key not in cache: cache[key] = pluck(notes[n], d, a)
    return cache[key]
bars = [("D3", ["D4","F4","A4","F4"]), ("C4", ["C4","E4","G4","E4"]), ("Bb3", ["D4","F4","Bb3","F4"]), ("A3", ["C5","A4","E4","A4"])]
notes["Bb3"] = 233.1
music = np.zeros(N + SR * 3); beat = 60 / 84 / 2; t = 0.0; b = 0
melody = ["A4", "G4", "F4", "E4", "D4", "E4", "F4", "A4", "D5", "C5", "A4", "G4", "F4", "E4", "D4", "D4"]
while t < total + 2:
    bass, arp = bars[b % 4]
    o = int(t * SR); m = P(bass, 2.4, 0.35); music[o:o + len(m)] += m[:len(music) - o]
    for j in range(8):
        oo = int((t + j * beat) * SR); x = P(arp[j % 4], 1.2, 0.16)
        music[oo:oo + len(x)] += x[:len(music) - oo]
    if (b // 4) % 2 == 1:
        for j in range(2):
            oo = int((t + j * 4 * beat) * SR); x = P(melody[(b * 2 + j) % 16], 1.8, 0.22)
            music[oo:oo + len(x)] += x[:len(music) - oo]
    t += 8 * beat; b += 1
music = music[:N]
# ducking sotto la voce
env = np.abs(voice); w = int(0.25 * SR); w2 = int(1.2 * SR)
env = np.convolve(env, np.ones(w) / w, "same"); duck = np.where(env > 0.01, 0.28, 1.0)
duck = np.minimum(duck, np.convolve(np.where(env > 0.01, 0.28, 1.0), np.ones(w2) / w2, "same")); duck = np.convolve(duck, np.ones(w) / w, "same")
sfx = np.zeros(N)
def add(t0, x):
    o = int(t0 * SR); x = x[:max(0, N - o)]; sfx[o:o + len(x)] += x
def noise(d): return rng.uniform(-1, 1, int(d * SR))
def lp(x, a):  # filtro passa-basso semplice
    y = np.zeros_like(x); acc = 0
    for i in range(len(x)): acc += a * (x[i] - acc); y[i] = acc
    return y
def tt(d): return np.linspace(0, d, int(d * SR), endpoint=False)
def thunder(d=2.0):
    x = lp(noise(d), 0.02) * 6; tm = tt(d); return x * np.exp(-tm * 1.6) * (1 - np.exp(-tm * 30)) * 0.9
def thump():
    tm = tt(0.6); f = 120 * np.exp(-tm * 5); return (np.sin(2 * np.pi * np.cumsum(f) / SR) * np.exp(-tm * 6) + lp(noise(0.6), 0.1) * np.exp(-tm * 12)) * 0.8
def water(d=3.0):
    tm = tt(d); x = noise(d) - lp(noise(d), 0.05); return x * np.minimum(1, tm * 2) * np.exp(-np.maximum(0, tm - d + 1) * 3) * 0.12
def chime(f0=880, n=6, step=0.12):
    out = np.zeros(int((n * step + 1.5) * SR))
    for i in range(n):
        f = f0 * 2 ** ((i * 2) / 12); tm = tt(1.2); s = np.sin(2 * np.pi * f * tm) * np.exp(-tm * 4) * 0.18
        o = int(i * step * SR); out[o:o + len(s)] += s
    return out
def whoosh(d=1.0):
    tm = tt(d); return (noise(d) - lp(noise(d), 0.3)) * np.sin(np.pi * tm / d) ** 2 * 0.15
def applause(d=2.6):
    out = np.zeros(int(d * SR))
    for _ in range(int(d * 90)):
        o = rng.integers(0, len(out) - 400); c = noise(0.012) * np.exp(-tt(0.012) * 300) * rng.uniform(0.1, 0.35)
        out[o:o + len(c)] += c
    tm = tt(d); return out * np.minimum(1, tm * 4) * np.minimum(1, (d - tm) * 1.5)
for e in tl["items"]:
    s = e["start"]; ev = e.get("event")
    if ev == "title": add(s + 0.2, chime(660, 8, 0.15))
    if ev == "zeus_enter": add(s, thunder())
    if ev in ("pos_enter", "atena_enter", "kids_enter"): add(s + 0.3, whoosh())
    if ev == "trident": add(s + 0.9, thump()); add(s + 1.1, water(2.2))
    if ev == "horse": add(s, chime(1200, 4, 0.08))
    if ev == "plant": add(s + 1.0, chime(1046, 3, 0.15))
    if ev == "grow": add(s, chime(523, 10, 0.25))
    if ev == "olives": add(s + 0.5, chime(1400, 5, 0.1))
    if ev == "cheer": add(e["end"] - 0.3, applause())
    if ev == "atene": add(e["end"] - 1.5, thunder(1.5) * 0.4); add(e["end"] - 0.4, chime(784, 8, 0.1))
    if ev == "celebrate": add(s, applause(2.4))
    if ev == "fine": add(s + 0.3, chime(587, 8, 0.18))
    if e["type"] == "card": add(s, whoosh(0.8) * 0.7)
mix = voice * 1.0 + lp(music, 0.35) * duck * 0.42 + sfx
mix = mix / np.max(np.abs(mix)) * 0.92
fade = int(2 * SR); mix[-fade:] *= np.linspace(1, 0, fade)
sf.write(D + "/audio/mix.wav", mix, SR)
print("ok", len(mix) / SR)
