# Render del video con le immagini dell'utente: Ken Burns + bocca animata + frasi al centro
import json, sys, subprocess, numpy as np, cv2
from PIL import Image, ImageDraw, ImageFont
D = __import__("os").path.dirname(__import__("os").path.abspath(__file__)); IMG = D + "/imagenes"
W, H, FPS = 1080, 1920, 30
TL = json.load(open(f"{D}/timeline.json")); items = TL["items"]; TOTAL = TL["total"]

# area nitida di ogni immagine (x0,y0,x1,y1): esclude bande sfocate e bordi bianchi
BOX = {1:(92,0,988,1920),2:(0,185,1080,1726),3:(137,0,937,1920),4:(0,0,907,1920),5:(122,0,958,1920),
 6:(90,50,990,1900),7:(143,50,975,1898),8:(136,50,936,1898),9:(105,50,909,1898),10:(120,50,1040,1898),
 11:(0,186,1080,1528),12:(142,0,975,1878),13:(136,0,938,1878),14:(105,0,909,1878),15:(0,185,1080,1485),
 16:(88,0,991,1920),17:(0,185,1080,1536),18:(136,0,936,1920),19:(105,0,909,1920),20:(119,0,961,1920)}
# bocche: (x, y, semilarghezza)
M = {"z4":(557,727,52),"z5":(548,630,48),"z18":(535,537,50),"p6":(700,628,42),"p8":(480,694,24),
     "a12":(658,733,34),"a14":(572,582,30),"a16":(594,604,32),"w10":(866,1000,26),"m17":(190,732,38),"g17":(820,872,28)}
# per ogni elemento della timeline: immagine, fuoco (x,y), zoom iniziale/finale, bocca
SH = [
 (1,(540,1000),1.0,1.18,None),(3,(540,700),1.12,1.0,None),(4,(560,800),1.0,1.15,"z4"),(6,(640,760),1.0,1.12,"p6"),
 (12,(620,820),1.0,1.15,"a12"),(5,(560,900),1.1,1.0,None),(6,(560,1000),1.15,1.0,"p6"),(7,(540,1100),1.0,1.2,None),
 (7,(560,900),1.2,1.35,None),(8,(540,1200),1.0,1.15,None),(8,(600,900),1.15,1.3,None),(9,(520,1100),1.0,1.12,None),
 (8,(500,800),1.25,1.45,"p8"),(10,(830,1000),1.55,1.7,"w10"),(12,(640,760),1.2,1.35,"a12"),(11,(540,800),1.0,1.25,None),
 (13,(540,1150),1.0,1.15,None),(15,(540,900),1.0,1.15,None),(16,(560,800),1.0,1.12,"a16"),(14,(560,800),1.0,1.15,"a14"),
 (17,(330,860),1.0,1.1,"m17"),(20,(540,1300),1.0,1.15,None),(17,(760,900),1.0,1.1,"g17"),(9,(500,1000),1.12,1.0,None),
 (2,(540,950),1.0,1.15,None),(18,(520,700),1.0,1.12,"z18"),(19,(500,900),1.0,1.1,None),(5,(560,760),1.0,1.15,"z5"),
 (19,(500,800),1.1,1.0,None),(12,(620,860),1.05,1.2,"a12"),(6,(620,800),1.0,1.12,"p6"),(1,(540,800),1.15,1.0,None),
 (2,(540,900),1.12,1.0,None),(20,(540,1000),1.0,1.15,None),(16,(500,1500),1.1,1.25,None),(20,(540,1350),1.2,1.35,None),
 (15,(540,800),1.0,1.15,None),(19,(500,900),1.0,1.1,None),(17,(540,900),1.0,1.1,None),(15,(540,900),1.1,1.0,None),
 (1,(540,1000),1.0,1.1,None),(1,(540,900),1.1,1.2,None)]
assert len(SH) == len(items), (len(SH), len(items))
SPK = {"z4":"zeus","z5":"zeus","z18":"zeus","p6":"pos","p8":"pos","a12":"atena","a14":"atena","a16":"atena","w10":"ab2","m17":"ab1","g17":"ab3"}

imgs = {i: cv2.imread(f"{IMG}/escena_{i:02d}.jpg") for i in range(1, 21)}
bounds = []
for k, it in enumerate(items):
    a = 0 if k == 0 else (items[k-1]["end"] + it["start"]) / 2
    b = (it["end"] + items[k+1]["start"]) / 2 if k + 1 < len(items) else TOTAL + 1
    bounds.append((a, b))
