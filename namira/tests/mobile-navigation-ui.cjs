'use strict';
// Isolated CI fixture only; never signs in to or writes to the hosted site.
const fs=require('node:fs'),path=require('node:path'),{spawn}=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
(async()=>{
 const manifest=path.resolve(process.env.NAMIRA_UI_MANIFEST||'storage/store-ui-fixture.json'),m=JSON.parse(fs.readFileSync(manifest,'utf8'));
 assert.equal(m.dsn,'sqlite:'+m.storage+'/db.sqlite');
 const artifacts=path.resolve('storage/navigation-ui-artifacts');fs.mkdirSync(artifacts,{recursive:true});
 const key=require('node:crypto').randomBytes(32).toString('hex'),base='http://127.0.0.1:8789';
 const server=spawn('php',['-S','127.0.0.1:8789','-t','public','tests/store-ui-router.php'],{cwd:path.resolve(__dirname,'..'),env:{...process.env,NAMIRA_UI_TEST:'1',NAMIRA_UI_MANIFEST:manifest,NAMIRA_UI_KEY:key},stdio:['ignore','pipe','pipe']});
 let logs='',browser;server.stdout.on('data',b=>logs+=b);server.stderr.on('data',b=>logs+=b);const checks=[];
 const pass=s=>{checks.push(s);console.log('PASS '+s);};
 try{
  for(let i=0;i<60;i++){try{await fetch(base+'/index.php?page=home');break;}catch(e){await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({headless:true});
  for(const width of [320,360,390,430,760]){
   const context=await browser.newContext({viewport:{width,height:width===320?568:844},isMobile:true,hasTouch:true});
   await context.route('**/*',route=>new URL(route.request().url()).hostname==='127.0.0.1'?route.continue():route.abort());
   const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
   assert.equal((await page.goto(base+'/index.php?page=home')).status(),200);
   const bottom=page.getByRole('navigation',{name:'ناوبری پایین موبایل'}),toggle=page.getByRole('button',{name:'باز کردن منوی سایت'}),dialog=page.getByRole('dialog',{name:'منوی نامیرا'});
   assert.equal(await bottom.locator('a').count(),5);assert(await bottom.isVisible());assert(!(await page.locator('.desktop-navigation').isVisible()));assert(!(await page.locator('.header-actions').isVisible()));
   assert.deepEqual(await bottom.locator('.nm-bottom-label').allTextContents(),['خانه','تصاویر','هنرمندان','فروشگاه','حساب']);
   assert.equal(await bottom.locator('[aria-current=page]').innerText(),'خانه');
   const rects=await bottom.locator('a').evaluateAll(nodes=>nodes.map(n=>({x:n.getBoundingClientRect().x,w:n.getBoundingClientRect().width,h:n.getBoundingClientRect().height})));
   assert(rects.every((r,i)=>r.w>=44&&r.h>=44&&(!i||r.x<rects[i-1].x)));
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
   assert(await page.locator('body').evaluate(el=>parseFloat(getComputedStyle(el).paddingBottom)>=96));
   pass(width+'px five RTL touch targets, compact header, content clearance and no overflow');
   await page.evaluate(()=>window.scrollTo(0,150));const before=await bottom.boundingBox();await page.evaluate(()=>window.scrollTo(0,350));const after=await bottom.boundingBox();assert(Math.abs(before.y-after.y)<1);
   await toggle.click();await dialog.waitFor({state:'visible'});await page.waitForFunction(()=>Math.abs(document.querySelector('[data-mobile-drawer]').getBoundingClientRect().x)<1);
   assert.equal(await toggle.getAttribute('aria-expanded'),'true');assert.equal(await dialog.locator('h3').count(),4);
   assert(await page.evaluate(()=>getComputedStyle(document.body).position==='fixed'));
   assert(await page.getByRole('button',{name:'بستن منو',exact:true}).evaluate(el=>el===document.activeElement));
   const dr=await dialog.boundingBox();assert(Math.abs(dr.x)<1&&dr.width<=width*.89&&dr.height<=844);
   assert.equal(await dialog.getByRole('link',{name:'بانک شهدا',exact:true}).count(),0);
   assert(await dialog.getByRole('link',{name:'ورود / ثبت‌نام',exact:true}).isVisible()||await dialog.getByRole('link',{name:'ورود / ثبت‌نام',exact:true}).count()===1);
   await dialog.locator('a').last().focus();await page.keyboard.press('Tab');assert(await page.getByRole('button',{name:'بستن منو',exact:true}).evaluate(el=>el===document.activeElement));
   await page.keyboard.press('Escape');await dialog.waitFor({state:'hidden'});assert.equal(await toggle.getAttribute('aria-expanded'),'false');assert(await toggle.evaluate(el=>el===document.activeElement));
   assert.equal(await page.locator('body').evaluate(el=>el.style.position),'');
   pass(width+'px drawer focus, background lock, Escape and focus restoration');
   await toggle.click();await dialog.waitFor({state:'visible'});await page.waitForFunction(()=>Math.abs(document.querySelector('[data-mobile-drawer]').getBoundingClientRect().x)<1);await page.mouse.click(width-4,200);await dialog.waitFor({state:'hidden'});
   pass(width+'px outside tap dismisses drawer');
   await toggle.click();await dialog.waitFor({state:'visible'});await page.waitForFunction(()=>Math.abs(document.querySelector('[data-mobile-drawer]').getBoundingClientRect().x)<1);
   if(width===390){await page.screenshot({path:path.join(artifacts,'mobile-drawer.png')});console.log('VISUAL:navigation-drawer:'+((await page.screenshot({type:'jpeg',quality:55})).toString('base64')));}
   await dialog.getByRole('link',{name:'دسته‌بندی تصاویر و شهدا',exact:true}).click();
   await page.waitForURL(/page=gallery#gallery-filters/);assert(await page.locator('#gallery-filters').isVisible());assert.equal(await bottom.locator('[aria-current=page]').innerText(),'تصاویر');
   await toggle.click();await dialog.waitFor({state:'visible'});await page.waitForFunction(()=>Math.abs(document.querySelector('[data-mobile-drawer]').getBoundingClientRect().x)<1);assert.equal(await dialog.getByRole('link',{name:'دسته‌بندی تصاویر و شهدا',exact:true}).getAttribute('aria-current'),'page');
   await page.getByRole('button',{name:'بستن منو',exact:true}).click();await dialog.waitFor({state:'hidden'});
   const chip=page.locator('[data-filter-chip]').first();if(await chip.count()){await chip.click();await page.locator('[data-gallery-dialog]').waitFor({state:'visible'});await page.keyboard.press('Escape');await page.locator('[data-gallery-dialog]').waitFor({state:'hidden'});}
   pass(width+'px real categories anchor, active gallery and gallery dialog coexist');
   for(const [target,label] of [['artists','هنرمندان'],['shop','فروشگاه'],['member-login','حساب']]){
    await bottom.locator('a[href*="page='+target+'"]').click();await page.waitForURL('**/index.php?page='+target);
    assert.equal(await bottom.locator('[aria-current=page]').innerText(),label);
   }
   pass(width+'px real artist/store/account destinations and active selection');
   await page.goto(base+'/index.php?page=home');
   if(width===320){await page.screenshot({path:path.join(artifacts,'mobile-home.png')});console.log('VISUAL:navigation-home:'+((await page.screenshot({type:'jpeg',quality:55})).toString('base64')));}
   await toggle.click();await dialog.waitFor({state:'visible'});await page.waitForFunction(()=>Math.abs(document.querySelector('[data-mobile-drawer]').getBoundingClientRect().x)<1);await page.setViewportSize({width:1280,height:900});await dialog.waitFor({state:'hidden'});await page.waitForFunction(()=>document.body.style.position!=='fixed');assert.equal(await page.locator('body').evaluate(el=>el.style.position),'');
   assert(!(await bottom.isVisible()));assert(!(await toggle.isVisible()));assert(await page.locator('.desktop-navigation').isVisible());assert(await page.locator('.header-actions').isVisible());
   pass(width+'px resize closes drawer and restores desktop navigation');
   assert.deepEqual(errors,[]);await context.close();
  }
  const context=await browser.newContext({viewport:{width:390,height:844}});
  await context.route('**/*',route=>new URL(route.request().url()).hostname==='127.0.0.1'?route.continue():route.abort());
  assert.equal((await context.request.get(base+'/__test/login?role=buyer',{headers:{'X-Namira-Test-Key':key}})).status(),200);
  const page=await context.newPage();await page.goto(base+'/index.php?page=account');
  const bottom=page.locator('.nm-bottom-nav');assert.equal(await bottom.locator('[aria-current=page]').innerText(),'حساب');assert((await bottom.locator('a').last().getAttribute('href')).includes('page=account'));
  await page.locator('[data-mobile-menu]').click();const dialog=page.locator('[data-mobile-drawer]');await dialog.waitFor({state:'visible'});await page.waitForFunction(()=>Math.abs(document.querySelector('[data-mobile-drawer]').getBoundingClientRect().x)<1);
  assert.equal(await dialog.getByRole('link',{name:'ورود / ثبت‌نام',exact:true}).count(),0);
  assert.equal(await dialog.getByRole('link',{name:'حساب کاربری',exact:true}).count(),1);
  await dialog.getByRole('link',{name:'سفارش‌های من',exact:true}).click();await page.waitForURL(/page=store-account/);
  await page.locator('[data-mobile-menu]').click();await page.locator('[data-mobile-drawer]').getByRole('button',{name:'خروج از حساب',exact:true}).click();await page.waitForLoadState('domcontentloaded');
  assert((await page.locator('.nm-bottom-nav a').last().getAttribute('href')).includes('page=member-login'));
  pass('member account, real orders route and CSRF-protected logout restore guest menu');
  await page.goto(base+'/index.php?page=home');await page.setViewportSize({width:1440,height:1000});await page.screenshot({path:path.join(artifacts,'desktop-home.png')});assert(!(await page.locator('.nm-bottom-nav').isVisible()));assert(!(await page.locator('[data-mobile-menu]').isVisible()));assert(await page.locator('.desktop-navigation').isVisible());pass('1440px desktop retains original navigation without bottom bar');
  fs.writeFileSync(path.join(artifacts,'results.json'),JSON.stringify({checks:checks.length,items:checks,status:'success'},null,2));console.log(checks.length+' mobile navigation browser checks passed');
 }catch(e){fs.writeFileSync(path.join(artifacts,'failure.txt'),e.stack||String(e));throw e;}
 finally{if(browser)await browser.close();server.kill();fs.writeFileSync(path.join(artifacts,'server.log'),logs);}
})().catch(e=>{console.error(e);process.exitCode=1;});
