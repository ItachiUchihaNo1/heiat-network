"""Runs only against isolated localhost CLI servers, never deployed domains."""
import os,sys,json,pathlib,subprocess,time,http.client,urllib.parse,re
root=pathlib.Path(__file__).resolve().parents[1]
m=json.loads((root/'storage/calls-ui-fixture.json').read_text())
assert m['dsn']=='sqlite:'+m['storage']+'/db.sqlite'
servers=[];checks=[]
def request(surface,path='/',method='GET',data=None,cookie='',host=None):
    c=http.client.HTTPConnection('127.0.0.1',8792 if surface=='public' else 8793,timeout=20)
    headers={'Host':host or ('naamiraa.ir' if surface=='public' else 'admin.naamiraa.ir')}
    if cookie:headers['Cookie']=cookie
    body=urllib.parse.urlencode(data or {}) if method=='POST' else None
    if body:headers['Content-Type']='application/x-www-form-urlencoded'
    c.request(method,path,body,headers);r=c.getresponse();raw=r.read();headers=dict(r.getheaders());c.close();return r.status,headers,raw.decode('utf-8',errors='replace')
def check(v,label):
    assert v,label
    checks.append(label);print('PASS '+label)
def csrf(html):return re.search(r'name="csrf" value="([a-f0-9]+)"',html).group(1)
def cookie(headers):return headers['Set-Cookie'].split(';')[0]
try:
    for surface,port in [('public',8792),('admin',8793)]:
        env={**os.environ,'NAMIRA_DOMAIN_TEST':'1','NAMIRA_TEST_SURFACE':surface,'NAMIRA_UI_MANIFEST':str(root/'storage/calls-ui-fixture.json')}
        servers.append(subprocess.Popen([os.environ.get('PHP_BINARY','php'),'-S','127.0.0.1:'+str(port),'-t','public','tests/domain-http-router.php'],cwd=root,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL))
    for n in range(50):
        try:request('public');request('admin');break
        except OSError:time.sleep(.1)
    s,h,b=request('public');check(s==200 and 'نامیرا' in b,'public root serves public website');pc=cookie(h);check(pc.startswith('namira_public_session=') and 'Domain=' not in h['Set-Cookie'] and 'secure' in h['Set-Cookie'].lower(),'public session cookie is host-only and secure')
    s,h,b=request('public','/index.php?page=admin&tab=users');check(s==302 and h['Location']=='https://admin.naamiraa.ir/index.php?page=admin&tab=users','legacy admin GET redirects to isolated origin')
    s,h,b=request('public','/index.php?page=admin','POST',{'action':'user-save'});check(s==403,'management POST denied before CSRF and database mutation')
    s,h,b=request('admin');check(s==303 and h['Location']=='/index.php?page=login&legacy=1','admin root requires separate staff login')
    s,h,b=request('admin','/index.php?page=login');ac=cookie(h);check(s==200 and 'ورود مدیر نامیرا' in b and 'نامیرا' in b and 'desktop-navigation' not in b,'admin login uses dedicated shell without public navigation');check(ac.startswith('namira_admin_session=') and 'Domain=' not in h['Set-Cookie'],'admin cookie has independent host-only name')
    s,h,b=request('admin','/index.php?page=login','POST',{'action':'login','csrf':csrf(b),'email':'calls-admin@example.test','password':'fixture-admin-password'},ac);check(s==303 and h['Location']=='/index.php?page=admin','actual administrator login succeeds on admin origin');ac=cookie(h)
    s,h,b=request('admin','/index.php?page=admin&tab=calls',cookie=ac);check(s==200 and 'admin-sidebar' in b and 'namira-admin-site' in b and 'desktop-navigation' not in b,'authenticated admin panel rendered with isolated layout');check('noindex' in h.get('X-Robots-Tag',''),'admin pages are noindex');check('Warning:' not in b and 'Fatal error:' not in b,'admin panel has no PHP warnings')
    s,h,b=request('admin','/index.php?page=profile&id='+str(m['martyr']),cookie=ac);check(s==302 and h['Location'].startswith('https://naamiraa.ir/'),'public profile preview leaves admin origin')
    s,h,b=request('admin','/index.php?page=call-file&id='+str(m['file']),cookie=ac);check(s==200,'private admin file download preserves administrator session')
    forged='namira_admin_session='+pc.split('=',1)[1];s,h,b=request('admin','/index.php?page=admin',cookie=forged);check(s==303 and 'login' in h['Location'],'public session ID cannot become administrative session')
    s,h,b=request('public',host='evil.example');check(s==421,'untrusted Host fails closed')
    print(str(len(checks))+' domain HTTP integration checks passed')
finally:
    for p in servers:p.terminate()
    for p in servers:p.wait(timeout=10)
