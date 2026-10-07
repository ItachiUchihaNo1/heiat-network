import { PrismaClient } from '@prisma/client';

const prisma = new PrismaClient();

const categories = [
  ['مدیریت هیأت','dashboard'],['کودک و نوجوان','child'],['بانوان','heart'],['رسانه','media'],['جذب و ارتباط با مخاطب','network'],
  ['مالی و اقتصادی','money'],['ساختمان و فضا','building'],['برنامه‌ریزی و محتوا','content'],['تشکل و شبکه‌سازی','network'],
  ['جهاد و خدمت','service'],['آموزش و تربیت','book'],['مناسبت‌ها','star'],['مداحی و شعر','star'],['سایر','category']
] as const;

async function ensureMenu(location:string,label:string,href:string,icon:string,sortOrder:number){
  const found=await prisma.menuItem.findFirst({where:{location,label,href}});
  if(!found) await prisma.menuItem.create({data:{location,label,href,icon,sortOrder,enabled:true}});
}

async function ensureSection(pageId:string,key:string,data:any){
  return prisma.cmsSection.upsert({where:{pageId_key:{pageId,key}},update:{},create:{pageId,key,...data}});
}

async function seedCms(){
  await prisma.siteConfig.upsert({
    where:{singletonKey:'default'},
    update:{},
    create:{
      singletonKey:'default',siteName:'شبکه تجربه هیأت',siteSubtitle:'صاحب مسئله ← صاحب تجربه',searchPlaceholder:'مسئله‌ات را جست‌وجو کن...',logoText:'هـ',menuIcon:'☰',profileIcon:'○',searchIcon:'⌕',
      primaryColor:'#0b4f59',primary2Color:'#0f8a80',accentColor:'#23b49b',backgroundColor:'#f5f8f9',cardColor:'#ffffff',textColor:'#163239',mutedColor:'#6d8186',borderRadius:18,maxContentWidth:520
    }
  });

  for(let i=0;i<categories.length;i++){
    const [name,icon]=categories[i];
    await prisma.issueCategory.upsert({
      where:{slug:`cat-${String(i+1).padStart(2,'0')}`},
      update:{},
      create:{slug:`cat-${String(i+1).padStart(2,'0')}`,name,icon,sortOrder:i+1,enabled:true}
    });
  }

  const bottom=[
    ['خانه','/','home'],['تجربه‌ها','/knowledge','knowledge'],['ثبت مسئله','/issues/new','plus'],['مشاوره‌ها','/bookings','sessions'],['پروفایل','/profile','profile']
  ] as const;
  for(let i=0;i<bottom.length;i++) await ensureMenu('BOTTOM',bottom[i][0],bottom[i][1],bottom[i][2],i+1);
  const drawer=[
    ['خانه','/','home'],['ثبت مسئله','/issues/new','plus'],['صاحبان تجربه','/experts','experts'],['بانک تجربه','/knowledge','knowledge'],['مشاوره‌های من','/bookings','sessions'],['پروفایل','/profile','profile']
  ] as const;
  for(let i=0;i<drawer.length;i++) await ensureMenu('DRAWER',drawer[i][0],drawer[i][1],drawer[i][2],i+1);

  const home=await prisma.cmsPage.upsert({where:{slug:'home'},update:{},create:{slug:'home',title:'صفحه اصلی',isPublished:true}});
  const quick=await ensureSection(home.id,'quick-actions',{type:'quick_actions',sortOrder:10,enabled:true,columnsDesktop:4,columnsMobile:4});
  const quickCount=await prisma.cmsItem.count({where:{sectionId:quick.id}});
  if(!quickCount) await prisma.cmsItem.createMany({data:[
    {sectionId:quick.id,title:'ثبت مسئله',subtitle:'مسئله‌ای داری؟',icon:'plus',linkUrl:'/issues/new',sortOrder:1,enabled:true},
    {sectionId:quick.id,title:'صاحبان تجربه',subtitle:'فرد مناسب را پیدا کن',icon:'experts',linkUrl:'/experts',sortOrder:2,enabled:true},
    {sectionId:quick.id,title:'تجربه‌ها',subtitle:'راه‌های رفته‌شده',icon:'knowledge',linkUrl:'/knowledge',sortOrder:3,enabled:true},
    {sectionId:quick.id,title:'شبکه همراهان',subtitle:'عضویت و نقش‌ها',icon:'network',linkUrl:'/profile',sortOrder:4,enabled:true},
  ]});
  await ensureSection(home.id,'hero',{type:'hero',eyebrow:'شبکه مشورت و تجربه هیأت',title:'هر مسئله‌ای، یک راه‌رفته دارد.',body:'مسئله را دقیق ثبت کن؛ ابتدا تجربه‌های مرتبط را می‌بینی و اگر کافی نبود، به صاحب تجربه‌ای که این مسیر را واقعاً رفته وصل می‌شوی.',primaryLabel:'ثبت مسئله',primaryUrl:'/issues/new',secondaryLabel:'پیدا کردن صاحب تجربه',secondaryUrl:'/experts',sortOrder:20,enabled:true,columnsDesktop:1,columnsMobile:1});
  await ensureSection(home.id,'my-tickets',{type:'user_tickets',title:'وضعیت مسئله‌های من',primaryLabel:'مشاهده همه',primaryUrl:'/issues',sortOrder:30,enabled:true,columnsDesktop:1,columnsMobile:1});
  await ensureSection(home.id,'categories',{type:'categories',title:'در چه زمینه‌ای دنبال کمک هستی؟',subtitle:'موضوع مسئله را انتخاب کن',sortOrder:40,enabled:true,columnsDesktop:4,columnsMobile:4});
  await ensureSection(home.id,'experts',{type:'experts',title:'صاحبان تجربه پیشنهادی',subtitle:'افرادی که این مسیر را در میدان تجربه کرده‌اند',primaryLabel:'مشاهده همه',primaryUrl:'/experts',sortOrder:50,enabled:true,columnsDesktop:1,columnsMobile:1});
  await ensureSection(home.id,'knowledge',{type:'knowledge',title:'شاید پاسخ مسئله‌ات همین‌جا باشد',subtitle:'قبل از مشاوره، تجربه‌های ثبت‌شده را ببین',primaryLabel:'همه تجربه‌ها',primaryUrl:'/knowledge',sortOrder:60,enabled:true,columnsDesktop:2,columnsMobile:2});
  const faq=await ensureSection(home.id,'faq',{type:'faq',title:'مسئله‌های پرتکرار هیأت‌ها',sortOrder:70,enabled:true,columnsDesktop:2,columnsMobile:1});
  if(!await prisma.cmsItem.count({where:{sectionId:faq.id}})) await prisma.cmsItem.createMany({data:[
    {sectionId:faq.id,title:'چطور اعضای هیأت را پای کار نگه داریم؟',icon:'network',linkUrl:'/knowledge',sortOrder:1,enabled:true},
    {sectionId:faq.id,title:'برای ساخت مکان هیأت از کجا شروع کنیم؟',icon:'building',linkUrl:'/knowledge?domain=ساختمان%20و%20فضا',sortOrder:2,enabled:true},
    {sectionId:faq.id,title:'چگونه نوجوان‌ها را جذب کنیم؟',icon:'child',linkUrl:'/knowledge?domain=کودک%20و%20نوجوان',sortOrder:3,enabled:true},
    {sectionId:faq.id,title:'برای رسانه هیأت چه نیرویی لازم داریم؟',icon:'media',linkUrl:'/knowledge?domain=رسانه',sortOrder:4,enabled:true},
  ]});
  const courses=await ensureSection(home.id,'courses',{type:'courses',title:'آموزش‌های کاربردی برای هیأت',subtitle:'کارگاه، نشست و محتوای تجربه‌محور',sortOrder:80,enabled:true,columnsDesktop:2,columnsMobile:1});
  if(!await prisma.cmsItem.count({where:{sectionId:courses.id}})) await prisma.cmsItem.createMany({data:[
    {sectionId:courses.id,title:'کارگاه صورت‌بندی مسئله',subtitle:'از مشکل مبهم تا مسئله قابل حل',icon:'book',linkUrl:'#',sortOrder:1,enabled:true},
    {sectionId:courses.id,title:'نشست تجربه‌نگاری',subtitle:'چطور تجربه هیأت را قابل استفاده کنیم؟',icon:'knowledge',linkUrl:'#',sortOrder:2,enabled:true},
  ]});
  await ensureSection(home.id,'join-experts',{type:'cta',title:'راهی را رفته‌ای؟',body:'تجربه‌ات می‌تواند مسیر یک هیأت دیگر را کوتاه‌تر کند.',primaryLabel:'به جمع صاحبان تجربه بپیوند',primaryUrl:'/profile',sortOrder:90,enabled:true,columnsDesktop:1,columnsMobile:1});
}

