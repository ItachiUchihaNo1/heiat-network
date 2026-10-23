# نامیرا — PHP و MySQL برای cPanel

این تحویل یک پایه اجرایی و قابل توسعه است؛ گزارش دقیق تکمیل و کمبودها در `docs/STATUS.fa.md` است. پرداخت واقعی، پیامک و API پست فعال نیستند. کد فعلی سایت را با داده نمایشی پر نمی‌کند؛ seed فقط با APP_ENV غیر production اجرا می‌شود.

## نصب روی هاست شما

Document Root اعلام‌شده شما **`Public_html/new`** است. بزرگی P حفظ شده است. این مسیر را به public_html تغییر ندهید.

1. در GitHub → مخزن `ItachiUchihaNo1/heiat-network` → Settings → Secrets and variables → Actions، این سه **Repository Secret** را ثبت کنید:
   - `DEPLOY_FTPS_HOST`: نام دقیق FTP Server از cPanel → FTP Accounts → Configure FTP Client؛ بدون پروتکل، مسیر و پورت.
   - `DEPLOY_FTPS_USER`: نام کامل حساب FTP، معمولاً همراه دامنه.
   - `DEPLOY_FTPS_PASSWORD`: رمز همان حساب FTP.
2. workflow `.github/workflows/namira.yml` ابتدا تست PHP را اجرا می‌کند و سپس با FTPS صریح روی پورت ۲۱ منتقل می‌کند. اعتبار گواهی TLS بررسی می‌شود. SFTP پروتکل دیگری است و با این workflow کار نمی‌کند.
3. مقصد پیش‌فرض FTP، `Public_html/new` است و باید از قبل وجود داشته باشد. اگر حساب FTP داخل همین new محدود شده است، در تب **Variables** متغیر `DEPLOY_FTPS_DIR` را `/` قرار دهید. اگر از ریشه دیگری وارد می‌شود، مسیر واقعی تا همین Document Root را بنویسید. تغییر این متغیر به معنی تغییر Document Root نیست.
4. در GitHub → Actions → **Namira PHP - Test and deploy FTPS** → Run workflow اجرا کنید. در pushهای بعدی مربوط به namira خودکار اجرا می‌شود. اجرای ناقص را سبز یا استقرار موفق تلقی نکنید. کد خروج ۲ یعنی فایل‌ها منتقل شده‌اند ولی تنظیم MySQL/migration هنوز کامل نیست.
5. در cPanel → **MySQL Database Wizard**، یک دیتابیس و یک کاربر بسازید و دسترسی ALL PRIVILEGES را فقط برای همان دیتابیس به آن کاربر بدهید. نام کامل دیتابیس و کاربر، همراه پیشوند حساب cPanel، لازم است.
6. در cPanel → **MultiPHP Manager**، برای `test.shahidpics.com`، PHP 8.4 را انتخاب کنید. افزونه‌های `pdo_mysql`, `gd` با WebP، `fileinfo`, `mbstring`, `zip`, `SimpleXML` لازم‌اند. memory_limit حداقل 256M؛ upload_max_filesize=20M و post_max_size متناسب با مجموع فایل‌ها (مثلاً 220M)؛ max_file_uploads حداقل 10.
7. در File Manager، گزینه Show Hidden Files را فعال کنید. به **`Public_html/new/_namira`** بروید. از `.env.example` یک کپی به نام `.env` بسازید و این مقادیر را وارد کنید:

```dotenv
APP_ENV=production
APP_URL=https://test.shahidpics.com
DB_DSN=mysql:host=localhost;dbname=YOUR_CPANEL_PREFIX_namira;charset=utf8mb4
DB_USER=YOUR_CPANEL_PREFIX_namira
DB_PASSWORD=YOUR_DATABASE_PASSWORD
STORAGE_PATH=
PAYMENT_DRIVER=disabled
SHIPPING_TOMAN=50000
MAX_UPLOAD_MB=20
```

رمز دیتابیس، رمز FTP نیست. رمزها را به GitHub commit نکنید. از رمز دیتابیس فاقد کوتیشن ابتدا/انتهای رشته استفاده کنید چون parser ساده env آن را حذف می‌کند. مجوز فایل `.env` را در حدی قرار دهید که فقط کاربر PHP بخواند (معمولاً 600).

8. در cPanel → **Terminal**، این دستورها را اجرا کنید. در اینجا `$HOME` همان پوشه اصلی حساب cPanel است؛ مسیر واقعی `Public_html` باید زیر آن باشد:

```sh
cd "$HOME/Public_html/new/_namira"
php -v
php bin/console.php migrate
php bin/console.php admin YOUR_EMAIL@example.com superadmin
```

دستور آخر رمز جدید مدیریت با حداقل ۱۲ کاراکتر را از ورودی می‌خواند؛ رمز را به‌عنوان آرگومان دستور ننویسید. ورودی ترمینال ممکن است رمز را روی صفحه نشان دهد؛ بعد از اتمام ترمینال را ببندید. اگر `php -v` نسخه قدیمی بود، از مسیر PHP 8.4 ارائه‌شده توسط شرکت میزبان (در بسیاری از cPanelها `/opt/cpanel/ea-php84/root/usr/bin/php`) به‌جای `php` استفاده کنید. اگر Terminal موجود نیست، از پشتیبانی میزبان بخواهید همین دستورها را برای مسیر حساب شما اجرا کند؛ نصب از طریق وب تعبیه نشده است.

