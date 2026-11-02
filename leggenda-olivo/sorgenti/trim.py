import numpy as np, soundfile as sf, glob, os, re, sys
sys.path.insert(0,'.')
import importlib.util
spec=importlib.util.spec_from_file_location("b","build_audio_g.py")
src=open("build_audio_g.py").read().split("def call(")[0]   # solo dati
ns={"__file__":os.path.abspath("build_audio_g.py")}; exec(src,ns); V,CH,S=ns["V"],ns["CHORUS"],ns["S"]
SR=24000
def gaps(a,minlen=0.18):
    hop=SR//100; en=np.array([np.sqrt(np.mean(a[k:k+hop]**2)) for k in range(0,len(a)-hop,hop)])
    sil=en<max(en)*0.04; out=[];k=0
    while k<len(sil):
        if sil[k]:
            k0=k
            while k<len(sil) and sil[k]:k+=1
            if k0>0 and k<len(sil) and (k-k0)/100>=minlen: out.append(((k0+k)/2/100,(k-k0)/100))
        else:k+=1
    return out
def nearest(a,frac):
    g=gaps(a); d=len(a)/SR
    if not g: return None
    return min(g,key=lambda x:abs(x[0]-frac*d))[0]
report=[]
for i,e in enumerate(S):
    if e["type"]!="line": continue
    sp=e["speaker"];txt=e["text"]
    vs=[(f"{i:02d}_{sp}{j}",st) for j,(v,st,_) in enumerate(CH[sp])] if sp in CH else [(f"{i:02d}_{sp}",V[sp][1] if e.get("event")!="fine" else "con voce dolce, come alla fine di una fiaba")]
    if sp=="tutti": continue
    for base,st in vs:
        p=f"audio_g/{base}_raw.wav"
        if not os.path.exists(p): continue
        a,_=sf.read(p); d=len(a)/SR; Ni=len("Leggi in italiano, "+st+": "); Nt=len(txt)
        exp=Nt*0.075+0.8
        if d>exp*1.45 and d>3:
            c=nearest(a,Ni/(Ni+Nt))
            sf.write(f"audio_g/{base}_trim.wav",a[int(c*SR):],SR); report.append(base); print("cut",base,round(d,1),"->",round(d-c,1))
        else:
            sf.write(f"audio_g/{base}_trim.wav",a,SR)
# canzone: Fenrir (pulito) e Laomedeia (con istruzione)
song=[e["text"] for e in S if e.get("verse")]; L=[len(x) for x in song]
for v,instr in [("Fenrir",""),("Laomedeia","Leggi in italiano, "+CH["tutti"][1][1]+": ")]:
    a,_=sf.read(f"audio_g/song_{v}_full_raw.wav")
    if instr:
        c=nearest(a,len(instr)/(len(instr)+sum(L))); a=a[int(c*SR):]
    tot=sum(L); cuts=[nearest(a,sum(L[:q])/tot) for q in (1,2,3)]
    edges=[0]+[int(c*SR) for c in cuts]+[len(a)]
    print("song",v,[round(c,2) for c in cuts],round(len(a)/SR,1))
    for q in range(4): sf.write(f"audio_g/song_{v}_{q+1}_trim.wav",a[edges[q]:edges[q+1]],SR)
open("trimmed.txt","w").write("\n".join(report))
