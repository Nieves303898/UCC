import subprocess, glob
W, H, G = 1080, 1920, 8
TRIM = {1: (0, 4.79), 2: (0, 5), 3: (1, 5), 4: (1.5, 5), 5: (2.5, 5), 6: (7, 5), 7: (4, 5), 8: (14, 5), 9: (1, 5)}
shots = sorted(glob.glob("screens5/*.jpg"))
plan = [("img", s) for s in shots]
plan[5:5] = [("vid", [6, 7]), ("vid", [8, 9]), ("vid", [3]), ("vid", [1])]   # calentamiento y largada
plan[10:10] = [("vid", [2, 4, 5])]                                           # carrera
enc = ["-r", "30", "-pix_fmt", "yuv420p", "-c:v", "libx264", "-crf", "20", "-an"]
def cover(w, h): return f"scale={w}:{h}:force_original_aspect_ratio=increase,crop={w}:{h},setsar=1"
for i, (kind, x) in enumerate(plan):
    out = f"seg/{i:03d}.mp4"
    if kind == "img":
        cmd = ["ffmpeg", "-loglevel", "error", "-y", "-loop", "1", "-t", "3", "-i", x, "-vf", "format=yuv420p", *enc, out]
    else:
        n = len(x); hs = [(H - G * (n - 1)) // n] * n; hs[-1] = H - G * (n - 1) - sum(hs[:-1])
        ins, fl = [], []
        for k, v in enumerate(x):
            ss, d = TRIM[v]; ins += ["-ss", str(ss), "-t", str(d), "-i", f"vids/v{v}.mov"]
            fl.append(f"[{k}:v]{cover(W, hs[k])}[s{k}]")
        d = min(TRIM[v][1] for v in x)
        if n == 1: f = fl[0].replace("[s0]", "[v]")
        else:
            pads = "".join(f"[s{k}]pad={W}:{hs[k] + (G if k < n - 1 else 0)}:0:0:white[p{k}];" for k in range(n))
            f = ";".join(fl) + ";" + pads + "".join(f"[p{k}]" for k in range(n)) + f"vstack={n}[v]"
        cmd = ["ffmpeg", "-loglevel", "error", "-y", *ins, "-filter_complex", f, "-map", "[v]", "-t", str(d), *enc, out]
    subprocess.run(cmd, check=True); print(i, kind, x)
open("seg/list.txt", "w").write("".join(f"file '{i:03d}.mp4'\n" for i in range(len(plan))))
