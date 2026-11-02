import json,base64,urllib.request,numpy as np,soundfile as sf,sys
sys.path.insert(0,'.')
from script import S
K=__import__('os').environ['GEMINI_API_KEY']
idx=[i for i,e in enumerate(S) if e["type"]=="line" and e["speaker"]=="narr"]
text="\n\n".join(S[i]["text"] for i in idx)
json.dump(idx,open('audio_g/narr_idx.json','w'))
body={"contents":[{"parts":[{"text":text}]}],"generationConfig":{"responseModalities":["AUDIO"],"speechConfig":{"voiceConfig":{"prebuiltVoiceConfig":{"voiceName":sys.argv[1]}}}}}
for m in ["gemini-3.8-flash-tts","gemini-2.5-flash-preview-tts","gemini-3.1-flash-tts-preview","gemini-3.8-flash-lite-tts"]:
    req=urllib.request.Request(f"https://generativelanguage.googleapis.com/v1beta/models/{m}:generateContent",data=json.dumps(body).encode(),headers={"x-goog-api-key":K,"Content-Type":"application/json"})
    try:
        d=json.load(urllib.request.urlopen(req,timeout=300))
        a=np.frombuffer(base64.b64decode(d["candidates"][0]["content"]["parts"][0]["inlineData"]["data"]),dtype="<i2").astype(np.float32)/32768
        sf.write("audio_g/narr_full_raw.wav",a,24000); print("ok",m,len(a)/24000); break
    except urllib.error.HTTPError as e: print(m,e.code)
