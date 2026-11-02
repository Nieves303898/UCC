import json,numpy as np,soundfile as sf,sys
sys.path.insert(0,'.')
from script import S
exec(open('trim.py').read().split('def nearest')[0].split('SR=24000')[1].join(['SR=24000\n','']) if False else '')
SR=24000
def gaps(a,minlen=0.25):
    hop=SR//100; en=np.array([np.sqrt(np.mean(a[k:k+hop]**2)) for k in range(0,len(a)-hop,hop)])
    sil=en<max(en)*0.03; out=[];k=0
    while k<len(sil):
        if sil[k]:
            k0=k
            while k<len(sil) and sil[k]:k+=1
            if k0>0 and k<len(sil) and (k-k0)/100>=minlen: out.append(((k0+k)/2/100,(k-k0)/100))
        else:k+=1
    return out
idx=json.load(open('audio_g/narr_idx.json')); L=[len(S[i]["text"]) for i in idx]
a,_=sf.read('audio_g/narr_full_raw.wav'); d=len(a)/SR; g=gaps(a)
# scegli i n-1 gap più lunghi che siano vicini ai confini attesi
cands=sorted(g,key=lambda x:-x[1])[:len(idx)+6]
tot=sum(L); cuts=[]
for q in range(1,len(idx)):
    exp=sum(L[:q])/tot*d
    c=min([x for x in cands if all(abs(x[0]-y)>0.5 for y in cuts)],key=lambda x:abs(x[0]-exp)/1.0-x[1]*2)
    cuts.append(c[0])
cuts=sorted(cuts); edges=[0]+[int(c*SR) for c in cuts]+[len(a)]
print("gaps",[(round(p,1),round(l,2)) for p,l in sorted(cands)])
print("cuts",[round(c,1) for c in cuts])
for q,i in enumerate(idx): sf.write(f"audio_g/{i:02d}_narr_trim.wav",a[edges[q]:edges[q+1]],SR)
