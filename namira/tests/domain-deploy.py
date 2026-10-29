"""Isolated FTPS safety tests; no live network or credentials."""
import importlib.util,pathlib,unittest,posixpath,os
from unittest.mock import patch
from ftplib import error_perm
spec=importlib.util.spec_from_file_location('domains',pathlib.Path(__file__).parents[1]/'deploy/domains.py');d=importlib.util.module_from_spec(spec);spec.loader.exec_module(d)
class FTP:
 def __init__(self,*a,**kw):self.files={};self.current='/';self.created=set(['/','/public_html','/admin.naamiraa.ir']);self.events=[]
 def connect(self,*a):pass
 def login(self,*a):pass
 def prot_p(self):pass
 def pwd(self):return self.current
 def cwd(self,p):
  if p not in self.created:raise error_perm('550 missing')
  self.current=p
 def mkd(self,p):self.created.add(p)
 def storbinary(self,cmd,b):self.files[cmd[5:]]=b.read();self.events.append(cmd)
 def retrbinary(self,cmd,fn):
  if cmd[5:] not in self.files:raise error_perm('550 missing')
  fn(self.files[cmd[5:]])
 def rename(self,a,b):self.files[b]=self.files.pop(a)
 def sendcmd(self,*a):return '200'
 def delete(self,p):del self.files[p]
 def quit(self):pass
 def close(self):pass
class Safety(unittest.TestCase):
 def test_late_completion_after_proxy_timeout_is_confirmed_without_retry(self):
  from unittest.mock import Mock
  remote=Mock();remote.maybe.side_effect=[None,b'lock',None,b'lock',b'config']
  with patch.object(d.time,'sleep') as wait:self.assertTrue(d.installation_confirmed(remote,attempts=3,delay=10))
  self.assertEqual(wait.call_count,2);self.assertEqual({c.args[0] for c in remote.maybe.call_args_list},{'_namira_live/.setup.lock','_namira_live/config.local.json'})
 def test_unconfirmed_install_is_not_reported_as_success(self):
  from unittest.mock import Mock
  remote=Mock();remote.maybe.return_value=None
  with patch.object(d.time,'sleep'):self.assertFalse(d.installation_confirmed(remote,attempts=2,delay=10))
 def test_cookie_jar_is_not_shadowed_by_http_helper(self):
  jar=d.CookieJar();opener=d.build_opener(d.HTTPCookieProcessor(jar));self.assertTrue(callable(d.http));self.assertTrue(any(getattr(h,'cookiejar',None) is jar for h in opener.handlers))
 def test_entry_domains_and_private_core(self):
  public=d.entry('public');admin=d.entry('admin');self.assertIn(b"NAMIRA_SURFACE', 'public'",public);self.assertIn(b"NAMIRA_SURFACE', 'admin'",admin);self.assertIn(b"dirname(__DIR__).'/_namira_live'",admin);self.assertIn(b'PUBLIC_APP_URL=https://naamiraa.ir',public);self.assertIn(b'ADMIN_APP_URL=https://admin.naamiraa.ir',admin);self.assertNotIn(b'DB_PASSWORD',admin)
 def test_traversal_rejected(self):
  r=d.Remote(FTP())
  for p in ['../public_html','/absolute','a/./b','a/../../b']:
   with self.assertRaises(d.DeploymentError):r.path(p)
 def test_atomic_write_and_readback(self):
  f=FTP();r=d.Remote(f);r.put('_namira_live/releases/test/app/a.php',b'private',True);self.assertEqual(r.read('_namira_live/releases/test/app/a.php'),b'private');self.assertFalse(any('.upload-' in p for p in f.files))
 def test_missing_database_secret_prevents_network(self):
  with patch.dict(os.environ,{'DEPLOY_FTPS_HOST':'fixture','DEPLOY_FTPS_USER':'ouidoyhy','DEPLOY_FTPS_PASSWORD':'test-only'},clear=True),patch.object(d,'FTP_TLS') as factory:
   with self.assertRaises(d.DeploymentError):d.deploy()
   factory.assert_not_called()
 def test_wrong_admin_mapping_stops_before_application_upload(self):
  f=FTP()
  def http(url,*a,**kw):
   if url.startswith('https://admin.'):return 200,b'wrong-root',{}
   name=url.rsplit('/',1)[-1];return 200,f.files['/public_html/'+name],{}
  env={'DEPLOY_FTPS_HOST':'fixture','DEPLOY_FTPS_USER':'ouidoyhy','DEPLOY_FTPS_PASSWORD':'test-only','NAAMIRAA_DB_PASSWORD':'test-only','NAMIRA_RELEASE_ID':'a'*40}
  with patch.dict(os.environ,env,clear=True),patch.object(d,'FTP_TLS',return_value=f),patch.object(d,'http',side_effect=http),patch.object(d.ssl,'create_default_context',return_value=object()):
   with self.assertRaises(d.DeploymentError):d.deploy()
  self.assertEqual(f.files,{})
 def test_csrf_extraction_does_not_accept_missing_or_short_tokens(self):
  self.assertEqual(d.csrf(b'<input name="csrf" value="'+b'a'*64+b'">'),'a'*64)
  for data in [b'error',b'<input name="csrf" value="a">']:
   with self.assertRaises(d.DeploymentError):d.csrf(data)
if __name__=='__main__':unittest.main()
