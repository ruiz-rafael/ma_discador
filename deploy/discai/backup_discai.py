"""Consistent portal backup; tenant DB dumps and private files, stored only under own backup directory."""
from pathlib import Path
import datetime,fcntl,os,shutil,sqlite3,subprocess,tarfile,tempfile
os.umask(0o077)
root=Path('/srv/zyrex-ma');base=root/'discai';destination=root/'backups/discai';destination.mkdir(mode=0o700,exist_ok=True)
with (base/'backup.lock').open('w')as lock:
 fcntl.flock(lock,fcntl.LOCK_EX)
 with tempfile.TemporaryDirectory(prefix='snapshot-',dir=destination)as name:
  stage=Path(name)
  with sqlite3.connect('file:'+str(base/'portal-storage/portal.sqlite')+'?mode=ro',uri=True)as source,sqlite3.connect(stage/'portal.sqlite')as target:
   source.backup(target)
   assert target.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
  for filename in ['portal.env','compose.json','portal-nginx.conf']:shutil.copy2(base/filename,stage/filename)
  for folder in (base/'tenants').glob('*')if(base/'tenants').exists()else[]:
   if not(folder/'compose.json').exists():continue
   target=stage/'tenants'/folder.name;target.mkdir(parents=True)
   for f in ['.env','compose.json','nginx.conf']:shutil.copy2(folder/f,target/f)
   with (target/'database.sql').open('wb')as out:subprocess.run(['docker','compose','-f',str(folder/'compose.json'),'exec','-T','database','pg_dump','-U','discai','discai'],cwd=folder,stdout=out,check=True)
   if(folder/'storage/app/private').exists():shutil.copytree(folder/'storage/app/private',target/'private')
  stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ');archive=destination/('discai-'+stamp+'.tar.gz')
  with tarfile.open(archive,'w:gz')as tar:tar.add(stage,arcname='discai')
  archive.chmod(0o600)
 print('DiscAI backup completed; database integrity verified.')
