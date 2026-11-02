import json, subprocess, os, sys, time, base64, urllib.request, numpy as np, soundfile as sf
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from script import S
D = os.path.dirname(os.path.abspath(__file__)); AD = D + "/audio_g"; os.makedirs(AD, exist_ok=True)
K = __import__('os').environ['GEMINI_API_KEY']
SR = 24000; FPS = 30
# (voce Gemini, stile, semitoni extra)
V = {
 "narr":  ("Charon", "narratore", 0),
 "zeus":  ("Algenib",   "come Zeus, il re degli dèi: voce profonda, potente, solenne e maestosa, lenta", -1.5),
 "pos":   ("Orus",      "come Poseidone, il dio del mare: voce forte, fiera e tonante", -1.0),
 "atena": ("Kore",      "come Atena, la dea della saggezza: voce calma, nobile, sicura e gentile", 0),
 "ab1":   ("Puck",      "come un uomo del popolo stupito ed entusiasta", 0),
 "ab2":   ("Aoede",     "come una donna del popolo, riflessiva e gentile", 0),
 "ab3":   ("Zephyr",    "come una ragazza giovane, allegra e decisa", 1.0),
 "olivo": ("Achernar",  "come un piccolo albero tenero e dolce, con voce morbida e lenta", 2.0),
 "b1":    ("Laomedeia", "come una bambina allegra di sei anni", 2.5),
 "b2":    ("Fenrir",    "come un bambino vivace di sei anni", 3.5),
 "b3":    ("Autonoe",   "come una bambina entusiasta di sei anni", 2.5),
}
CHORUS = {"abitanti": [("Puck","con entusiasmo, gridando di gioia",0),("Aoede","con entusiasmo, gridando di gioia",0),("Zephyr","con entusiasmo, gridando di gioia",1.0)],
          "tutti": [("Leda","cantilenando allegramente come in una filastrocca per bambini, con una pausa lunga dopo ogni verso",2.0),("Laomedeia","cantilenando allegramente come in una filastrocca per bambini, con una pausa lunga dopo ogni verso",2.5),("Fenrir","cantilenando allegramente come in una filastrocca per bambini, con una pausa lunga dopo ogni verso",3.5)]}
