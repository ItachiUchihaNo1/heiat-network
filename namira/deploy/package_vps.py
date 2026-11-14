"""Deterministic code-only archives. Never package runtime configuration or uploads."""
import argparse,hashlib,io,json,os,pathlib,re,tarfile,gzip

MAX_ARCHIVE=32*1024*1024
MAX_EXPANDED=128*1024*1024
EXTENSIONS={'.php','.json','.css','.js','.png','.jpg','.jpeg','.webp','.svg','.ttf','.woff','.woff2','.txt','.md'}

def allowed(name):
    p=pathlib.PurePosixPath(name)
    if not name or name.startswith('/') or '\\' in name or any(not re.fullmatch(r'[A-Za-z0-9_-][A-Za-z0-9_.-]*',x) or x in ('.','..') for x in p.parts):return False
    if p.name.lower() in ('config.local.json','config.json','credentials.json') or p.suffix.lower() not in EXTENSIONS:return False
    return len(p.parts)>1 and (p.parts[0] in ('app','views','database','bin') or len(p.parts)>2 and p.parts[:2]==('public','assets'))

def build(source,revision):
    if not re.fullmatch('[a-f0-9]{40}',revision):raise ValueError('Full commit SHA required')
    source=pathlib.Path(source).resolve();files={}
    for folder in ['app','views','database','bin','public/assets']:
        base=source/folder
        if not base.is_dir() or base.is_symlink():raise ValueError('Source folder missing or linked: '+folder)
        for current,dirs,names in os.walk(base,followlinks=False):
            for name in dirs:
                if (pathlib.Path(current)/name).is_symlink():raise ValueError('Linked source directory')
            for name in names:
                file=pathlib.Path(current)/name;relative=file.relative_to(source).as_posix()
                if file.is_symlink() or not allowed(relative):raise ValueError('Disallowed source path: '+relative)
                raw=file.read_bytes()
                if len(raw)>MAX_ARCHIVE:raise ValueError('Source file too large')
                files[relative]=raw
    if sum(map(len,files.values()))>MAX_EXPANDED or len(files)>5000:raise ValueError('Package too large')
    manifest={'format':1,'revision':revision,'files':{p:{'sha256':hashlib.sha256(b).hexdigest(),'bytes':len(b)} for p,b in sorted(files.items())}}
    files['manifest.json']=json.dumps(manifest,sort_keys=True,separators=(',',':')).encode()
    out=io.BytesIO()
    with gzip.GzipFile(fileobj=out,mode='wb',mtime=0) as zipped:
        with tarfile.open(fileobj=zipped,mode='w',format=tarfile.USTAR_FORMAT) as archive:
            for name,raw in sorted(files.items()):
                item=tarfile.TarInfo(name);item.size=len(raw);item.mode=0o640;item.mtime=0;archive.addfile(item,io.BytesIO(raw))
    raw=out.getvalue()
    if len(raw)>MAX_ARCHIVE:raise ValueError('Archive too large')
    return raw,manifest

def validate(raw,revision):
    if len(raw)>MAX_ARCHIVE:raise ValueError('Archive too large')
    files={};total=0
    with tarfile.open(fileobj=io.BytesIO(raw),mode='r:gz') as archive:
        for item in archive:
            if len(files)>=5001 or not item.isreg() or item.name in files:raise ValueError('Invalid archive entry')
            if item.name!='manifest.json' and not allowed(item.name):raise ValueError('Disallowed archive path')
            if item.size<0 or item.size>MAX_ARCHIVE:raise ValueError('Entry too large')
            total+=item.size
            if total>MAX_EXPANDED:raise ValueError('Expanded archive too large')
            files[item.name]=archive.extractfile(item).read(item.size+1)
            if len(files[item.name])!=item.size:raise ValueError('Truncated archive')
    manifest=json.loads(files.pop('manifest.json'))
    if manifest.get('format')!=1 or manifest.get('revision')!=revision or set(manifest.get('files',{}))!=set(files):raise ValueError('Manifest mismatch')
    for name,b in files.items():
        if manifest['files'][name]!={'sha256':hashlib.sha256(b).hexdigest(),'bytes':len(b)}:raise ValueError('File checksum mismatch')
    return files,manifest

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--source',required=True);parser.add_argument('--revision',required=True);parser.add_argument('--output',required=True);args=parser.parse_args()
    raw,manifest=build(args.source,args.revision);validate(raw,args.revision);target=pathlib.Path(args.output);target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(raw)
    digest=hashlib.sha256(raw).hexdigest();target.with_suffix(target.suffix+'.sha256').write_text(digest+'\n');print(json.dumps({'revision':args.revision,'sha256':digest,'files':len(manifest['files']),'bytes':len(raw)}))
