import crypto from 'node:crypto';
import type { Request, Response } from 'express';
import { nextIdFor, store } from './store.ts';
import type {
  AdminRecord,
  AdminSession,
  NavigationItem,
  RateLimitRule,
  RateLimitScope,
  RateLimitUnit,
} from './types.ts';

export const ADMIN_BASE_PATH = '/448662';
export const SESSION_COOKIE_NAME = process.env.SESSION_NAME || 'village_admin_session';

export const MAX_IMAGE_UPLOAD_BYTES = 10 * 1024 * 1024;
export const MAX_VIDEO_UPLOAD_BYTES = 50 * 1024 * 1024;
export const MAX_ASSET_UPLOAD_BYTES = 5 * 1024 * 1024;

export const BUCKET_WEBSITE_ASSETS = process.env.BUCKET_WEBSITE_ASSETS || 'website-assets';
export const BUCKET_HERO_BANNERS = process.env.BUCKET_HERO_BANNERS || 'hero-banners';
export const BUCKET_GALLERY_IMAGES = process.env.BUCKET_GALLERY_IMAGES || 'gallery-images';
export const BUCKET_POST_MEDIA = process.env.BUCKET_POST_MEDIA || 'post-media';

export const ALLOWED_IMAGE_MIMES: Record<string, string> = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
  'image/gif': 'gif',
};

export const ALLOWED_BANNER_MIMES: Record<string, string> = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
};

export const ALLOWED_LOGO_MIMES: Record<string, string> = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
};

export const ALLOWED_FAVICON_MIMES: Record<string, string> = {
  'image/png': 'png',
  'image/webp': 'webp',
  'image/x-icon': 'ico',
  'image/vnd.microsoft.icon': 'ico',
};

export const ALLOWED_ASSET_MIMES: Record<string, string> = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
  'image/x-icon': 'ico',
  'image/vnd.microsoft.icon': 'ico',
};

export const ALLOWED_VIDEO_MIMES: Record<string, string> = {
  'video/mp4': 'mp4',
  'video/webm': 'webm',
};

export function e(value: string | number | null | undefined): string {
  if (value === null || value === undefined) return '';
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

export function safeUrl(url: string | null | undefined, fallback = '/'): string {
  const trimmed = String(url ?? '').trim();
  if (!trimmed || trimmed === '#' || /[\x00-\x1F\x7F]/.test(trimmed)) {
    return fallback;
  }
  if ((trimmed.startsWith('/') && !trimmed.startsWith('//')) || trimmed.startsWith('#')) {
    return trimmed;
  }
  try {
    const parsed = new URL(trimmed);
    if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
      return trimmed;
    }
  } catch {
    return fallback;
  }
  return fallback;
}

export function cleanNavUrl(url: string | null | undefined, fallback = '/'): string {
  const safe = safeUrl(url, fallback);
  if (safe === '/index.php') return '/';
  if (safe.startsWith('/index.php?')) return '/' + safe.slice('/index.php'.length);
  return safe;
}

export function sanitizeHexColor(color: string | null | undefined, fallback = '#14532d'): string {
  const trimmed = String(color ?? '').trim();
  if (/^#[0-9a-fA-F]{6}$/.test(trimmed)) {
    return trimmed.toLowerCase();
  }
  return fallback;
}

export function slugify(text: string): string {
  const slug = text
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
  return slug || `item-${crypto.randomBytes(4).toString('hex')}`;
}

export function validateContentSlug(slugInput: string): string {
  const slug = slugInput.toLowerCase().trim();
  if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug) || slug.length > 120) {
    throw new Error('পেজের ঠিকানায় শুধু ছোট হাতের ইংরেজি অক্ষর, সংখ্যা এবং একক হাইফেন ব্যবহার করুন।');
  }
  const reserved = new Set([
    '448662', 'admin', 'api', 'assets', 'index', 'index-php',
    'posts', 'post', 'gallery', 'login', 'logout', 'forgot-password',
    'content-frame', 'page', 'router', 'sitemap', 'robots', 'favicon', 'uploads',
  ]);
  if (reserved.has(slug)) {
    throw new Error('এই পেজের ঠিকানাটি ওয়েবসাইটের জন্য সংরক্ষিত। অনুগ্রহ করে অন্য ঠিকানা দিন।');
  }
  return slug;
}

