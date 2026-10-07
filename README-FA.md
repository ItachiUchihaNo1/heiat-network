# شبکه تجربه هیأت — نسخه اصلی Full‑Stack + CMS مدیریتی

این مخزن نسخه اصلی وب‌اپ «شبکه تجربه هیأت» است. فرانت‌اند، Backend/API، PostgreSQL/Prisma، احراز هویت OTP، رزرو و جلسات، بانک تجربه و یک CMS/داشبورد مدیریتی یکپارچه در همین پروژه قرار دارند.

> برای مرحله فعلی تأیید ظاهر در GitHub، پوشه `docs/` یک Preview استاتیک دارد و هیچ OTP یا سروری لازم ندارد. نسخه اصلی Next.js روی GitHub Pages اجرا نمی‌شود؛ GitHub Pages فقط Preview را نمایش می‌دهد. نسخه اصلی وقتی وارد فاز سرور شدید روی VPS/Docker اجرا می‌شود.

## امکانات محصول

- ورود/ثبت‌نام با شماره موبایل و OTP
- پروفایل هیأت‌جو
- پروفایل صاحب تجربه، حوزه‌ها، ظرفیت و زمان‌های آزاد
- ثبت مسئله و صف اعتبارسنجی
- تأیید، رد یا درخواست تکمیل توسط ناظر
- Matching صاحب تجربه
- رزرو Slot و جلوگیری از رزرو همزمان
- جلسات و اتصال Jitsi
- رضایت‌سنجی
- بانک تجربه و جست‌وجو
- تبدیل جلسه به تجربه توسط مدیر دانش
- نقش‌های `HAYAT_JOO / EXPERT / MODERATOR / KNOWLEDGE_EDITOR / ADMIN`
- Audit Log مدیریتی

## CMS و داشبورد مدیریتی

آدرس اصلی پنل:

```text
/admin
```

ادمین می‌تواند بدون تغییر کد این موارد را مدیریت کند:

- نام سایت، زیرعنوان، لوگو، favicon و متن لوگوی جایگزین
- آیکن منو، پروفایل و جست‌وجو
- رنگ اصلی، رنگ دوم، Accent، پس‌زمینه، رنگ کارت، متن و متن فرعی
- عرض رابط، گردی گوشه‌ها، Font Family و CSS سفارشی
- متن جست‌وجو، فوتر، تلفن و ایمیل پشتیبانی
- صفحه‌ساز صفحه اصلی
- نمایش/مخفی کردن هر Section
- ترتیب Sectionها
- تعداد ستون در موبایل و دسکتاپ
- Hero، CTA، میانبرها، FAQ، آموزش‌ها و کارت‌های سفارشی
- عنوان، متن، آیکن، تصویر، Badge و لینک هر آیتم
- منوی کناری، Bottom Navigation، Header Menu و Footer Menu
- ترتیب، آیکن، تصویر و لینک آیتم‌های منو
- دسته‌بندی مسائل و آیکن/تصویر آن‌ها
- کتابخانه Media و آپلود تصویر
- کاربران، نقش‌ها، فعال/غیرفعال بودن و تصویر پروفایل
- اطلاعات و تأیید صاحبان تجربه
- تصویر کاور و محتوای تجربه‌ها
- وضعیت مسائل و جلسات
- گزارش تغییرات مدیریتی
- ساخت صفحه‌های محتوایی مستقل و اتصال آن‌ها به منو

### مسیرهای مدیریت

```text
/admin                       داشبورد
/admin/cms/home              صفحه‌ساز صفحه اصلی
/admin/cms/pages             صفحه‌های سفارشی
/admin/cms/appearance        ظاهر و برند
/admin/cms/menus             منوها
/admin/cms/categories        دسته‌بندی مسائل
/admin/cms/media             تصاویر و رسانه
/admin/users                 کاربران و صاحبان تجربه
/admin/operations            مسائل و جلسات
/admin/moderation            صف ناظر
/admin/knowledge             بانک دانش و ویرایش تجربه‌ها
/admin/audit                 گزارش تغییرات
```

---

# مرحله فعلی شما: GitHub و تأیید ظاهر

## 1. Repository بسازید

