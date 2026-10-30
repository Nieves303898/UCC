import sys, math, random, subprocess
from PIL import Image, ImageFilter, ImageDraw, ImageFont, ImageEnhance
J, POSTER, OUT = sys.argv[1], sys.argv[2], sys.argv[3]
SPEED = float(sys.argv[4]) if len(sys.argv) > 4 else 1.0  # >1 = más largo
W, H, FPS = 1080, 1920, 30
FONT = "/usr/share/fonts/opentype/inter/InterDisplay-Black.otf"
GREEN, PINK, YELLOW, INK = (124, 194, 66), (244, 143, 177), (255, 214, 92), (24, 64, 40)
random.seed(7)

poster = Image.open(POSTER).convert("RGB")
bg = poster.resize((W, round(poster.height * W / poster.width))).resize((W, H))
bg = Image.blend(bg.filter(ImageFilter.GaussianBlur(30)), Image.new("RGB", (W, H), (255, 252, 240)), 0.5).convert("RGBA")

def pill(text, size, color, maxw=1000):
    f = ImageFont.truetype(FONT, size)
    while f.getlength(text) > maxw - 80: size -= 4; f = ImageFont.truetype(FONT, size)
    tw = f.getlength(text); bb = f.getbbox(text)
    im = Image.new("RGBA", (int(tw) + 80, bb[3] + 50), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    d.rounded_rectangle((0, 0, im.width - 1, im.height - 1), radius=im.height // 2, fill=color)
    d.text((40, 22), text, font=f, fill=INK)
    return im

def load(code, w, h):  # recorte "cover" al tamaño del hueco
    im = Image.open(f"{J}/IMG_{code}.jpg").convert("RGB")
    s = max(w / im.width, h / im.height)
    im = im.resize((math.ceil(im.width * s), math.ceil(im.height * s)), Image.LANCZOS)
    x, y = (im.width - w) // 2, (im.height - h) // 2
    return im.crop((x, y, x + w, y + h))

def polaroid(im, border=14, rot=0):
    c = Image.new("RGBA", (im.width + 2 * border, im.height + 2 * border), (255, 255, 255, 255))
    c.paste(im, (border, border))
    sh = Image.new("RGBA", (c.width + 60, c.height + 60), (0, 0, 0, 0))
    ImageDraw.Draw(sh).rectangle((30, 38, 30 + c.width, 38 + c.height), fill=(0, 0, 0, 90))
    sh = sh.filter(ImageFilter.GaussianBlur(12)); sh.alpha_composite(c, (30, 30))
    return sh.rotate(rot, resample=Image.BICUBIC, expand=True)

def is_land(code):
    with Image.open(f"{J}/IMG_{code}.jpg") as im: return im.width > im.height

TOP = 270
LAYOUT = {3: [(40, TOP, 1000, 750), (40, TOP + 800, 485, 790), (555, TOP + 800, 485, 790)],
          4: [(40, TOP, 485, 780), (555, TOP, 485, 780), (40, TOP + 820, 485, 780), (555, TOP + 820, 485, 780)]}

def collage_scene(codes, header, color):
    slots = LAYOUT[len(codes)]
    if len(codes) == 3:  # la más apaisada arriba
        codes = sorted(codes, key=lambda c: not is_land(c))
        codes = [codes[0]] + codes[1:]
    tiles = []
    for c, (x, y, w, h) in zip(codes, slots):
        p = polaroid(load(c, w, h), rot=random.uniform(-2.5, 2.5))
        tiles.append((p, x + w // 2, y + h // 2))
    return dict(kind="collage", tiles=tiles, header=pill(header, 64, color), dur=4.2)

def medal_scene(code):
    im = Image.open(f"{J}/IMG_{code}.jpg").convert("RGB")
    f = min(960 / im.width, 1420 / im.height)
    im = im.resize((round(im.width * f), round(im.height * f)), Image.LANCZOS)
    return dict(kind="medal", tiles=[(polaroid(im, 18, random.uniform(-2, 2)), W // 2, TOP + 60 + 1420 // 2)],
                header=pill("¡FELICITACIONES, CAMPEONES!", 58, YELLOW), dur=2.7)

def title_scene(lines, dur=1.6):
    return dict(kind="title", pills=[pill(t, s, c) for t, s, c in lines], dur=dur)

def poster_box(box):
    s = W / poster.width
    return poster.resize((W, round(poster.height * s))).crop(tuple(round(v * s) for v in box)).convert("RGBA")

ANTES = "6512 6513 6509 6511 6516 6517 6521 6525 6526".split()
DURANTE = "6533 6535 6536 6549 6550 6552 6556 6558 6559 6563 6573 6574 6578 6581 6585 6588 6589 6590 6592".split()
MEDALLAS = "6600 6603 6608 6613 6615 6620 6622 6627 6609 6617".split()

def chunks(lst):
    out, i = [], 0
    while i < len(lst):
        k = 4 if len(lst) - i == 4 else 3
        out.append(lst[i:i + k]); i += k
    return out

scenes = [dict(kind="cover", logo=poster_box((35, 25, 245, 258)), title=poster_box((285, 15, 795, 305)), dur=3.5)]
scenes.append(title_scene([("EL ANTES", 120, GREEN), ("Así preparamos todo", 54, (255, 255, 255))]))
scenes += [collage_scene(c, "EL ANTES", GREEN) for c in chunks(ANTES)]
scenes.append(title_scene([("EL DURANTE", 120, PINK), ("¡A correr y a jugar!", 54, (255, 255, 255))]))
scenes += [collage_scene(c, "EL DURANTE", PINK) for c in chunks(DURANTE)]
scenes.append(title_scene([("EL FINAL", 120, YELLOW), ("Nuestros medallistas", 54, (255, 255, 255))]))
scenes += [medal_scene(c) for c in MEDALLAS]
g = Image.open(f"{J}/IMG_6629.jpg").convert("RGB"); f = 960 / g.width
scenes.append(dict(kind="medal", tiles=[(polaroid(g.resize((960, round(g.height * f)), Image.LANCZOS), 18, -1.5), W // 2, 900)],
                   header=pill("¡GRACIAS A TODOS!", 70, GREEN), dur=3.5,
                   footer=pill("¡Corremos juntos, crecemos juntos!", 52, PINK)))
scenes.append(dict(kind="poster", dur=4.0))
for sc in scenes: sc["dur"] *= SPEED

def back_ease(x):
    x = min(max(x, 0), 1); c = 1.6
    return 1 + (c + 1) * (x - 1) ** 3 + c * (x - 1) ** 2

def put(fr, im, cx, cy, k=1.0, a=1.0):
    if k <= 0.01 or a <= 0: return
    if k != 1: im = im.resize((max(1, round(im.width * k)), max(1, round(im.height * k))), Image.BILINEAR)
    if a < 1:
        im = im.copy(); im.putalpha(im.getchannel("A").point(lambda v: int(v * a)))
    fr.alpha_composite(im, (round(cx - im.width / 2), round(cy - im.height / 2)))

P = poster.resize((W, round(poster.height * W / poster.width))).convert("RGBA")
def render(sc, t):
    fr = bg.copy(); d = sc["dur"]
    if sc["kind"] == "cover":
        put(fr, sc["logo"], W // 2, 520, 1.7 * back_ease(t / 0.6))
        put(fr, sc["title"], W // 2, 1000, 1.25 * back_ease((t - 0.5) / 0.6))
        put(fr, pill("Escuela Dr. Henoch Domingo Aguiar", 46, (255, 255, 255)), W // 2, 1360, back_ease((t - 1.2) / 0.5))
    elif sc["kind"] == "title":
        y = H // 2 - 80
        for i, p in enumerate(sc["pills"]):
            put(fr, p, W // 2, y, back_ease((t - 0.15 * i) / 0.5)); y += p.height + 40
    elif sc["kind"] == "poster":
        put(fr, P, W // 2, H // 2, 1 + 0.03 * t / d, min(t / 0.5, 1))
    else:
        put(fr, sc["header"], W // 2, 165, back_ease(t / 0.5))
        step = 0.45 if sc["kind"] == "collage" else 0
        for i, (p, cx, cy) in enumerate(sc["tiles"]):
            tt = t - 0.15 - step * i
            put(fr, p, cx, cy, back_ease(tt / 0.55) * (1 + 0.025 * t / d), min(tt / 0.25, 1))
        if "footer" in sc: put(fr, sc["footer"], W // 2, 1640, back_ease((t - 0.8) / 0.5))
    return fr

total = sum(s["dur"] for s in scenes)
print(f"duración: {total:.1f}s, escenas: {len(scenes)}")
ff = subprocess.Popen(["ffmpeg", "-y", "-loglevel", "error", "-f", "rawvideo", "-pix_fmt", "rgb24", "-s", f"{W}x{H}",
    "-r", str(FPS), "-i", "-", "-f", "lavfi", "-i", "anullsrc=r=48000:cl=stereo", "-map", "0:v", "-map", "1:a",
    "-c:v", "libx264", "-pix_fmt", "yuv420p", "-crf", "21", "-c:a", "aac", "-shortest", OUT], stdin=subprocess.PIPE)
XF = 0.35
for si, sc in enumerate(scenes):
    for k in range(round(sc["dur"] * FPS)):
        t = k / FPS
        fr = render(sc, t)
        if t > sc["dur"] - XF and si + 1 < len(scenes):  # fundido a la siguiente escena
            fr = Image.blend(fr, bg, (t - (sc["dur"] - XF)) / XF)
        ff.stdin.write(fr.convert("RGB").tobytes())
ff.stdin.close(); ff.wait()