export function formatDate(timestamp: string | null | undefined, format: 'd/m/Y' | 'd/m/Y H:i' | 'd/m/Y H:i:s' = 'd/m/Y'): string {
  if (!timestamp) return '';
  const d = new Date(timestamp);
  if (Number.isNaN(d.getTime())) return '';
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const yyyy = d.getFullYear();
  if (format === 'd/m/Y') return `${dd}/${mm}/${yyyy}`;
  const hh = String(d.getHours()).padStart(2, '0');
  const min = String(d.getMinutes()).padStart(2, '0');
  if (format === 'd/m/Y H:i') return `${dd}/${mm}/${yyyy} ${hh}:${min}`;
  const ss = String(d.getSeconds()).padStart(2, '0');
  return `${dd}/${mm}/${yyyy} ${hh}:${min}:${ss}`;
}

export function formatPopupDhakaLocal(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  // Format in Asia/Dhaka (UTC+6) as YYYY-MM-DDTHH:mm
  const dhakaMs = d.getTime() + 6 * 3600 * 1000;
  const dd = new Date(dhakaMs);
  const y = dd.getUTCFullYear();
  const m = String(dd.getUTCMonth() + 1).padStart(2, '0');
  const day = String(dd.getUTCDate()).padStart(2, '0');
  const h = String(dd.getUTCHours()).padStart(2, '0');
  const min = String(dd.getUTCMinutes()).padStart(2, '0');
  return `${y}-${m}-${day}T${h}:${min}`;
}

export function parsePopupDhakaLocal(value: string | null | undefined): string | null {
  const trimmed = String(value ?? '').trim();
  if (!trimmed) return null;
  // Parse YYYY-MM-DDTHH:mm in Asia/Dhaka (+06:00)
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(trimmed);
  if (!match) {
    const parsed = new Date(trimmed);
    if (Number.isNaN(parsed.getTime())) {
      throw new Error('সঠিক তারিখ ও সময় লিখুন।');
    }
    return parsed.toISOString();
  }
  const isoWithOffset = `${match[1]}-${match[2]}-${match[3]}T${match[4]}:${match[5]}:00+06:00`;
  const parsed = new Date(isoWithOffset);
  if (Number.isNaN(parsed.getTime())) {
    throw new Error('সঠিক তারিখ ও সময় লিখুন।');
  }
  return parsed.toISOString();
}

export function adminUrl(subpath = ''): string {
  const base = ADMIN_BASE_PATH.replace(/\/+$/, '');
  const clean = subpath.replace(/^\/+/, '');
  return clean === '' ? base : `${base}/${clean}`;
}

export function clientIp(req: Request): string {
  const fwd = req.headers['x-forwarded-for'];
  const raw = Array.isArray(fwd) ? fwd[0] : (fwd || req.socket.remoteAddress || '127.0.0.1');
  return String(raw).split(',')[0].trim().slice(0, 45) || '127.0.0.1';
}

