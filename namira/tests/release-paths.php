<?php
declare(strict_types=1);
$source=dirname(__DIR__);$tmp=$source.'/storage/release-paths-'.bin2hex(random_bytes(6));$shared=$tmp.'/_namira';$release=$shared.'/releases/fixture';mkdir($release.'/app',0700,true);mkdir($shared.'/storage',0700,true);
foreach(['bootstrap.php','Env.php','Installer.php'] as $file)copy($source.'/app/'.$file,$release.'/app/'.$file);
file_put_contents($shared.'/.namira-shared-root',"namira-private-shared-v1\n");file_put_contents($shared.'/.setup.key',str_repeat('a',64));file_put_contents($shared.'/config.local.json',json_encode(['NAMIRA_RELEASE_TEST'=>'shared-config']));
$release=realpath($release);$shared=realpath($shared);
require $release.'/app/bootstrap.php';$checks=0;
function ok(bool $condition,string $message):void{global $checks;if(!$condition)throw new RuntimeException('FAIL '.$message);$checks++;echo 'PASS '.$message."\n";}
try{
    ok(BASE_PATH===$release,'views and code remain within the selected release');
    ok(Namira\Env::privateRoot()===$shared,'CLI discovers only explicitly marked shared root');
    ok(getenv('NAMIRA_RELEASE_TEST')==='shared-config','release loads existing shared private configuration');
    putenv('STORAGE_PATH');ok(Namira\Env::storage()===$shared.'/storage','originals use shared storage outside release');
    ok(!(new Namira\Installer())->enabled(),'shared existing configuration prevents reinstall');
    unlink($shared.'/config.local.json');ok((new Namira\Installer())->enabled(),'fresh setup finds shared setup key');
    file_put_contents($shared.'/.setup.lock','installed');ok(!(new Namira\Installer())->enabled(),'shared installation lock survives release changes');
    $other=$tmp.'/explicit';mkdir($other);file_put_contents($other.'/.setup.key',str_repeat('b',64));ok((new Namira\Installer($other))->enabled(),'explicit isolated installer root remains supported');
    echo "$checks shared release path checks passed.\n";
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $item){if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($tmp);
}