async function seedDemo(admin:any){
  const expertsData = [
    {phone:'09121111111',name:'حجت‌الاسلام علی حسینی',city:'تهران',headline:'راه‌اندازی و مدیریت هیأت نوجوان',bio:'۱۲ سال تجربه میدانی در سازماندهی، جذب و نگهداشت نوجوان در هیأت‌های محلی.',domains:['کودک و نوجوان','جذب و ارتباط با مخاطب','مدیریت هیأت'],tags:['نوجوان','سازماندهی','جذب'],yearsExperience:12,experienceCount:24},
    {phone:'09122222222',name:'مهدی رضایی',city:'مشهد',headline:'ساخت و توسعه فضای هیأت',bio:'تجربه عملی از تأمین زمین تا مشارکت مردمی، اجرا و بهره‌برداری از حسینیه.',domains:['ساختمان و فضا','مالی و اقتصادی'],tags:['حسینیه','مشارکت مردمی','ساخت'],yearsExperience:10,experienceCount:14},
    {phone:'09123333333',name:'زهرا موسوی',city:'قم',headline:'مدیریت رسانه و تولید محتوای هیأت',bio:'ساخت تیم رسانه، طراحی تقویم محتوایی و پوشش مناسبتی برای هیأت‌های شهری.',domains:['رسانه','برنامه‌ریزی و محتوا'],tags:['رسانه','تولید محتوا','تیم رسانه'],yearsExperience:7,experienceCount:18}
  ];
  for(const item of expertsData){
    const user=await prisma.user.upsert({where:{phone:item.phone},update:{name:item.name,city:item.city,roles:['HAYAT_JOO','EXPERT'],verifiedAt:new Date(),isActive:true},create:{phone:item.phone,name:item.name,city:item.city,roleInOrg:'صاحب تجربه',roles:['HAYAT_JOO','EXPERT'],verifiedAt:new Date(),isActive:true}});
    const profile=await prisma.expertProfile.upsert({where:{userId:user.id},update:{headline:item.headline,bio:item.bio,domains:item.domains,tags:item.tags,yearsExperience:item.yearsExperience,experienceCount:item.experienceCount,isVerified:true},create:{userId:user.id,headline:item.headline,bio:item.bio,domains:item.domains,tags:item.tags,yearsExperience:item.yearsExperience,experienceCount:item.experienceCount,isVerified:true,capacityWeek:3,capacityMonth:10}});
    if(!await prisma.availabilitySlot.count({where:{expertProfileId:profile.id}})){
      const base=new Date();base.setHours(0,0,0,0);
      for(let d=1;d<=5;d++){const dt=new Date(base);dt.setDate(dt.getDate()+d);dt.setHours(18+(d%2),30,0,0);await prisma.availabilitySlot.create({data:{expertProfileId:profile.id,startsAt:dt,duration:d%2?30:45}})}
    }
  }
  if(!await prisma.experience.count()) await prisma.experience.createMany({data:[
    {title:'از ۲۰ نوجوان تا یک هیأت نوجوان فعال',domain:'کودک و نوجوان',subdomain:'راه‌اندازی هیأت نوجوان',city:'تهران',orgType:'محلی',problem:'چگونه با یک جمع کوچک نوجوان، ساختار مستمر و پایدار ایجاد کنیم؟',solutions:['شروع با هسته ۵ نفره مسئولیت‌پذیر','تقویم ثابت هفتگی','تفکیک برنامه جذب از برنامه نگهداشت'],keyPoints:['مسئولیت واقعی به نوجوان بدهید','جلسه کوتاه ولی منظم بهتر از برنامه سنگین مقطعی است'],pitfalls:['تمرکز صرف بر برنامه مناسبتی','وابسته کردن کار به یک مربی'],warnings:['حریم خانواده‌ها و رضایت والدین را جدی بگیرید'],prerequisites:['هسته اجرایی کوچک','فضای ثابت'],tags:['نوجوان','جذب','مدیریت'],accessLevel:'PUBLIC',status:'PUBLISHED',createdById:admin.id,publishedAt:new Date()},
    {title:'ساخت حسینیه با مشارکت مردم؛ از زمین تا بهره‌برداری',domain:'ساختمان و فضا',subdomain:'ساخت حسینیه',city:'مشهد',orgType:'محلی',problem:'چطور پروژه ساخت بدون فرسودگی تیم و بی‌اعتمادی مالی جلو برود؟',solutions:['فازبندی پروژه','گزارش مالی منظم','تعیین مسئول فنی مستقل'],keyPoints:['شفافیت مالی بخشی از خود پروژه است','هر فاز باید خروجی قابل مشاهده داشته باشد'],pitfalls:['شروع بدون برآورد هزینه','قول‌های مبهم به خیرین'],warnings:['مجوزها و الزامات ایمنی باید قبل از اجرا بررسی شوند'],prerequisites:['زمین یا قرارداد معتبر','برآورد اولیه'],tags:['ساخت','حسینیه','مشارکت مردمی'],accessLevel:'PUBLIC',status:'PUBLISHED',createdById:admin.id,publishedAt:new Date()},
    {title:'تیم رسانه ۵ نفره؛ نقش‌ها و فرآیند هفتگی',domain:'رسانه',subdomain:'تیم رسانه',city:'قم',orgType:'شهری',problem:'چطور با تیم کم‌تعداد، خروجی منظم و قابل اتکا داشته باشیم؟',solutions:['تعریف نقش ثابت','تقویم محتوا','جلسه مرور هفتگی ۲۰ دقیقه‌ای'],keyPoints:['هر نفر مالک یک خروجی مشخص باشد','آرشیو فایل‌ها ساختار ثابت داشته باشد'],pitfalls:['همه‌کاره بودن یک نفر','تولید بدون تقویم'],warnings:['رضایت افراد در انتشار تصویر رعایت شود'],prerequisites:['تقویم برنامه‌های هیأت','فضای اشتراک فایل'],tags:['رسانه','تولید محتوا','تیم'],accessLevel:'PUBLIC',status:'PUBLISHED',createdById:admin.id,publishedAt:new Date()}
  ]});
}

async function main(){
  const adminPhone=process.env.ADMIN_PHONE||'09120000000';
  const admin=await prisma.user.upsert({where:{phone:adminPhone},update:{name:'مدیر سامانه',city:'تهران',roles:['HAYAT_JOO','MODERATOR','KNOWLEDGE_EDITOR','ADMIN'],verifiedAt:new Date(),isActive:true},create:{phone:adminPhone,name:'مدیر سامانه',city:'تهران',roleInOrg:'مدیر شبکه',roles:['HAYAT_JOO','MODERATOR','KNOWLEDGE_EDITOR','ADMIN'],verifiedAt:new Date(),isActive:true}});
  await seedCms();
  if((process.env.SEED_DEMO_DATA||'true')==='true') await seedDemo(admin);
  console.log('Seed completed.');
  console.log(`Admin phone: ${adminPhone}`);
}

main().catch(e=>{console.error(e);process.exit(1)}).finally(()=>prisma.$disconnect());