export function sanitizeRichHtml(rawHtml: string): string {
  let html = String(rawHtml ?? '').trim();
  if (!html) return '';

  html = html.replace(/<!--[\s\S]*?-->/g, '');
  html = html.replace(/<!doctype[^>]*>/gi, '');
  html = html.replace(
    /<(script|style|iframe|object|embed|svg|math|template|form|input|button|textarea|select|option|video|audio|source|link|meta|base)\b[^>]*>[\s\S]*?<\/\1\s*>/gi,
    ''
  );
  html = html.replace(
    /<\/?(script|style|iframe|object|embed|svg|math|template|form|input|button|textarea|select|option|video|audio|source|link|meta|base)\b[^>]*>/gi,
    ''
  );

  const allowedTags = new Set([
    'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'ins',
    'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'blockquote',
    'a', 'img', 'span', 'div', 'pre', 'code', 'sub', 'sup',
  ]);
  const voidTags = new Set(['br', 'hr', 'img']);

  return html.replace(/<\s*(\/?)\s*([a-z][a-z0-9]*)\b([^>]*)>/gi, (_full, slash: string, tagRaw: string, rawAttrs: string) => {
    const closing = slash === '/';
    const tag = tagRaw.toLowerCase();
    if (!allowedTags.has(tag)) return '';
    if (closing) return voidTags.has(tag) ? '' : `</${tag}>`;

    const attrs: Record<string, string> = {};
    const attrRegex = /([a-zA-Z_:][a-zA-Z0-9:._-]*)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'`=<>]+))/g;
    let m: RegExpExecArray | null;
    while ((m = attrRegex.exec(rawAttrs || '')) !== null) {
      const name = m[1].toLowerCase();
      const value = (m[2] ?? m[3] ?? m[4] ?? '')
        .replace(/&quot;/g, '"')
        .replace(/&#039;/g, "'")
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&amp;/g, '&');

      if (name === 'style') {
        const style = sanitizeRichStyle(value);
        if (style) attrs.style = style;
      } else if (name === 'href' && tag === 'a') {
        const url = safeRichContentUrl(value);
        if (url) attrs.href = url;
      } else if (name === 'src' && tag === 'img') {
        const url = safeRichContentUrl(value);
        if (url) attrs.src = url;
      } else if ((name === 'alt' || name === 'title') && (tag === 'a' || tag === 'img')) {
        attrs[name] = value.slice(0, 500);
      } else if ((name === 'width' || name === 'height') && tag === 'img' && /^[1-9][0-9]{0,3}$/.test(value)) {
        attrs[name] = value;
      } else if (name === 'target' && tag === 'a' && (value === '_blank' || value === '_self')) {
        attrs.target = value;
      } else if (name === 'dir' && (value === 'ltr' || value === 'rtl' || value === 'auto')) {
        attrs.dir = value;
      } else if (name === 'lang' && /^[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*$/.test(value)) {
        attrs.lang = value;
      }
    }

    if (tag === 'a' && attrs.href && attrs.target === '_blank') {
      attrs.rel = 'noopener noreferrer';
    }
    if (tag === 'img' && !attrs.src) return '';

    const serialized = Object.entries(attrs)
      .map(([k, v]) => ` ${k}="${e(v)}"`)
      .join('');
    return `<${tag}${serialized}>`;
  });
}

function safeRichContentUrl(value: string): string {
  const v = value.trim();
  if (!v || /[\x00-\x20\x7F]/.test(v) || v.includes('\\')) return '';
  if (v.startsWith('/') && !v.startsWith('//')) return v;
  if (v.startsWith('#')) return v;
  try {
    const parsed = new URL(v);
    return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? v : '';
  } catch {
    return '';
  }
}

function sanitizeRichStyle(style: string): string {
  const allowed: Record<string, string> = {};
  for (const decl of style.split(';')) {
    const idx = decl.indexOf(':');
    if (idx < 0) continue;
    const prop = decl.slice(0, idx).trim().toLowerCase();
    const val = decl.slice(idx + 1).trim().toLowerCase();
    if (!val || /url\s*\(|expression|javascript|behavior|@import|\/\*/i.test(val)) continue;

    if (prop === 'font-size' && /^(?:[8-9]|[1-7][0-9]|8[0-4])(?:px|pt|em|rem|%)$/.test(val)) {
      allowed[prop] = val;
    } else if (
      (prop === 'color' || prop === 'background-color') &&
      (/^#[0-9a-f]{3,8}$/i.test(val) ||
        ['black', 'white', 'red', 'blue', 'green', 'gray', 'grey', 'orange', 'purple', 'transparent'].includes(val))
    ) {
      allowed[prop] = val;
    } else if (prop === 'text-align' && ['left', 'right', 'center', 'justify', 'start', 'end'].includes(val)) {
      allowed[prop] = val;
    } else if (prop === 'font-weight' && ['normal', 'bold', '400', '500', '600', '700', '800'].includes(val)) {
      allowed[prop] = val;
    } else if (prop === 'font-style' && ['normal', 'italic'].includes(val)) {
      allowed[prop] = val;
    } else if (prop === 'text-decoration' && ['none', 'underline', 'line-through'].includes(val)) {
      allowed[prop] = val;
    }
  }
  return Object.entries(allowed)
    .map(([k, v]) => `${k}:${v}`)
    .join(';');
}

export function richHtmlPlainText(html: string, limit = 500): string {
  const stripped = html
    .replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/\s+/g, ' ')
    .trim();
  return stripped.slice(0, limit);
}

export function allAdminPermissions(): Record<string, { label: string; description: string }> {
  return {
    manage_posts: {
      label: 'গ্রামের খবর ও পোস্ট',
      description: 'লেখা, ছবি, ভিডিও ও কাস্টম কোডসহ পোস্ট তৈরি, সম্পাদনা, প্রকাশ, অপ্রকাশ এবং মুছে ফেলুন।',
    },
    manage_pages: {
      label: 'পেজ ব্যবস্থাপনা ও আমাদের পরিচিতি',
      description: 'নিজস্ব HTML/CSS/JavaScript পেজ তৈরি ও প্রকাশ করুন এবং আমাদের পরিচিতি পেজ পরিচালনা করুন।',
    },
    manage_gallery: {
      label: 'ছবির গ্যালারি',
      description: 'গ্যালারিতে ছবি আপলোড, ক্যাপশন সম্পাদনা, ক্রম পরিবর্তন, বিশেষ ছবি নির্বাচন এবং মুছে ফেলুন।',
    },
    manage_banners: {
      label: 'প্রধান ব্যানার',
      description: 'মূল পাতার ব্যানার আপলোড, ক্রম ও প্রদর্শনের সময় নির্ধারণ করুন।',
    },
    manage_popups: {
      label: 'পপআপ ব্যবস্থাপনা',
      description: 'ছবি বা লেখার পপআপ তৈরি করুন এবং লিংক, প্রদর্শনের লক্ষ্য ও সময়সূচি নির্ধারণ করুন।',
    },
    manage_categories: {
      label: 'বিভাগসমূহ',
      description: 'পোস্ট ও গ্যালারির বিভাগ তৈরি, সম্পাদনা, ক্রম পরিবর্তন এবং মুছে ফেলুন।',
    },
    manage_navigation: {
      label: 'হেডার ও ফুটার',
      description: 'হেডারের লোগো, রং ও মেনু এবং ফুটারের লেখা, লিংক ও যোগাযোগের তথ্য পরিবর্তন করুন।',
    },
    manage_settings: {
      label: 'ওয়েবসাইট সেটিংস',
      description: 'ওয়েবসাইটের নাম, ফেভিকন, সার্চ ইঞ্জিনের তথ্য, প্রধান রং ও সামাজিক যোগাযোগের লিংক পরিবর্তন করুন।',
    },
  };
}

export function decodeAdminPermissions(raw: unknown): string[] {
  let list: unknown[] = [];
  if (typeof raw === 'string') {
    try {
      const parsed = JSON.parse(raw);
      if (Array.isArray(parsed)) list = parsed;
    } catch {
      list = [];
    }
  } else if (Array.isArray(raw)) {
    list = raw;
  }
  const valid = new Set(Object.keys(allAdminPermissions()));
  const clean: string[] = [];
  for (const item of list) {
    if (typeof item === 'string' && valid.has(item) && !clean.includes(item)) {
      clean.push(item);
    }
  }
  return clean;
}

export function isSuperAdmin(admin: AdminRecord | null | undefined): boolean {
  return admin?.role === 'super_admin';
}

export function adminHasPermission(permission: string, admin: AdminRecord | null | undefined): boolean {
  if (!admin) return false;
  if (isSuperAdmin(admin)) return true;
  return admin.permissions.includes(permission);
}

export function validatePasswordStrength(password: string): string[] {
  const errors: string[] = [];
  if (password.length < 10) errors.push('পাসওয়ার্ড কমপক্ষে ১০ অক্ষরের হতে হবে।');
  if (!/[A-Z]/.test(password)) errors.push('পাসওয়ার্ডে অন্তত একটি বড় হাতের ইংরেজি অক্ষর থাকতে হবে।');
  if (!/[a-z]/.test(password)) errors.push('পাসওয়ার্ডে অন্তত একটি ছোট হাতের ইংরেজি অক্ষর থাকতে হবে।');
  if (!/[0-9]/.test(password)) errors.push('পাসওয়ার্ডে অন্তত একটি সংখ্যা থাকতে হবে।');
  if (!/[^a-zA-Z0-9]/.test(password)) errors.push('পাসওয়ার্ডে অন্তত একটি বিশেষ চিহ্ন থাকতে হবে।');
  return errors;
}

export function logAdminActivity(
  adminId: number | null,
  action: string,
  entityType: string | null = null,
  entityId: number | null = null,
  details: Record<string, unknown> | null = null,
  ipAddress: string | null = null
): void {
  store.activity_logs.unshift({
    id: nextIdFor('activity_logs'),
    admin_id: adminId,
    action,
    entity_type: entityType,
    entity_id: entityId,
    details,
    ip_address: ipAddress,
    created_at: new Date().toISOString(),
  });
  if (store.activity_logs.length > 500) {
    store.activity_logs.length = 500;
  }
}

export function adminActivityLabel(action: string): string {
  const map: Record<string, string> = {
    admin_account_updated: 'অ্যাডমিন অ্যাকাউন্ট হালনাগাদ',
    admin_account_created: 'অ্যাডমিন অ্যাকাউন্ট তৈরি',
    admin_account_deleted: 'অ্যাডমিন অ্যাকাউন্ট মুছে ফেলা',
    admin_enabled: 'অ্যাডমিন অ্যাকাউন্ট চালু',
    admin_disabled: 'অ্যাডমিন অ্যাকাউন্ট বন্ধ',
    admin_login: 'অ্যাডমিন লগইন',
    admin_logout: 'অ্যাডমিন লগআউট',
    banner_created: 'ব্যানার তৈরি',
    banner_updated: 'ব্যানার হালনাগাদ',
    banner_deleted: 'ব্যানার মুছে ফেলা',
    footer_settings_updated: 'ফুটারের সেটিংস হালনাগাদ',
    gallery_image_deleted: 'গ্যালারির ছবি মুছে ফেলা',
    gallery_image_uploaded: 'গ্যালারিতে ছবি আপলোড',
    gallery_image_updated: 'গ্যালারির ছবি হালনাগাদ',
    password_reset_completed: 'পাসওয়ার্ড পরিবর্তন সম্পন্ন',
    password_reset_requested: 'পাসওয়ার্ড পরিবর্তনের অনুরোধ',
    website_settings_updated: 'ওয়েবসাইটের সেটিংস হালনাগাদ',
    header_brand_updated: 'হেডার ব্র্যান্ডিং হালনাগাদ',
    post_created: 'পোস্ট তৈরি',
    post_updated: 'পোস্ট হালনাগাদ',
    post_deleted: 'পোস্ট মুছে ফেলা',
    popup_created: 'পপআপ তৈরি',
    popup_updated: 'পপআপ হালনাগাদ',
    popup_deleted: 'পপআপ মুছে ফেলা',
    popup_toggled: 'পপআপের অবস্থা পরিবর্তন',
    password_changed: 'পাসওয়ার্ড পরিবর্তন',
    navigation_item_created: 'মেনু আইটেম তৈরি',
    nav_item_created: 'মেনু আইটেম তৈরি',
    nav_item_updated: 'মেনু আইটেম হালনাগাদ',
    nav_item_deleted: 'মেনু আইটেম মুছে ফেলা',
    content_page_created: 'ল্যান্ডিং পেজ তৈরি',
    content_page_updated: 'ল্যান্ডিং পেজ হালনাগাদ',
    content_page_published: 'ল্যান্ডিং পেজ প্রকাশিত',
    content_page_unpublished: 'ল্যান্ডিং পেজ অপ্রকাশিত',
    content_page_deleted: 'ল্যান্ডিং পেজ মুছে ফেলা',
  };
  return map[action] || 'অন্যান্য কার্যক্রম';
}

export function adminEntityLabel(type: string): string {
  const map: Record<string, string> = {
    admin: 'অ্যাডমিন',
    post: 'পোস্ট',
    content_page: 'কাস্টম পেজ',
    gallery_image: 'গ্যালারির ছবি',
    hero_banner: 'ব্যানার',
    site_popup: 'পপআপ',
    website_settings: 'ওয়েবসাইটের সেটিংস',
    footer_settings: 'ফুটারের সেটিংস',
    navigation_item: 'মেনু আইটেম',
    navigation_items: 'মেনু আইটেম',
    rate_limit_rules: 'অনুরোধ সীমার নিয়ম',
    rate_limit_buckets: 'অনুরোধ সীমার হিসাব',
  };
  return map[type] || 'অন্যান্য';
}

export function getNavigationTree(location: 'header' | 'footer', includeHidden = false): NavigationItem[] {
  const menu = store.navigation_menus.find((m) => m.location === location);
  if (!menu) return [];
  const rows = store.navigation_items
    .filter((item) => item.menu_id === menu.id && (includeHidden || item.is_visible))
    .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);

  const byId = new Map<number, NavigationItem>();
  for (const row of rows) {
    byId.set(row.id, { ...row, children: [] });
  }
  const tree: NavigationItem[] = [];
  for (const node of byId.values()) {
    if (node.parent_id !== null && byId.has(node.parent_id)) {
      byId.get(node.parent_id)!.children!.push(node);
    } else {
      tree.push(node);
    }
  }
  return tree;
}

