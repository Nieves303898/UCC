import json,base64,urllib.request,glob,os,subprocess,sys,time
K=__import__('os').environ['GEMINI_API_KEY']
def tr(path):
    mp3=path.replace('.wav','.mp3'); subprocess.run(['ffmpeg','-y','-loglevel','error','-i',path,'-b:a','64k',mp3],check=True)
    body={"contents":[{"parts":[{"text":"Trascrivi esattamente parola per parola cosa viene detto in questo audio (in italiano). Indica anche, tra parentesi quadre, eventuali pause lunghe o suoni strani. Solo la trascrizione."},{"inline_data":{"mime_type":"audio/mp3","data":base64.b64encode(open(mp3,'rb').read()).decode()}}]}]}
    for m in ["gemini-3.8-flash","gemini-3.7-flash","gemini-3.6-flash","gemini-3.5-flash","gemini-3-flash-preview","gemini-flash-latest","gemini-3.1-flash-lite","gemini-flash-lite-latest"]:
        req=urllib.request.Request(f"https://generativelanguage.googleapis.com/v1beta/models/{m}:generateContent",data=json.dumps(body).encode(),headers={"x-goog-api-key":K,"Content-Type":"application/json"})
        try: d=json.load(urllib.request.urlopen(req,timeout=120)); return d["candidates"][0]["content"]["parts"][0]["text"].strip()
        except urllib.error.HTTPError as e: last=e.code
        except Exception as e: last=str(e)
    return f"ERR {last}"
if __name__=="__main__":
 for f in sys.argv[1:]:
  print(os.path.basename(f),'=>',tr(f).replace('\n',' / '),flush=True)
