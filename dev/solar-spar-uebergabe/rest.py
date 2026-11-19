# Rumpf ohne gewollte Abweichungen; Abschnitt Ähnliche Produkte getrennt. Nur lesend.
import sys
import os,re,collections,difflib
if len(sys.argv) != 3:
    sys.exit('Aufruf: rest.py <alte produkt-Seiten> <neue produkt-Seiten>')
ALT, NEU = sys.argv[1], sys.argv[2]
def teile(p):
    t=open(p,encoding='utf-8',errors='replace').read()
    b=re.split(r'\n---\n',t,1)[1] if '\n---\n' in t else ''
    b=b.replace('http://solar-spar.localhost/','https://solar-spar.de/')
    b=re.sub(r'<p class="mb-0">Lieferzeit[^<]*</p>','',b)
    b=re.sub(r'\{\{< (in-cart|shop-add-to-cart)[^>]*>\}\}','<<WK>>',b)
    b=b.replace('/#focus" class="btn btn-outline-primary">','/" class="btn btn-outline-primary">')
    m=re.search(r'<hr class="my-4">\s*<div class="row mb-5">\s*<div class="col-md-12">\s*<h4 class="mt-2">Ähnliche Produkte</h4>.*?(?=<script|\Z)',b,re.S)
    aehn=m.group(0) if m else ''
    rest=b.replace(aehn,'') if aehn else b
    n=lambda x: re.sub(r'>\s+<','><',re.sub(r'\s+',' ',x)).strip()
    return n(rest),n(aehn)
g=ga=0; c=collections.Counter(); bsp={}; aa=collections.Counter(); absp={}
for f in sorted(os.listdir(ALT)):
    if f=='_index.md': continue
    ra,aa_=teile(os.path.join(ALT,f)); rn,an=teile(os.path.join(NEU,f))
    k=('alt ohne' if not aa_ else 'alt mit')+' / '+('neu ohne' if not an else 'neu mit')+(' gleich' if aa_==an else ' anders')
    aa[k]+=1; absp.setdefault(k,f)
    if ra==rn: g+=1; continue
    A=ra.replace('><','>\n<').split('\n'); N=rn.replace('><','>\n<').split('\n')
    seen=set()
    for z in difflib.unified_diff(A,N,lineterm='',n=0):
        if z[:3] in('---','+++') or z.startswith('@@'): continue
        s=z[0]+' '+re.sub(r'\d+','N',z[1:])[:90]
        if s in seen: continue
        seen.add(s); c[s]+=1; bsp.setdefault(s,(f,z[:260]))
print('Rumpf ohne ähnliche Produkte gleich:',g,'von 22431')
for k,v in aa.most_common(): print(v,k,absp[k][:70])
for s,v in c.most_common(14): print(v,s,'|',bsp[s][0][:60],'|',bsp[s][1][:200])
