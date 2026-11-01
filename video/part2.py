import subprocess, os
from PIL import Image, ImageFilter, ImageDraw, ImageFont
U = "/root/.claude/uploads/2811ebac-11bf-5872-8f5d-d23ba1e951ea/"
V = "/home/user/UCC/.work/vids/"
OUT = "/home/user/UCC/.work/p2/"
W, H, G = 1080, 1920, 8
FONT = "/usr/share/fonts/opentype/inter/InterDisplay-Black.otf"
def ph(n): return ("img", U + n + "-image.jpg")
def vd(n, ss, d=5): return ("vid", V + f"v{n}.mov", ss, d)

# --- placas de inicio y cierre, armadas con piezas del afiche ---
poster = Image.open(U + "a7eee805-image.jpg").convert("RGB")
s = W / poster.width; P = poster.resize((W, round(poster.height * s)), Image.LANCZOS)
def crop(b): return P.crop(tuple(round(v * s) for v in b))
bg = Image.blend(poster.resize((W, H)).filter(ImageFilter.GaussianBlur(30)), Image.new("RGB", (W, H), (255, 252, 240)), 0.45)
def place(fr, im, cy, k=1.0):
    im = im.resize((round(im.width * k), round(im.height * k)), Image.LANCZOS)
    fr.paste(im, ((W - im.width) // 2, cy - im.height // 2))
def pill(fr, text, cy, size, color):
    f = ImageFont.truetype(FONT, size); tw = f.getlength(text); bb = f.getbbox(text)
    w, h = int(tw) + 90, bb[3] + 56; x, y = (W - w) // 2, cy - h // 2
    ImageDraw.Draw(fr).rounded_rectangle((x, y, x + w, y + h), radius=h // 2, fill=color)
    ImageDraw.Draw(fr).text((x + 45, y + 26), text, font=f, fill=(24, 64, 40))
logo = crop((35, 25, 245, 258)); title = crop((285, 15, 795, 305))
start = bg.copy(); place(start, logo, 560, 1.8); place(start, title, 1080, 1.3); pill(start, "PARTE 2", 1420, 84, (244, 143, 177))
start.save(OUT + "inicio.jpg", quality=94)
end = bg.copy(); place(end, logo, 400, 1.5); place(end, crop((185, 335, 870, 560)), 830, 1.35)
place(end, crop((215, 1165, 805, 1355)), 1260, 1.3); place(end, crop((285, 1365, 785, 1462)), 1620, 1.35)
end.save(OUT + "gracias.jpg", quality=94)

PLAN = [
    [("img", OUT + "inicio.jpg")],
    [ph("a03ac497"), ph("5ea0ac06")],            # preparativos
    [ph("2ceb9018"), ph("700946f1")],
    [ph("ec45277c"), ph("37b2df42")],            # calentamiento
    [vd(6, 7), vd(7, 4)],
    [vd(8, 14), vd(9, 1)],
    [ph("5d921f0c"), ph("070128f9")],            # salida
    [vd(3, 1)],
    [vd(1, 0, 4.79)],
    [ph("ba7dbbb6"), ph("6d857504")],            # carrera y postas
    [vd(2, 0), vd(4, 1.5)],
    [vd(5, 2.5), vd(2, 6.5)],
    [vd(4, 7.5), vd(5, 13)],
    [ph("78921a4f"), ph("bcf7e892")],            # mural
    [("img", OUT + "gracias.jpg")],
]
enc = ["-r", "30", "-pix_fmt", "yuv420p", "-c:v", "libx264", "-crf", "20", "-an"]
def cover(w, h): return f"scale={w}:{h}:force_original_aspect_ratio=increase,crop={w}:{h},setsar=1"
names = []
for i, items in enumerate(PLAN):
    n = len(items); hs = [(H - G * (n - 1)) // n] * n; hs[-1] = H - G * (n - 1) - sum(hs[:-1])
    vids = [it[3] for it in items if it[0] == "vid"]
    d = min(vids) if vids else (3.5 if "gracias" in items[0][1] else 2.5 if "inicio" in items[0][1] else 3)
    ins, fl = [], []
    for k, it in enumerate(items):
        ins += (["-loop", "1", "-t", str(d), "-i", it[1]] if it[0] == "img" else ["-ss", str(it[2]), "-t", str(it[3]), "-i", it[1]])
        fl.append(f"[{k}:v]{cover(W, hs[k])}" + (f",pad={W}:{hs[k] + G}:0:0:white" if k < n - 1 else "") + f"[p{k}]")
    f = ";".join(fl) + ";" + ("".join(f"[p{k}]" for k in range(n)) + f"vstack={n}[v]" if n > 1 else "[p0]null[v]")
    out = f"{OUT}{i:03d}.mp4"; names.append(out)
    subprocess.run(["ffmpeg", "-loglevel", "error", "-y", *ins, "-filter_complex", f, "-map", "[v]", "-t", str(d), *enc, out], check=True)
open(OUT + "list.txt", "w").write("".join(f"file '{x}'\n" for x in names))
