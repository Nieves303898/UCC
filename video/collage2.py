import os, sys, math
from PIL import Image, ImageFilter, ImageEnhance
J, OUT = sys.argv[1], sys.argv[2]
W, H, GAP = 1080, 1920, 8
SKIP = {"6510", "6514", "6524"}  # repetidas
codes = sorted(f[4:8] for f in os.listdir(J) if f[4:8] not in SKIP)
def img(c): return Image.open(f"{J}/IMG_{c}.jpg").convert("RGB")
def cover(im, w, h):
    s = max(w / im.width, h / im.height)
    im = im.resize((math.ceil(im.width * s), math.ceil(im.height * s)), Image.LANCZOS)
    x, y = (im.width - w) // 2, (im.height - h) // 2
    return im.crop((x, y, x + w, y + h))
# pantallas en orden cronológico: horizontales de a dos (arriba/abajo), verticales solas
ORDER = """6511 | 6509 6512 | 6513 6516 | 6517 6521 | 6525 6526 | 6533 6535 | 6536 6549 | 6550 6552 |
6556 6558 | 6559 6563 | 6573 | 6574 | 6578 6581 | 6585 6588 | 6589 | 6590 | 6592 | 6600 | 6603 | 6608 |
6609 6617 | 6613 | 6615 | 6620 | 6622 | 6627 | 6629"""
screens = [("H", *g.split()) if len(g.split()) == 2 else ("V", g.strip()) for g in ORDER.replace("\n", " ").split("|")]
assert sorted(c for sc in screens for c in sc[1:]) == codes
for i, s in enumerate(screens):
    if s[0] == "H":
        fr = Image.new("RGB", (W, H), "white"); h = (H - GAP) // 2
        fr.paste(cover(img(s[1]), W, h), (0, 0)); fr.paste(cover(img(s[2]), W, H - h - GAP), (0, h + GAP))
    else:
        im = img(s[1])
        fr = ImageEnhance.Brightness(cover(im, W, H).filter(ImageFilter.GaussianBlur(40))).enhance(0.7)
        f = min(W / im.width, H / im.height); im = im.resize((round(im.width * f), round(im.height * f)), Image.LANCZOS)
        fr.paste(im, ((W - im.width) // 2, (H - im.height) // 2))
    fr.save(f"{OUT}/{i:03d}.jpg", quality=92)
    print(i, s)
