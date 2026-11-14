"""One-time, reviewed sudo setup. Does not alter Nginx, MySQL, uploads or admin SSH keys."""
import argparse,base64,fcntl,grp,hashlib,json,os,pathlib,pwd,re,shutil,subprocess,sys,tempfile,time,urllib.request

APP=pathlib.Path('/var/www/naamiraa');PRIVATE=APP/'_namira_live';STATE=PRIVATE/'deploy-state';RELEASES=PRIVATE/'releases'
BACKUPS=pathlib.Path('/var/backups/naamiraa/deploy');LIB=pathlib.Path('/usr/local/lib/namira-deploy')
USER='namira-deploy';GROUP='namira-app-read';WEB='www-data'
RELEASE=re.compile(r'^[a-f0-9]{40}-[a-f0-9]{12}$')

def run(*args):return subprocess.run(args,check=True,capture_output=True,text=True)
def write(path,data,mode=0o640,uid=0,gid=0):
    path=pathlib.Path(path);temp=path.with_name(path.name+'.setup-tmp');temp.write_bytes(data);os.chmod(temp,mode);os.chown(temp,uid,gid);os.replace(temp,path)
def public_key(value):
    if '\n' in value or '\r' in value:raise ValueError('Invalid public key')
    fields=value.strip().split()
    if len(fields)<2 or fields[0]!='ssh-ed25519' or len(base64.b64decode(fields[1],validate=True))!=51:raise ValueError('Ed25519 public key required')
    return fields[0]+' '+fields[1]+' naamiraa-github-actions'
def merged_assets():
    result={}
    for surface in ['public_html','admin.naamiraa.ir']:
        assets=APP/surface/'assets'
        if not assets.is_dir() or not assets.resolve().is_relative_to(APP.resolve()):raise RuntimeError('Assets outside expected project')
        for path in assets.rglob('*'):
            if path.is_symlink():raise RuntimeError('Linked source asset requires manual review')
            if path.is_file():
                relative=path.relative_to(assets).as_posix();raw=path.read_bytes()
                if relative in result and result[relative]!=raw:raise RuntimeError('Public/admin asset copies differ; review before setup')
                result[relative]=raw
    return result