// ============================================================================
// Sessions & CSRF
// ============================================================================
const SESSION_SECRET = process.env.SESSION_SECRET || crypto.randomBytes(32).toString('hex');

function signSessionId(id: string): string {
  const sig = crypto.createHmac('sha256', SESSION_SECRET).update(id).digest('hex');
  return `${id}.${sig}`;
}

function verifySessionCookie(cookieVal: string | undefined): string | null {
  if (!cookieVal) return null;
  const idx = cookieVal.lastIndexOf('.');
  if (idx <= 0) return null;
  const id = cookieVal.slice(0, idx);
  const sig = cookieVal.slice(idx + 1);
  const expected = crypto.createHmac('sha256', SESSION_SECRET).update(id).digest('hex');
  if (sig.length !== expected.length) return null;
  if (!crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected))) return null;
  return id;
}

export function getOrCreateSession(req: Request, res: Response): AdminSession {
  const rawCookie = req.cookies?.[SESSION_COOKIE_NAME] as string | undefined;
  const verifiedId = verifySessionCookie(rawCookie);
  if (verifiedId && store.sessions.has(verifiedId)) {
    return store.sessions.get(verifiedId)!;
  }
  const id = crypto.randomBytes(24).toString('hex');
  const session: AdminSession = {
    id,
    admin_id: null,
    admin_login_at: 0,
    admin_last_active: 0,
    csrf_token: crypto.randomBytes(32).toString('hex'),
    flash: null,
  };
  store.sessions.set(id, session);
  res.cookie(SESSION_COOKIE_NAME, signSessionId(id), {
    httpOnly: true,
    sameSite: 'lax',
    path: '/',
    maxAge: 31536000 * 1000,
  });
  return session;
}

