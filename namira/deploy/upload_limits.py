"""Scoped, reversible upload policy for the existing two Namira Nginx sites."""
import datetime,os,pathlib,re,subprocess,sys
MARK='# namira-upload-policy'
SITES={'naamiraa-public':'/var/www/naamiraa/public_html','naamiraa-admin':'/var/www/naamiraa/admin.naamiraa.ir'}
def transform(text,root):
    if root not in text or 'php8.3-fpm.sock' not in text:
        raise RuntimeError('Unexpected Namira site root or FPM socket; no configuration changed')
    text=re.sub(r'^.*fastcgi_param PHP_VALUE .*# namira-upload-policy\s*$', '',text,flags=re.M)
    if re.search(r'fastcgi_param\s+PHP_(?:ADMIN_)?VALUE\b',text):
        raise RuntimeError('Existing custom PHP_VALUE needs review; no configuration changed')
    directive='    client_max_body_size 1024m; '+MARK
    text=re.sub(r'^[ \t]*client_max_body_size[ \t]+[^;\n]+;[ \t]*(?:#[^\n]*)?$','',text,flags=re.M)
    if re.search(r'\bclient_max_body_size\b',text):raise RuntimeError('Inline upload directive needs review; no configuration changed')
    text,n=re.subn(r'\bserver\s*\{',lambda m:m.group(0)+'\n'+directive+'\n',text)
    if not n:raise RuntimeError('No server block; no configuration changed')
    policy='    fastcgi_param PHP_VALUE "upload_max_filesize=50M\\npost_max_size=1024M\\nmax_file_uploads=100"; '+MARK
    text,n=re.subn(r'fastcgi_pass\s+unix:[^;]*php8\.3-fpm\.sock;',lambda m:m.group(0)+'\n'+policy+'\n',text)
    if not n:raise RuntimeError('No expected PHP handler; no configuration changed')
    return text

def main():
    if '--test' in sys.argv:
        s='server {\nroot /var/www/naamiraa/public_html;\nclient_max_body_size 20m;\nlocation ~ php { fastcgi_pass unix:/run/php/php8.3-fpm.sock; }\n}'
        a=transform(s,SITES['naamiraa-public']);assert '50M' in a and '1024m' in a and '20m' not in a
        b=transform(a,SITES['naamiraa-public']);assert b.count('fastcgi_param PHP_VALUE')==1 and b.count('client_max_body_size')==1
        try:transform(s,'/different/root')
        except RuntimeError:pass
        else:raise AssertionError('foreign site accepted')
        print('Scoped upload policy transformation checks passed');return
    if os.geteuid()!=0:raise RuntimeError('Run the manual installer with sudo')
    targets={}
    for name,root in SITES.items():
        p=pathlib.Path('/etc/nginx/sites-available')/name
        if p.is_symlink() or not p.is_file() or p.stat().st_uid!=0:raise RuntimeError('Expected root-owned Namira site missing')
        text=p.read_text();targets[p]=(text,transform(text,root))
    backup=pathlib.Path('/var/backups/naamiraa/upload-policy')/datetime.datetime.now().strftime('%Y%m%d-%H%M%S-%f')
    backup.mkdir(parents=True,mode=0o700)
    def replace(p,text):
        stat=p.stat();temp=p.with_name(p.name+'.namira-upload-tmp');fd=os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_EXCL,stat.st_mode&0o777)
        with os.fdopen(fd,'w') as f:f.write(text)
        os.chown(temp,stat.st_uid,stat.st_gid);os.replace(temp,p)
    try:
        for p,(old,new) in targets.items():
            dest=backup/p.name;dest.write_text(old);os.chmod(dest,0o600);replace(p,new)
        subprocess.run(['/usr/sbin/nginx','-t'],check=True)
        subprocess.run(['/usr/bin/systemctl','reload','nginx'],check=True)
    except BaseException:
        for p,(old,new) in targets.items():replace(p,old)
        subprocess.run(['/usr/sbin/nginx','-t'],check=True)
        subprocess.run(['/usr/bin/systemctl','reload','nginx'],check=True)
        raise
    print('Namira image limit: 50 MiB; multipart request: 1024 MiB. Backup: '+str(backup))
if __name__=='__main__':main()
, '',text,flags=re.M)
    text,n=re.subn(r'\bserver\s*\{',lambda m:m.group(0)+'\n'+directive+'\n',text)
    if not n:raise RuntimeError('No server block; no configuration changed')
    policy='    fastcgi_param PHP_VALUE "upload_max_filesize=50M\\npost_max_size=1024M\\nmax_file_uploads=100"; '+MARK
    text,n=re.subn(r'fastcgi_pass\s+unix:[^;]*php8\.3-fpm\.sock;',lambda m:m.group(0)+'\n'+policy+'\n',text)
    if not n:raise RuntimeError('No expected PHP handler; no configuration changed')
    return text

def main():
    if '--test' in sys.argv:
        s='server {\nroot /var/www/naamiraa/public_html;\nclient_max_body_size 20m;\nlocation ~ php { fastcgi_pass unix:/run/php/php8.3-fpm.sock; }\n}'
        a=transform(s,SITES['naamiraa-public']);assert '50M' in a and '1024m' in a and '20m' not in a
        b=transform(a,SITES['naamiraa-public']);assert b.count('fastcgi_param PHP_VALUE')==1 and b.count('client_max_body_size')==1
        try:transform(s,'/different/root')
        except RuntimeError:pass
        else:raise AssertionError('foreign site accepted')
        print('Scoped upload policy transformation checks passed');return
    if os.geteuid()!=0:raise RuntimeError('Run the manual installer with sudo')
    targets={}
    for name,root in SITES.items():
        p=pathlib.Path('/etc/nginx/sites-available')/name
        if p.is_symlink() or not p.is_file() or p.stat().st_uid!=0:raise RuntimeError('Expected root-owned Namira site missing')
        text=p.read_text();targets[p]=(text,transform(text,root))
    backup=pathlib.Path('/var/backups/naamiraa/upload-policy')/datetime.datetime.now().strftime('%Y%m%d-%H%M%S-%f')
    backup.mkdir(parents=True,mode=0o700)
    def replace(p,text):
        stat=p.stat();temp=p.with_name(p.name+'.namira-upload-tmp');fd=os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_EXCL,stat.st_mode&0o777)
        with os.fdopen(fd,'w') as f:f.write(text)
        os.chown(temp,stat.st_uid,stat.st_gid);os.replace(temp,p)
    try:
        for p,(old,new) in targets.items():
            dest=backup/p.name;dest.write_text(old);os.chmod(dest,0o600);replace(p,new)
        subprocess.run(['/usr/sbin/nginx','-t'],check=True)
        subprocess.run(['/usr/bin/systemctl','reload','nginx'],check=True)
    except BaseException:
        for p,(old,new) in targets.items():replace(p,old)
        subprocess.run(['/usr/sbin/nginx','-t'],check=True)
        subprocess.run(['/usr/bin/systemctl','reload','nginx'],check=True)
        raise
    print('Namira image limit: 50 MiB; multipart request: 1024 MiB. Backup: '+str(backup))
if __name__=='__main__':main()
