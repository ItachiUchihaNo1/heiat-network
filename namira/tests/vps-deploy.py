import hashlib,importlib.util,io,json,os,pathlib,sys,tarfile,tempfile,unittest
from unittest.mock import patch
DEPLOY=pathlib.Path(__file__).parents[1]/'deploy';sys.path.insert(0,str(DEPLOY))
import package_vps as pkg
import vps_gateway as gateway
import install_vps as installer

REV='a'*40
OLD='b'*40+'-'+'c'*12
class VPS(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory();self.root=pathlib.Path(self.temp.name);self.source=self.root/'source'
  for p in ['app','views','database','bin','public/assets']:(self.source/p).mkdir(parents=True,exist_ok=True)
  for p in ['app/bootstrap.php','views/layout.php','database/migrate.php','bin/deploy-check.php']:(self.source/p).write_text('<?php // fixture')
  (self.source/'public/assets/font.css').write_text('body{}');(self.source/'storage').mkdir();(self.source/'storage/original.jpg').write_bytes(b'private original');(self.source/'config.local.json').write_text('private credential');(self.source/'.env').write_text('PRIVATE=secret')
 def tearDown(self):self.temp.cleanup()
 def archive(self):return pkg.build(self.source,REV)[0]
 def stage(self,check=None,health=None):
  private=self.root/'app';state=private/'deploy-state';releases=private/'releases';state.mkdir(parents=True);(releases/OLD).mkdir(parents=True);(private/'storage').mkdir();(private/'storage/photo.jpg').write_bytes(b'KEEP');(private/'config.local.json').write_bytes(b'KEEP_CONFIG');os.symlink('../releases/'+OLD,state/'current')
  roots=[]
  for name in ['public_html','admin.naamiraa.ir']:
   folder=self.root/name;folder.mkdir();(folder/'index.php').write_bytes(b'KEEP_ENTRY');os.symlink(str(state/'current/public/assets'),folder/'assets');roots.append(str(folder))
  policy={'private_root':str(private),'public_root':roots[0],'admin_root':roots[1],'php':'/usr/bin/php8.3'}
  return gateway.Deployment(policy,check=check or (lambda *_:{'ok':True,'backup':'fixture-only'}),health=health or (lambda *_:None))
 def test_code_package_excludes_live_files(self):
  raw=self.archive();files,manifest=pkg.validate(raw,REV);self.assertNotIn('config.local.json',files);self.assertFalse(any('storage' in p or p.startswith('.') for p in files));self.assertEqual(len(files),5)
 def test_deterministic_package(self):self.assertEqual(self.archive(),self.archive())
 def test_symlink_source_rejected(self):
  os.symlink(self.source/'config.local.json',self.source/'app/leak.json')
  with self.assertRaises(ValueError):self.archive()
 def test_private_configuration_in_code_tree_rejected(self):
  (self.source/'app/config.local.json').write_text('secret')
  with self.assertRaises(ValueError):self.archive()
 def test_tar_traversal_and_links_rejected(self):
  for name,kind in [('../storage/original.jpg',tarfile.REGTYPE),('app/link.php',tarfile.SYMTYPE)]:
   out=io.BytesIO()
   with tarfile.open(fileobj=out,mode='w:gz') as archive:
    item=tarfile.TarInfo(name);item.type=kind;item.size=0;item.linkname='/etc/shadow' if kind==tarfile.SYMTYPE else '';archive.addfile(item)
   with self.assertRaises(ValueError):pkg.validate(out.getvalue(),REV)
 def test_manifest_revision_must_match(self):
  with self.assertRaises(ValueError):pkg.validate(self.archive(),'f'*40)
 def test_only_fixed_ssh_commands_allowed(self):
  self.assertEqual(gateway.command('status'),['status']);self.assertEqual(gateway.command('deploy '+REV+' '+'d'*64)[1],REV)
  for value in ['bash','status extra','deploy ../../etc x','status; id','deploy '+REV+' '+'d'*64+'; id']:
   with self.assertRaises((gateway.DeployError,ValueError)):gateway.command(value)
 def test_success_atomic_pointer_and_live_files_preserved(self):
  d=self.stage();raw=self.archive();digest=hashlib.sha256(raw).hexdigest()
  with patch.object(gateway.subprocess,'run') as run:
   run.return_value.returncode=0;result=d.apply(raw,REV,digest)
  self.assertTrue(result['ok']);self.assertEqual(d.current(),REV+'-'+digest[:12]);self.assertEqual((d.private/'storage/photo.jpg').read_bytes(),b'KEEP');self.assertEqual((d.private/'config.local.json').read_bytes(),b'KEEP_CONFIG');self.assertEqual((self.root/'public_html/index.php').read_bytes(),b'KEEP_ENTRY')
 def test_bad_digest_never_changes_pointer(self):
  d=self.stage()
  with self.assertRaises(gateway.DeployError):d.apply(self.archive(),REV,'0'*64)
  self.assertEqual(d.current(),OLD)
 def test_preflight_failure_leaves_current_untouched(self):
  def fail(*_):raise gateway.DeployError('fixture_preflight')
  d=self.stage(check=fail);raw=self.archive()
  with patch.object(gateway.subprocess,'run') as run:
   run.return_value.returncode=0
   with self.assertRaises(gateway.DeployError):d.apply(raw,REV,hashlib.sha256(raw).hexdigest())
  self.assertEqual(d.current(),OLD)
 def test_health_failure_rolls_back_code(self):
  def fail(*_):raise gateway.DeployError('fixture_health')
  d=self.stage(health=fail);raw=self.archive()
  with patch.object(gateway.subprocess,'run') as run:
   run.return_value.returncode=0
   with self.assertRaises(gateway.DeployError):d.apply(raw,REV,hashlib.sha256(raw).hexdigest())
  self.assertEqual(d.current(),OLD);self.assertEqual((d.private/'storage/photo.jpg').read_bytes(),b'KEEP')
 def test_repeated_release_is_idempotent(self):
  calls=[];d=self.stage(check=lambda *_:calls.append(1) or {'backup':'test'});raw=self.archive();digest=hashlib.sha256(raw).hexdigest()
  with patch.object(gateway.subprocess,'run') as run:
   run.return_value.returncode=0;d.apply(raw,REV,digest);result=d.apply(raw,REV,digest)
  self.assertTrue(result['already_current']);self.assertEqual(calls,[1])
 def test_pointer_escape_rejected(self):
  d=self.stage();(d.state/'current').unlink();os.symlink('/etc',d.state/'current')
  with self.assertRaises(gateway.DeployError):d.current()
 def test_setup_refuses_non_public_keys(self):
  for value in ['-----BEGIN OPENSSH PRIVATE KEY-----','ssh-rsa AAAA','ssh-ed25519 AAAA\ncommand=bad']:
   with self.assertRaises(ValueError):installer.public_key(value)

if __name__=='__main__':unittest.main()