export function regenerateSession(req: Request, res: Response, oldSession: AdminSession): AdminSession {
  store.sessions.delete(oldSession.id);
  const newId = crypto.randomBytes(24).toString('hex');
  const fresh: AdminSession = {
    id: newId,
    admin_id: oldSession.admin_id,
    admin_login_at: oldSession.admin_login_at,
    admin_last_active: oldSession.admin_last_active,
    csrf_token: crypto.randomBytes(32).toString('hex'),
    flash: oldSession.flash,
  };
  store.sessions.set(newId, fresh);
  res.cookie(SESSION_COOKIE_NAME, signSessionId(newId), {
    httpOnly: true,
    sameSite: 'lax',
    path: '/',
    maxAge: 31536000 * 1000,
  });
  return fresh;
}

export function getCurrentAdmin(session: AdminSession): AdminRecord | null {
  if (!session.admin_id) return null;
  const admin = store.admins.find((a) => a.id === session.admin_id && a.is_active);
  if (!admin) {
    session.admin_id = null;
    return null;
  }
  session.admin_last_active = Math.floor(Date.now() / 1000);
  return admin;
}

export function setFlash(session: AdminSession, type: 'success' | 'error', message: string): void {
  session.flash = { type, message };
}

export function consumeFlash(session: AdminSession): { type: 'success' | 'error'; message: string } | null {
  const f = session.flash;
  session.flash = null;
  return f;
}

