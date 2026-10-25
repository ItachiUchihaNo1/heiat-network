'use strict';
const fs=require('node:fs'),vm=require('node:vm');const source=fs.readFileSync(__dirname+'/../public/assets/image-previews.js','utf8');
(async()=>{
 let checks=0;const check=(ok,label)=>{if(!ok)throw Error(label);checks++;};
 let calls=[],closed=0,canvases=[];
 const window={async createImageBitmap(file){calls.push(file);return {width:file.width,height:file.height,close(){closed++;}};}};
 const context={window,URL,Image:class{},document:{createElement(){const c={width:0,height:0,getContext(){return {drawImage(){c.drawn=[c.width,c.height];}};},toBlob(fn,type){fn({type,size:1234});}};canvases.push(c);return c;}}};
 vm.runInNewContext(source,context);
 const progress=[],files=[{name:'48mp.jpg',width:8000,height:6000},{name:'portrait.png',width:6000,height:9000},{name:'long.webp',width:100,height:20000}];
 const result=await window.NamiraImagePreviews(files,(n,total)=>progress.push([n,total]));
 check(result.length===3&&result.every(x=>x.type==='image/webp'),'all companions generated as WebP');
 check(canvases[0].drawn.join(',')==='1600,1200','landscape fit preserves aspect ratio');
 check(canvases[1].drawn.join(',')==='1067,1600','portrait is bounded by longest edge');
 check(canvases[2].drawn[1]===1600,'thin portrait cannot create unbounded canvas height');
 check(closed===3&&canvases.every(c=>c.width===1&&c.height===1),'bitmaps and canvas buffers released');
 check(calls.every((v,i)=>v===files[i]),'original File objects remain unchanged');
 check(progress[0][0]===0&&progress.at(-1)[0]===3,'preparation reports real completed-file count');
 try{await window.NamiraImagePreviews([{name:'too-large.jpg',width:40001,height:200}],()=>{});throw Error('accepted unsafe image');}catch(e){check(e.message.includes('too-large.jpg')&&e.message.includes('۴۰۰۰۰'),'unsafe dimensions rejected with file name');}
 check(closed===4,'bitmap released on error');
 console.log(checks+' client-preview checks passed');
})().catch(e=>{console.error(e);process.exitCode=1;});

