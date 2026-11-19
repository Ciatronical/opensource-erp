# Front Matter und Rumpf aller Produktseiten alt gegen neu, Abweichungen nach Art gezählt. Nur lesend.
import os,re,sys,collections,difflib,json
if len(sys.argv) != 3:
    sys.exit('Aufruf: vergleich.py <alte produkt-Seiten> <neue produkt-Seiten>')
ALT, NEU = sys.argv[1], sys.argv[2]
def lies(p):
    t=open(p,encoding='utf-8',errors='replace').read()
    m=re.match(r'---\n(.*?)\n---\n?(.*)',t,re.S)
    if not m: return None,None
    fm=collections.OrderedDict(); k=None
    for z in m.group(1).split('\n'):
        z=z.rstrip()
        if not z: continue
        mm=re.match(r'^([A-Za-z_\-]+):\s*(.*)$',z)
        if mm and not z.startswith(' '): k=mm.group(1); fm[k]=mm.group(2)
        elif k: fm[k]+='\n'+re.sub(r'\s+#.*$','',z.strip())
    b=re.sub(r'\s+',' ',m.group(2)); b=re.sub(r'>\s+<','><',b).strip()
    return fm,[z for z in re.sub(r'><','>\n<',b).split('\n') if z]
def norm(z):
    z=z.replace('http://solar-spar.localhost/','https://solar-spar.de/')
    if '{{< in-cart' in z or '{{< shop-add-to-cart' in z: return '<<WARENKORB>>'
    return z
a=set(os.listdir(ALT)); n=set(os.listdir(NEU))
print('alt',len(a),'neu',len(n),'nur alt',len(a-n),'nur neu',len(n-a)); print(sorted(a-n)[:5],sorted(n-a)[:5])
fmk=collections.Counter(); bsp={}; gleichfm=0; gleichbody=0; sig=collections.Counter(); sigbsp={}; anz=0
for f in sorted(a&n):
    if f=='_index.md': continue
    fa,ba=lies(os.path.join(ALT,f)); fn,bn=lies(os.path.join(NEU,f))
    if fa is None or fn is None: fmk['KEIN FRONT MATTER']+=1; continue
    anz+=1; ok=True
    for k in sorted(set(fa)|set(fn)):
        if k=='lastmod': continue
        va=norm(fa.get(k,'<fehlt>')); vn=norm(fn.get(k,'<fehlt>'))
        if k=='price' and vn!='<fehlt>' and va!='<fehlt>' and float(va)==float(vn): continue
        if va!=vn:
            ok=False; art=k+(' (fehlt neu)' if vn=='<fehlt>' else ' (fehlt alt)' if va=='<fehlt>' else '')
            fmk[art]+=1; bsp.setdefault(art,(f,va[:140],vn[:140]))
    gleichfm+=ok
    ba=[norm(z) for z in ba]; bn=[norm(z) for z in bn]
    if ba==bn: gleichbody+=1; continue
    gesehen=set()
    for z in difflib.unified_diff(ba,bn,lineterm='',n=0):
        if z[:3] in('---','+++') or z.startswith('@@'): continue
        s=z[0]+' '+re.sub(r'\d+','N',re.sub(r'(href|src|alt|id|data-bs-target)="[^"]*"',r'\1="…"',z[1:]))[:70]
        if s in gesehen: continue
        gesehen.add(s); sig[s]+=1; sigbsp.setdefault(s,(f,z[:200]))
print('verglichen',anz,'Front Matter gleich',gleichfm,'Rumpf gleich',gleichbody)
print('--- Front Matter, Abweichungen je Schlüssel')
for k,c in fmk.most_common(): print(c,k,'|',bsp.get(k))
print('--- Rumpf, Zeilenarten (Seiten)')
for s,c in sig.most_common(40): print(c,s,'|',sigbsp[s][0][:50])