export function csrfField(session: AdminSession): string {
  return `<input type="hidden" name="csrf_token" value="${e(session.csrf_token)}">`;
}

export function validateCsrf(req: Request, session: AdminSession): boolean {
  const token = String(req.body?.csrf_token ?? req.headers['x-csrf-token'] ?? '');
  if (!token || !session.csrf_token || token.length !== session.csrf_token.length) {
    return false;
  }
  return crypto.timingSafeEqual(Buffer.from(token), Buffer.from(session.csrf_token));
}

// ============================================================================
// Rate Limiter (mirrors includes/rate_limiter.php)
// ============================================================================
export const VALID_RATE_UNITS: RateLimitUnit[] = ['seconds', 'minutes', 'hours', 'days'];

export function windowSeconds(value: number, unit: RateLimitUnit): number {
  const v = Math.max(1, value);
  switch (unit) {
    case 'seconds': return v;
    case 'minutes': return v * 60;
    case 'hours': return v * 3600;
    case 'days': return v * 86400;
  }
}

export function hashBucketKey(rawKey: string): string {
  return crypto.createHash('sha256').update(rawKey.trim().toLowerCase()).digest('hex');
}

export function formatRetryAfter(seconds: number): string {
  const s = Math.max(1, seconds);
  if (s < 60) return s === 1 ? '১ সেকেন্ড' : `${s} সেকেন্ড`;
  if (s < 3600) {
    const mins = Math.ceil(s / 60);
    return mins === 1 ? '১ মিনিট' : `${mins} মিনিট`;
  }
  if (s < 86400) {
    const hours = Math.ceil(s / 3600);
    return hours === 1 ? '১ ঘণ্টা' : `${hours} ঘণ্টা`;
  }
  const days = Math.ceil(s / 86400);
  return days === 1 ? '১ দিন' : `${days} দিন`;
}

