'use strict';
const CACHE='namira-pwa-shell-v1',OFFLINE='/index.php?page=pwa-offline',SHELL=[OFFLINE,...[32,48,96,180,192,512].map(n=>'/assets/icons/icon-'+n+'.png'),'/assets/icons/icon-maskable-512.png'];
self.addEventListener('install',event=>event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(SHELL.map(url=>new Request(url,{credentials:'omit',cache:'reload'}))))));
self.addEventListener('activate',event=>event.waitUntil((async()=>{for(const name of await caches.keys())if(name.startsWith('namira-pwa-shell-')&&name!==CACHE)await caches.delete(name);await self.clients.claim();})()));
self.addEventListener('message',event=>{if(event.data?.type==='ACTIVATE')self.skipWaiting();});
function publicDocument(url){if(url.pathname==='/index.php')return ['home','gallery','image','martyrs','profile','artists','artist','calls','call','about','advanced-search'].includes(url.searchParams.get('page')||'home');return ['/', '/images','/martyrs','/artists','/calls','/about','/advanced-search'].includes(url.pathname)||/^\/(images|martyrs|artists|calls)\/\d+(?:\/[^/]*)?\/?$/.test(url.pathname);}
self.addEventListener('fetch',event=>{const r=event.request,u=new URL(r.url);if(r.method!=='GET'||u.origin!==self.location.origin)return;
 // Dynamic pages, API responses, private data, previews and original downloads never enter Cache Storage.
 if(r.mode==='navigate'&&publicDocument(u)){event.respondWith(fetch(r).catch(async()=>await caches.match(OFFLINE)||new Response('اتصال اینترنت برقرار نیست',{status:503,headers:{'Content-Type':'text/plain; charset=utf-8'}})));return;}
 if(!u.search&&SHELL.includes(u.pathname)){event.respondWith(fetch(r).catch(async()=>await caches.match(u.pathname)||Response.error()));}
});
