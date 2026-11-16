<?php
declare(strict_types=1);
namespace Namira {
 function curl_init(string $url):object {$GLOBALS['transport']['url']=$url;return (object)[];}
 function curl_setopt_array($handle,array $options):bool {$GLOBALS['transport']['options']=$options;return true;}
 function curl_exec($handle):bool {$GLOBALS['transport']['calls']++;if($GLOBALS['transport']['timeout'])return false;$body=$GLOBALS['transport']['body'];$writer=$GLOBALS['transport']['options'][CURLOPT_WRITEFUNCTION];return $writer($handle,$body)===strlen($body);}
 function curl_getinfo($handle,int $key):int {return $GLOBALS['transport']['status'];}
 function curl_close($handle):void {}
}
namespace {
 require dirname(__DIR__).'/app/bootstrap.php';
 putenv('APP_ENV=testing');putenv('EITAA_ENABLED=1');putenv('EITAA_TOKEN=fake-transport-token');
 $transport=['calls'=>0,'timeout'=>false,'body'=>'{"ok":true,"result":"success"}','status'=>200];$checks=0;
 function assertTransport(bool $v,string $name):void {global $checks;if(!$v)throw new RuntimeException($name);$checks++;echo "PASS $name\n";}
 Namira\EitaaApp::send('101','پیام آزمایشی بدون تماس شبکه');
 assertTransport($transport['url']==='https://eitaayar.ir/api/app/sendMessage','fixed documented endpoint');
 $o=$transport['options'];$data=json_decode($o[CURLOPT_POSTFIELDS],true);
 assertTransport($data['chat_id']===101&&$data['token']==='fake-transport-token'&&str_contains($data['text'],'پیام'),'documented payload and numeric chat ID');
 assertTransport($o[CURLOPT_POST]&&$o[CURLOPT_SSL_VERIFYPEER]&&$o[CURLOPT_SSL_VERIFYHOST]===2&&!$o[CURLOPT_FOLLOWLOCATION],'POST TLS verification and redirects disabled');
 assertTransport($o[CURLOPT_TIMEOUT]===12&&$o[CURLOPT_CONNECTTIMEOUT]===5,'bounded connect and total timeout');
 foreach([['{"ok":false}',200,false],['{"ok":true,"result":"no"}',200,false],['not-json',200,false],['{"ok":true,"result":"success"}',500,false],['',200,true],[str_repeat('x',33000),200,false]] as [$body,$status,$timeout]){$transport['body']=$body;$transport['status']=$status;$transport['timeout']=$timeout;$before=$transport['calls'];try{Namira\EitaaApp::send('101','آزمون');throw new RuntimeException('Expected failure');}catch(Namira\HttpError $e){assertTransport($e->status===503&&!str_contains($e->getMessage(),'fake-transport-token'),'provider failure is safe and redacted');}assertTransport($transport['calls']===$before+1,'uncertain provider result never automatically retries');}
 foreach(['0','-2','abc','9999999999999999999'] as $id){$before=$transport['calls'];try{Namira\EitaaApp::send($id,'متن');throw new RuntimeException('Expected rejection');}catch(Namira\HttpError $e){assertTransport($e->status===422&&$transport['calls']===$before,'invalid destination rejected before transport');}}
 echo "$checks transport checks passed; all cURL calls mocked.\n";
}