export interface RateLimitResult {
  allowed: boolean;
  limit: number;
  remaining: number;
  retry_after: number;
  retry_message: string;
  reset_at: number;
  rule: RateLimitRule;
}

export function checkRateLimit(
  scope: RateLimitScope,
  targets: { ip?: string | null; account?: string | null },
  consume = true
): RateLimitResult {
  const rule = store.rate_limit_rules.find((r) => r.scope === scope)!;
  const nowSec = Math.floor(Date.now() / 1000);
  const maxRequests = Math.max(1, rule.max_requests);
  const winSec = windowSeconds(rule.window_value, rule.window_unit);

  // Match PHP behavior: keep admin_login protection active; other scopes honor rule.is_enabled when checked
  const enabled = scope === 'admin_login' ? rule.is_enabled : false;
  if (!enabled) {
    return {
      allowed: true,
      limit: maxRequests,
      remaining: maxRequests,
      retry_after: 0,
      retry_message: '',
      reset_at: nowSec + winSec,
      rule,
    };
  }

  const normalized: Array<{ type: 'ip' | 'account'; key: string }> = [];
  if (targets.ip) normalized.push({ type: 'ip', key: hashBucketKey(`ip:${targets.ip}`) });
  if (targets.account) normalized.push({ type: 'account', key: hashBucketKey(`account:${targets.account}`) });

  if (normalized.length === 0) {
    return {
      allowed: true,
      limit: maxRequests,
      remaining: maxRequests,
      retry_after: 0,
      retry_message: '',
      reset_at: nowSec + winSec,
      rule,
    };
  }

  const nowIso = new Date(nowSec * 1000).toISOString();
  const expIso = new Date((nowSec + winSec) * 1000).toISOString();

  let blockedRetryAfter = 0;
  let highestCount = 0;
  let soonestReset = nowSec + winSec;
  const touched = [];

  for (const t of normalized) {
    let bucket = store.rate_limit_buckets.find(
      (b) => b.scope === scope && b.bucket_type === t.type && b.bucket_key === t.key
    );
    if (!bucket) {
      bucket = {
        id: nextIdFor('rate_limit_buckets'),
        scope,
        bucket_type: t.type,
        bucket_key: t.key,
        request_count: 0,
        window_started_at: nowIso,
        window_expires_at: expIso,
        last_request_at: nowIso,
      };
      store.rate_limit_buckets.push(bucket);
    }

    let expTs = Math.floor(new Date(bucket.window_expires_at).getTime() / 1000);
    if (Number.isNaN(expTs) || expTs <= nowSec) {
      bucket.request_count = 0;
      bucket.window_started_at = nowIso;
      bucket.window_expires_at = expIso;
      bucket.last_request_at = nowIso;
      expTs = nowSec + winSec;
    }

    if (bucket.request_count >= maxRequests) {
      blockedRetryAfter = Math.max(blockedRetryAfter, Math.max(1, expTs - nowSec));
    }
    if (bucket.request_count > highestCount) highestCount = bucket.request_count;
    if (expTs < soonestReset) soonestReset = expTs;
    touched.push(bucket);
  }

  if (blockedRetryAfter > 0) {
    const human = formatRetryAfter(blockedRetryAfter);
    return {
      allowed: false,
      limit: maxRequests,
      remaining: 0,
      retry_after: blockedRetryAfter,
      retry_message: `${rule.label} সীমা অতিক্রম হয়েছে। অনুগ্রহ করে ${human} পরে আবার চেষ্টা করুন।`,
      reset_at: nowSec + blockedRetryAfter,
      rule,
    };
  }

  if (consume) {
    for (const b of touched) {
      b.request_count += 1;
      b.last_request_at = nowIso;
    }
    highestCount += 1;
  }

  return {
    allowed: true,
    limit: maxRequests,
    remaining: Math.max(0, maxRequests - highestCount),
    retry_after: 0,
    retry_message: '',
    reset_at: soonestReset,
    rule,
  };
}