def shot_at(t):
    for k, (a, b) in enumerate(bounds):
        if a <= t < b: return k
    return len(items) - 1
def ease(x): x = min(1, max(0, x)); return x * x * (3 - 2 * x)
def talk(t, who):
    for it in items:
        if it["type"] == "line" and it["start"] <= t < it["end"] and it["speaker"] == who:
            return it["env"][min(len(it["env"]) - 1, int((t - it["start"]) * FPS))]
    return 0.0

def mouth_warp(img, m, amt):
    if amt < 0.03: return img
    mx, my, w = m; A = 0.42 * w * amt
    x0, x1 = int(mx - 2 * w), int(mx + 2 * w); y0, y1 = int(my - 0.5 * w), int(my + 2.6 * w)
    roi = img[y0:y1, x0:x1]
    hh, ww = roi.shape[:2]
    xs, ys = np.meshgrid(np.arange(ww, dtype=np.float32), np.arange(hh, dtype=np.float32))
    dx = (xs + x0 - mx) / (1.7 * w); fx = np.clip(np.cos(np.clip(dx, -1, 1) * np.pi / 2), 0, 1) ** 1.5
    yy = (ys + y0 - my) / w
    fy = np.where(yy < -0.1, 0, np.where(yy < 0.25, (yy + 0.1) / 0.35, np.clip(1 - (yy - 0.25) / 2.2, 0, 1)))
    d = A * fx * fy
    out = img.copy()
    out[y0:y1, x0:x1] = cv2.remap(roi, xs, ys - d.astype(np.float32), cv2.INTER_LINEAR, borderMode=cv2.BORDER_REFLECT)
    # leggero scuro dentro la bocca
    dk = np.exp(-(dx / 0.55) ** 2 - ((yy - 0.18 * amt) / (0.08 + 0.18 * amt)) ** 2) * 0.45 * amt
    reg = out[y0:y1, x0:x1].astype(np.float32) * (1 - dk[..., None]); out[y0:y1, x0:x1] = reg.astype(np.uint8)
    return out

def frame_of(k, t, shake=0):
    imgn, (fx, fy), z0, z1, mk = SH[k]
    a, b = bounds[k]; p = ease((t - a) / (b - a))
    src = imgs[imgn]
    if mk:
        amt = talk(t, SPK[mk]); src = mouth_warp(src, M[mk], amt)
    bx0, by0, bx1, by1 = BOX[imgn]; bw, bh = bx1 - bx0, by1 - by0
    vw = min(bw, bh * W / H); vh = vw * H / W
    z = z0 + (z1 - z0) * p
    z *= 1 + 0.006 * np.sin(t * 1.7)          # respiro
    vw /= z; vh /= z
    cx = min(max(fx, bx0 + vw / 2), bx1 - vw / 2); cy = min(max(fy, by0 + vh / 2), by1 - vh / 2)
    s = W / vw
    Mx = np.float32([[s, 0, W / 2 - cx * s + shake * np.sin(t * 83)], [0, s, H / 2 - cy * s + shake * np.cos(t * 71)]])
    return cv2.warpAffine(src, Mx, (W, H), flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_REFLECT)

font_cache = {}
def font(sz):
    if sz not in font_cache: font_cache[sz] = ImageFont.truetype(f"{D}/mont800.ttf", sz)
    return font_cache[sz]
def chunks(text):
    w = text.split(); out = []; cur = []
    for x in w:
        c = " ".join(cur + [x])
        if cur and (len(c) > 20 or len(cur) >= 4): out.append(" ".join(cur)); cur = [x]
        else: cur.append(x)
    if cur: out.append(" ".join(cur))
    return out
cap_cache = {}
def caption_img(txt, big=False):
    key = (txt, big)
    if key in cap_cache: return cap_cache[key]
    sz = 150 if big else 80
    f = font(sz)
    while f.getlength(txt) > 960 and sz > 50: sz -= 2; f = font(sz)
    tw = int(f.getlength(txt)) + 60; th = sz + 70
    im = Image.new("RGBA", (tw, th), (0, 0, 0, 0)); d = ImageDraw.Draw(im)
    sh = Image.new("RGBA", (tw, th), (0, 0, 0, 0)); ds = ImageDraw.Draw(sh)
    ds.text((30, 38), txt, font=f, fill=(0, 0, 0, 200), stroke_width=10, stroke_fill=(0, 0, 0, 200))
    from PIL import ImageFilter
    sh = sh.filter(ImageFilter.GaussianBlur(10))
    d.text((30, 32), txt, font=f, fill=(255, 255, 255, 255), stroke_width=7, stroke_fill=(0, 0, 0, 230))
    out = Image.alpha_composite(sh, im)
    arr = np.asarray(out).astype(np.float32) / 255
    cap_cache[key] = arr; return arr
