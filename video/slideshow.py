import sys, subprocess
from PIL import Image, ImageFilter, ImageEnhance
J, OUT, TOTAL = sys.argv[1], sys.argv[2], float(sys.argv[3])
W, H, FPS, XF = 1080, 1920, 30, 0.6
ORDER = sorted(f[4:8] for f in __import__("os").listdir(J) if f not in ("IMG_6510.jpg", "IMG_6514.jpg", "IMG_6524.jpg"))
n = len(ORDER)
per = (TOTAL + XF * (n - 1)) / n  # duración de cada foto incluyendo el fundido

def prep(code):
    im = Image.open(f"{J}/IMG_{code}.jpg").convert("RGB")
    s = max(W / im.width, H / im.height)
    bg = im.resize((round(im.width * s), round(im.height * s))).crop((0, 0, W, H))
    bg = ImageEnhance.Brightness(bg.filter(ImageFilter.GaussianBlur(40))).enhance(0.6)
    f = min(W / im.width, 1500 / im.height)
    fg = im.resize((round(im.width * f * 1.1), round(im.height * f * 1.1)), Image.LANCZOS)
    return bg, fg, f

cache = {}
def frame_of(i, t):  # t: segundos desde que empieza la foto i
    if i not in cache:
        [cache.pop(k) for k in list(cache) if k < i - 1]
        cache[i] = prep(ORDER[i])
    bg, fg, f = cache[i]
    z = 1 + 0.08 * (t / per) if i % 2 == 0 else 1.08 - 0.08 * (t / per)
    w, h = round(fg.width / 1.1 * z), round(fg.height / 1.1 * z)
    vw, vh = min(w, W), min(h, 1500)
    # recorte centrado, con un leve paneo
    cx = (w - vw) // 2; cy = (h - vh) // 2
    im = fg.resize((w, h), Image.BILINEAR).crop((cx, cy, cx + vw, cy + vh))
    out = bg.copy(); out.paste(im, ((W - vw) // 2, (H - vh) // 2))
    return out

ff = subprocess.Popen(["ffmpeg", "-y", "-loglevel", "error", "-f", "rawvideo", "-pix_fmt", "rgb24",
    "-s", f"{W}x{H}", "-r", str(FPS), "-i", "-", "-c:v", "libx264", "-pix_fmt", "yuv420p",
    "-crf", "20", "-preset", "medium", OUT], stdin=subprocess.PIPE)
step = per - XF
for k in range(int(TOTAL * FPS)):
    t = k / FPS
    i = min(int(t // step), n - 1)
    lt = t - i * step
    fr = frame_of(i, lt)
    if lt < XF and i > 0:  # fundido desde la foto anterior
        fr = Image.blend(frame_of(i - 1, lt + step), fr, lt / XF)
    ff.stdin.write(fr.tobytes())
ff.stdin.close(); ff.wait()
