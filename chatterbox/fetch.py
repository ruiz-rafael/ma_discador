"""Download only pinned, checksummed public upstream artifacts at install/build time."""
import hashlib,json,sys,urllib.request,concurrent.futures,time
from pathlib import Path

def fetch(item,root):
 p=root/item['path'];p.parent.mkdir(parents=True,exist_ok=True)
 def digest(p):
  h=hashlib.sha256()
  with p.open('rb')as f:
   for b in iter(lambda:f.read(1048576),b''):h.update(b)
  return h.hexdigest()
 if p.exists()and digest(p)==item['sha256']:return
 tmp=p.with_suffix(p.suffix+'.partial')
 for attempt in range(3):
  try:
   with urllib.request.urlopen(item['url'],timeout=90)as response,tmp.open('wb')as out:
    while b:=response.read(1048576):out.write(b)
   assert digest(tmp)==item['sha256'],'Checksum mismatch: '+item['path']
   tmp.replace(p);return
  except Exception:
   tmp.unlink(missing_ok=True)
   if attempt==2:raise
   time.sleep(2)
if __name__=='__main__':
 manifest=json.loads(Path(sys.argv[1]).read_text());root=Path(sys.argv[2]);root.mkdir(parents=True,exist_ok=True)
 with concurrent.futures.ThreadPoolExecutor(max_workers=2)as pool:list(pool.map(lambda x:fetch(x,root),manifest))
 print('Verified artifacts:',len(manifest),flush=True)
