"""Root-owned forced SSH command, executed as the unprivileged namira-deploy user."""
import fcntl,hashlib,json,os,pathlib,pwd,re,shlex,shutil,signal,socket,stat,subprocess,sys,tempfile,time,uuid
from urllib.request import Request,urlopen
import ssl
from package_vps import MAX_ARCHIVE,validate

RELEASE=re.compile(r'^[a-f0-9]{40}-[a-f0-9]{12}$')
class DeployError(Exception):pass

def command(value):
    parts=shlex.split(value)
    if parts==['status']:return parts
    if len(parts)==3 and parts[0]=='deploy' and re.fullmatch('[a-f0-9]{40}',parts[1]) and re.fullmatch('[a-f0-9]{64}',parts[2]):return parts
    raise DeployError('command_not_allowed')

def policy():
    file=pathlib.Path('/etc/namira-deploy.json');info=file.stat()
    if info.st_uid!=0 or stat.S_IMODE(info.st_mode)&0o022:raise DeployError('policy_not_protected')
    p=json.loads(file.read_text())
    if p['private_root']!='/var/www/naamiraa/_namira_live' or p['backups_root']!='/var/backups/naamiraa/deploy':raise DeployError('unexpected_policy')
    if not p.get('prepared'):raise DeployError('setup_not_complete')
    return p

class Deployment:
    def __init__(self,p,check=None,health=None):
        self.p=p;self.private=pathlib.Path(p['private_root']);self.releases=self.private/'releases';self.state=self.private/'deploy-state'
        if self.releases.is_symlink() or self.state.is_symlink():raise DeployError('linked_control_directory')
        self.check=check or self.database_check;self.health=health or self.http_health
    def current(self):
        target=os.readlink(self.state/'current')
        if not target.startswith('../releases/') or not RELEASE.fullmatch(target[12:]):raise DeployError('invalid_current_pointer')
        return target[12:]
    def switch(self,release):
        if not RELEASE.fullmatch(release):raise DeployError('invalid_release')
        temp=self.state/('.current-'+uuid.uuid4().hex);os.symlink('../releases/'+release,temp);os.replace(temp,self.state/'current')
    def database_check(self,candidate,release):
        root=pathlib.Path(self.p['backups_root']);backup=root/release
        if backup.is_symlink() or root.is_symlink():raise DeployError('linked_backup_directory')
        backup.mkdir(mode=0o700,exist_ok=True)
        run=subprocess.run([self.p['php'],str(candidate/'bin/deploy-check.php'),str(self.private),str(backup)],capture_output=True,text=True,timeout=600,env={'PATH':'/usr/bin:/bin','LANG':'C.UTF-8','APP_ENV':'production'})
        if run.returncode:raise DeployError('database_extensions_or_schema_check_failed')
        try:proof=json.loads(run.stdout)
        except ValueError:raise DeployError('invalid_preflight_response')
        if proof.get('ok') is not True or proof.get('database')!='namira_app' or proof.get('schema_changed') is not False:raise DeployError('preflight_not_confirmed')
        return proof
    def http_health(self,release):
        original=socket.getaddrinfo;hosts={'naamiraa.ir','admin.naamiraa.ir'}
        def resolve(host,port,*a,**kw):return original(self.p.get('health_ip','127.0.0.1') if host in hosts else host,port,*a,**kw)
        socket.getaddrinfo=resolve
        try:
            for url in [self.p['public_url']+'/',self.p['public_url']+'/images',self.p['admin_url']+'/index.php?page=login']:
                url+=('&' if '?' in url else '?')+'__namira_release='+release
                req=Request(url,headers={'Cache-Control':'no-cache','User-Agent':'Namira-Deployment-Health'})
                with urlopen(req,context=ssl.create_default_context(),timeout=30) as r:
                    if r.status!=200 or r.headers.get('X-Namira-Release')!=release:raise DeployError('release_health_failed')
                    body=r.read(2*1024*1024)
                    if b'Fatal error' in body:raise DeployError('php_health_failed')
        finally:socket.getaddrinfo=original
    def apply(self,raw,revision,digest):
        if hashlib.sha256(raw).hexdigest()!=digest:raise DeployError('archive_digest_mismatch')
        files,manifest=validate(raw,revision);release=revision+'-'+digest[:12];old=self.current()
        expected=str(self.state/'current/public/assets')
        for root in [self.p['public_root'],self.p['admin_root']]:
            assets=pathlib.Path(root)/'assets'
            if not assets.is_symlink() or os.readlink(assets)!=expected:raise DeployError('assets_not_prepared')
        candidate=self.releases/release
        if candidate.exists():
            if candidate.is_symlink():raise DeployError('linked_release')
            for name,raw_file in files.items():
                target=candidate/name
                if target.is_symlink() or target.read_bytes()!=raw_file:raise DeployError('existing_release_differs')
        else:
            temp=pathlib.Path(tempfile.mkdtemp(prefix='.incoming-',dir=self.releases))
            try:
                for name,raw_file in files.items():
                    target=temp/name;target.parent.mkdir(mode=0o750,parents=True,exist_ok=True);target.write_bytes(raw_file);target.chmod(0o640)
                for folder,dirs,names in os.walk(temp):os.chmod(folder,0o750)
                for name in files:
                    if name.endswith('.php'):
                        lint=subprocess.run([self.p['php'],'-l',str(temp/name)],capture_output=True,timeout=20)
                        if lint.returncode:raise DeployError('php_lint_failed')
                (temp/'manifest.json').write_text(json.dumps(manifest,sort_keys=True));(temp/'manifest.json').chmod(0o640);os.rename(temp,candidate)
            finally:
                if temp.exists():
                    if temp.resolve().parent!=self.releases.resolve() or not temp.name.startswith('.incoming-'):raise DeployError('unsafe_staging_cleanup')
                    shutil.rmtree(temp)
        if old==release:self.health(release);return {'ok':True,'release':release,'already_current':True}
        proof=self.check(candidate,release);switched=False
        try:
            self.switch(release);switched=True;self.health(release)
        except BaseException:
            if switched:self.switch(old)
            raise
        result={'ok':True,'release':release,'previous':old,'database_backup':proof.get('backup'),'schema_changed':False,'real_sms':0,'real_payments':0}
        log=self.state/('deploy-'+str(time.time_ns())+'.json');log.write_text(json.dumps(result));log.chmod(0o640);return result

def main():
    os.umask(0o027);args=command(os.environ.get('SSH_ORIGINAL_COMMAND',''));p=policy();deployment=Deployment(p)
    if os.geteuid()==0 or os.geteuid()!=pwd.getpwnam(p['deployment_user']).pw_uid:raise DeployError('deployment_account_required')
    if args==['status']:
        print(json.dumps({'ok':True,'release':deployment.current(),'database':'namira_app','transport':'restricted-ssh'}));return
    def interrupted(*_):raise DeployError('deployment_interrupted')
    for sig in [signal.SIGHUP,signal.SIGTERM,signal.SIGALRM]:signal.signal(sig,interrupted)
    signal.alarm(900)
    with open(deployment.state/'.deploy.lock','a') as lock:
        try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:raise DeployError('deployment_in_progress')
        raw=sys.stdin.buffer.read(MAX_ARCHIVE+1)
        if len(raw)>MAX_ARCHIVE:raise DeployError('archive_too_large')
        print(json.dumps(deployment.apply(raw,args[1],args[2])))

if __name__=='__main__':
    try:main()
    except Exception as e:
        print(json.dumps({'ok':False,'error':str(e) if isinstance(e,DeployError) else 'deployment_failed'}));sys.exit(1)
