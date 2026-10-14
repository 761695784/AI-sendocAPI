"""Contrôle local sans le modèle (faux modèle = sinusoïde) :  python test_local.py .   — nécessite fastapi, soundfile, numpy, httpx, ffmpeg."""
import os, sys, types
os.environ["DOCTRACK_TTS_NO_LOAD"]="1"; os.environ["DOCTRACK_API_KEY"]="k"
sys.path.insert(0, sys.argv[1])
import numpy as np, app
assert app.wolof_number(0)=="tus" and app.wolof_number(10)=="fukk" and app.wolof_number(11)=="fukk ak benn"
assert app.wolof_number(25)=="ñaar-fukk ak juróom" and app.wolof_number(100)=="téeméer" and app.wolof_number(3000)=="ñett junni"
assert app.wolof_number(1250)=="junni ak ñaar téeméer ak juróom-fukk"
n=app.normalize("Salaam, maa ngi tudd assistant DocTrack. Lan nga bëgg def ?  Fey 3 000 FCFA, woo 771234567.")
print(n)
assert "Dok Trak" in n and "def?" in n and "ñett junni" in n and "juróom-ñaar, juróom-ñaar, benn" in n
long=" ".join(["Mën naa la dimbali nga yégle kayit bu la réer, walla nga wax ab kayit bu nga gis."]*5)
L=app.SEGMENT_CHARS
segs=app.split_segments(long); print([len(s) for s in segs]); assert all(len(s)<=L for s in segs) and " ".join(segs)==long
w="a"*90+" "+"b"*150+" "+"c"*120; s2=app.split_segments(w); assert all(len(s)<=L for s in s2), [len(x) for x in s2]
assert app.normalize("Waaw")=="Waaw." and app.normalize("Lan la ?")=="Lan la?"
assert app.is_complete("x"*100, 5.0) and not app.is_complete("x"*100, 2.0)
assert app.split_segments("Waaw.")==["Waaw."]
# point d'accès avec un faux modèle (sinusoïde)
calls=[]
def fake(segment, description, seed):
    calls.append((segment, seed)); t=np.linspace(0,1,24000,dtype=np.float32)
    return np.concatenate([np.zeros(6000,dtype=np.float32), 0.5*np.sin(2*np.pi*220*t), np.zeros(6000,dtype=np.float32)])
app._generate_once=fake; app._model=types.SimpleNamespace(config=types.SimpleNamespace(sampling_rate=24000))
from fastapi.testclient import TestClient
c=TestClient(app.app)
assert c.get("/").json()["loaded"] is True
assert c.post("/synthesize", json={"text":"Waaw"}).status_code==401
h={"Authorization":"Bearer k"}
r=c.post("/synthesize", json={"text":long}, headers=h); print(r.status_code, r.headers["content-type"], len(r.content), dict((k,v) for k,v in r.headers.items() if k.startswith("x-")))
assert r.status_code==200 and r.content[:3] in (b"ID3", b"\xff\xfb", b"\xff\xf3") and len(calls)>=len(segs) and calls[0][1]==42
assert c.post("/synthesize", json={"text":"x"*700}, headers=h).status_code==413
assert c.post("/synthesize", json={"text":"Bonjour","lang":"fr"}, headers=h).status_code==422
assert c.post("/synthesize", json={"text":"Waaw","format":"wav"}, headers=h).content[:4]==b"RIFF"
# phrase coupée par le modèle : nouvelle tentative avec une autre graine, on garde la plus complète
tries=[]
def cut(segment, description, seed):
    tries.append(seed); n=24000*(1 if seed==42 else 6); return 0.5*np.sin(2*np.pi*220*np.linspace(0,1,n,dtype=np.float32))
app._generate_once=cut
r=c.post("/synthesize", json={"text":"Mën naa la dimbali nga yégle kayit bu la réer, walla nga wax ab kayit bu nga gis."}, headers=h)
assert tries==[42,43] and float(r.headers["x-duration-s"])>5, (tries, r.headers["x-duration-s"])
print("OK")
