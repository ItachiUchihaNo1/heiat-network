"""Deploy two Namira surfaces; no credentials are accepted in source or printed."""
import os,re,io,json,ssl,secrets,uuid,hashlib,time,posixpath
from http.cookiejar import CookieJar
from pathlib import Path
from ftplib import FTP_TLS,error_perm
from urllib.request import Request,urlopen,build_opener,HTTPCookieProcessor,HTTPSHandler
from urllib.parse import urlencode
ROOT=Path(__file__).resolve().parents[1]
PUBLIC='public_html';ADMIN='admin.naamiraa.ir';PRIVATE='_namira_live'
SITES={'public':'https://naamiraa.ir','admin':'https://admin.naamiraa.ir'}
class DeploymentError(RuntimeError): pass
class Remote:
 def __init__(self,ftp):self.f=ftp;self.home=ftp.pwd();self.dirs={self.home.rstrip('/') or '/'}
 def path(self,p):
  if p.startswith('/') or any(x in ('..','.') for x in p.split('/')):raise DeploymentError('Unsafe relative path')
  return self.home.rstrip('/')+'/'+p
 def read(self,p):
  b=io.BytesIO();self.f.retrbinary('RETR '+self.path(p),b.write);return b.getvalue()
 def maybe(self,p):
  try:return self.read(p)
  except error_perm as e:
   if str(e).startswith('550'):return None
   raise
 def ensure(self,p):
  current=self.home.rstrip('/')
  for part in p.split('/'):
   if not part:continue
   if part in ('.','..'):raise DeploymentError('Unsafe folder')
   current+='/'+part
   if current in self.dirs:continue
   try:self.f.cwd(current)
   except error_perm:self.f.mkd(current)
   self.dirs.add(current)
  self.f.cwd(self.home)
 def put(self,p,data,private=False):
  self.ensure(posixpath.dirname(p));tmp=p+'.upload-'+uuid.uuid4().hex;self.f.storbinary('STOR '+self.path(tmp),io.BytesIO(data));self.f.rename(self.path(tmp),self.path(p));self.f.sendcmd('SITE CHMOD '+('600 ' if private else '644 ')+self.path(p))
 def remove_created(self,p):self.f.delete(self.path(p))
def http(url,data=None,opener=None):
 req=Request(url,data=urlencode(data).encode() if data is not None else None,headers={'User-Agent':'Naamiraa-verified-domain-deployment','Cache-Control':'no-cache'})
 with (opener.open(req,timeout=300) if opener else urlopen(req,timeout=60,context=ssl.create_default_context())) as r:return r.status,r.read(),dict(r.headers)
def entry(surface):
 if surface not in SITES:raise DeploymentError('Unknown surface')
 return ("<?php\ndeclare(strict_types=1);\ndefine('NAMIRA_SURFACE', '"+surface+"');\ndefine('NAMIRA_SHARED_PATH', dirname(__DIR__).'/_namira_live');\nputenv('APP_ENV=production');\nputenv('APP_URL=https://naamiraa.ir');\nputenv('PUBLIC_APP_URL=https://naamiraa.ir');\nputenv('ADMIN_APP_URL=https://admin.naamiraa.ir');\nrequire NAMIRA_SHARED_PATH.'/current.php';\nNamira\\Web::run();\n").encode()
def csrf(raw):
 m=re.search(rb'name="csrf" value="([a-f0-9]{64})"',raw)
 if not m:raise DeploymentError('Installer CSRF field not found; no secret values logged')
 return m.group(1).decode()