SONG = [e["text"] for e in S if e.get("verse")]
def song_part(v, st, j, verse):
    out = f"{AD}/song_{v}_{verse}_raw.wav"
    if os.path.exists(out): return out
    full = call(v, st, "\n".join(SONG), f"{AD}/song_{v}_full_raw.wav")
    a,_ = sf.read(full); hop = SR//50
    en = np.array([np.sqrt(np.mean(a[k:k+hop]**2)) for k in range(0, len(a)-hop, hop)])
    sil = en < max(en)*0.03; gaps=[]; k=0
    while k < len(sil):
        if sil[k]:
            k0=k
            while k < len(sil) and sil[k]: k+=1
            if k0>0 and k<len(sil): gaps.append((k-k0,(k0+k)//2))
        else: k+=1
    cuts = sorted(c for _,c in sorted(gaps, reverse=True)[:len(SONG)-1])
    edges = [0]+[c*hop for c in cuts]+[len(a)]
    print("song", v, "cuts", [round(c*hop/SR,2) for c in cuts], flush=True)
    for q in range(len(SONG)): sf.write(f"{AD}/song_{v}_{q+1}_raw.wav", a[edges[q]:edges[q+1]], SR)
    return out
def call(voice, style, text, path):
    if os.path.exists(path): return path
    body = {"contents":[{"parts":[{"text":f"Leggi in italiano, {style}: {text}"}]}],
            "generationConfig":{"responseModalities":["AUDIO"],"speechConfig":{"voiceConfig":{"prebuiltVoiceConfig":{"voiceName":voice}}}}}
    for attempt in range(12):
        for model in ["gemini-3.8-flash-tts","gemini-2.5-flash-preview-tts","gemini-3.1-flash-tts-preview","gemini-3.8-flash-lite-tts"]:
            req = urllib.request.Request(f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent",
                data=json.dumps(body).encode(), headers={"x-goog-api-key":K,"Content-Type":"application/json"})
            try:
                d = json.load(urllib.request.urlopen(req, timeout=180))
                pcm = base64.b64decode(d["candidates"][0]["content"]["parts"][0]["inlineData"]["data"])
                a = np.frombuffer(pcm, dtype="<i2").astype(np.float32)/32768
                sf.write(path, a, SR); print("ok", os.path.basename(path), model, flush=True); return path
            except urllib.error.HTTPError as e:
                print("http", e.code, model, os.path.basename(path), flush=True)
            except Exception as e:
                print("err", e, flush=True)
        time.sleep(30)
    raise SystemExit("rate limit: riprovare più tardi")
def proc(raw, out, semi):
    flt = (f"rubberband=pitch={2**(semi/12):.5f}," if semi else "") + "silenceremove=stop_periods=-1:stop_duration=0.3:stop_threshold=-30dB,atempo=1.06,silenceremove=start_periods=1:start_threshold=-45dB,areverse,silenceremove=start_periods=1:start_threshold=-45dB,areverse"
    subprocess.run(["ffmpeg","-y","-loglevel","error","-i",raw,"-af",flt,"-ar",str(SR),"-ac","1",out],check=True)
    a,_=sf.read(out); return a
def thick(a, semi, delay):
    import tempfile
    sf.write("./_t.wav", a, SR)
    b = proc("./_t.wav", "./_t2.wav", semi)
    out = np.zeros(max(len(a), len(b)+int(delay*SR))); out[:len(a)] += a; out[int(delay*SR):int(delay*SR)+len(b)] += b*0.7
    return out
SONGMAP = {1:[("Fenrir",3.5)],2:[("Fenrir",3.5)],3:[("Laomedeia",2.5)],4:[("Fenrir",3.5),("Laomedeia",2.5)]}
def line(i, e):
    sp = e["speaker"]
    if sp == "tutti":
        parts=[proc(f"{AD}/song_{v}_{e['verse']}_trim.wav", f"{AD}/{i:02d}_tutti_{v}.wav", semi) for v,semi in SONGMAP[e["verse"]]]
        n=max(len(p) for p in parts); mix=np.zeros(n)
        for p in parts: mix[:len(p)]+=p
        return thick(mix, 2.5, 0.035)
    if sp == "abitanti":
        parts=[proc(f"{AD}/{i:02d}_abitanti{j}_trim.wav", f"{AD}/{i:02d}_abitanti{j}.wav", semi) for j,semi in [(0,0),(2,1.0)]]
        n=max(len(p) for p in parts)+int(.06*SR); mix=np.zeros(n); mix[:len(parts[0])]+=parts[0]; o=int(.04*SR); mix[o:o+len(parts[1])]+=parts[1]
        return thick(mix, 3.0, 0.05)
    v,st,semi = V[sp]
    return proc(f"{AD}/{i:02d}_{sp}_trim.wav", f"{AD}/{i:02d}_{sp}.wav", semi)
def line_old(i, e):
    sp = e["speaker"]; txt = e["text"]
    if sp in CHORUS:
        parts=[]
        for j,(v,st,semi) in enumerate(CHORUS[sp]):
            raw = song_part(v,st,j,e["verse"]) if sp=="tutti" else call(v,st,txt,f"{AD}/{i:02d}_{sp}{j}_raw.wav")
            parts.append(proc(raw, f"{AD}/{i:02d}_{sp}{j}.wav", semi))
        n=max(len(p) for p in parts)+int(.08*SR); mix=np.zeros(n); rng=np.random.default_rng(i)
        for p in parts: o=int(rng.uniform(0,.05)*SR); mix[o:o+len(p)]+=p
        return mix
    v,st,semi = V[sp]
    if e.get("event")=="fine": st="con voce dolce, come alla fine di una fiaba"
    return proc(call(v,st,txt,f"{AD}/{i:02d}_{sp}_raw.wav"), f"{AD}/{i:02d}_{sp}.wav", semi)
timeline=[]; segs=[]; t=0.0
for i,e in enumerate(S):
    e=dict(e)
    if e["type"]=="line":
        a=line(i,e); a=a/(np.max(np.abs(a))+1e-9)*0.9
        hop=SR//FPS; env=[float(np.sqrt(np.mean(a[j:j+hop]**2))) for j in range(0,len(a),hop)]
        m=max(env) or 1; e["env"]=[round(min(1,x/m*1.4),3) for x in env]
        e["start"]=t+0.15; e["end"]=e["start"]+len(a)/SR; segs.append((e["start"],a)); t=e["end"]+0.35
    else:
        e["start"]=t; e["end"]=t+e["dur"]; t=e["end"]
    timeline.append(e)
total=t+0.3; voice=np.zeros(int(total*SR)+SR)
for st,a in segs: o=int(st*SR); voice[o:o+len(a)]+=a
os.makedirs(D+"/audio",exist_ok=True); sf.write(D+"/audio/voice.wav",voice,SR)
json.dump({"total":total,"fps":FPS,"items":timeline},open(D+"/timeline.json","w"),ensure_ascii=False)
print("DONE total",round(total,1))
