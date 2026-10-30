import sys, math, subprocess
from PIL import Image, ImageFilter, ImageEnhance, ImageDraw
U = "/root/.claude/uploads/2811ebac-11bf-5872-8f5d-d23ba1e951ea/"
OUT = sys.argv[1]
W, H, FPS = 1080, 1920, 30
poster = Image.open(U + "87c67c43-image.jpg").convert("RGB")
S = W / poster.width
P = poster.resize((W, round(poster.height * S)), Image.LANCZOS)
PY = (H - P.height) // 2

# fondo: afiche desenfocado y aclarado, a pantalla completa
bg = poster.resize((round(H * poster.width / poster.height), H)).crop((0, 0, W, H)) if False else None
full = poster.resize((W, round(poster.height * H / poster.height * W / poster.width)))
bg = poster.resize((round(poster.width * H / poster.height * 1.0), H))
bg = bg.resize((max(W, bg.width), H)).crop((0, 0, W, H))
bg = bg.filter(ImageFilter.GaussianBlur(28))
bg = Image.blend(bg, Image.new("RGB", (W, H), (255, 253, 245)), 0.45)
base_mid = P.filter(ImageFilter.GaussianBlur(22))
base_mid = Image.blend(base_mid, Image.new("RGB", P.size, (255, 253, 245)), 0.35)
bg.paste(base_mid, (0, PY))

def feather(size, r=18):
    m = Image.new("L", size, 0)
    ImageDraw.Draw(m).rounded_rectangle((r, r, size[0] - r, size[1] - r), radius=r * 2, fill=255)
    return m.filter(ImageFilter.GaussianBlur(r * 0.6))

def region(box):
    b = tuple(round(v * S) for v in box)
    im = P.crop(b).convert("RGBA")
    im.putalpha(feather(im.size))
    return im, b

A_SHORT, GAP = 10.09, 0.5
L = A_SHORT + GAP  # inicio del audio largo
items = [  # caja en coords del afiche original, segundo de aparición
    ((35, 25, 245, 258), 0.5),           # escudo
    ((285, 15, 795, 305), 2.8),          # título maratón
    ((185, 335, 870, 560), L + 0.7),     # ¡GRACIAS!
    ((265, 560, 805, 785), L + 5.3),     # hoy vivimos...
    ((15, 915, 362, 1185), L + 15.6),    # corazón familias
    ((335, 835, 690, 1135), L + 21.5),   # corazón papás
    ((680, 915, 1022, 1205), L + 28.1),  # corazón comunidad
    ((215, 1165, 805, 1355), L + 35.1),  # corremos juntos
    ((285, 1365, 785, 1462), L + 40.0),  # pie escuela
]
items = [(region(b), t) for b, t in items]
kids = [(region((0, 440, 292, 955)), 0.3, -1), (region((768, 455, 1024, 945)), 0.6, 1)]
TOTAL = L + 43.75 + 1.5

def ease_back(x):
    x = min(max(x, 0), 1); c = 1.7
    return 1 + (c + 1) * (x - 1) ** 3 + c * (x - 1) ** 2

def pop(frame, im, b, t0, t):
    if t < t0: return
    k = ease_back((t - t0) / 0.55)
    a = min((t - t0) / 0.3, 1)
    w, h = max(1, round(im.width * k)), max(1, round(im.height * k))
    s = im.resize((w, h), Image.LANCZOS)
    if a < 1: s.putalpha(s.getchannel("A").point(lambda v: int(v * a)))
    cx, cy = (b[0] + b[2]) // 2, (b[1] + b[3]) // 2 + PY
    frame.alpha_composite(s, (cx - w // 2, cy - h // 2))

ff = subprocess.Popen(["ffmpeg", "-y", "-loglevel", "error", "-f", "rawvideo", "-pix_fmt", "rgb24",
    "-s", f"{W}x{H}", "-r", str(FPS), "-i", "-",
    "-i", U + "4fea8959-AUDIO-2026-10-30-18-08-46.m4a",
    "-i", U + "b4967644-AUDIO-2026-10-30-18-07-02.m4a",
    "-filter_complex", f"[2:a]adelay={int(L*1000)}[b];[1:a][b]amix=inputs=2:normalize=0,loudnorm=I=-16[a]",
    "-map", "0:v", "-map", "[a]", "-c:v", "libx264", "-pix_fmt", "yuv420p", "-crf", "20",
    "-c:a", "aac", "-b:a", "192k", "-t", f"{TOTAL:.2f}", OUT], stdin=subprocess.PIPE)
bgA = bg.convert("RGBA")
for i in range(int(TOTAL * FPS)):
    t = i / FPS
    fr = bgA.copy()
    for (im, b), t0 in items: pop(fr, im, b, t0, t)
    for (im, b), t0, side in kids:
        if t < t0: continue
        x = min((t - t0) / 0.8, 1); x = 1 - (1 - x) ** 3
        dx = round(side * (1 - x) * 420)
        dy = round(-abs(math.sin((t - t0) * 3.2 + side)) * 26)
        r = math.sin((t - t0) * 3.2 + side) * 4 * side
        s = im.rotate(r, resample=Image.BICUBIC, expand=True)
        fr.alpha_composite(s, (b[0] + dx - (s.width - im.width) // 2, b[1] + PY + dy - (s.height - im.height) // 2))
    if t > TOTAL - 0.8:  # fundido final
        fr = Image.blend(fr, Image.new("RGBA", (W, H), (255, 255, 255, 255)), (t - (TOTAL - 0.8)) / 0.8)
    ff.stdin.write(fr.convert("RGB").tobytes())
ff.stdin.close(); ff.wait()