export function resetRateLimit(scope: RateLimitScope, targets: { ip?: string | null; account?: string | null }): void {
  const keys = new Set<string>();
  if (targets.ip) keys.add(`ip:${hashBucketKey(`ip:${targets.ip}`)}`);
  if (targets.account) keys.add(`account:${hashBucketKey(`account:${targets.account}`)}`);
  store.rate_limit_buckets = store.rate_limit_buckets.filter(
    (b) => !(b.scope === scope && keys.has(`${b.bucket_type}:${b.bucket_key}`))
  );
}

export function resetRateLimitScope(scope: RateLimitScope): number {
  const before = store.rate_limit_buckets.length;
  store.rate_limit_buckets = store.rate_limit_buckets.filter((b) => b.scope !== scope);
  return before - store.rate_limit_buckets.length;
}

export function purgeExpiredRateLimits(): number {
  const nowMs = Date.now();
  const before = store.rate_limit_buckets.length;
  store.rate_limit_buckets = store.rate_limit_buckets.filter(
    (b) => new Date(b.window_expires_at).getTime() > nowMs
  );
  return before - store.rate_limit_buckets.length;
}

export function applyRateLimitHeaders(res: Response, result: RateLimitResult): void {
  const retryAfter = Math.max(1, result.retry_after || 60);
  res.status(429);
  res.setHeader('Retry-After', String(retryAfter));
  res.setHeader('X-RateLimit-Limit', String(result.limit));
  res.setHeader('X-RateLimit-Remaining', '0');
  res.setHeader('X-RateLimit-Reset', String(result.reset_at));
}
