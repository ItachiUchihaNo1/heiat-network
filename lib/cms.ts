import { prisma } from './prisma';

export const defaultSiteConfig = {
  siteName: 'شبکه تجربه هیأت',
  siteSubtitle: 'صاحب مسئله ← صاحب تجربه',
  searchPlaceholder: 'مسئله‌ات را جست‌وجو کن...',
  logoUrl: null as string | null,
  faviconUrl: null as string | null,
  logoText: 'هـ',
  menuIcon: '☰',
  profileIcon: '○',
  searchIcon: '⌕',
  primaryColor: '#0b4f59',
  primary2Color: '#0f8a80',
  accentColor: '#23b49b',
  backgroundColor: '#f5f8f9',
  cardColor: '#ffffff',
  textColor: '#163239',
  mutedColor: '#6d8186',
  borderRadius: 18,
  maxContentWidth: 520,
  fontFamily: 'Tahoma, Segoe UI, sans-serif',
  customCss: '',
  footerText: '',
};

export async function getSiteConfig() {
  try {
    return await prisma.siteConfig.findUnique({ where: { singletonKey: 'default' } }) || defaultSiteConfig;
  } catch {
    return defaultSiteConfig;
  }
}

export async function getMenus(location: string) {
  try {
    return await prisma.menuItem.findMany({
      where: { location, enabled: true, parentId: null },
      include: { children: { where: { enabled: true }, orderBy: { sortOrder: 'asc' } } },
      orderBy: { sortOrder: 'asc' },
    });
  } catch {
    return [];
  }
}

export async function getHomeCms() {
  try {
    const page = await prisma.cmsPage.findUnique({
      where: { slug: 'home' },
      include: {
        sections: {
          where: { enabled: true },
          include: { items: { where: { enabled: true }, orderBy: { sortOrder: 'asc' } } },
          orderBy: { sortOrder: 'asc' },
        },
      },
    });
    return page;
  } catch {
    return null;
  }
}

export function safeJson(value: FormDataEntryValue | null, fallback: unknown = {}) {
  if (!value || typeof value !== 'string' || !value.trim()) return fallback;
  try { return JSON.parse(value); } catch { return fallback; }
}

export function text(value: FormDataEntryValue | null) {
  return typeof value === 'string' ? value.trim() : '';
}

export function nullableText(value: FormDataEntryValue | null) {
  const v = text(value);
  return v || null;
}

export function intValue(value: FormDataEntryValue | null, fallback = 0) {
  const n = Number(value);
  return Number.isFinite(n) ? Math.trunc(n) : fallback;
}
