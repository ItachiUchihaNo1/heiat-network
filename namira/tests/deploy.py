"""Isolated deployment failure tests; never connects to a real FTP server."""
import importlib.util
import io
import os
from pathlib import Path
import posixpath
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('namira_ftps', Path(__file__).parents[1] / 'deploy/ftps.py')
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)
OLD = b"<?php require __DIR__.'/_namira/app/bootstrap.php'; Namira\\Web::run();"

class FakeFTP:
    def __init__(self):
        self.files = {'Public_html/new/index.php': OLD, 'Public_html/new/_namira/.env': b'private-config', 'Public_html/new/_namira/storage/original.jpg': b'original'}
        self.events = []
        self.current = ''
        self.fail = None
    def connect(self, host, port): self.events.append(('connect', host, port))
    def login(self, user, password): pass
    def prot_p(self): self.events.append(('private-data',))
    def pwd(self): return self.current
    def cwd(self, path): self.current = posixpath.normpath(path if path.startswith('/') or path.startswith('Public_html') else posixpath.join(self.current, path))
    def mkd(self, path): pass
    def path(self, path): return posixpath.join(self.current, path)
    def storbinary(self, command, stream):
        path = self.path(command[5:])
        if self.fail and self.fail in path: raise OSError('Injected upload failure')
        self.files[path] = stream.read()
        self.events.append(('store', path))
    def retrbinary(self, command, callback):
        path = self.path(command[5:])
        if path not in self.files: raise deploy.error_perm('550 Missing')
        callback(self.files[path])
    def rename(self, source, target):
        self.files[self.path(target)] = self.files.pop(self.path(source))
        self.events.append(('activate' if target == 'index.php' else 'rename', self.path(target)))
    def delete(self, path): del self.files[self.path(path)]
    def voidcmd(self, command): pass
    def sendcmd(self, command): pass
    def size(self, path):
        if self.path(path) not in self.files: raise deploy.error_perm('550 Missing')
        return len(self.files[self.path(path)])
    def quit(self): pass
    def close(self): pass

class DeploymentTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(dir=Path.cwd())
        self.root = Path(self.tmp.name)
        for folder in ['app','views','database','bin','docs','licenses','public/assets']:
            (self.root / folder).mkdir(parents=True)
            (self.root / folder / 'sample.txt').write_text('release')
        (self.root / '.env.example').write_text('sample')
        (self.root / 'public/index.php').write_text('must-not-copy')
        self.ftp = FakeFTP()
        self.stack = [patch.object(deploy,'ROOT',self.root),patch.object(deploy,'FTP_TLS',return_value=self.ftp),patch.dict(os.environ,{'DEPLOY_FTPS_HOST':'host.example','DEPLOY_FTPS_USERNAME':'test','DEPLOY_FTPS_PASSWORD':'fixture','NAMIRA_RELEASE_ID':'test-release'}),patch.object(deploy,'http_status',side_effect=lambda path: 403 if path.startswith('/_namira/') else 200)]
        for item in self.stack: item.start()
    def tearDown(self):
        for item in reversed(self.stack): item.stop()
        self.tmp.cleanup()
    def test_stages_before_activation_and_preserves_shared_files(self):
        self.assertEqual(deploy.deploy(),0)
        index = self.ftp.files['Public_html/new/index.php']
        self.assertIn(b"define('NAMIRA_SHARED_PATH'",index)
        self.assertIn(b'/_namira/releases/test-release-',index)
        activation = [i for i,event in enumerate(self.ftp.events) if event[0]=='activate']
        self.assertEqual(len(activation),1)
        self.assertTrue(all(event[0]!='store' for event in self.ftp.events[activation[0]+1:]))
        self.assertEqual(self.ftp.files['Public_html/new/_namira/.env'],b'private-config')
        self.assertEqual(self.ftp.files['Public_html/new/_namira/storage/original.jpg'],b'original')
        self.assertNotIn('Public_html/new/_namira/app/sample.txt',self.ftp.files)
        self.assertEqual(self.ftp.events[0],('connect','host.example',21))
        self.assertIn(('private-data',),self.ftp.events)
    def test_upload_failure_does_not_switch(self):
        self.ftp.fail='/views/'
        with self.assertRaises(OSError): deploy.deploy()
        self.assertEqual(self.ftp.files['Public_html/new/index.php'],OLD)
    def test_wrong_site_rejected_before_writes(self):
        self.ftp.files['Public_html/new/index.php']=b'<?php wordpress();'
        with self.assertRaisesRegex(RuntimeError,'does not belong'): deploy.deploy()
        self.assertFalse(any(event[0]=='store' for event in self.ftp.events))
    def test_configured_http_failure_restores_previous(self):
        with patch.object(deploy,'http_status',side_effect=lambda path:403 if path.startswith('/_namira/') else 503):
            with self.assertRaisesRegex(RuntimeError,'503'): deploy.deploy()
        self.assertEqual(self.ftp.files['Public_html/new/index.php'],OLD)
        self.assertEqual(sum(event[0]=='activate' for event in self.ftp.events),2)
    def test_private_directory_failure_prevents_switch(self):
        with patch.object(deploy,'http_status',return_value=200):
            with self.assertRaisesRegex(RuntimeError,'403'): deploy.deploy()
        self.assertEqual(self.ftp.files['Public_html/new/index.php'],OLD)
    def test_fresh_setup_uses_shared_key(self):
        self.ftp.files.clear()
        with patch.object(deploy,'http_status',side_effect=lambda path:403 if path.startswith('/_namira/') else 503): self.assertEqual(deploy.deploy(),2)
        self.assertEqual(len(self.ftp.files['Public_html/new/_namira/.setup.key']),64)
    def test_retry_uses_new_release_directory(self):
        deploy.deploy(); first=self.ftp.files['Public_html/new/index.php']
        deploy.deploy(); self.assertNotEqual(first,self.ftp.files['Public_html/new/index.php'])
    def test_invalid_release_identifier_rejected(self):
        with patch.dict(os.environ,{'NAMIRA_RELEASE_ID':'../other'}):
            with self.assertRaisesRegex(RuntimeError,'identifier'): deploy.deploy()
        self.assertFalse(self.ftp.events)

if __name__ == '__main__': unittest.main()