def deploy():
 host=os.environ['DEPLOY_FTPS_HOST'];user=os.environ['DEPLOY_FTPS_USER'];password=os.environ['DEPLOY_FTPS_PASSWORD'];dbpass=os.environ.get('NAAMIRAA_DB_PASSWORD')
 if not dbpass:raise DeploymentError('NAAMIRAA_DB_PASSWORD secret is required')
 revision=os.environ['NAMIRA_RELEASE_ID']
 if not re.fullmatch('[a-f0-9]{40}',revision):raise DeploymentError('Immutable source revision required')
 release=revision+'-'+uuid.uuid4().hex[:12];prefix=PRIVATE+'/releases/'+release
 ftp=FTP_TLS(context=ssl.create_default_context(),timeout=60);ftp.connect(host,21);ftp.login(user,password);ftp.prot_p();r=Remote(ftp)
 try:
  for folder in [PUBLIC,ADMIN]:ftp.cwd(r.path(folder));ftp.cwd(r.home)
  # A matching nonce proves the HTTPS virtual host serves the exact supplied folder.
  for surface,folder in [('public',PUBLIC),('admin',ADMIN)]:
   name='_namira-map-'+uuid.uuid4().hex+'.txt';remote=folder+'/'+name;content=secrets.token_hex(32).encode();r.put(remote,content)
   try:
    status,body,_=http(SITES[surface]+'/'+name)
    if status!=200 or body!=content:raise DeploymentError('HTTPS root mapping does not match '+folder)
   finally:r.remove_created(remote)
  old_indices={surface:r.maybe(folder+'/index.php') for surface,folder in [('public',PUBLIC),('admin',ADMIN)]}
  for surface,raw in old_indices.items():
   if raw is not None and b'Namira\\Web::run' not in raw:raise DeploymentError('Foreign entry point in '+surface+'; not overwritten')
  source='Public_html/new/_namira'
  original=r.maybe('Public_html/new/index.php')
  if original is None or b'Namira\\Web::run' not in original or b'/_namira/' not in original:raise DeploymentError('Verified original Namira entry point required')
  if r.maybe(source+'/config.local.json') is None:raise DeploymentError('Existing private source configuration not found')
  r.ensure(PRIVATE);r.put(PRIVATE+'/.htaccess',b'Require all denied\n',True);r.put(PRIVATE+'/.namira-shared-root',b'namira-private-shared-v1\n',True)
  configured=r.maybe(PRIVATE+'/config.local.json') is not None
  old_pointer=r.maybe(PRIVATE+'/current.php');backup=PRIVATE+'/backups/entry-'+release
  for surface,raw in old_indices.items():
   if raw is not None:r.put(backup+'/'+surface+'-index.php',raw,True)
  if old_pointer is not None:r.put(backup+'/current.php',old_pointer,True)
  manifest=[];count=0
  for folder in ['app','views','database','bin','docs','licenses']:
   for file in sorted((ROOT/folder).rglob('*')):
    if file.is_file():
     rel=file.relative_to(ROOT).as_posix();raw=file.read_bytes();r.put(prefix+'/'+rel,raw,True);manifest.append({'path':rel,'sha256':hashlib.sha256(raw).hexdigest()});count+=1
  for file in sorted((ROOT/'public').rglob('*')):
   if not file.is_file() or file.name=='index.php' or file.name=='.htaccess':continue
   rel=file.relative_to(ROOT/'public').as_posix();raw=file.read_bytes()
   for folder in [PUBLIC,ADMIN]:r.put(folder+'/'+rel,raw)
   count+=2
  # Keep host PHP handlers/INI directives; only append Namira's static protection once.
  for folder in [PUBLIC,ADMIN]:
   old=r.maybe(folder+'/.htaccess') or b''
   if b'# BEGIN NAMIRA DOMAIN' not in old:
    r.put(backup+'/'+folder.replace('.','_')+'-htaccess',old,True)
    r.put(folder+'/.htaccess',old+b'\n# BEGIN NAMIRA DOMAIN\n'+(ROOT/'public/.htaccess').read_bytes()+b'\n# END NAMIRA DOMAIN\n')
  r.ensure(PRIVATE+'/storage');r.put(PRIVATE+'/storage/.htaccess',b'Require all denied\n',True)
  key=None
  if not configured:
   key=r.maybe(PRIVATE+'/.setup.key')
   if key is None:key=secrets.token_hex(32).encode();r.put(PRIVATE+'/.setup.key',key,True)
   r.put(PRIVATE+'/.migration-source.json',json.dumps({'root':'/home/ouidoyhy/Public_html/new/_namira'}).encode(),True)
  r.put(prefix+'/manifest.json',json.dumps(manifest).encode(),True)
  r.put(PRIVATE+'/current.php',("<?php\nrequire __DIR__.'/releases/"+release+"/app/bootstrap.php';\n").encode(),True)
  for surface,folder in [('admin',ADMIN),('public',PUBLIC)]:r.put(folder+'/index.php',entry(surface))
  print('DOMAIN_RELEASE_FILES_READY '+json.dumps({'source':revision,'release':release,'files':count+2,'private':PRIVATE,'public':PUBLIC,'admin':ADMIN}))
  if not configured:
   jar=CookieJar();opener=build_opener(HTTPCookieProcessor(jar),HTTPSHandler(context=ssl.create_default_context()));setup=SITES['admin']+'/index.php?page=setup'
   _,body,_=http(setup,opener=opener)
   _,body,_=http(setup,{'action':'unlock','csrf':csrf(body),'setup_key':key.decode()},opener)
   try:http(setup,{'action':'migrate-existing','csrf':csrf(body),'db_host':'localhost','db_port':'3306','db_name':'ouidoyhy_namira_live','db_user':'ouidoyhy_nmapp','db_password':dbpass},opener)
   except Exception:
    # A lost response is not permission to repeat a database copy.
    if r.maybe(PRIVATE+'/.setup.lock') is None:raise DeploymentError('Database copy not confirmed; inspect private status and account privileges; originals retained')
   if r.maybe(PRIVATE+'/.setup.lock') is None or r.maybe(PRIVATE+'/config.local.json') is None:raise DeploymentError('Destination installation not confirmed')
  for surface,folder in [('public',PUBLIC),('admin',ADMIN)]:
   if r.read(folder+'/index.php')!=entry(surface):raise DeploymentError('Entry point readback mismatch')
   for asset in ['calls.js','calls.css','application.css']:
    if hashlib.sha256(r.read(folder+'/assets/'+asset)).digest()!=hashlib.sha256((ROOT/'public/assets'/asset).read_bytes()).digest():raise DeploymentError('Asset readback mismatch')
  status,body,_=http(SITES['public']+'/index.php?page=home')
  if status!=200 or 'نامیرا'.encode() not in body:raise DeploymentError('Public application verification failed')
  status,body,_=http(SITES['admin']+'/index.php?page=login')
  if status!=200 or 'ورود مدیر نامیرا'.encode() not in body or b'desktop-navigation' in body:raise DeploymentError('Dedicated admin login verification failed')
  report=json.loads(r.read(PRIVATE+'/domain-migration-report.json'))
  result={'source':revision,'release':release,'public':'https://naamiraa.ir','admin':'https://admin.naamiraa.ir','database':'ouidoyhy_namira_live','copied_files':report['copied_files'],'copied_bytes':report['copied_bytes'],'source_rows':sum(report['source_tables'].values()),'target_rows':sum(report['target_tables'].values()),'source_modified':False,'public_http':200,'admin_login_http':200}
  Path('naamiraa-release-result.json').write_text(json.dumps(result,indent=2));print('NAAMIRAA_RELEASE_VERIFIED '+json.dumps(result));return 0
 finally:
  try:ftp.quit()
  except Exception:ftp.close()
if __name__=='__main__':
 try:raise SystemExit(deploy())
 except Exception as e:
  # HTTP bodies, URLs with query parameters and credentials are deliberately not logged.
  print('Naamiraa deployment stopped: '+type(e).__name__+(' '+str(e) if isinstance(e,DeploymentError) else ''),flush=True);raise SystemExit(1)