9. صفحه اصلی را در `https://test.shahidpics.com` باز کنید. ورود مدیریت: `https://test.shahidpics.com/index.php?page=login`. سایت ابتدا آرشیو خالی دارد. از مدیریت شهید ثبت کنید یا Excel وارد کنید؛ سپس تصاویر نام‌گذاری‌شده مطابق کد را بارگذاری کنید.
10. این نشانی باید **403 Forbidden** بدهد: `https://test.shahidpics.com/_namira/.env.example`. workflow قبل از انتقال کد هم حفاظت همین پوشه را با یک فایل بی‌خطر بررسی می‌کند و در صورت نبود حفاظت متوقف می‌شود.
11. در cPanel → Cron Jobs، هر ۵ دقیقه دستور زیر را با مسیر مطلق و نسخه درست PHP اضافه کنید:

```sh
php /home/YOUR_CPANEL_USER/Public_html/new/_namira/bin/console.php expire-orders
```

این کار فقط رزرو سفارش آزمایشی منقضی را آزاد می‌کند؛ صف پردازش آرشیو نیست.

## ساختار انتقال

```text
Public_html/new/
  index.php
  .htaccess
  assets/
  _namira/                 # دسترسی HTTP با Require all denied بسته می‌شود
    .htaccess
    .env                   # فقط روی سرور؛ انتقال بعدی آن را دست نمی‌زند
    app/ views/ database/ bin/ docs/
    storage/               # اصل تصاویر، پیش‌نمایش‌ها و عکس‌های خصوصی درخواست
    releases/              # نسخه قبلی index.php برای بازیابی نقطه ورود
```

بهترین جداسازی، قراردادن تمام کد خصوصی در `/home/YOUR_CPANEL_USER/namira` خارج از Document Root است. برای حساب FTP محدود به پوشه عمومی، ساختار بالا با آزمون اجباری HTTP 403 استفاده می‌شود. استقرار روی وب‌سروری که `.htaccess` را اجرا نمی‌کند متوقف خواهد شد. پوشه‌های runtime و فایل `.env` در انتقال بعدی پاک یا جایگزین نمی‌شوند. انتقال حذف همگام ندارد؛ پاک‌سازی فایل‌های قدیمی باید با بررسی نسخه انجام شود. استقرار چندفایلی اتمیک کامل و rollback کامل هنوز فراهم نیست؛ از فایل و دیتابیس پشتیبان مستقل داشته باشید.

## اجرای محلی

PHP 8.4 با افزونه‌های بالا و `pdo_sqlite` برای تست محلی لازم است؛ Composer یا Node برای اجرا لازم نیست. در ریشه namira از `.env.example` کپی `.env` بسازید:

```dotenv
APP_ENV=local
APP_URL=http://127.0.0.1:8787
DB_DSN=sqlite:/ABSOLUTE/PATH/namira/storage/local.sqlite
DB_USER=
DB_PASSWORD=
PAYMENT_DRIVER=test
```

```sh
php bin/console.php migrate
php bin/console.php seed
php bin/console.php admin admin@example.com superadmin
php -S 127.0.0.1:8787 -t public
```

SQLite یک پایگاه داده واقعی برای توسعه و آزمون است؛ استقرار cPanel با MySQL تنظیم شده است. داده‌های اصلی در LocalStorage ذخیره نمی‌شوند؛ سبد در دیتابیس و شناسه آن در نشست سمت سرور قرار دارد.

## تست و عملیات

```sh
php tests/run.php
# روی یک دیتابیس کاملاً مجزا با نام namira_test:
TEST_DB_DSN='mysql:host=127.0.0.1;dbname=namira_test;charset=utf8mb4' TEST_DB_USER=root TEST_DB_PASSWORD='TEST_ONLY' php tests/run.php
```

هر اجرای تست، مسیر test تصادفی زیر storage می‌سازد؛ وارد بسته یا Git نمی‌شود. برای MySQL، از دیتابیس تازه و خالی namira_test استفاده کنید. تست را روی دیتابیس اصلی اجرا نکنید.

ورود Excel: اولین شیت استاندارد، سه ستون `code`, `first_name`, `last_name` با code به‌صورت Text؛ حداکثر ۵۰۰۰ ردیف/۵MB. ابتدا پیش‌نمایش و سپس تأیید مدیر. ورود مجدد، رکورد موجود را تغییر نمی‌دهد. خطاهای هر ردیف در گزارش مدیریت می‌مانند. فایل‌های اصلی با الگوی `001254-1.jpg` تطبیق می‌شوند. تنها JPG/PNG/WebP پذیرفته می‌شود. بارگذاری چندفایلی هر نوبت تا ۱۰ فایل است؛ هر مورد موفق یا خطادار جدا گزارش می‌شود.

نقش‌ها: `superadmin`, `content`, `shop`. ایجاد/تغییر نقش با `php bin/console.php admin EMAIL ROLE` و غیرفعال‌سازی با `php bin/console.php disable-user EMAIL` است.

## حفاظت و بازیابی

پیش از migration، از MySQL با ابزار Backup/cPanel یا mysqldump و از کل storage پشتیبان بگیرید. نسخه پشتیبان را خارج از Document Root و ترجیحاً خارج از سرور نگهداری کنید. برای بازیابی، ابتدا دیتابیس را روی دیتابیس مجزا restore کنید، فایل‌ها را در storage همان نسخه بازگردانید، DB_DSN را تغییر دهید و اصل و پیش‌نمایش چند تصویر را بررسی کنید. آزمون خودکار بازیابی و پشتیبان‌گیری زمان‌بندی‌شده خارج سرور هنوز تحویل نشده‌اند.

برای خطاهای 503، cPanel → Metrics → Errors یا error_log هاست را بررسی کنید. هیچ stack trace و رمز در صفحه عمومی نمایش داده نمی‌شود. تنظیم‌نشدن دیتابیس به معنی اتصال آزمایشی پنهان نیست؛ سایت 503 می‌دهد.