def draw_caption(frame, t):
    l = None
    for it in items:
        if it["type"] == "line" and it["start"] - 0.05 <= t < it["end"] + 0.25: l = it; break
    if not l: return frame
    cs = chunks(l["text"]); tot = sum(len(c) + 3 for c in cs); dur = l["end"] - l["start"]
    acc = 0; ci = len(cs) - 1; cst = l["start"]
    for i, c in enumerate(cs):
        dd = (len(c) + 3) / tot * dur
        if t < l["start"] + acc + dd: ci = i; cst = l["start"] + acc; break
        acc += dd
    big = l.get("event") == "fine"
    arr = caption_img(cs[ci], big)
    k = min(1, max(0, (t - cst) / 0.12)); sc = 0.85 + 0.15 * (1 + 2.7 * (k - 1) ** 3 + 1.7 * (k - 1) ** 2)
    alpha = min(1, k * 2) * min(1, max(0, (l["end"] + 0.25 - t) / 0.15))
    if sc != 1: arr = cv2.resize(arr, (max(1, int(arr.shape[1] * sc)), max(1, int(arr.shape[0] * sc))), interpolation=cv2.INTER_LINEAR)
    h, w = arr.shape[:2]; cy = 960 if big else 1240
    x0 = W // 2 - w // 2; y0 = cy - h // 2
    xa, ya = max(0, x0), max(0, y0); xb, yb = min(W, x0 + w), min(H, y0 + h)
    sub = arr[ya - y0:yb - y0, xa - x0:xb - x0]
    a = sub[..., 3:4] * alpha
    reg = frame[ya:yb, xa:xb].astype(np.float32)
    rgb = sub[..., [2, 1, 0]] * 255
    frame[ya:yb, xa:xb] = (reg * (1 - a) + rgb * a).astype(np.uint8)
    return frame

vig = None
def render(t):
    global vig
    k = shot_at(t); it = items[k]
    shake = 0
    if it.get("event") == "trident":
        lt = t - it["start"]
        if 0.9 < lt < 1.6: shake = (1.6 - lt) * 25
    f = frame_of(k, t, shake)
    a, b = bounds[k]
    if k > 0 and t - a < 0.2:                 # dissolvenza breve tra inquadrature
        g = frame_of(k - 1, t); al = (t - a) / 0.2
        f = cv2.addWeighted(f, al, g, 1 - al, 0)
    if it.get("event") == "trident":
        lt = t - it["start"]
        if 0.9 < lt < 1.3: f = cv2.addWeighted(f, 1, np.full_like(f, 255), (1.3 - lt) * 1.2, 0)
    if vig is None:
        yy, xx = np.mgrid[0:H, 0:W]; r = np.sqrt(((xx - W / 2) / W) ** 2 + ((yy - H / 2) / H) ** 2)
        vig = (1 - 0.45 * np.clip((r - 0.3) / 0.45, 0, 1) ** 2)[..., None].astype(np.float32)
    f = (f.astype(np.float32) * vig).astype(np.uint8)
    f = draw_caption(f, t)
    if t < 0.5: f = (f * (t / 0.5)).astype(np.uint8)
    if t > TOTAL - 1.2: f = (f * max(0, (TOTAL - t) / 1.2)).astype(np.uint8)
    return f

if __name__ == "__main__":
    if sys.argv[1] == "preview":
        for t in map(float, sys.argv[2:]): cv2.imwrite(f"{D}/prev/i_{t}.jpg", render(t))
    else:
        w, nw = int(sys.argv[1]), int(sys.argv[2]); N = int(TOTAL * FPS)
        a, b = N * w // nw, N * (w + 1) // nw
        ff = subprocess.Popen(["ffmpeg", "-y", "-loglevel", "error", "-f", "rawvideo", "-pix_fmt", "bgr24", "-s", f"{W}x{H}", "-r", str(FPS), "-i", "-",
                               "-c:v", "libx264", "-preset", "medium", "-crf", "18", "-pix_fmt", "yuv420p", f"{D}/iseg_{w}.mp4"], stdin=subprocess.PIPE)
        for fr in range(a, b): ff.stdin.write(render(fr / FPS).tobytes())
        ff.stdin.close(); ff.wait(); print("done", w)
