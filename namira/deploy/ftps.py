"""Deploy Namira using explicit FTPS. Secrets are read from the runner environment only."""
from ftplib import FTP_TLS, error_perm
from pathlib import Path
from urllib.request import Request, urlopen
from urllib.error import HTTPError
import io
import os
import posixpath
import ssl
import sys
import uuid

ROOT = Path(__file__).resolve().parents[1]
DEST = os.environ.get('DEPLOY_FTPS_DIR', 'Public_html/new').strip()
SITE = os.environ.get('DEPLOY_SITE_URL', 'https://test.shahidpics.com').rstrip('/')


def ensure_dir(ftp, path):
    start = ftp.pwd()
    for part in path.split('/'):
        if not part:
            continue
        if part in ('.', '..'):
            raise RuntimeError('Unsafe deployment path')
        try:
            ftp.cwd(part)
        except error_perm:
            ftp.mkd(part)
            ftp.cwd(part)
    ftp.cwd(start)


def upload(ftp, remote, content):
    parent = posixpath.dirname(remote)
    if parent:
        ensure_dir(ftp, parent)
    temporary = remote + '.upload-' + uuid.uuid4().hex
    ftp.storbinary('STOR ' + temporary, io.BytesIO(content))
    ftp.rename(temporary, remote)


def http_status(path):
    request = Request(SITE + path, headers={'Cache-Control': 'no-cache', 'User-Agent': 'Namira-Deploy-Check'})
    try:
        with urlopen(request, timeout=30, context=ssl.create_default_context()) as response:
            return response.status
    except HTTPError as error:
        return error.code


def deploy():
    if not SITE.startswith('https://') or not DEST or '..' in DEST.split('/'):
        raise RuntimeError('HTTPS site URL and a safe FTP directory are required')
    required = ['DEPLOY_FTPS_HOST', 'DEPLOY_FTPS_USERNAME', 'DEPLOY_FTPS_PASSWORD']
    if any(not os.environ.get(key) for key in required):
        raise RuntimeError('Missing one of DEPLOY_FTPS_HOST / DEPLOY_FTPS_USERNAME / DEPLOY_FTPS_PASSWORD')
    host = os.environ['DEPLOY_FTPS_HOST']
    if '/' in host or ':' in host:
        raise RuntimeError('DEPLOY_FTPS_HOST must be a hostname only, without protocol, port or directory')
    ftp = FTP_TLS(context=ssl.create_default_context(), timeout=60)
    try:
        ftp.connect(host, int(os.environ.get('DEPLOY_FTPS_PORT', '21')))
        ftp.login(os.environ['DEPLOY_FTPS_USERNAME'], os.environ['DEPLOY_FTPS_PASSWORD'])
        ftp.prot_p()
        # The destination must already exist. Do not guess or create an alternate document root.
        ftp.cwd(DEST)
        ensure_dir(ftp, '_namira')
        ftp.storbinary('STOR _namira/.htaccess', io.BytesIO(b'Require all denied\n'))
        canary = '_namira/check-' + uuid.uuid4().hex + '.txt'
        ftp.storbinary('STOR ' + canary, io.BytesIO(b'Namira access-control test; no private data.'))
        try:
            if http_status('/' + canary) != 403:
                raise RuntimeError('Private directory must return HTTP 403. No application code was uploaded. Verify FTP root, domain document root and Apache AllowOverride.')
        finally:
            ftp.delete(canary)  # Only the newly generated non-sensitive test file is removed.
        # Keep a recoverable copy of the previous entry point, without printing its contents.
        try:
            previous = io.BytesIO()
            ftp.retrbinary('RETR index.php', previous.write)
            upload(ftp, '_namira/releases/index-' + uuid.uuid4().hex + '.php', previous.getvalue())
        except error_perm as error:
            if not str(error).startswith('550'):
                raise
        count = 0
        for folder in ['app', 'views', 'database', 'bin', 'docs']:
            for local in sorted((ROOT / folder).rglob('*')):
                if local.is_file():
                    upload(ftp, '_namira/' + local.relative_to(ROOT).as_posix(), local.read_bytes())
                    count += 1
        upload(ftp, '_namira/.env.example', (ROOT / '.env.example').read_bytes())
        ensure_dir(ftp, '_namira/storage')
        ftp.storbinary('STOR _namira/storage/.htaccess', io.BytesIO(b'Require all denied\n'))
        for local in sorted((ROOT / 'public').rglob('*')):
            if local.is_file() and local.name != 'index.php':
                upload(ftp, local.relative_to(ROOT / 'public').as_posix(), local.read_bytes())
                count += 1
        index = b"<?php\ndeclare(strict_types=1);\nrequire __DIR__.'/_namira/app/bootstrap.php';\nNamira\\Web::run();\n"
        upload(ftp, 'index.php', index)
        print(f'FTPS upload completed ({count + 1} application/public files). Existing .env and storage preserved.')
        status = http_status('/index.php?page=home')
        if status == 503:
            print('FILES UPLOADED; SITE NOT READY: create _namira/.env, configure MySQL, then run migration and create the administrator in cPanel Terminal.')
            return 2
        if status != 200:
            raise RuntimeError(f'Files uploaded but homepage returned HTTP {status}; inspect cPanel error log')
        if http_status('/_namira/.env.example') != 403:
            raise RuntimeError('Private directory protection failed after deployment')
        print('VERIFIED: homepage HTTP 200 and private application directory HTTP 403.')
        return 0
    finally:
        try:
            ftp.quit()
        except Exception:
            ftp.close()


if __name__ == '__main__':
    try:
        sys.exit(deploy())
    except Exception as error:
        print(f'Deployment stopped: {type(error).__name__}: {error}', file=sys.stderr)
        sys.exit(1)
