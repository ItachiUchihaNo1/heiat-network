export const DOMAINS = [
  'مدیریت هیأت', 'کودک و نوجوان', 'بانوان', 'رسانه', 'جذب و ارتباط با مخاطب',
  'مالی و اقتصادی', 'ساختمان و فضا', 'برنامه‌ریزی و محتوا', 'تشکل و شبکه‌سازی',
  'جهاد و خدمت', 'آموزش و تربیت', 'مناسبت‌ها', 'مداحی و شعر', 'سایر'
] as const;

export const TICKET_STATUS: Record<string, string> = {
  DRAFT: 'پیش‌نویس',
  SUBMITTED: 'ثبت‌شده',
  UNDER_REVIEW: 'در حال بررسی',
  NEEDS_INFO: 'نیازمند تکمیل',
  MATCHED: 'مشاوران پیشنهاد شدند',
  SCHEDULED: 'رزرو شده',
  COMPLETED: 'انجام شده',
  ARCHIVED: 'بایگانی',
  REJECTED: 'رد شده',
};