در GitHub یک Repository جدید بسازید؛ مثلاً:

```text
heiat-network
```

فایل‌های همین پوشه را در Root مخزن Push کنید.

## 2. Preview استاتیک

Preview در این مسیر است:

```text
docs/index.html
```

Workflow آماده‌ی GitHub Pages نیز وجود دارد:

```text
.github/workflows/pages.yml
```

در GitHub:

```text
Settings → Pages → Source → GitHub Actions
```

سپس Workflow `Deploy UI Preview` اجرا می‌شود و لینکی شبیه این خواهید داشت:

```text
https://USERNAME.github.io/heiat-network/
```

این Preview فقط برای تأیید UI/UX است و به دیتابیس، OTP و Backend وصل نیست.

## 3. CI کد اصلی

فایل زیر برای بررسی Build نسخه Full‑Stack در GitHub قرار داده شده:

```text
.github/workflows/ci.yml
```

این Workflow یک PostgreSQL موقت بالا می‌آورد، Prisma را آماده می‌کند، Seed اجرا می‌کند و Build را بررسی می‌کند.

---

# اجرای نسخه اصلی روی سیستم توسعه

## پیش‌نیازها

- Node.js 22+
- Docker + Docker Compose
- Git

## 1. ساخت `.env`

```bash
cp .env.example .env
```

برای توسعه نمونه:

```env
NODE_ENV=development
APP_URL=http://localhost:3000
DOMAIN=localhost

AUTH_SECRET=change-this-to-at-least-32-random-characters
OTP_PEPPER=another-random-secret-at-least-32-characters

POSTGRES_DB=heiat
POSTGRES_USER=heiat
POSTGRES_PASSWORD=change-me
DATABASE_URL=postgresql://heiat:change-me@db:5432/heiat?schema=public

SMS_PROVIDER=development
OTP_TTL_MINUTES=5
OTP_MAX_ATTEMPTS=5

JITSI_BASE_URL=https://meet.jit.si

ADMIN_PHONE=09120000000
SEED_DEMO_DATA=true
UPLOAD_DIR=/data/uploads
```

در حالت `SMS_PROVIDER=development` هیچ پنل پیامکی لازم نیست. کد OTP تستی در محیط توسعه نمایش داده می‌شود.

## 2. دیتابیس

```bash
docker compose up -d db
```

## 3. ساخت جداول

```bash
docker compose build app
docker compose run --rm app npx prisma db push
```

## 4. Seed

```bash
docker compose run --rm app npm run db:seed
```

Seed این‌ها را می‌سازد:

- ادمین با شماره `ADMIN_PHONE`
- تنظیمات اولیه CMS
- منوها
- دسته‌بندی‌ها
- Sectionهای صفحه اصلی
- در صورت `SEED_DEMO_DATA=true` چند صاحب تجربه و تجربه نمونه

## 5. اجرا

```bash
docker compose -f docker-compose.dev.yml --env-file .env up -d
```

سپس:

```text
http://localhost:3000
```

### ورود ادمین در توسعه

شماره‌ای را که در `ADMIN_PHONE` گذاشته‌اید وارد کنید. چون `SMS_PROVIDER=development` است، کد تست در صفحه/لاگ توسعه نمایش داده می‌شود. پس برای تست CMS فعلاً نیازی به اتصال SMS ندارید.

---

# اجرای مستقیم با Node

اگر PostgreSQL محلی دارید:

```bash
npm install
npx prisma generate
npx prisma db push
npm run db:seed
npm run dev
```

در این حالت `DATABASE_URL` باید به PostgreSQL محلی اشاره کند؛ معمولاً Host برابر `localhost` است، نه `db`.

---

# انتشار روی VPS در فاز بعد

پیشنهاد ساختار:

```text
app.example.ir     → Next.js + API
PostgreSQL         → Docker volume
/data/uploads      → Docker volume برای تصاویر CMS
meet.example.ir    → در صورت راه‌اندازی Jitsi اختصاصی
```

## نصب روی Ubuntu

```bash
apt update
apt install -y docker.io docker-compose-v2 git
systemctl enable --now docker
```

Clone:

```bash
git clone https://github.com/USERNAME/heiat-network.git /opt/heiat
cd /opt/heiat
cp .env.example .env
```

`.env` را برای Production تنظیم کنید، سپس:

```bash
docker compose up -d db
docker compose build app
docker compose run --rm app npx prisma db push
docker compose run --rm app npm run db:seed
docker compose up -d
```

Caddy بر اساس `DOMAIN` Reverse Proxy و HTTPS را مدیریت می‌کند.

---

# تصاویر CMS چگونه ذخیره می‌شوند؟

در نسخه VPS تک‌سرور، تصاویر در Volume پایدار Docker ذخیره می‌شوند:

```text
UPLOAD_DIR=/data/uploads
```

و از Route زیر سرو می‌شوند:

```text
/api/media/<filename>
```

فرمت‌های مجاز:

- JPG
- PNG
- WEBP
- GIF

حداکثر حجم در CMS: 8MB.

برای زمانی که پروژه چند سرور/Replica شد، Media باید از Volume محلی به S3-compatible storage مثل MinIO یا سرویس Object Storage منتقل شود. برای MVP تک‌سرور، Volume انتخاب ساده‌تر و مناسب‌تری است.

---

# امنیت و چیزهایی که عمداً از CMS قابل ویرایش نیستند

Secretها نباید داخل دیتابیس CMS باشند. این موارد فقط از `.env` مدیریت می‌شوند:

- `AUTH_SECRET`
- `OTP_PEPPER`
- رمز PostgreSQL
- Token پنل پیامکی
- تنظیمات حساس سرویس‌های بیرونی

این تصمیم امنیتی است، نه محدودیت پنل مدیریت.

آپلود SVG نیز عمداً غیرفعال است چون SVG می‌تواند حاوی محتوای اجرایی باشد.

---

# اتصال OTP واقعی در فاز سرور

فعلاً:

```env
SMS_PROVIDER=development
```

بعداً:

```env
SMS_PROVIDER=webhook
SMS_WEBHOOK_URL=https://...
SMS_WEBHOOK_TOKEN=...
```

قرارداد ارسال:

```http
POST SMS_WEBHOOK_URL
Authorization: Bearer SMS_WEBHOOK_TOKEN
Content-Type: application/json
```

Body:

```json
{
  "to": "09123456789",
  "message": "کد ورود شبکه تجربه هیأت: 123456"
}
```

---

# چه چیزهایی هنوز Integration خارجی می‌خواهند؟

هسته محصول و CMS آماده‌اند، اما این قابلیت‌ها ذاتاً به سرویس خارجی نیاز دارند و در مرحله سرور باید نهایی شوند:

1. SMS واقعی OTP
2. Jitsi اختصاصی، اگر نخواهید از `meet.jit.si` استفاده کنید
3. ضبط جلسه
4. Speech-to-Text
5. خلاصه‌سازی AI و استخراج خودکار دانش
6. Object Storage در صورت چندسروری شدن

پنل مدیر دانش در حال حاضر تبدیل/ویرایش تجربه را دستی انجام می‌دهد.

---

# نکته مهم درباره دیتابیس Production

برای MVP و نصب اولیه `prisma db push` سریع است. قبل از اینکه سامانه دارای داده واقعی و حساس شود، workflow دیتابیس را به Prisma Migrations منتقل کنید:

```bash
npx prisma migrate dev --name initial
npx prisma migrate deploy
```

و برای PostgreSQL Backup روزانه داشته باشید.

---

# ترتیب پیشنهادی پروژه شما

1. `docs/` روی GitHub Pages → تأیید ظاهر
2. اصلاح UI/UX بر اساس نظر کارفرما
3. Freeze طراحی نسخه اول
4. اجرای Full‑Stack محلی و تست CMS
5. تهیه VPS و دامنه
6. PostgreSQL + Docker + HTTPS
7. تست واقعی ثبت مسئله، Matching و رزرو
8. اتصال SMS
9. تست امنیت و Backup
10. سپس ضبط/ASR/AI
11. اتصال Android به همان Backend

به این ترتیب قبل از هزینه روی سرویس‌ها، ظاهر و UX تأیید می‌شود و بعد همان Repository وارد فاز عملیاتی می‌شود.
