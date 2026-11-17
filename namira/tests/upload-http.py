"""Isolated real Nginx/FPM multipart boundary regression; never touches a live site."""
import importlib.util,json,pathlib,subprocess,tempfile,shutil
source=pathlib.Path(__file__).resolve().parents[1]/'deploy/upload_limits.py'
spec=importlib.util.spec_from_file_location('upload_limits',source);policy=importlib.util.module_from_spec(spec);spec.loader.exec_module(policy)
base=pathlib.Path(tempfile.mkdtemp(prefix='namira-upload-http-',dir='/tmp'));base.chmod(0o755)
root=base/'public';root.mkdir(mode=0o755)
(root/'probe.php').write_text('<?php header("Content-Type: application/json"); echo json_encode($_FILES);')
(root/'probe.php').chmod(0o644)
conf=base/'nginx.conf'
text='user www-data;\nworker_processes 1;\npid '+str(base/'nginx.pid')+';\nerror_log '+str(base/'error.log')+';\nevents { worker_connections 32; }\nhttp { access_log off; client_body_temp_path '+str(base/'body')+'; server { listen 127.0.0.1:18897; root '+str(root)+'; location ~ [.]php$ { include /etc/nginx/fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php8.3-fpm.sock; } } }'
conf.write_text(policy.transform(text,str(root)))
args=['/usr/sbin/nginx','-p',str(base)+'/', '-c',str(conf)]
started=False
try:
 subprocess.run(['sudo']+args+['-t'],check=True)
 subprocess.run(['sudo','/usr/bin/systemctl','start','php8.3-fpm'],check=True)
 subprocess.run(['sudo']+args,check=True);started=True
 file=base/'boundary.jpg'
 with file.open('wb') as stream:stream.truncate(50*1048576)
 def upload(*fields):
  command=['curl','--fail','--silent','--show-error','--max-time','60']
  for field in fields:command+=['-F',field+'=@'+str(file)]
  return json.loads(subprocess.check_output(command+['http://127.0.0.1:18897/probe.php']))
 exact=upload('image');assert exact['image']['error']==0 and exact['image']['size']==50*1048576
 print('PASS real Nginx/FPM accepts exact 50 MiB file')
 batch=upload('first','second');assert all(batch[k]['error']==0 and batch[k]['size']==50*1048576 for k in ['first','second'])
 print('PASS real Nginx/FPM accepts multipart batch over old 20/50 MiB request limits')
 with file.open('ab') as stream:stream.write(b'x')
 over=upload('image');assert over['image']['error']==1
 print('PASS real FPM rejects 50 MiB plus one byte at file boundary')
finally:
 if started:subprocess.run(['sudo']+args+['-s','quit'],check=True)
 subprocess.run(['sudo','rm','-rf','--',str(base)],check=True)
