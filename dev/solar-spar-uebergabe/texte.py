# Zusammenfassung und Beschreibung alt gegen neu: Aufteilung und Gleichheit. Nur lesend.
import sys
import os,re,collections
if len(sys.argv) != 3:
    sys.exit('Aufruf: texte.py <alte produkt-Seiten> <neue produkt-Seiten>')
ALT, NEU = sys.argv[1], sys.argv[2]
def art(p):
    t=open(p,encoding='utf-8',errors='replace').read()
    m=re.search(r'<div class="pb-4 text-secondary">(.*?)</div>\s*<h3 class="h5 pb-1">Preis',t,re.S)
    s=m.group(1).strip() if m else None
    d=re.search(r'id="tab-description">\s*(.*?)\s*<div class="alert alert-info',t,re.S)
    d=d.group(1).strip() if d else ''
    sa='kein Block' if s is None else 'leer' if s=='' else 'mit <p>' if s.startswith('<p') else 'Text'
    da='keine' if 'Keine weitere Beschreibung' in d else 'leer' if d=='' else 'doppel-<p>' if d.startswith('<p><p') else 'vorhanden'
    return sa,da,s or '',d
c=collections.Counter(); bsp={}; gleichS=gleichD=0; n=0
for f in sorted(os.listdir(ALT)):
    if f=='_index.md': continue
    a=art(os.path.join(ALT,f)); b=art(os.path.join(NEU,f)); n+=1
    k=(a[0],a[1],'->',b[0],b[1]); c[k]+=1; bsp.setdefault(k,f)
    nz=lambda x:re.sub(r'\s+',' ',x.replace('\xa0',' '))
    gleichS+= nz(a[2])==nz(b[2]); gleichD+= nz(a[3])==nz(b[3])
print(n,'Zusammenfassung gleich',gleichS,'Beschreibung gleich',gleichD)
for k,v in c.most_common(): print(v,k,bsp[k][:60])
