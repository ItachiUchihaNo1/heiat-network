const {chromium}=require('playwright');
const http=require('http'),fs=require('fs'),path=require('path'),assert=require('assert/strict');
const dir=process.env.CHECKOUT_UI_DIR,assets=path.resolve('namira/public');
const policy=JSON.parse(fs.readFileSync(path.join(dir,'policy.json')));
const server=http.createServer((req,res)=>{
 if(req.url==='/redirect'){res.writeHead(303,{Location:'https://ble.ir/namira_fixture?start=pay_fixture','Content-Security-Policy':policy.after});return res.end();}
 if(req.url.startsWith('/assets/')){const p=path.join(assets,req.url.split('?')[0]);res.setHeader('Content-Type',p.endsWith('.css')?'text/css':'text/javascript');return res.end(fs.readFileSync(p));}
 const name=req.url.startsWith('/cart')?'cart':'order';res.setHeader('Content-Security-Policy',req.url.includes('old')?policy.before:policy.after);res.setHeader('Content-Type','text/html; charset=utf-8');
 res.end('<!doctype html><html lang="fa" dir="rtl"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="/assets/theme.css"><link rel="stylesheet" href="/assets/application.css"><body><main class="wrap">'+fs.readFileSync(path.join(dir,name+'.html'),'utf8')+'</main></body></html>');
});
(async()=>{await new Promise(r=>server.listen(0,'127.0.0.1',r));const origin='http://127.0.0.1:'+server.address().port;const browser=await chromium.launch();const result=[];
 for(const width of [320,390,1280]){const page=await browser.newPage({viewport:{width,height:900}});for(const route of ['/cart','/order']){await page.goto(origin+route);const proof=await page.evaluate(()=>({overflow:document.documentElement.scrollWidth>innerWidth,controls:[...document.querySelectorAll('.store-customer select,.store-customer input:not([type=hidden]):not([type=checkbox]),.store-customer textarea')].map(e=>({height:e.getBoundingClientRect().height,width:e.getBoundingClientRect().width})),message:!!document.querySelector('.store-message-form')}));assert(!proof.overflow,JSON.stringify({width,route,...proof}));assert(proof.controls.every(c=>c.height>=44&&c.width>0));await page.screenshot({path:path.join(dir,route.slice(1)+'-'+width+'.png'),fullPage:true});result.push({width,route,overflow:false,controls:proof.controls.length});}await page.close();}
 const page=await browser.newPage();let redirected=0;await page.route('https://ble.ir/**',route=>{assert.equal(route.request().method(),'GET');redirected++;return route.fulfill({status:200,contentType:'text/html',body:'Bale handoff intercepted; no external request or payment'});});
 const click=async()=>{await page.locator('form').filter({has:page.locator('input[value="commerce-bale-start"]')}).evaluate(f=>f.action='/redirect');await page.getByRole('button',{name:'ادامه پرداخت در بله',exact:true}).click();};
 await page.goto(origin+'/order?old');await click();await page.waitForTimeout(500);assert.equal(redirected,0,'old self-only policy reproduces blocked handoff');
 await page.goto(origin+'/order');await click();await page.waitForURL('https://ble.ir/**');assert.equal(redirected,1,'corrected policy permits GET handoff');
 await browser.close();server.close();fs.writeFileSync(path.join(dir,'browser.json'),JSON.stringify({layouts:result,oldPolicyBlocked:true,newPolicyRedirected:true,realPayments:0},null,2));console.log('CHECKOUT_BROWSER_VERIFIED '+JSON.stringify(result));
})().catch(e=>{console.error(e);server.close();process.exit(1)});