def install(args):
    if os.geteuid()!=0:raise RuntimeError('Run setup with sudo')
    if not re.fullmatch('[a-f0-9]{40}',args.source_ref):raise ValueError('Pinned source commit required')
    key=public_key(args.public_key)
    if APP.is_symlink() or LIB.is_symlink():raise RuntimeError('Linked installation root requires review')
    for path in [PRIVATE,RELEASES,PRIVATE/'storage',APP/'public_html',APP/'admin.naamiraa.ir']:
        if not path.is_dir() or path.is_symlink():raise RuntimeError('Expected real project folder missing')
    if not (PRIVATE/'config.local.json').is_file() or (PRIVATE/'config.local.json').is_symlink():raise RuntimeError('Existing private configuration required')
    for file in [PRIVATE/'current.php',PRIVATE/'.env']:
        if file.is_symlink():raise RuntimeError('Linked bootstrap or environment requires review')
    for folder in [STATE,BACKUPS,BACKUPS.parent,pathlib.Path('/home')/USER,pathlib.Path('/home')/USER/'.ssh']:
        if folder.is_symlink():raise RuntimeError('Linked deployment control folder requires review')
    for surface in ['public_html','admin.naamiraa.ir']:
        entry=(APP/surface/'index.php').read_text()
        if 'NAMIRA_SHARED_PATH' not in entry or 'current.php' not in entry:raise RuntimeError('Existing shared entry point must be reviewed')
    php=shutil.which('php8.3')
    if not php:raise RuntimeError('PHP 8.3 CLI is required')
    check=run(php,'-r',"echo json_encode([PHP_MAJOR_VERSION,PHP_MINOR_VERSION,extension_loaded('pdo_mysql'),extension_loaded('gd'),extension_loaded('mbstring'),extension_loaded('zip'),extension_loaded('dom'),extension_loaded('curl')]);")
    if json.loads(check.stdout)!=[8,3,True,True,True,True,True,True]:raise RuntimeError('PHP CLI extensions must match PHP-FPM requirements')
    run('/usr/sbin/nginx','-t')
    run('/usr/bin/systemctl','is-active','--quiet','php8.3-fpm')
    nginx_user=re.search(r'^\s*user\s+([A-Za-z0-9_-]+)',pathlib.Path('/etc/nginx/nginx.conf').read_text(),re.M)
    pool_user=re.search(r'^\s*user\s*=\s*([A-Za-z0-9_-]+)',pathlib.Path('/etc/php/8.3/fpm/pool.d/www.conf').read_text(),re.M)
    if not nginx_user or not pool_user or nginx_user[1]!=WEB or pool_user[1]!=WEB:raise RuntimeError('Custom web service users require setup review')
    for site in ['naamiraa-public','naamiraa-admin']:
        if 'php8.3-fpm.sock' not in pathlib.Path('/etc/nginx/sites-available',site).read_text():raise RuntimeError('Custom FPM socket requires setup review')
    ssh_policy=run('/usr/sbin/sshd','-T').stdout
    for line in ssh_policy.splitlines():
        if line.startswith('allowusers ') and USER not in line.split()[1:] and '*' not in line.split()[1:]:raise RuntimeError('SSH AllowUsers must include namira-deploy before setup')
    # Source URLs are pinned to an immutable commit, never to a branch or user-supplied host.
    bundle={}
    for name in ['package_vps.py','vps_gateway.py','vps-current.php']:
        url='https://raw.githubusercontent.com/ItachiUchihaNo1/heiat-network/'+args.source_ref+'/namira/deploy/'+name
        with urllib.request.urlopen(url,timeout=30) as response:bundle[name]=response.read(2*1024*1024)
        if not bundle[name]:raise RuntimeError('Empty setup dependency')
    try:group=grp.getgrnam(GROUP)
    except KeyError:run('/usr/sbin/groupadd',GROUP);group=grp.getgrnam(GROUP)
    try:account=pwd.getpwnam(USER)
    except KeyError:run('/usr/sbin/useradd','--create-home','--shell','/bin/sh','--gid',GROUP,USER);account=pwd.getpwnam(USER)
    if account.pw_uid==0 or set(os.getgrouplist(USER,account.pw_gid))&{g.gr_gid for g in grp.getgrall() if g.gr_name in ['sudo','adm','docker','lxd','root']}:raise RuntimeError('Deployment account must not be privileged')
    run('/usr/sbin/usermod','-g',GROUP,USER);run('/usr/sbin/usermod','-aG',GROUP,WEB);run('/usr/sbin/usermod','-L',USER)
    run('/usr/bin/systemctl','reload','php8.3-fpm')
    run('/usr/bin/systemctl','reload','nginx')
    gid=group.gr_gid;uid=account.pw_uid
    for path in [PRIVATE,APP]:os.chown(path,-1,gid);os.chmod(path,(path.stat().st_mode|0o050)&~0o022)
    for path in [PRIVATE/'config.local.json',PRIVATE/'.env']:
        if path.exists():os.chown(path,-1,gid);os.chmod(path,0o640)
    os.chown(RELEASES,uid,gid);os.chmod(RELEASES,0o750)
    for path in [STATE,BACKUPS]:path.mkdir(mode=0o750,parents=True,exist_ok=True);os.chown(path,uid,gid);os.chmod(path,0o750 if path==STATE else 0o700)
    lock=open(STATE/'.deploy.lock','a');os.chown(STATE/'.deploy.lock',uid,gid);os.chmod(STATE/'.deploy.lock',0o640)
    try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    except BlockingIOError:raise RuntimeError('Wait for the active deployment before setup')
    for path in [BACKUPS.parent]:os.chown(path,-1,gid);os.chmod(path,path.stat().st_mode|0o010)
    LIB.mkdir(mode=0o755,parents=True,exist_ok=True);os.chown(LIB,0,0);os.chmod(LIB,0o755)
    for name in ['package_vps.py','vps_gateway.py']:write(LIB/name,bundle[name],0o644)
    home=pathlib.Path(account.pw_dir);home.mkdir(exist_ok=True);os.chown(home,0,0);os.chmod(home,0o755);ssh=home/'.ssh';ssh.mkdir(exist_ok=True);os.chown(ssh,0,0);os.chmod(ssh,0o755)
    authorized='restrict,command="/usr/bin/python3 /usr/local/lib/namira-deploy/vps_gateway.py" '+key+'\n';write(ssh/'authorized_keys',authorized.encode(),0o644)
    policy={'private_root':str(PRIVATE),'public_root':str(APP/'public_html'),'admin_root':str(APP/'admin.naamiraa.ir'),'backups_root':str(BACKUPS),'php':php,'public_url':'https://naamiraa.ir','admin_url':'https://admin.naamiraa.ir','health_ip':'127.0.0.1','deployment_user':USER,'prepared':False}
    write('/etc/namira-deploy.json',json.dumps(policy,indent=2).encode(),0o644)
    current=PRIVATE/'current.php';old_loader=current.read_bytes();already=old_loader==bundle['vps-current.php'] and (STATE/'current').is_symlink()
    backup=BACKUPS/('setup-'+str(time.time_ns()));backup.mkdir(mode=0o700);os.chown(backup,uid,gid);write(backup/'current.php',old_loader,0o600,uid,gid)
    moved={};changed=False;pointer_changed=False;pointer=STATE/'current';old_pointer=os.readlink(pointer) if pointer.is_symlink() else None
    if pointer.exists() and not pointer.is_symlink():raise RuntimeError('Release pointer is not a symlink')
    try:
        if not already:
            match=re.search(rb'releases/([a-f0-9]{40}-[a-f0-9]{12})/app/bootstrap\.php',old_loader)
            if not match:raise RuntimeError('Existing release pointer requires manual review')
            active=RELEASES/match[1].decode()
            if not active.is_dir() or active.is_symlink():raise RuntimeError('Existing release missing')
            assets=merged_assets();baseline=hashlib.sha1(old_loader+str(time.time_ns()).encode()).hexdigest()+'-'+os.urandom(6).hex();candidate=RELEASES/baseline;candidate.mkdir(mode=0o750)
            for name in ['app','views','database','bin']:
                source=active/name
                if source.exists():
                    if source.is_symlink():raise RuntimeError('Linked code directory requires manual review')
                    shutil.copytree(source,candidate/name,symlinks=True)
            for relative,raw in assets.items():file=candidate/'public/assets'/relative;file.parent.mkdir(parents=True,exist_ok=True);file.write_bytes(raw)
            for folder,dirs,files in os.walk(candidate):
                if any((pathlib.Path(folder)/name).is_symlink() for name in dirs+files):raise RuntimeError('Linked code requires manual review')
                os.chown(folder,0,gid);os.chmod(folder,0o750)
                for name in files:os.chown(pathlib.Path(folder)/name,0,gid);os.chmod(pathlib.Path(folder)/name,0o640)
            pending=STATE/('.setup-current-'+str(time.time_ns()));os.symlink('../releases/'+baseline,pending);os.replace(pending,pointer);pointer_changed=True
            for surface in ['public_html','admin.naamiraa.ir']:
                original=APP/surface/'assets';saved=APP/surface/('assets.before-github-'+str(time.time_ns()));os.rename(original,saved);moved[str(original)]=str(saved);os.symlink(str(STATE/'current/public/assets'),original)
            write(current,bundle['vps-current.php'],0o640,0,gid);changed=True
        run('/usr/bin/systemctl','reload','php8.3-fpm')
        sys.path.insert(0,str(LIB));from vps_gateway import Deployment
        deployment=Deployment(policy);deployment.http_health(deployment.current())
        policy['prepared']=True;write('/etc/namira-deploy.json',json.dumps(policy,indent=2).encode(),0o644)
        print(json.dumps({'ok':True,'deployment_user':USER,'release':deployment.current(),'backup':str(backup),'uploads_changed':False,'database_changed':False,'main_ssh_account_changed':False}))
    except BaseException:
        if changed:write(current,old_loader,0o640,0,gid)
        for original,saved in moved.items():
            path=pathlib.Path(original)
            if path.is_symlink():path.unlink()
            os.rename(saved,original)
        if pointer_changed:
            if old_pointer is None:pointer.unlink()
            else:
                pending=STATE/('.restore-current-'+str(time.time_ns()));os.symlink(old_pointer,pending);os.replace(pending,pointer)
        if changed:subprocess.run(['/usr/bin/systemctl','reload','php8.3-fpm'],capture_output=True)
        raise

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--source-ref',required=True);p.add_argument('--public-key',required=True);args=p.parse_args()
    try:install(args)
    except Exception as e:print(json.dumps({'ok':False,'error':str(e) if isinstance(e,(ValueError,RuntimeError)) else 'setup_failed'}));sys.exit(1)
