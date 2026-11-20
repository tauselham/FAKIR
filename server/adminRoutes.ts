import bcrypt from 'bcryptjs';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import multer from 'multer';
import type { Express, NextFunction, Request, Response } from 'express';
import { getDemoAdminCredentials, nextIdFor, store } from './store.ts';
import type { AdminRecord, AdminRole, AdminSession, ContentPage, PostRecord, RateLimitScope } from './types.ts';
import {
  ADMIN_BASE_PATH,
  ALLOWED_ASSET_MIMES,
  ALLOWED_BANNER_MIMES,
  ALLOWED_FAVICON_MIMES,
  ALLOWED_IMAGE_MIMES,
  ALLOWED_LOGO_MIMES,
  ALLOWED_VIDEO_MIMES,
  BUCKET_GALLERY_IMAGES,
  BUCKET_HERO_BANNERS,
  BUCKET_POST_MEDIA,
  BUCKET_WEBSITE_ASSETS,
  MAX_ASSET_UPLOAD_BYTES,
  MAX_IMAGE_UPLOAD_BYTES,
  MAX_VIDEO_UPLOAD_BYTES,
  VALID_RATE_UNITS,
  adminActivityLabel,
  adminEntityLabel,
  adminHasPermission,
  adminUrl,
  allAdminPermissions,
  applyRateLimitHeaders,
  checkRateLimit,
  clientIp,
  consumeFlash,
  csrfField,
  decodeAdminPermissions,
  e,
  formatDate,
  formatPopupDhakaLocal,
  getCurrentAdmin,
  getOrCreateSession,
  isSuperAdmin,
  logAdminActivity,
  parsePopupDhakaLocal,
  purgeExpiredRateLimits,
  regenerateSession,
  resetRateLimit,
  resetRateLimitScope,
  richHtmlPlainText,
  safeUrl,
  sanitizeHexColor,
  sanitizeRichHtml,
  setFlash,
  slugify,
  validateContentSlug,
  validateCsrf,
  validatePasswordStrength,
  windowSeconds,
} from './utils.ts';
import { deleteStoredFile, storeUploadedFile } from './store.ts';

const APP_URL = process.env.APP_URL || 'http://localhost:3000';
const ADMIN_ASSETS_DIR = path.resolve(process.cwd(), 'admin', 'assets');

const upload = multer({
  storage: multer.memoryStorage(),
  limits: {
    fileSize: MAX_VIDEO_UPLOAD_BYTES,
    files: 30,
  },
});

function maybeMultipart(req: Request, res: Response, next: NextFunction): void {
  if (req.is('multipart/form-data')) {
    upload.any()(req, res, (err: unknown) => {
      if (err) {
        const session = getOrCreateSession(req, res);
        const msg = err instanceof Error ? err.message : 'ফাইল আপলোড করা যায়নি।';
        setFlash(session, 'error', msg);
        res.redirect(303, req.originalUrl.split('?')[0]);
        return;
      }
      next();
    });
    return;
  }
  next();
}

function getUploadedFiles(req: Request, fieldName: string): Express.Multer.File[] {
  const files = (req as Request & { files?: Express.Multer.File[] }).files;
  if (!Array.isArray(files)) return [];
  return files.filter((f) => f.fieldname === fieldName || f.fieldname === `${fieldName}[]`);
}

function getSingleUploadedFile(req: Request, fieldName: string): Express.Multer.File | null {
  const list = getUploadedFiles(req, fieldName);
  return list.length > 0 && list[0].size > 0 ? list[0] : null;
}

function sendAdminNocacheHeaders(res: Response): void {
  res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
  res.setHeader('Pragma', 'no-cache');
  res.setHeader('Expires', '0');
  res.setHeader('X-Frame-Options', 'DENY');
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
}

function requireValidCsrf(req: Request, res: Response, session: AdminSession): boolean {
  if (!validateCsrf(req, session)) {
    res.status(403).type('text').send('Security validation failed (invalid CSRF token). Please go back, refresh, and try again.');
    return false;
  }
  return true;
}

function requireAdminAuth(req: Request, res: Response): { session: AdminSession; admin: AdminRecord } | null {
  sendAdminNocacheHeaders(res);
  const session = getOrCreateSession(req, res);
  const admin = getCurrentAdmin(session);
  if (!admin) {
    res.redirect(303, adminUrl('login.php'));
    return null;
  }
  return { session, admin };
}

function checkAdminAccess(
  req: Request,
  res: Response,
  session: AdminSession,
  admin: AdminRecord,
  options?: { activePage?: string; requiredPermission?: string; requiredSuperAdmin?: boolean }
): boolean {
  if (options?.requiredSuperAdmin && !isSuperAdmin(admin)) {
    logAdminActivity(admin.id, 'unauthorized_access_attempt', 'admin_module', null, {
      module: options.activePage || '',
      reason: 'super_admin_required',
    }, clientIp(req));
    setFlash(session, 'error', 'শুধুমাত্র সুপার অ্যাডমিন এই বিভাগে প্রবেশ করতে পারবেন।');
    res.redirect(303, adminUrl('dashboard.php'));
    return false;
  }
  if (options?.requiredPermission && !adminHasPermission(options.requiredPermission, admin)) {
    logAdminActivity(admin.id, 'unauthorized_access_attempt', 'admin_module', null, {
      module: options.activePage || '',
      permission: options.requiredPermission,
    }, clientIp(req));
    setFlash(session, 'error', 'এই বিভাগে প্রবেশের অনুমতি আপনার নেই।');
    res.redirect(303, adminUrl('dashboard.php'));
    return false;
  }
  if (req.method === 'POST') {
    const rl = checkRateLimit('admin_api', { ip: clientIp(req), account: `admin:${admin.id}` });
    if (!rl.allowed) {
      applyRateLimitHeaders(res, rl);
      res.type('text').send(rl.retry_message);
      return false;
    }
  }
  return true;
}

function renderAdminLayout(
  session: AdminSession,
  currentAdmin: AdminRecord,
  activePage: string,
  pageTitle: string,
  bodyHtml: string,
  extraScripts = ''
): string {
  const siteSettings = store.website_settings;
  const flash = consumeFlash(session);

  const allNavItems: Array<{ id: string; label: string; href: string; perm: string | null; superOnly: boolean }> = [
    { id: 'dashboard', label: 'ড্যাশবোর্ড', href: adminUrl('dashboard.php'), perm: null, superOnly: false },
    { id: 'pages', label: 'পেজ ব্যবস্থাপনা', href: adminUrl('pages.php'), perm: 'manage_pages', superOnly: false },
    { id: 'posts', label: 'গ্রামের খবর ও পোস্ট', href: adminUrl('posts.php'), perm: 'manage_posts', superOnly: false },
    { id: 'gallery', label: 'ছবির গ্যালারি', href: adminUrl('gallery.php'), perm: 'manage_gallery', superOnly: false },
    { id: 'banners', label: 'প্রধান ব্যানার', href: adminUrl('banners.php'), perm: 'manage_banners', superOnly: false },
    { id: 'popups', label: 'পপআপ ব্যবস্থাপনা', href: adminUrl('popups.php'), perm: 'manage_popups', superOnly: false },
    { id: 'categories', label: 'বিভাগসমূহ', href: adminUrl('categories.php'), perm: 'manage_categories', superOnly: false },
    { id: 'header', label: 'হেডার ও মেনু', href: adminUrl('header.php'), perm: 'manage_navigation', superOnly: false },
    { id: 'footer', label: 'ফুটার সেটিংস', href: adminUrl('footer.php'), perm: 'manage_navigation', superOnly: false },
    { id: 'settings', label: 'ওয়েবসাইট সেটিংস', href: adminUrl('settings.php'), perm: 'manage_settings', superOnly: false },
    { id: 'admins', label: 'অ্যাডমিন ব্যবহারকারী', href: adminUrl('admins.php'), perm: null, superOnly: true },
    { id: 'rate_limits', label: 'অনুরোধ সীমা', href: adminUrl('rate-limits.php'), perm: null, superOnly: true },
    { id: 'security', label: 'নিরাপত্তা ও পাসওয়ার্ড', href: adminUrl('security.php'), perm: null, superOnly: false },
  ];

  const navItems = allNavItems.filter((item) => {
    if (item.superOnly) return isSuperAdmin(currentAdmin);
    if (item.perm) return adminHasPermission(item.perm, currentAdmin);
    return true;
  });

  const roleLabel = isSuperAdmin(currentAdmin)
    ? 'সুপার অ্যাডমিন'
    : currentAdmin.role.charAt(0).toUpperCase() + currentAdmin.role.slice(1);

  return `<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>${e(pageTitle)} — অ্যাডমিন প্যানেল</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;500;600;700&family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="${e(adminUrl('assets/css/admin.css'))}?v=20261116-bengali">
</head>
<body>
<div class="adm-shell">
  <aside class="adm-sidebar" id="adm-sidebar" aria-label="অ্যাডমিন নেভিগেশন">
    <div class="adm-brand">
      <span>${e(siteSettings.site_name)} অ্যাডমিন</span>
    </div>

    <nav class="adm-nav">
      ${navItems
        .map(
          (item) => `<a href="${e(item.href)}" class="adm-nav-link ${activePage === item.id ? 'is-active' : ''}">
          <span>${e(item.label)}</span>
        </a>`
        )
        .join('')}
    </nav>

    <div class="adm-sidebar-foot">
      <a href="/" target="_blank" rel="noopener noreferrer" class="adm-nav-link">View Public Site ↗</a>
      <form method="post" action="${e(adminUrl('logout.php'))}">
        ${csrfField(session)}
        <button type="submit" class="btn btn-outline btn-sm" style="width: 100%; background: rgba(255,255,255,0.12); color: #fff; border-color: rgba(255,255,255,0.2);">
          লগআউট (${e(currentAdmin.username)})
        </button>
      </form>
    </div>
  </aside>

  <div class="adm-main">
    <header class="adm-topbar">
      <div class="adm-topbar-left">
        <button type="button" class="adm-menu-btn" id="adm-menu-btn" aria-label="সাইডবার খুলুন বা বন্ধ করুন">☰</button>
        <h1 class="adm-page-title">${e(pageTitle)}</h1>
      </div>
      <div style="font-size: 0.85rem; color: var(--adm-muted);">
        লগইন করেছেন <strong>${e(currentAdmin.full_name)}</strong> (${e(roleLabel)})
      </div>
    </header>

    <main class="adm-content">
      ${
        currentAdmin.must_change_password && activePage !== 'security'
          ? `<div class="alert alert-error" role="status" style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
              <span>You are using an initial or temporary password. Please set your own permanent password now.</span>
              <a href="${e(adminUrl('security.php'))}" class="btn btn-green btn-sm">পাসওয়ার্ড পরিবর্তন করুন</a>
            </div>`
          : ''
      }
      ${
        flash
          ? `<div class="alert ${flash.type === 'success' ? 'alert-success' : 'alert-error'}" role="status">${e(flash.message)}</div>`
          : ''
      }
      ${bodyHtml}
    </main>
  </div>
</div>
${extraScripts}
<script src="${e(adminUrl('assets/js/admin.js'))}?v=20261115-rich-editor" defer></script>
</body>
</html>`;
}

export function registerAdminRoutes(app: Express): void {
  // Block direct /admin and /admin/* access just like includes/admin-router.php
  app.use('/admin', (_req: Request, res: Response) => {
    res.status(404).type('text').send('404 Not Found');
  });

  // Serve admin CSS/JS assets safely under /448662/assets/*
  app.get(`${ADMIN_BASE_PATH}/assets/*`, (req: Request, res: Response) => {
    const rel = String(req.params[0] || '').replace(/^\/+/, '');
    if (!rel || rel.includes('..') || rel.includes('\0')) {
      res.status(404).type('text').send('404 Not Found');
      return;
    }
    const resolved = path.resolve(ADMIN_ASSETS_DIR, rel);
    if (!resolved.startsWith(ADMIN_ASSETS_DIR + path.sep) || !fs.existsSync(resolved) || !fs.statSync(resolved).isFile()) {
      res.status(404).type('text').send('404 Not Found');
      return;
    }
    const ext = path.extname(resolved).toLowerCase();
    const mimeMap: Record<string, string> = {
      '.css': 'text/css; charset=UTF-8',
      '.js': 'application/javascript; charset=UTF-8',
      '.svg': 'image/svg+xml',
      '.png': 'image/png',
      '.jpg': 'image/jpeg',
      '.jpeg': 'image/jpeg',
      '.webp': 'image/webp',
      '.ico': 'image/x-icon',
    };
    res.setHeader('Content-Type', mimeMap[ext] || 'application/octet-stream');
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('Cache-Control', 'public, max-age=3600');
    res.sendFile(resolved);
  });

  // Admin root entry (/448662 and /448662/)
  app.get([ADMIN_BASE_PATH, `${ADMIN_BASE_PATH}/`, `${ADMIN_BASE_PATH}/index.php`], (req: Request, res: Response) => {
    sendAdminNocacheHeaders(res);
    const session = getOrCreateSession(req, res);
    if (getCurrentAdmin(session)) {
      res.redirect(303, adminUrl('dashboard.php'));
    } else {
      res.redirect(303, adminUrl('login.php'));
    }
  });

  // --------------------------------------------------------------------------
  // Login
  // --------------------------------------------------------------------------
  app.all(`${ADMIN_BASE_PATH}/login.php`, (req: Request, res: Response) => {
    sendAdminNocacheHeaders(res);
    let session = getOrCreateSession(req, res);
    if (getCurrentAdmin(session)) {
      res.redirect(303, adminUrl('dashboard.php'));
      return;
    }

    let error: string | null = null;
    let identifier = '';

    if (req.method === 'POST') {
      if (!requireValidCsrf(req, res, session)) return;
      identifier = String(req.body?.identifier ?? '').trim();
      const password = String(req.body?.password ?? '');
      const ip = clientIp(req);
      const accountKey = identifier !== '' ? identifier.toLowerCase() : null;

      const rl = checkRateLimit('admin_login', { ip, account: accountKey });
      if (!rl.allowed) {
        applyRateLimitHeaders(res, rl);
        store.login_attempts.push({
          id: nextIdFor('login_attempts'),
          ip_address: ip,
          identifier: identifier.slice(0, 120),
          success: false,
          attempted_at: new Date().toISOString(),
        });
        error = rl.retry_message;
      } else {
        const normalizedId = identifier.toLowerCase();
        const admin = store.admins.find(
          (a) => a.username.toLowerCase() === normalizedId || a.email.toLowerCase() === normalizedId
        );

        const validPw = admin ? bcrypt.compareSync(password, admin.password_hash) : false;

        if (!admin || !admin.is_active || !validPw) {
          store.login_attempts.push({
            id: nextIdFor('login_attempts'),
            ip_address: ip,
            identifier: identifier.slice(0, 120),
            success: false,
            attempted_at: new Date().toISOString(),
          });
          error = 'ব্যবহারকারীর নাম অথবা পাসওয়ার্ড সঠিক নয়।';
        } else {
          resetRateLimit('admin_login', { ip, account: accountKey });
          store.login_attempts.push({
            id: nextIdFor('login_attempts'),
            ip_address: ip,
            identifier: identifier.slice(0, 120),
            success: true,
            attempted_at: new Date().toISOString(),
          });
          admin.last_login_at = new Date().toISOString();

          session = regenerateSession(req, res, session);
          const nowSec = Math.floor(Date.now() / 1000);
          session.admin_id = admin.id;
          session.admin_login_at = nowSec;
          session.admin_last_active = nowSec;

          logAdminActivity(admin.id, 'admin_login', 'admin', admin.id, null, ip);
          setFlash(session, 'success', 'অ্যাডমিন প্যানেলে আপনাকে স্বাগতম।');
          res.redirect(303, adminUrl('dashboard.php'));
          return;
        }
      }
    }

    const flash = consumeFlash(session);
    const siteSettings = store.website_settings;
    const demoCreds = getDemoAdminCredentials();
    const seededAdmin = store.admins.find((a) => a.id === 1);
    const showDemoHint = seededAdmin && bcrypt.compareSync(demoCreds.password, seededAdmin.password_hash);

    res.type('html').send(`<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>অ্যাডমিন লগইন করুন — ${e(siteSettings.site_name)}</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;500;600;700&family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="${e(adminUrl('assets/css/admin.css'))}">
</head>
<body class="auth-page">
  <div class="auth-card">
    <div class="auth-brand">${e(siteSettings.site_name)}</div>
    <h1 class="auth-title">অ্যাডমিন লগইন করুন</h1>
    <p class="auth-subtitle">Sign in with your administrator username or email address.</p>
    ${
      showDemoHint
        ? `<p class="form-hint" style="margin: -12px 0 16px;">Demo Super Admin: <code>${e(demoCreds.username)}</code> / <code>${e(
            demoCreds.password
          )}</code></p>`
        : ''
    }
    ${flash ? `<div class="alert ${flash.type === 'success' ? 'alert-success' : 'alert-error'}">${e(flash.message)}</div>` : ''}
    ${error ? `<div class="alert alert-error">${e(error)}</div>` : ''}

    <form method="post" action="${e(adminUrl('login.php'))}" autocomplete="on">
      ${csrfField(session)}
      <div class="form-row">
        <label class="form-label" for="identifier">ব্যবহারকারীর নাম অথবা ইমেইল</label>
        <input class="form-control" type="text" id="identifier" name="identifier" value="${e(identifier)}" required autofocus autocomplete="username">
      </div>
      <div class="form-row">
        <label class="form-label" for="password">পাসওয়ার্ড</label>
        <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-green" style="width: 100%;">লগইন করুন</button>
    </form>

    <div style="display: flex; justify-content: space-between; margin-top: 18px; font-size: 0.85rem;">
      <a href="${e(adminUrl('forgot-password.php'))}" style="color: var(--adm-green); font-weight: 600;">Forgot password?</a>
      <a href="/" style="color: var(--adm-muted);">← মূল ওয়েবসাইট</a>
    </div>
  </div>
</body>
</html>`);
  });

  // --------------------------------------------------------------------------
  // Logout
  // --------------------------------------------------------------------------
  app.all(`${ADMIN_BASE_PATH}/logout.php`, (req: Request, res: Response) => {
    sendAdminNocacheHeaders(res);
    let session = getOrCreateSession(req, res);
    const admin = getCurrentAdmin(session);
    if (req.method === 'POST') {
      if (!requireValidCsrf(req, res, session)) return;
      if (admin) {
        logAdminActivity(admin.id, 'admin_logout', 'admin', admin.id, null, clientIp(req));
      }
      session.admin_id = null;
      session = regenerateSession(req, res, session);
      setFlash(session, 'success', 'আপনি নিরাপদে লগআউট হয়েছেন।');
    }
    res.redirect(303, adminUrl('login.php'));
  });

  // --------------------------------------------------------------------------
  // Forgot Password
  // --------------------------------------------------------------------------
  app.all(`${ADMIN_BASE_PATH}/forgot-password.php`, (req: Request, res: Response) => {
    sendAdminNocacheHeaders(res);
    const session = getOrCreateSession(req, res);
    let message: string | null = null;
    let errors: string[] = [];
    const token = String(req.query.token ?? req.body?.token ?? '').trim();

    if (req.method === 'POST') {
      if (!requireValidCsrf(req, res, session)) return;
      const action = String(req.body?.action ?? 'request');
      const targetEmail = String(req.body?.email ?? '').trim();
      const accountKey =
        action === 'reset'
          ? token !== ''
            ? `token:${crypto.createHash('sha256').update(token).digest('hex')}`
            : null
          : targetEmail !== ''
            ? `email:${targetEmail.toLowerCase()}`
            : null;

      const rl = checkRateLimit('password_recovery', { ip: clientIp(req), account: accountKey });
      if (!rl.allowed) {
        applyRateLimitHeaders(res, rl);
        errors.push(rl.retry_message);
      } else if (action === 'request') {
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(targetEmail)) {
          errors.push('Please enter a valid email address.');
        } else {
          const admin = store.admins.find((a) => a.email.toLowerCase() === targetEmail.toLowerCase() && a.is_active);
          let generatedToken: string | null = null;
          if (admin) {
            generatedToken = crypto.randomBytes(32).toString('hex');
            const tokenHash = crypto.createHash('sha256').update(generatedToken).digest('hex');
            store.password_resets.push({
              id: nextIdFor('password_resets'),
              admin_id: admin.id,
              token_hash: tokenHash,
              expires_at: new Date(Date.now() + 1800 * 1000).toISOString(),
              used_at: null,
              created_at: new Date().toISOString(),
            });
            logAdminActivity(admin.id, 'password_reset_requested', 'admin', admin.id, null, clientIp(req));
          }
          message =
            'If an active administrator account matches that email, a password recovery token has been issued for delivery.';
          if (generatedToken && process.env.APP_DEBUG !== 'false') {
            message += ` Debug reset link: ${adminUrl('forgot-password.php')}?token=${generatedToken}`;
          }
        }
      } else if (action === 'reset') {
        if (token === '') {
          errors.push('Reset token is missing or invalid.');
        } else {
          const newPw = String(req.body?.new_password ?? '');
          const confirmPw = String(req.body?.confirm_password ?? '');
          if (newPw !== confirmPw) {
            errors.push('নতুন পাসওয়ার্ড ও নিশ্চিত পাসওয়ার্ড মেলেনি।');
          } else {
            const pwErrs = validatePasswordStrength(newPw);
            if (pwErrs.length > 0) {
              errors = pwErrs;
            } else {
              const tokenHash = crypto.createHash('sha256').update(token).digest('hex');
              const nowMs = Date.now();
              const resetRow = store.password_resets.find(
                (pr) => pr.token_hash === tokenHash && !pr.used_at && new Date(pr.expires_at).getTime() > nowMs
              );
              if (!resetRow) {
                errors.push('This password reset link is invalid or has expired.');
              } else {
                const targetAdmin = store.admins.find((a) => a.id === resetRow.admin_id && a.is_active);
                if (!targetAdmin) {
                  errors.push('অ্যাডমিন অ্যাকাউন্টটি আর সক্রিয় নেই।');
                } else {
                  targetAdmin.password_hash = bcrypt.hashSync(newPw, 10);
                  targetAdmin.must_change_password = false;
                  targetAdmin.updated_at = new Date().toISOString();
                  resetRow.used_at = new Date().toISOString();
                  logAdminActivity(targetAdmin.id, 'password_reset_completed', 'admin', targetAdmin.id, null, clientIp(req));
                  setFlash(session, 'success', 'আপনার পাসওয়ার্ড পরিবর্তন করা হয়েছে। অনুগ্রহ করে লগইন করুন।');
                  res.redirect(303, adminUrl('login.php'));
                  return;
                }
              }
            }
          }
        }
      }
    }

    const siteSettings = store.website_settings;
    res.type('html').send(`<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>পাসওয়ার্ড পুনরুদ্ধার — ${e(siteSettings.site_name)}</title>
  <link rel="stylesheet" href="${e(adminUrl('assets/css/admin.css'))}">
</head>
<body class="auth-page">
  <div class="auth-card">
    <div class="auth-brand">${e(siteSettings.site_name)}</div>
    <h1 class="auth-title">${token !== '' ? 'নতুন পাসওয়ার্ড নির্ধারণ করুন' : 'পাসওয়ার্ড পুনরুদ্ধার'}</h1>
    <p class="auth-subtitle">
      ${
        token !== ''
          ? 'Choose a strong password meeting all security requirements.'
          : 'Enter your administrator email address to generate a time-limited recovery token.'
      }
    </p>
    ${message ? `<div class="alert alert-success">${e(message)}</div>` : ''}
    ${errors.length > 0 ? `<div class="alert alert-error">${errors.map((err) => `<div>${e(err)}</div>`).join('')}</div>` : ''}
    ${
      token !== ''
        ? `<form method="post" action="${e(adminUrl('forgot-password.php'))}">
            ${csrfField(session)}
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="token" value="${e(token)}">
            <div class="form-row">
              <label class="form-label" for="new_password">নতুন পাসওয়ার্ড</label>
              <input class="form-control" type="password" id="new_password" name="new_password" required minlength="10">
              <span class="form-hint">Minimum 10 characters with uppercase, lowercase, number, and symbol.</span>
            </div>
            <div class="form-row">
              <label class="form-label" for="confirm_password">পাসওয়ার্ড নিশ্চিত করুন</label>
              <input class="form-control" type="password" id="confirm_password" name="confirm_password" required minlength="10">
            </div>
            <button type="submit" class="btn btn-green" style="width: 100%;">পাসওয়ার্ড রিসেট করুন</button>
          </form>`
        : `<form method="post" action="${e(adminUrl('forgot-password.php'))}">
            ${csrfField(session)}
            <input type="hidden" name="action" value="request">
            <div class="form-row">
              <label class="form-label" for="email">অ্যাডমিনের ইমেইল</label>
              <input class="form-control" type="email" id="email" name="email" required>
            </div>
            <button type="submit" class="btn btn-green" style="width: 100%;">পুনরুদ্ধার টোকেনের অনুরোধ করুন</button>
          </form>`
    }
    <p style="margin-top: 18px; font-size: 0.85rem;">
      <a href="${e(adminUrl('login.php'))}" style="color: var(--adm-green); font-weight: 600;">← লগইন পাতায় ফিরে যান</a>
    </p>
  </div>
</body>
</html>`);
  });

  // --------------------------------------------------------------------------
  // Dashboard & API Stats
  // --------------------------------------------------------------------------
  app.get(`${ADMIN_BASE_PATH}/dashboard.php`, (req: Request, res: Response) => {
    const auth = requireAdminAuth(req, res);
    if (!auth) return;
    const { session, admin } = auth;

    const totalPublishedPosts = store.posts.filter((p) => p.status === 'published').length;
    const totalImages = store.gallery_images.length;
    const totalVideos = store.posts.filter((p) => p.post_type === 'video').length;
    const totalCategories = store.gallery_categories.length + store.post_categories.length;
    const totalBanners = store.hero_banners.length;

    const recentLogs = store.activity_logs
      .filter((l) => isSuperAdmin(admin) || l.admin_id === admin.id)
      .slice(0, 15)
      .map((l) => ({
        ...l,
        username: l.admin_id ? store.admins.find((a) => a.id === l.admin_id)?.username ?? null : null,
      }));

    const allPerms = allAdminPermissions();
    const myLabels = (admin.permissions || []).map((k) => allPerms[k]?.label || k);

    const body = `
<section class="adm-stats-grid" aria-label="কমিউনিটির সারসংক্ষেপ">
  <div class="adm-stat-card"><p class="adm-stat-label">প্রকাশিত পোস্ট</p><p class="adm-stat-val">${totalPublishedPosts}</p></div>
  <div class="adm-stat-card"><p class="adm-stat-label">গ্যালারির ছবি</p><p class="adm-stat-val">${totalImages}</p></div>
  <div class="adm-stat-card"><p class="adm-stat-label">ভিডিও পোস্ট</p><p class="adm-stat-val">${totalVideos}</p></div>
  <div class="adm-stat-card"><p class="adm-stat-label">মোট বিভাগ</p><p class="adm-stat-val">${totalCategories}</p></div>
  <div class="adm-stat-card"><p class="adm-stat-label">প্রধান ব্যানার</p><p class="adm-stat-val">${totalBanners}</p></div>
</section>

<section class="adm-panel">
  <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
    <h2 class="adm-panel-title" style="margin: 0;">দ্রুত কাজ</h2>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
      ${adminHasPermission('manage_posts', admin) ? `<a href="${e(adminUrl('posts.php'))}" class="btn btn-green btn-sm">+ নতুন পোস্ট</a>` : ''}
      ${adminHasPermission('manage_gallery', admin) ? `<a href="${e(adminUrl('gallery.php'))}" class="btn btn-outline btn-sm">+ গ্যালারিতে ছবি আপলোড</a>` : ''}
      ${adminHasPermission('manage_banners', admin) ? `<a href="${e(adminUrl('banners.php'))}" class="btn btn-outline btn-sm">ব্যানার পরিচালনা</a>` : ''}
      ${isSuperAdmin(admin) ? `<a href="${e(adminUrl('admins.php'))}" class="btn btn-outline btn-sm">Manage Admins &amp; Permissions</a>` : ''}
      <a href="${e(adminUrl('security.php'))}" class="btn btn-outline btn-sm">পাসওয়ার্ড পরিবর্তন করুন</a>
    </div>
  </div>
  ${
    !isSuperAdmin(admin)
      ? `<p style="margin: 0 0 8px; color: var(--adm-ink); font-size: 0.9rem;"><strong>আপনার জন্য নির্ধারিত অনুমতি:</strong> ${e(
          myLabels.length > 0 ? myLabels.join(', ') : 'শুধু ড্যাশবোর্ড ও পাসওয়ার্ড'
        )}</p>`
      : ''
  }
  <p style="margin: 0; color: var(--adm-muted); font-size: 0.9rem;">
    Changes saved in the অ্যাডমিন প্যানেল appear immediately on the public site.
  </p>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">সাম্প্রতিক অ্যাডমিন কার্যক্রম</h2>
  ${
    recentLogs.length === 0
      ? '<p style="color: var(--adm-muted); margin: 0;">এখনো কোনো কার্যক্রমের তথ্য নেই।</p>'
      : `<div class="table-wrap"><table class="adm-table">
          <thead><tr><th>সময়</th><th>অ্যাডমিন</th><th>কার্যক্রম</th><th>বিষয়</th><th>আইপি ঠিকানা</th></tr></thead>
          <tbody>
            ${recentLogs
              .map(
                (log) => `<tr>
                <td class="num-tab">${e(formatDate(log.created_at, 'd/m/Y H:i'))}</td>
                <td>${e(log.username || 'সিস্টেম')}</td>
                <td>${e(adminActivityLabel(log.action))}</td>
                <td>${e(adminEntityLabel(log.entity_type || '') + (log.entity_id ? ` #${log.entity_id}` : ''))}</td>
                <td class="num-tab">${e(log.ip_address || '')}</td>
              </tr>`
              )
              .join('')}
          </tbody>
        </table></div>`
  }
</section>`;

    res.type('html').send(renderAdminLayout(session, admin, 'dashboard', 'ড্যাশবোর্ড', body));
  });

  app.get(`${ADMIN_BASE_PATH}/api-stats.php`, (req: Request, res: Response) => {
    sendAdminNocacheHeaders(res);
    const session = getOrCreateSession(req, res);
    const admin = getCurrentAdmin(session);
    if (!admin) {
      res.status(401).json({ error: 'Authentication required.' });
      return;
    }
    const rl = checkRateLimit('admin_api', { ip: clientIp(req), account: `admin:${admin.id}` });
    if (!rl.allowed) {
      applyRateLimitHeaders(res, rl);
      res.json({ error: rl.retry_message });
      return;
    }
    res.json({
      published_posts: store.posts.filter((p) => p.status === 'published').length,
      gallery_images: store.gallery_images.length,
      video_posts: store.posts.filter((p) => p.post_type === 'video').length,
      hero_banners: store.hero_banners.length,
      generated_at: new Date().toISOString(),
    });
  });

  // --------------------------------------------------------------------------
  // Editor Inline Image Upload (JSON)
  // --------------------------------------------------------------------------
  app.all(`${ADMIN_BASE_PATH}/editor-image-upload.php`, maybeMultipart, (req: Request, res: Response) => {
    sendAdminNocacheHeaders(res);
    const session = getOrCreateSession(req, res);
    const admin = getCurrentAdmin(session);
    if (!admin) {
      res.status(401).json({ ok: false, error: 'Authentication required.' });
      return;
    }
    if (!adminHasPermission('manage_posts', admin)) {
      res.status(403).json({ ok: false, error: 'You do not have permission to upload post images.' });
      return;
    }
    if (req.method !== 'POST') {
      res.status(405).setHeader('Allow', 'POST').json({ ok: false, error: 'Method not allowed.' });
      return;
    }
    if (!validateCsrf(req, session)) {
      res.status(403).json({ ok: false, error: 'Invalid CSRF token.' });
      return;
    }

    try {
      const file = getSingleUploadedFile(req, 'image');
      if (!file) throw new Error('No file was selected for upload.');
      const uploaded = storeUploadedFile(file, BUCKET_POST_MEDIA, ALLOWED_IMAGE_MIMES, MAX_IMAGE_UPLOAD_BYTES, 'editor-images');
      logAdminActivity(
        admin.id,
        'post_editor_image_uploaded',
        'storage_object',
        null,
        { bucket: uploaded.bucket, path: uploaded.path, mime: uploaded.mime },
        clientIp(req)
      );
      res.json({
        ok: true,
        url: uploaded.url,
        mime: uploaded.mime,
        name: path.basename(uploaded.path),
      });
    } catch (err) {
      res.status(400).json({
        ok: false,
        error: err instanceof Error ? err.message : 'Image upload failed.',
      });
    }
  });

  // --------------------------------------------------------------------------
  // Posts Manager
  // --------------------------------------------------------------------------
  app.all(`${ADMIN_BASE_PATH}/posts.php`, maybeMultipart, (req: Request, res: Response) => {
    const auth = requireAdminAuth(req, res);
    if (!auth) return;
    const { session, admin } = auth;
    if (!checkAdminAccess(req, res, session, admin, { activePage: 'posts', requiredPermission: 'manage_posts' })) return;

    if (req.method === 'POST') {
      if (!requireValidCsrf(req, res, session)) return;
      const action = String(req.body?.action ?? '');

      try {
        if (action === 'create' || action === 'update') {
          const rl = checkRateLimit('post_submission', { ip: clientIp(req), account: `admin:${admin.id}` });
          if (!rl.allowed) throw new Error(rl.retry_message);

          const postId = Number.parseInt(String(req.body?.post_id ?? '0'), 10) || 0;
          const rawType = String(req.body?.post_type ?? '');
          const postType = (['text', 'image', 'video', 'custom'].includes(rawType) ? rawType : 'text') as PostRecord['post_type'];
          const title = String(req.body?.title ?? '').trim();
          const categoryId = Number.parseInt(String(req.body?.category_id ?? '0'), 10) || null;
          const status = req.body?.status === 'draft' ? 'draft' : 'published';
          const richHtml = sanitizeRichHtml(String(req.body?.content_html ?? ''));
          const customCode = String(req.body?.code_document ?? '');
          const customExcerpt = String(req.body?.custom_excerpt ?? '').trim();

          if (title !== '' && title.length > 220) {
            throw new Error('পোস্টের শিরোনাম সর্বোচ্চ ২২০ অক্ষরের হতে পারবে।');
          }
          if (Buffer.byteLength(richHtml, 'utf8') > 1048576 || Buffer.byteLength(customCode, 'utf8') > 2097152) {
            throw new Error('কনটেন্টের আকার নির্ধারিত সীমা ছাড়িয়েছে।');
          }
          if (postType === 'custom' && customCode.trim() === '') {
            throw new Error('আপনার সম্পূর্ণ HTML, CSS ও JavaScript কোড যোগ করুন।');
          }
          if (postType === 'text' && richHtml.trim() === '') {
            throw new Error('পোস্টের লেখা লিখুন অথবা একটি ছবি যোগ করুন।');
          }

          let description =
            postType === 'custom'
              ? customExcerpt !== ''
                ? customExcerpt.slice(0, 500)
                : title !== ''
                  ? title
                  : 'Custom HTML, CSS and JavaScript community post'
              : richHtmlPlainText(richHtml, 500);
          if (description === '') description = title !== '' ? title : 'Community post';

          const existing = action === 'update' ? store.posts.find((p) => p.id === postId) : null;
          if (action === 'update' && !existing) throw new Error('পোস্টটি পাওয়া যায়নি।');

          let mediaBucket = existing?.media_bucket ?? null;
          let mediaPath = existing?.media_path ?? null;
          let mediaUrl = existing?.media_url ?? null;
          let mediaMime = existing?.media_mime ?? null;
          let oldBucketToRemove: string | null = null;
          let oldPathToRemove: string | null = null;

          const uploadedFile = getSingleUploadedFile(req, 'media_file');

          if (postType === 'text' || postType === 'custom') {
            if (existing?.media_bucket && existing.media_path) {
              oldBucketToRemove = existing.media_bucket;
              oldPathToRemove = existing.media_path;
            }
            mediaBucket = null;
            mediaPath = null;
            mediaUrl = null;
            mediaMime = null;
          } else if (uploadedFile) {
            const allowed = postType === 'video' ? ALLOWED_VIDEO_MIMES : ALLOWED_IMAGE_MIMES;
            const maxSize = postType === 'video' ? MAX_VIDEO_UPLOAD_BYTES : MAX_IMAGE_UPLOAD_BYTES;
            const up = storeUploadedFile(uploadedFile, BUCKET_POST_MEDIA, allowed, maxSize, 'posts');
            if (existing?.media_bucket && existing.media_path) {
              oldBucketToRemove = existing.media_bucket;
              oldPathToRemove = existing.media_path;
            }
            mediaBucket = up.bucket;
            mediaPath = up.path;
            mediaUrl = up.url;
            mediaMime = up.mime;
          } else if (action === 'create' || !existing || existing.post_type !== postType || !existing.media_path) {
            throw new Error('এই ধরনের পোস্টের জন্য একটি ছবি অথবা ভিডিও আপলোড করুন।');
          }

          const now = new Date().toISOString();
          if (action === 'create') {
            const slugBase = slugify(title !== '' ? title : description).slice(0, 220);
            const slug = `${slugBase}-${crypto.randomBytes(3).toString('hex')}`;
            const newPost: PostRecord = {
              id: nextIdFor('posts'),
              category_id: categoryId,
              admin_id: admin.id,
              post_type: postType,
              title: title !== '' ? title : null,
              slug,
              description,
              content_html: postType === 'custom' ? null : richHtml,
              code_document: postType === 'custom' ? customCode : null,
              media_bucket: mediaBucket,
              media_path: mediaPath,
              media_url: mediaUrl,
              media_mime: mediaMime,
              html_content: null,
              css_content: null,
              js_content: null,
              status,
              published_at: status === 'published' ? now : null,
              created_at: now,
              updated_at: now,
            };
            store.posts.unshift(newPost);
            logAdminActivity(admin.id, 'post_created', 'post', newPost.id, null, clientIp(req));
            setFlash(session, 'success', 'পোস্ট তৈরি হয়েছে।');
          } else if (existing) {
            existing.category_id = categoryId;
            existing.post_type = postType;
            existing.title = title !== '' ? title : null;
            existing.description = description;
            existing.content_html = postType === 'custom' ? null : richHtml;
            existing.code_document = postType === 'custom' ? customCode : null;
            existing.media_bucket = mediaBucket;
            existing.media_path = mediaPath;
            existing.media_url = mediaUrl;
            existing.media_mime = mediaMime;
            existing.html_content = null;
            existing.css_content = null;
            existing.js_content = null;
            existing.status = status;
            existing.published_at = status === 'published' ? existing.published_at || now : null;
            existing.updated_at = now;
            logAdminActivity(admin.id, 'post_updated', 'post', existing.id, null, clientIp(req));
            setFlash(session, 'success', 'পোস্ট হালনাগাদ হয়েছে।');
          }

          if (oldBucketToRemove && oldPathToRemove) {
            deleteStoredFile(oldBucketToRemove, oldPathToRemove);
          }
          res.redirect(303, adminUrl('posts.php'));
          return;
        } else if (action === 'toggle_status') {
          const postId = Number.parseInt(String(req.body?.post_id ?? '0'), 10) || 0;
          const newStatus = req.body?.new_status === 'published' ? 'published' : 'draft';
          const target = store.posts.find((p) => p.id === postId);
          if (target) {
            const now = new Date().toISOString();
            target.status = newStatus;
            target.published_at = newStatus === 'published' ? target.published_at || now : null;
            target.updated_at = now;
            logAdminActivity(admin.id, `post_status_${newStatus}`, 'post', postId, null, clientIp(req));
          }
          setFlash(session, 'success', 'পোস্টের দৃশ্যমানতা হালনাগাদ হয়েছে।');
          res.redirect(303, adminUrl('posts.php'));
          return;
        } else if (action === 'delete') {
          const postId = Number.parseInt(String(req.body?.post_id ?? '0'), 10) || 0;
          const idx = store.posts.findIndex((p) => p.id === postId);
          if (idx >= 0) {
            const [removed] = store.posts.splice(idx, 1);
            deleteStoredFile(removed.media_bucket, removed.media_path);
            logAdminActivity(admin.id, 'post_deleted', 'post', postId, null, clientIp(req));
          }
          setFlash(session, 'success', 'পোস্ট মুছে ফেলা হয়েছে।');
          res.redirect(303, adminUrl('posts.php'));
          return;
        }
      } catch (err) {
        setFlash(session, 'error', err instanceof Error ? err.message : 'পোস্ট সংরক্ষণ করা যায়নি।');
        let returnUrl = adminUrl('posts.php');
        if (action === 'create') returnUrl += '?add=1';
        if (action === 'update') returnUrl += `?edit=${Math.max(0, Number.parseInt(String(req.body?.post_id ?? '0'), 10) || 0)}`;
        res.redirect(303, returnUrl);
        return;
      }
    }

    const categories = [...store.post_categories].sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
    const catById = new Map(categories.map((c) => [c.id, c]));
    const posts = [...store.posts].sort((a, b) => b.created_at.localeCompare(a.created_at));
    const editId = Number.parseInt(String(req.query.edit ?? '0'), 10) || 0;
    const editPost = editId > 0 ? store.posts.find((p) => p.id === editId) ?? null : null;
    const showPostForm = req.query.add !== undefined || editPost !== null;

    let initialRichHtml = editPost?.content_html ?? '';
    if (editPost && editPost.post_type !== 'custom' && initialRichHtml.trim() === '') {
      initialRichHtml = `<p>${e(editPost.description).replace(/\r?\n/g, '<br>')}</p>`;
    }

    let legacyCodeDocument = '';
    if (editPost && editPost.post_type === 'custom' && !editPost.code_document) {
      const legacyJs = (editPost.js_content || '').replace(/<\/script/gi, '<\\/script');
      legacyCodeDocument = `<!doctype html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n<meta name="viewport" content="width=device-width, initial-scale=1">\n<style>\n${
        editPost.css_content || ''
      }\n</style>\n</head>\n<body>\n${editPost.html_content || ''}\n<script>\n${legacyJs}\n</script>\n</body>\n</html>`;
    }

    const headerBanner = `
<section class="adm-panel">
  <div class="adm-section-heading">
    <div>
      <h2 class="adm-panel-title" style="margin-bottom:5px;">গ্রামের খবর ও পোস্ট</h2>
      <p class="form-hint" style="margin:0;">আগের পোস্টগুলো পরিচালনা করুন অথবা নতুন পোস্ট তৈরি করুন।</p>
    </div>
    <a class="btn btn-green" href="${e(adminUrl('posts.php'))}?add=1">＋ পোস্ট যোগ করুন</a>
  </div>
</section>`;

    let mainSection = '';
    if (showPostForm) {
      const curType = editPost?.post_type ?? 'text';
      const curMediaPreview = editPost?.media_url
        ? editPost.post_type === 'video'
          ? `<video src="${e(safeUrl(editPost.media_url))}" controls preload="metadata" class="adm-preview-media"></video>`
          : `<img src="${e(safeUrl(editPost.media_url))}" alt="পোস্টের বর্তমান মিডিয়া" class="adm-preview-media">`
        : '';

      mainSection = `
<section class="adm-panel">
  <div class="adm-section-heading">
    <h2 class="adm-panel-title" style="margin-bottom:0;">${editPost ? `পোস্ট সম্পাদনা #${editPost.id}` : 'নতুন পোস্ট যোগ করুন'}</h2>
    <a href="${e(adminUrl('posts.php'))}" class="btn btn-outline">← সব পোস্টে ফিরে যান</a>
  </div>
  <form id="community-post-form" method="post" action="${e(adminUrl('posts.php'))}" enctype="multipart/form-data">
    ${csrfField(session)}
    <input type="hidden" name="action" value="${editPost ? 'update' : 'create'}">
    ${editPost ? `<input type="hidden" name="post_id" value="${editPost.id}">` : ''}

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="post_type_select">পোস্টের ধরন</label>
        <select class="form-control" name="post_type" id="post_type_select">
          <option value="text" ${curType === 'text' ? 'selected' : ''}>সমৃদ্ধ লেখা পোস্ট (WordPress-এর মতো)</option>
          <option value="image" ${curType === 'image' ? 'selected' : ''}>ছবিসহ লেখা পোস্ট</option>
          <option value="video" ${curType === 'video' ? 'selected' : ''}>ভিডিওসহ লেখা পোস্ট</option>
          <option value="custom" ${curType === 'custom' ? 'selected' : ''}>সম্পূর্ণ HTML + CSS + JavaScript ডকুমেন্ট</option>
        </select>
      </div>
      <div class="form-row">
        <label class="form-label" for="category_id">বিভাগ</label>
        <select class="form-control" name="category_id" id="category_id">
          <option value="">বিভাগবিহীন</option>
          ${categories
            .map(
              (c) => `<option value="${c.id}" ${editPost?.category_id === c.id ? 'selected' : ''}>${e(c.name)}</option>`
            )
            .join('')}
        </select>
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="title">পোস্টের শিরোনাম</label>
        <input class="form-control" type="text" id="title" name="title" maxlength="220" value="${e(editPost?.title ?? '')}" placeholder="শিরোনাম লিখুন">
      </div>
      <div class="form-row">
        <label class="form-label" for="status">প্রকাশের অবস্থা</label>
        <select class="form-control" name="status" id="status">
          <option value="published" ${(editPost?.status ?? 'published') === 'published' ? 'selected' : ''}>প্রকাশিত</option>
          <option value="draft" ${editPost?.status === 'draft' ? 'selected' : ''}>খসড়া</option>
        </select>
      </div>
    </div>

    <div class="form-row" id="post_media_group">
      <label class="form-label" for="media_file">প্রধান ছবি অথবা ভিডিও (ছবি/ভিডিও পোস্টের জন্য আবশ্যক)</label>
      <input class="form-control" type="file" id="media_file" name="media_file" data-preview-target="post_media_preview" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm">
      <span class="form-hint">ছবি সর্বোচ্চ ১০ মেগাবাইট এবং ভিডিও ৫০ মেগাবাইট। নিচের এডিটরে একাধিক ছবি যোগ করা যাবে।</span>
      <div class="adm-media-preview" id="post_media_preview">${curMediaPreview}</div>
    </div>

    <div id="post_rich_editor_group" class="form-row">
      <label class="form-label">পোস্টের লেখা — ভিজ্যুয়াল এডিটর</label>
      <div class="rich-editor" data-rich-editor>
        <div class="rich-toolbar" role="toolbar" aria-label="লেখা সাজানোর টুল">
          <button type="button" class="rich-tool" data-editor-command="bold"><strong>B</strong></button>
          <button type="button" class="rich-tool" data-editor-command="italic"><em>I</em></button>
          <button type="button" class="rich-tool" data-editor-command="underline"><u>U</u></button>
          <button type="button" class="rich-tool" data-editor-command="strikeThrough"><s>S</s></button>
          <select class="rich-tool-select" data-editor-block aria-label="লেখার ধরন"><option value="P">অনুচ্ছেদ</option><option value="H2">শিরোনাম ২</option><option value="H3">শিরোনাম ৩</option><option value="H4">শিরোনাম ৪</option><option value="BLOCKQUOTE">উদ্ধৃতি</option></select>
          <select class="rich-tool-select" data-editor-size aria-label="লেখার আকার"><option value="14">14 px</option><option value="16" selected>16 px</option><option value="18">18 px</option><option value="22">22 px</option><option value="28">28 px</option><option value="36">36 px</option></select>
          <label class="rich-color-tool" title="লেখার রং">A <input type="color" data-editor-color value="#202820" aria-label="লেখার রং"></label>
          <button type="button" class="rich-tool" data-editor-command="justifyLeft" title="বামে সাজান">⇤</button>
          <button type="button" class="rich-tool" data-editor-command="justifyCenter" title="মাঝখানে সাজান">↔</button>
          <button type="button" class="rich-tool" data-editor-command="justifyRight" title="ডানে সাজান">⇥</button>
          <button type="button" class="rich-tool" data-editor-command="insertUnorderedList" title="বুলেট তালিকা">• তালিকা</button>
          <button type="button" class="rich-tool" data-editor-command="insertOrderedList" title="ক্রমিক তালিকা">১. তালিকা</button>
          <button type="button" class="rich-tool" data-editor-link>🔗 লিংক</button>
          <button type="button" class="rich-tool" data-editor-image>▧ ছবি</button>
          <button type="button" class="rich-tool" data-editor-command="removeFormat" title="লেখার সাজসজ্জা মুছুন">মুছে ফেলুন</button>
          <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-editor-image-file hidden>
        </div>
        <div id="post_rich_editor" class="rich-editor-surface" contenteditable="true" role="textbox" aria-multiline="true" aria-label="পোস্টের লেখা" dir="auto">${sanitizeRichHtml(
          initialRichHtml
        )}</div>
      </div>
      <textarea id="post_content_html" name="content_html" hidden></textarea>
      <span class="form-hint">বাংলা ও ইংরেজি—দুই ভাষাতেই লিখতে পারবেন। লেখা নির্বাচন করে টুলবার থেকে সাজসজ্জা, লিংক বা ছবি যোগ করুন।</span>
    </div>

    <div class="form-row" id="post_custom_code_group" style="display:none;">
      <label class="form-label" for="post_code_document">সম্পূর্ণ HTML ডকুমেন্ট (HTML + CSS + JavaScript একসঙ্গে)</label>
      <textarea class="form-control code-document-editor" id="post_code_document" name="code_document" rows="24" spellcheck="false">${e(
        editPost?.code_document ?? legacyCodeDocument
      )}</textarea>
      <span class="form-hint">একটি সম্পূর্ণ HTML ডকুমেন্ট পেস্ট করুন। CSS-এর জন্য &lt;style&gt; এবং JavaScript-এর জন্য &lt;script&gt; ব্যবহার করুন।</span>
      <div class="form-row" style="margin-top:12px;">
        <label class="form-label" for="custom_excerpt">সংক্ষিপ্ত পরিচিতি (ঐচ্ছিক)</label>
        <input class="form-control" id="custom_excerpt" name="custom_excerpt" maxlength="500" value="${e(
          editPost?.post_type === 'custom' ? editPost.description : ''
        )}" placeholder="পোস্টের তালিকায় দেখানো সংক্ষিপ্ত বিবরণ">
      </div>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <button type="submit" class="btn btn-green">${editPost ? 'পরিবর্তন সংরক্ষণ করুন' : 'পোস্ট প্রকাশ করুন'}</button>
      <a href="${e(adminUrl('posts.php'))}" class="btn btn-outline">বাতিল</a>
    </div>
  </form>
</section>`;
    } else {
      mainSection = `
<section class="adm-panel">
  <h2 class="adm-panel-title">সব পোস্ট</h2>
  ${
    posts.length === 0
      ? '<p style="color:var(--adm-muted);margin:0;">এখনো কোনো পোস্ট তৈরি করা হয়নি। প্রথম পোস্ট প্রকাশ করতে <strong>পোস্ট যোগ করুন</strong> বাটনে ক্লিক করুন।</p>'
      : `<div class="table-wrap"><table class="adm-table">
          <thead><tr><th>পূর্বরূপ</th><th>শিরোনাম / সংক্ষিপ্ত বিবরণ</th><th>ধরন</th><th>বিভাগ</th><th>অবস্থা</th><th>তারিখ</th><th>করণীয়</th></tr></thead>
          <tbody>
            ${posts
              .map((p) => {
                const catName = p.category_id ? catById.get(p.category_id)?.name ?? '—' : '—';
                const preview =
                  p.post_type === 'image' && p.media_url
                    ? `<img src="${e(safeUrl(p.media_url))}" alt="" class="adm-thumb">`
                    : p.post_type === 'video'
                      ? '<span>ভিডিও</span>'
                      : p.post_type === 'custom'
                        ? '<span>কাস্টম কোড</span>'
                        : '<span>লেখা</span>';
                const typeLabel =
                  p.post_type === 'video' ? 'ভিডিও' : p.post_type === 'custom' ? 'কাস্টম কোড' : p.post_type === 'image' ? 'ছবি' : 'লেখা';
                return `<tr>
                  <td>${preview}</td>
                  <td><strong>${e(p.title || '(শিরোনামহীন)')}</strong><div class="post-admin-excerpt">${e(p.description)}</div></td>
                  <td>${e(typeLabel)}</td>
                  <td>${e(catName)}</td>
                  <td>${e(p.status === 'published' ? 'প্রকাশিত' : 'খসড়া')}</td>
                  <td class="num-tab">${e(formatDate(p.published_at || p.created_at))}</td>
                  <td><div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <a href="${e(adminUrl('posts.php'))}?edit=${p.id}" class="btn btn-outline btn-sm">সম্পাদনা</a>
                    <form method="post" action="${e(adminUrl('posts.php'))}">${csrfField(session)}<input type="hidden" name="action" value="toggle_status"><input type="hidden" name="post_id" value="${p.id}"><input type="hidden" name="new_status" value="${
                      p.status === 'published' ? 'draft' : 'published'
                    }"><button type="submit" class="btn btn-outline btn-sm">${p.status === 'published' ? 'অপ্রকাশ করুন' : 'প্রকাশ করুন'}</button></form>
                    <form method="post" action="${e(adminUrl('posts.php'))}" data-confirm="এই পোস্টটি স্থায়ীভাবে মুছে ফেলবেন? এটি আর ফেরানো যাবে না।">${csrfField(session)}<input type="hidden" name="action" value="delete"><input type="hidden" name="post_id" value="${p.id}"><button type="submit" class="btn btn-danger btn-sm">মুছুন</button></form>
                  </div></td>
                </tr>`;
              })
              .join('')}
          </tbody>
        </table></div>`
  }
</section>`;
    }

    res.type('html').send(renderAdminLayout(session, admin, 'posts', 'গ্রামের খবর ও পোস্ট', headerBanner + mainSection));
  });

  // --------------------------------------------------------------------------
  // Custom Pages Manager
  // --------------------------------------------------------------------------
  app.all(`${ADMIN_BASE_PATH}/pages.php`, (req: Request, res: Response) => {
    const auth = requireAdminAuth(req, res);
    if (!auth) return;
    const { session, admin } = auth;
    if (!checkAdminAccess(req, res, session, admin, { activePage: 'pages', requiredPermission: 'manage_pages' })) return;

    if (req.method === 'POST') {
      if (!requireValidCsrf(req, res, session)) return;
      const action = String(req.body?.action ?? '');

      try {
        if (action === 'save') {
          const pageId = Number.parseInt(String(req.body?.page_id ?? '0'), 10) || 0;
          let pageType: ContentPage['page_type'] = req.body?.page_type === 'about' ? 'about' : 'landing';
          const title = String(req.body?.title ?? '').trim();
          const slug = validateContentSlug(String(req.body?.slug ?? ''));
          const codeDocument = String(req.body?.code_document ?? '');
          const meta = String(req.body?.meta_description ?? '').trim();
          const status = req.body?.status === 'published' ? 'published' : 'draft';

          if (title === '' || title.length > 220) {
            throw new Error('পেজের শিরোনাম দিতে হবে এবং তা ২২০ অক্ষরের মধ্যে হতে হবে।');
          }
          if (codeDocument.trim() === '') throw new Error('সম্পূর্ণ HTML ডকুমেন্টের কোড দিতে হবে।');
          if (Buffer.byteLength(codeDocument, 'utf8') > 2097152) {
            throw new Error('সম্পূর্ণ HTML ডকুমেন্ট ২ মেগাবাইটের মধ্যে হতে হবে।');
          }
          if (meta.length > 300) throw new Error('সার্চ বিবরণ ৩০০ অক্ষরের বেশি হতে পারবে না।');

          const slugDup = store.content_pages.find((p) => p.slug === slug && p.id !== pageId);
          if (slugDup) {
            throw new Error('That লিংক Slug is already in use.');
          }

          const now = new Date().toISOString();
          if (pageId > 0) {
            const existing = store.content_pages.find((p) => p.id === pageId);
            if (!existing) throw new Error('পেজটি পাওয়া যায়নি।');
            if (existing.page_type === 'about') pageType = 'about';
            if (pageType === 'about' && store.content_pages.some((p) => p.page_type === 'about' && p.id !== pageId)) {
              throw new Error('আমাদের পরিচিতি পেজ আগে থেকেই রয়েছে।');
            }
            existing.page_type = pageType;
            existing.title = title;
            existing.slug = slug;
            existing.html_content = '';
            existing.css_content = '';
            existing.js_content = '';
            existing.code_document = codeDocument;
            existing.meta_description = meta !== '' ? meta : null;
            existing.status = status;
            existing.published_at = status === 'published' ? existing.published_at || now : null;
            existing.updated_at = now;
            logAdminActivity(admin.id, 'content_page_updated', 'content_page', pageId, { slug, status }, clientIp(req));
            setFlash(session, 'success', 'পেজ হালনাগাদ হয়েছে।');
          } else {
            if (pageType === 'about' && store.content_pages.some((p) => p.page_type === 'about')) {
              throw new Error('আমাদের পরিচিতি পেজ আগে থেকেই রয়েছে।');
            }
            const newPage: ContentPage = {
              id: nextIdFor('content_pages'),
              page_type: pageType,
              title,
              slug,
              html_content: '',
              css_content: '',
              js_content: '',
              code_document: codeDocument,
              meta_description: meta !== '' ? meta : null,
              status,
              admin_id: admin.id,
              published_at: status === 'published' ? now : null,
              created_at: now,
              updated_at: now,
            };
            store.content_pages.unshift(newPage);
            logAdminActivity(admin.id, 'content_page_created', 'content_page', newPage.id, { slug, type: pageType }, clientIp(req));
            setFlash(session, 'success', 'পেজ তৈরি হয়েছে।');
          }
          res.redirect(303, adminUrl('pages.php'));
          return;
        } else if (action === 'toggle_status') {
          const pageId = Number.parseInt(String(req.body?.page_id ?? '0'), 10) || 0;
          const newStatus = req.body?.new_status === 'published' ? 'published' : 'draft';
          const target = store.content_pages.find((p) => p.id === pageId);
          if (!target) throw new Error('পেজটি পাওয়া যায়নি।');
          const now = new Date().toISOString();
          target.status = newStatus;
          target.published_at = newStatus === 'published' ? target.published_at || now : null;
          target.updated_at = now;
          logAdminActivity(
            admin.id,
            newStatus === 'published' ? 'content_page_published' : 'content_page_unpublished',
            'content_page',
            target.id,
            { slug: target.slug, status: newStatus },
            clientIp(req)
          );
          setFlash(session, 'success', newStatus === 'published' ? 'পেজটি প্রকাশ করা হয়েছে।' : 'পেজটি খসড়ায় (অপ্রকাশিত) নেওয়া হয়েছে।');
          res.redirect(303, adminUrl('pages.php'));
          return;
        } else if (action === 'delete') {
          const pageId = Number.parseInt(String(req.body?.page_id ?? '0'), 10) || 0;
          const idx = store.content_pages.findIndex((p) => p.id === pageId);
          if (idx >= 0) {
            const [removed] = store.content_pages.splice(idx, 1);
            logAdminActivity(admin.id, 'content_page_deleted', 'content_page', removed.id, { slug: removed.slug }, clientIp(req));
          }
          setFlash(session, 'success', 'পেজ মুছে ফেলা হয়েছে।');
          res.redirect(303, adminUrl('pages.php'));
          return;
        }
      } catch (err) {
        setFlash(session, 'error', err instanceof Error ? err.message : 'পেজ সংরক্ষণ করা যায়নি।');
        res.redirect(303, adminUrl('pages.php'));
        return;
      }
    }

    const pages = [...store.content_pages].sort((a, b) => {
      if (a.page_type === 'about' && b.page_type !== 'about') return -1;
      if (b.page_type === 'about' && a.page_type !== 'about') return 1;
      return b.updated_at.localeCompare(a.updated_at);
    });
    const editId = Number.parseInt(String(req.query.edit ?? '0'), 10) || 0;
    const editPage = editId > 0 ? store.content_pages.find((p) => p.id === editId) ?? null : null;
    const hasAbout = pages.some((p) => p.page_type === 'about');

    let editCodeDocument = '';
    if (editPage) {
      editCodeDocument = editPage.code_document || '';
      if (editCodeDocument === '') {
        editCodeDocument = `<!doctype html>\n<html lang='bn'>\n<head>\n<meta charset='utf-8'>\n<meta name='viewport' content='width=device-width, initial-scale=1'>\n<style>\n${
          editPage.css_content || ''
        }\n</style>\n</head>\n<body>\n${editPage.html_content || ''}\n<script>\n${editPage.js_content || ''}\n</script>\n</body>\n</html>`;
      }
    }

    const body = `
<section class="adm-panel">
  <h2 class="adm-panel-title">${editPage ? 'পেজ সম্পাদনা' : 'নতুন পেজ তৈরি করুন'}</h2>
  <p style="color:var(--adm-muted);margin-top:0;">নিজস্ব HTML, CSS ও JavaScript ব্যবহার করে নতুন পেজ তৈরি করুন অথবা আমাদের পরিচিতি পেজ পরিচালনা করুন।</p>
  <form method="post" action="${e(adminUrl('pages.php'))}">
    ${csrfField(session)}
    <input type="hidden" name="action" value="save">
    ${editPage ? `<input type="hidden" name="page_id" value="${editPage.id}">` : ''}

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="page_type">পেজের ধরন</label>
        <select class="form-control" name="page_type" id="page_type" ${editPage?.page_type === 'about' ? 'disabled' : ''}>
          <option value="landing" ${(editPage?.page_type ?? 'landing') === 'landing' ? 'selected' : ''}>নতুন পেজ</option>
          <option value="about" ${editPage?.page_type === 'about' ? 'selected' : ''} ${!editPage && hasAbout ? 'disabled' : ''}>আমাদের পরিচিতি ${
            hasAbout && editPage?.page_type !== 'about' ? '(আগেই তৈরি আছে)' : ''
          }</option>
        </select>
        ${editPage?.page_type === 'about' ? '<input type="hidden" name="page_type" value="about">' : ''}
      </div>
      <div class="form-row">
        <label class="form-label" for="status">অবস্থা</label>
        <select class="form-control" name="status" id="status">
          <option value="draft" ${(editPage?.status ?? 'draft') === 'draft' ? 'selected' : ''}>খসড়া</option>
          <option value="published" ${editPage?.status === 'published' ? 'selected' : ''}>প্রকাশিত</option>
        </select>
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="title">পেজের শিরোনাম</label>
        <input class="form-control" type="text" id="title" name="title" maxlength="220" required value="${e(editPage?.title ?? '')}" placeholder="যেমন: গ্রীষ্মকালীন উৎসব">
      </div>
      <div class="form-row">
        <label class="form-label" for="slug">পাবলিক লিংকের শেষ অংশ</label>
        <input class="form-control" type="text" id="slug" name="slug" maxlength="120" required pattern="[a-z0-9]+(-[a-z0-9]+)*" value="${e(editPage?.slug ?? '')}" placeholder="summer-festival">
        <span class="form-hint">পাবলিক লিংক: ${e(APP_URL)}/আপনার-পেজ — ইংরেজি ছোট অক্ষর, সংখ্যা ও হাইফেন ব্যবহার করুন।</span>
      </div>
    </div>

    <div class="form-row">
      <label class="form-label" for="meta_description">সার্চ ফলাফলের বিবরণ (ঐচ্ছিক)</label>
      <input class="form-control" type="text" id="meta_description" name="meta_description" maxlength="300" value="${e(editPage?.meta_description ?? '')}">
    </div>

    <div class="form-row">
      <label class="form-label" for="code_document">সম্পূর্ণ HTML কোড</label>
      <textarea class="form-control code-document-editor" id="code_document" name="code_document" rows="28" spellcheck="false" required>${e(editCodeDocument)}</textarea>
      <span class="form-hint">HTML, CSS এবং JavaScript একই কোড ঘরে লিখুন। CSS-এর জন্য &lt;style&gt; এবং JavaScript-এর জন্য &lt;script&gt; ট্যাগ ব্যবহার করুন।</span>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <button type="submit" class="btn btn-green">${editPage ? 'পরিবর্তন সংরক্ষণ করুন' : 'পেজ তৈরি করুন'}</button>
      ${editPage ? `<a href="${e(adminUrl('pages.php'))}" class="btn btn-outline">বাতিল</a>` : ''}
    </div>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">আপনার তৈরি পেজ</h2>
  ${
    pages.length === 0
      ? '<p style="color:var(--adm-muted);">No pages have been created yet.</p>'
      : `<div class="table-wrap"><table class="adm-table">
          <thead><tr><th>শিরোনাম</th><th>ধরন</th><th>লিংক</th><th>অবস্থা</th><th>হালনাগাদ</th><th>করণীয়</th></tr></thead>
          <tbody>
            ${pages
              .map(
                (p) => `<tr>
                <td><strong>${e(p.title)}</strong></td>
                <td>${p.page_type === 'about' ? 'আমাদের পরিচিতি' : 'নতুন পেজ'}</td>
                <td><code>/${e(p.slug)}</code></td>
                <td>${e(p.status === 'published' ? 'প্রকাশিত' : 'খসড়া')}</td>
                <td>${e(formatDate(p.updated_at))}</td>
                <td>
                  <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <a class="btn btn-outline btn-sm" href="${e(adminUrl('pages.php'))}?edit=${p.id}">সম্পাদনা</a>
                    <form method="post" action="${e(adminUrl('pages.php'))}">
                      ${csrfField(session)}<input type="hidden" name="action" value="toggle_status"><input type="hidden" name="page_id" value="${p.id}"><input type="hidden" name="new_status" value="${p.status === 'published' ? 'draft' : 'published'}">
                      <button class="btn btn-outline btn-sm" type="submit">${p.status === 'published' ? 'অপ্রকাশ করুন' : 'প্রকাশ করুন'}</button>
                    </form>
                    ${p.status === 'published' ? `<a class="btn btn-outline btn-sm" href="/${e(p.slug)}" target="_blank" rel="noopener noreferrer">দেখুন ↗</a>` : ''}
                    <form method="post" action="${e(adminUrl('pages.php'))}" data-confirm="এই পেজটি স্থায়ীভাবে মুছে ফেলবেন?">
                      ${csrfField(session)}<input type="hidden" name="action" value="delete"><input type="hidden" name="page_id" value="${p.id}">
                      <button class="btn btn-danger btn-sm" type="submit">মুছুন</button>
                    </form>
                  </div>
                </td>
              </tr>`
              )
              .join('')}
          </tbody>
        </table></div>`
  }
</section>`;

    res.type('html').send(renderAdminLayout(session, admin, 'pages', 'পেজ ব্যবস্থাপনা & আমাদের পরিচিতি', body));
  });

  // --------------------------------------------------------------------------
  // Gallery Manager
  // --------------------------------------------------------------------------
  app.all(`${ADMIN_BASE_PATH}/gallery.php`, maybeMultipart, (req: Request, res: Response) => {
    const auth = requireAdminAuth(req, res);
    if (!auth) return;
    const { session, admin } = auth;
    if (!checkAdminAccess(req, res, session, admin, { activePage: 'gallery', requiredPermission: 'manage_gallery' })) return;

    if (req.method === 'POST') {
      if (!requireValidCsrf(req, res, session)) return;
      const action = String(req.body?.action ?? '');

      try {
        if (action === 'upload_multi') {
          const categoryId = Number.parseInt(String(req.body?.category_id ?? '0'), 10) || null;
          const title = String(req.body?.title ?? '').trim();
          const description = String(req.body?.description ?? '').trim();
          const isFeatured = Boolean(req.body?.is_featured);
          const isSlider = Boolean(req.body?.is_slider_item);

          const files = getUploadedFiles(req, 'images').filter((f) => f.size > 0);
          if (files.length === 0) {
            throw new Error('আপলোড করার জন্য অন্তত একটি ছবি নির্বাচন করুন।');
          }

          let uploadedCount = 0;
          const now = new Date().toISOString();
          for (const f of files) {
            const up = storeUploadedFile(f, BUCKET_GALLERY_IMAGES, ALLOWED_IMAGE_MIMES, MAX_IMAGE_UPLOAD_BYTES, 'photos');
            const newImgId = nextIdFor('gallery_images');
            store.gallery_images.unshift({
              id: newImgId,
              category_id: categoryId,
              admin_id: admin.id,
              title: title !== '' ? title : null,
              description: description !== '' ? description : null,
              storage_bucket: up.bucket,
              storage_path: up.path,
              image_url: up.url,
              mime_type: up.mime,
              file_size_bytes: up.size,
              is_featured: isFeatured,
              is_slider_item: isSlider,
              sort_order: 0,
              status: 'published',
              uploaded_at: now,
              updated_at: now,
            });
            logAdminActivity(admin.id, 'gallery_image_uploaded', 'gallery_image', newImgId, null, clientIp(req));
            uploadedCount++;
          }
          setFlash(session, 'success', `গ্যালারিতে ${uploadedCount}টি ছবি আপলোড হয়েছে।`);
          res.redirect(303, adminUrl('gallery.php'));
          return;
        } else if (action === 'update') {
          const imgId = Number.parseInt(String(req.body?.image_id ?? '0'), 10) || 0;
          const existing = store.gallery_images.find((g) => g.id === imgId);
          if (!existing) throw new Error('ছবির তথ্য পাওয়া যায়নি।');

          const categoryId = Number.parseInt(String(req.body?.category_id ?? '0'), 10) || null;
          const title = String(req.body?.title ?? '').trim();
          const description = String(req.body?.description ?? '').trim();
          const sortOrder = Number.parseInt(String(req.body?.sort_order ?? '0'), 10) || 0;
          const isFeatured = Boolean(req.body?.is_featured);
          const isSlider = Boolean(req.body?.is_slider_item);
          const status = req.body?.status === 'hidden' ? 'hidden' : 'published';

          const replaceFile = getSingleUploadedFile(req, 'replace_image');
          if (replaceFile) {
            const up = storeUploadedFile(replaceFile, BUCKET_GALLERY_IMAGES, ALLOWED_IMAGE_MIMES, MAX_IMAGE_UPLOAD_BYTES, 'photos');
            deleteStoredFile(existing.storage_bucket, existing.storage_path);
            existing.storage_bucket = up.bucket;
            existing.storage_path = up.path;
            existing.image_url = up.url;
            existing.mime_type = up.mime;
            existing.file_size_bytes = up.size;
          }

          existing.category_id = categoryId;
          existing.title = title !== '' ? title : null;
          existing.description = description !== '' ? description : null;
          existing.sort_order = sortOrder;
          existing.is_featured = isFeatured;
          existing.is_slider_item = isSlider;
          existing.status = status;
          existing.updated_at = new Date().toISOString();

          logAdminActivity(admin.id, 'gallery_image_updated', 'gallery_image', imgId, null, clientIp(req));
          setFlash(session, 'success', 'গ্যালারির ছবি হালনাগাদ হয়েছে।');
          res.redirect(303, adminUrl('gallery.php'));
          return;
        } else if (action === 'delete') {
          const imgId = Number.parseInt(String(req.body?.image_id ?? '0'), 10) || 0;
          const idx = store.gallery_images.findIndex((g) => g.id === imgId);
          if (idx >= 0) {
            const [removed] = store.gallery_images.splice(idx, 1);
            deleteStoredFile(removed.storage_bucket, removed.storage_path);
            logAdminActivity(admin.id, 'gallery_image_deleted', 'gallery_image', imgId, null, clientIp(req));
          }
          setFlash(session, 'success', 'গ্যালারির ছবি মুছে ফেলা হয়েছে।');
          res.redirect(303, adminUrl('gallery.php'));
          return;
        }
      } catch (err) {
        setFlash(session, 'error', err instanceof Error ? err.message : 'ছবি সংরক্ষণ করা যায়নি।');
        res.redirect(303, adminUrl('gallery.php'));
        return;
      }
    }

    const categories = [...store.gallery_categories].sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
    const catById = new Map(categories.map((c) => [c.id, c]));
    const images = [...store.gallery_images].sort((a, b) => a.sort_order - b.sort_order || b.uploaded_at.localeCompare(a.uploaded_at));
    const editId = Number.parseInt(String(req.query.edit ?? '0'), 10) || 0;
    const editImage = editId > 0 ? store.gallery_images.find((g) => g.id === editId) ?? null : null;

    const formPanel = editImage
      ? `<section class="adm-panel">
          <h2 class="adm-panel-title">ছবি সম্পাদনা #${editImage.id}</h2>
          <form method="post" action="${e(adminUrl('gallery.php'))}" enctype="multipart/form-data">
            ${csrfField(session)}
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="image_id" value="${editImage.id}">
            <div class="adm-grid-2">
              <div class="form-row">
                <label class="form-label" for="edit_title">Title (Optional)</label>
                <input class="form-control" type="text" id="edit_title" name="title" value="${e(editImage.title ?? '')}">
              </div>
              <div class="form-row">
                <label class="form-label" for="edit_cat">Category</label>
                <select class="form-control" name="category_id" id="edit_cat">
                  <option value="">বিভাগবিহীন</option>
                  ${categories.map((c) => `<option value="${c.id}" ${editImage.category_id === c.id ? 'selected' : ''}>${e(c.name)}</option>`).join('')}
                </select>
              </div>
            </div>
            <div class="form-row">
              <label class="form-label" for="edit_desc">Description (Optional)</label>
              <textarea class="form-control" id="edit_desc" name="description">${e(editImage.description ?? '')}</textarea>
            </div>
            <div class="adm-grid-2">
              <div class="form-row">
                <label class="form-label" for="edit_order">ক্রম</label>
                <input class="form-control" type="number" id="edit_order" name="sort_order" value="${editImage.sort_order}">
              </div>
              <div class="form-row">
                <label class="form-label" for="edit_status">দৃশ্যমানতা</label>
                <select class="form-control" name="status" id="edit_status">
                  <option value="published" ${editImage.status === 'published' ? 'selected' : ''}>প্রকাশিত</option>
                  <option value="hidden" ${editImage.status === 'hidden' ? 'selected' : ''}>লুকানো</option>
                </select>
              </div>
            </div>
            <div class="form-row">
              <label class="form-label" for="replace_image">Replace Image File (Optional)</label>
              <input class="form-control" type="file" id="replace_image" name="replace_image" data-preview-target="gallery_edit_preview" accept="image/jpeg,image/png,image/webp,image/gif">
              <div class="adm-media-preview" id="gallery_edit_preview">
                <img src="${e(safeUrl(editImage.image_url))}" alt="Current gallery photo" class="adm-preview-media">
              </div>
            </div>
            <div class="form-row" style="display: flex; gap: 20px; flex-wrap: wrap;">
              <label class="form-check"><input type="checkbox" name="is_featured" value="1" ${editImage.is_featured ? 'checked' : ''}><span>বিশেষ স্মৃতিতে দেখাবে</span></label>
              <label class="form-check"><input type="checkbox" name="is_slider_item" value="1" ${editImage.is_slider_item ? 'checked' : ''}><span>অনুভূমিক স্লাইডারে দেখাবে</span></label>
            </div>
            <div style="display: flex; gap: 10px;">
              <button type="submit" class="btn btn-green">ছবির তথ্য সংরক্ষণ করুন</button>
              <a href="${e(adminUrl('gallery.php'))}" class="btn btn-outline">Cancel</a>
            </div>
          </form>
        </section>`
      : `<section class="adm-panel">
          <h2 class="adm-panel-title">গ্যালারিতে ছবি আপলোড (একাধিক ছবি দেওয়া যাবে)</h2>
          <form method="post" action="${e(adminUrl('gallery.php'))}" enctype="multipart/form-data">
            ${csrfField(session)}
            <input type="hidden" name="action" value="upload_multi">
            <div class="adm-grid-2">
              <div class="form-row">
                <label class="form-label" for="images">Select Image(s)</label>
                <input class="form-control" type="file" id="images" name="images[]" multiple required data-preview-target="gallery_multi_preview" accept="image/jpeg,image/png,image/webp,image/gif">
                <span class="form-hint">You can select multiple photos at once.</span>
                <div class="adm-media-preview" id="gallery_multi_preview"></div>
              </div>
              <div class="form-row">
                <label class="form-label" for="category_id">Category (Optional)</label>
                <select class="form-control" name="category_id" id="category_id">
                  <option value="">সব ছবি / বিভাগবিহীন</option>
                  ${categories.map((c) => `<option value="${c.id}">${e(c.name)}</option>`).join('')}
                </select>
              </div>
            </div>
            <div class="adm-grid-2">
              <div class="form-row">
                <label class="form-label" for="title">Title (Optional — leave blank if untitled)</label>
                <input class="form-control" type="text" id="title" name="title" placeholder="ছবির শিরোনাম (ঐচ্ছিক)">
              </div>
              <div class="form-row">
                <label class="form-label" for="description">Description (Optional)</label>
                <input class="form-control" type="text" id="description" name="description" placeholder="ছবির ক্যাপশন (ঐচ্ছিক)">
              </div>
            </div>
            <div class="form-row" style="display: flex; gap: 20px; flex-wrap: wrap;">
              <label class="form-check"><input type="checkbox" name="is_featured" value="1"><span>মূল পাতায় দেখাবে</span></label>
              <label class="form-check"><input type="checkbox" name="is_slider_item" value="1" checked><span>অনুভূমিক স্লাইডারে রাখুন</span></label>
            </div>
            <button type="submit" class="btn btn-green">গ্যালারিতে আপলোড করুন</button>
          </form>
        </section>`;

    const listPanel = `
<section class="adm-panel">
  <h2 class="adm-panel-title">গ্যালারির ছবি (${images.length})</h2>
  ${
    images.length === 0
      ? '<p style="color: var(--adm-muted); margin: 0;">No gallery images uploaded yet.</p>'
      : `<div class="table-wrap"><table class="adm-table">
          <thead><tr><th>ছবি</th><th>শিরোনাম</th><th>বিভাগ</th><th>ক্রম</th><th>অবস্থান</th><th>অবস্থা</th><th>করণীয়</th></tr></thead>
          <tbody>
            ${images
              .map((img) => {
                const flags: string[] = [];
                if (img.is_featured) flags.push('Featured');
                if (img.is_slider_item) flags.push('Slider');
                const catName = img.category_id ? catById.get(img.category_id)?.name ?? '—' : '—';
                return `<tr>
                  <td><img src="${e(safeUrl(img.image_url))}" alt="" class="adm-thumb"></td>
                  <td>${e(img.title || '(শিরোনামহীন)')}</td>
                  <td>${e(catName)}</td>
                  <td class="num-tab">${img.sort_order}</td>
                  <td>${e(flags.length > 0 ? flags.join(', ') : 'Gallery')}</td>
                  <td>${e(img.status === 'published' ? 'প্রকাশিত' : 'লুকানো')}</td>
                  <td><div style="display: flex; gap: 6px;">
                    <a href="${e(adminUrl('gallery.php'))}?edit=${img.id}" class="btn btn-outline btn-sm">Edit</a>
                    <form method="post" action="${e(adminUrl('gallery.php'))}" data-confirm="গ্যালারি থেকে এই ছবিটি মুছে ফেলবেন?">
                      ${csrfField(session)}<input type="hidden" name="action" value="delete"><input type="hidden" name="image_id" value="${img.id}">
                      <button type="submit" class="btn btn-danger btn-sm">মুছুন</button>
                    </form>
                  </div></td>
                </tr>`;
              })
              .join('')}
          </tbody>
        </table></div>`
  }
</section>`;

    res.type('html').send(renderAdminLayout(session, admin, 'gallery', 'ছবির গ্যালারি', formPanel + listPanel));
  });

  // --------------------------------------------------------------------------
  // Hero Banners Manager
  // --------------------------------------------------------------------------
  app.all(`${ADMIN_BASE_PATH}/banners.php`, maybeMultipart, (req: Request, res: Response) => {
    const auth = requireAdminAuth(req, res);
    if (!auth) return;
    const { session, admin } = auth;
    if (!checkAdminAccess(req, res, session, admin, { activePage: 'banners', requiredPermission: 'manage_banners' })) return;

    if (req.method === 'POST') {
      if (!requireValidCsrf(req, res, session)) return;
      const action = String(req.body?.action ?? '');

      try {
        if (action === 'save_interval') {
          const interval = Math.max(2000, Math.min(20000, Number.parseInt(String(req.body?.hero_interval_ms ?? '5500'), 10) || 5500));
          store.website_settings.hero_interval_ms = interval;
          store.website_settings.updated_at = new Date().toISOString();
          logAdminActivity(admin.id, 'hero_interval_updated', 'website_settings', 1, null, clientIp(req));
          setFlash(session, 'success', 'স্লাইডশোর সময় হালনাগাদ হয়েছে।');
          res.redirect(303, adminUrl('banners.php'));
          return;
        } else if (action === 'create' || action === 'update') {
          const bannerId = Number.parseInt(String(req.body?.banner_id ?? '0'), 10) || 0;
          const title = String(req.body?.title ?? '').trim();
          const subtitle = String(req.body?.subtitle ?? '').trim();
          const buttonText = String(req.body?.button_text ?? '').trim();
          const buttonUrl = String(req.body?.button_url ?? '').trim();
          const sortOrder = Number.parseInt(String(req.body?.sort_order ?? '0'), 10) || 0;
          const isActive = Boolean(req.body?.is_active);
          const file = getSingleUploadedFile(req, 'banner_image');
          const now = new Date().toISOString();

          if (action === 'create') {
            if (!file) throw new Error('ব্যানারের ছবি নির্বাচন করতে হবে।');
            const up = storeUploadedFile(file, BUCKET_HERO_BANNERS, ALLOWED_BANNER_MIMES, MAX_IMAGE_UPLOAD_BYTES, 'banners');
            const newId = nextIdFor('hero_banners');
            store.hero_banners.push({
              id: newId,
              title: title !== '' ? title : null,
              subtitle: subtitle !== '' ? subtitle : null,
              button_text: buttonText !== '' ? buttonText : null,
              button_url: buttonUrl !== '' ? buttonUrl : null,
              storage_bucket: up.bucket,
              storage_path: up.path,
              image_url: up.url,
              sort_order: sortOrder,
              is_active: isActive,
              created_at: now,
              updated_at: now,
            });
            logAdminActivity(admin.id, 'banner_created', 'hero_banner', newId, null, clientIp(req));
            setFlash(session, 'success', 'ব্যানার যোগ করা হয়েছে।');
          } else {
            const existing = store.hero_banners.find((b) => b.id === bannerId);
            if (!existing) throw new Error('ব্যানারটি পাওয়া যায়নি।');
            if (file) {
              const up = storeUploadedFile(file, BUCKET_HERO_BANNERS, ALLOWED_BANNER_MIMES, MAX_IMAGE_UPLOAD_BYTES, 'banners');
              deleteStoredFile(existing.storage_bucket, existing.storage_path);
              existing.storage_bucket = up.bucket;
              existing.storage_path = up.path;
              existing.image_url = up.url;
            }
            existing.title = title !== '' ? title : null;
            existing.subtitle = subtitle !== '' ? subtitle : null;
            existing.button_text = buttonText !== '' ? buttonText : null;
            existing.button_url = buttonUrl !== '' ? buttonUrl : null;
            existing.sort_order = sortOrder;
            existing.is_active = isActive;
            existing.updated_at = now;
            logAdminActivity(admin.id, 'banner_updated', 'hero_banner', bannerId, null, clientIp(req));
            setFlash(session, 'success', 'ব্যানার হালনাগাদ হয়েছে।');
          }
          res.redirect(303, adminUrl('banners.php'));
          return;
        } else if (action === 'delete') {
          const bannerId = Number.parseInt(String(req.body?.banner_id ?? '0'), 10) || 0;
          const idx = store.hero_banners.findIndex((b) => b.id === bannerId);
          if (idx >= 0) {
            const [removed] = store.hero_banners.splice(idx, 1);
            deleteStoredFile(removed.storage_bucket, removed.storage_path);
            logAdminActivity(admin.id, 'banner_deleted', 'hero_banner', bannerId, null, clientIp(req));
          }
          setFlash(session, 'success', 'ব্যানার মুছে ফেলা হয়েছে।');
          res.redirect(303, adminUrl('banners.php'));
          return;
        }
      } catch (err) {
        setFlash(session, 'error', err instanceof Error ? err.message : 'ব্যানার সংরক্ষণ করা যায়নি।');
        res.redirect(303, adminUrl('banners.php'));
        return;
      }
    }

    const banners = [...store.hero_banners].sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
    const editId = Number.parseInt(String(req.query.edit ?? '0'), 10) || 0;
    const editBanner = editId > 0 ? store.hero_banners.find((b) => b.id === editId) ?? null : null;

    const body = `
<section class="adm-panel">
  <h2 class="adm-panel-title">স্লাইডশোর সময় নির্ধারণ</h2>
  <form method="post" action="${e(adminUrl('banners.php'))}" style="display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap;">
    ${csrfField(session)}
    <input type="hidden" name="action" value="save_interval">
    <div>
      <label class="form-label" for="hero_interval_ms">Auto-Slide Interval (milliseconds)</label>
      <input class="form-control" type="number" id="hero_interval_ms" name="hero_interval_ms" min="2000" max="20000" step="500" value="${Number(
        store.website_settings.hero_interval_ms || 5500
      )}">
    </div>
    <button type="submit" class="btn btn-green">সময় সংরক্ষণ করুন</button>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">${editBanner ? `Edit Hero Banner #${editBanner.id}` : 'Add Hero Banner'}</h2>
  <form method="post" action="${e(adminUrl('banners.php'))}" enctype="multipart/form-data">
    ${csrfField(session)}
    <input type="hidden" name="action" value="${editBanner ? 'update' : 'create'}">
    ${editBanner ? `<input type="hidden" name="banner_id" value="${editBanner.id}">` : ''}

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="banner_image">${editBanner ? 'Replace Image (Optional)' : 'Banner Image (Required)'}</label>
        <input class="form-control" type="file" id="banner_image" name="banner_image" data-preview-target="banner_image_preview" ${
          editBanner ? '' : 'required'
        } accept="image/jpeg,image/png,image/webp">
        <span class="form-hint">Recommended landscape 16:9 image (JPG/PNG/WebP).</span>
        <div class="adm-media-preview" id="banner_image_preview">
          ${editBanner?.image_url ? `<img src="${e(safeUrl(editBanner.image_url))}" alt="Current banner" class="adm-preview-media">` : ''}
        </div>
      </div>
      <div class="form-row">
        <label class="form-label" for="sort_order">প্রদর্শনের ক্রম</label>
        <input class="form-control" type="number" id="sort_order" name="sort_order" value="${editBanner?.sort_order ?? 0}">
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="title">Banner Title (Optional)</label>
        <input class="form-control" type="text" id="title" name="title" value="${e(editBanner?.title ?? '')}">
      </div>
      <div class="form-row">
        <label class="form-label" for="subtitle">Banner Subtitle (Optional)</label>
        <input class="form-control" type="text" id="subtitle" name="subtitle" value="${e(editBanner?.subtitle ?? '')}">
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="button_text">Button Text (Optional)</label>
        <input class="form-control" type="text" id="button_text" name="button_text" value="${e(editBanner?.button_text ?? '')}">
      </div>
      <div class="form-row">
        <label class="form-label" for="button_url">বাটনের লিংক (ঐচ্ছিক)</label>
        <input class="form-control" type="text" id="button_url" name="button_url" value="${e(editBanner?.button_url ?? '')}" placeholder="/gallery.php or #posts-section">
      </div>
    </div>

    <div class="form-row">
      <label class="form-check">
        <input type="checkbox" name="is_active" value="1" ${!editBanner || editBanner.is_active ? 'checked' : ''}>
        <span>মূল পাতায় ব্যানারটি দেখাবে</span>
      </label>
    </div>

    <div style="display: flex; gap: 10px;">
      <button type="submit" class="btn btn-green">${editBanner ? 'Save Banner' : 'Add Banner'}</button>
      ${editBanner ? `<a href="${e(adminUrl('banners.php'))}" class="btn btn-outline">Cancel</a>` : ''}
    </div>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">সংরক্ষিত ব্যানার</h2>
  <div class="table-wrap"><table class="adm-table">
    <thead><tr><th>ছবি</th><th>শিরোনাম</th><th>বাটন</th><th>ক্রম</th><th>অবস্থা</th><th>করণীয়</th></tr></thead>
    <tbody>
      ${banners
        .map(
          (b) => `<tr>
          <td><img src="${e(safeUrl(b.image_url))}" alt="" class="adm-thumb"></td>
          <td>${e(b.title || '(শুধু ছবি)')}</td>
          <td>${e(b.button_text || '—')}</td>
          <td class="num-tab">${b.sort_order}</td>
          <td>${b.is_active ? 'চালু' : 'বন্ধ'}</td>
          <td><div style="display: flex; gap: 6px;">
            <a href="${e(adminUrl('banners.php'))}?edit=${b.id}" class="btn btn-outline btn-sm">Edit</a>
            <form method="post" action="${e(adminUrl('banners.php'))}" data-confirm="এই ব্যানারটি মুছে ফেলবেন?">
              ${csrfField(session)}<input type="hidden" name="action" value="delete"><input type="hidden" name="banner_id" value="${b.id}">
              <button type="submit" class="btn btn-danger btn-sm">মুছুন</button>
            </form>
          </div></td>
        </tr>`
        )
        .join('')}
    </tbody>
  </table></div>
</section>`;

    res.type('html').send(renderAdminLayout(session, admin, 'banners', 'প্রধান ব্যানার', body));
  });

  // --------------------------------------------------------------------------
  // Popups Manager
  // --------------------------------------------------------------------------
  app.all(`${ADMIN_BASE_PATH}/popups.php`, maybeMultipart, (req: Request, res: Response) => {
    const auth = requireAdminAuth(req, res);
    if (!auth) return;
    const { session, admin } = auth;
    if (!checkAdminAccess(req, res, session, admin, { activePage: 'popups', requiredPermission: 'manage_popups' })) return;

    if (req.method === 'POST') {
      if (!requireValidCsrf(req, res, session)) return;
      const action = String(req.body?.action ?? '');

      try {
        if (action === 'save') {
          const id = Number.parseInt(String(req.body?.popup_id ?? '0'), 10) || 0;
          const type = req.body?.popup_type === 'image' ? 'image' : 'text';
          const title = String(req.body?.title ?? '').trim();
          const bodyText = String(req.body?.body_text ?? '').trim();
          let imageLink = String(req.body?.image_link_url ?? '').trim();
          const buttonText = String(req.body?.button_text ?? '').trim();
          let buttonUrl = String(req.body?.button_url ?? '').trim();
          const imageTarget = req.body?.image_link_target === '_blank' ? '_blank' : '_self';
          const buttonTarget = req.body?.button_target === '_blank' ? '_blank' : '_self';
          const freqRaw = String(req.body?.frequency ?? 'once_session');
          const frequency = (['every_page', 'once_session', 'once_day'].includes(freqRaw) ? freqRaw : 'once_session') as
            | 'every_page'
            | 'once_session'
            | 'once_day';
          const displayScope = req.body?.display_scope === 'home' ? 'home' : 'all';
          const devRaw = String(req.body?.device_target ?? 'all');
          const deviceTarget = (['all', 'desktop', 'mobile'].includes(devRaw) ? devRaw : 'all') as 'all' | 'desktop' | 'mobile';
          const delay = Math.max(0, Math.min(60, Number.parseInt(String(req.body?.delay_seconds ?? '3'), 10) || 0));
          const priority = Math.max(-1000, Math.min(1000, Number.parseInt(String(req.body?.priority ?? '0'), 10) || 0));
          const starts = parsePopupDhakaLocal(req.body?.starts_at);
          const ends = parsePopupDhakaLocal(req.body?.ends_at);
          const active = Boolean(req.body?.is_active);

          if (title.length > 180) throw new Error('শিরোনাম সর্বোচ্চ ১৮০ অক্ষরের হতে পারবে।');
          if (type === 'text' && bodyText === '') throw new Error('পপআপের লেখা লিখুন।');
          if (bodyText.length > 10000) throw new Error('পপআপের লেখা অনেক বড় (সর্বোচ্চ ১০,০০০ অক্ষর)।');
          if (starts && ends && new Date(ends).getTime() <= new Date(starts).getTime()) {
            throw new Error('শেষের সময় শুরুর সময়ের পরে হতে হবে।');
          }
          if (buttonText !== '' && buttonUrl === '') {
            throw new Error('বাটনের লেখা দিলে তার লিংকও দিন, নতুবা লেখাটি ফাঁকা রাখুন।');
          }
          if (imageLink !== '' && safeUrl(imageLink, '') === '') {
            throw new Error('ছবির জন্য নিরাপদ আপেক্ষিক অথবা http/https লিংক দিন।');
          }
          if (buttonUrl !== '' && safeUrl(buttonUrl, '') === '') {
            throw new Error('বাটনের জন্য নিরাপদ আপেক্ষিক অথবা http/https লিংক দিন।');
          }
          imageLink = imageLink !== '' ? safeUrl(imageLink, '') : '';
          buttonUrl = buttonUrl !== '' ? safeUrl(buttonUrl, '') : '';

          const existing = id > 0 ? store.site_popups.find((p) => p.id === id) ?? null : null;
          if (id > 0 && !existing) throw new Error('পপআপটি পাওয়া যায়নি।');

          let bucket = existing?.image_bucket ?? null;
          let objPath = existing?.image_path ?? null;
          let url = existing?.image_url ?? null;
          let oldImage: [string, string] | null = null;
          const file = getSingleUploadedFile(req, 'popup_image');

          if (type === 'image') {
            if (file) {
              const up = storeUploadedFile(file, BUCKET_WEBSITE_ASSETS, ALLOWED_ASSET_MIMES, MAX_ASSET_UPLOAD_BYTES, 'popups');
              if (existing?.image_bucket && existing.image_path) oldImage = [existing.image_bucket, existing.image_path];
              bucket = up.bucket;
              objPath = up.path;
              url = up.url;
            } else if (!existing || !existing.image_url) {
              throw new Error('এই পপআপের জন্য একটি ছবি নির্বাচন করুন।');
            }
          } else {
            if (existing?.image_bucket && existing.image_path) oldImage = [existing.image_bucket, existing.image_path];
            bucket = null;
            objPath = null;
            url = null;
          }

          const now = new Date().toISOString();
          if (existing) {
            existing.title = title !== '' ? title : null;
            existing.popup_type = type;
            existing.body_text = type === 'text' ? bodyText : null;
            existing.image_bucket = bucket;
            existing.image_path = objPath;
            existing.image_url = url;
            existing.image_link_url = type === 'image' && imageLink !== '' ? imageLink : null;
            existing.image_link_target = imageTarget;
            existing.button_text = buttonText !== '' ? buttonText : null;
            existing.button_url = buttonUrl !== '' ? buttonUrl : null;
            existing.button_target = buttonTarget;
            existing.starts_at = starts;
            existing.ends_at = ends;
            existing.delay_seconds = delay;
            existing.frequency = frequency;
            existing.display_scope = displayScope;
            existing.device_target = deviceTarget;
            existing.priority = priority;
            existing.is_active = active;
            existing.updated_at = now;
            logAdminActivity(admin.id, 'popup_updated', 'site_popup', existing.id, null, clientIp(req));
          } else {
            const newId = nextIdFor('site_popups');
            store.site_popups.unshift({
              id: newId,
              title: title !== '' ? title : null,
              popup_type: type,
              body_text: type === 'text' ? bodyText : null,
              image_bucket: bucket,
              image_path: objPath,
              image_url: url,
              image_link_url: type === 'image' && imageLink !== '' ? imageLink : null,
              image_link_target: imageTarget,
              button_text: buttonText !== '' ? buttonText : null,
              button_url: buttonUrl !== '' ? buttonUrl : null,
              button_target: buttonTarget,
              starts_at: starts,
              ends_at: ends,
              delay_seconds: delay,
              frequency,
              display_scope: displayScope,
              device_target: deviceTarget,
              priority,
              is_active: active,
              admin_id: admin.id,
              created_at: now,
              updated_at: now,
            });
            logAdminActivity(admin.id, 'popup_created', 'site_popup', newId, null, clientIp(req));
          }

          if (oldImage) deleteStoredFile(oldImage[0], oldImage[1]);
          setFlash(session, 'success', existing ? 'পপআপ হালনাগাদ হয়েছে।' : 'পপআপ তৈরি হয়েছে।');
          res.redirect(303, adminUrl('popups.php'));
          return;
        } else if (action === 'delete') {
          const id = Number.parseInt(String(req.body?.popup_id ?? '0'), 10) || 0;
          const idx = store.site_popups.findIndex((p) => p.id === id);
          if (idx >= 0) {
            const [removed] = store.site_popups.splice(idx, 1);
            deleteStoredFile(removed.image_bucket, removed.image_path);
            logAdminActivity(admin.id, 'popup_deleted', 'site_popup', id, null, clientIp(req));
          }
          setFlash(session, 'success', 'পপআপ মুছে ফেলা হয়েছে।');
          res.redirect(303, adminUrl('popups.php'));
          return;
        } else if (action === 'toggle') {
          const id = Number.parseInt(String(req.body?.popup_id ?? '0'), 10) || 0;
          const target = store.site_popups.find((p) => p.id === id);
          if (target) {
            target.is_active = !target.is_active;
            target.updated_at = new Date().toISOString();
            logAdminActivity(admin.id, 'popup_toggled', 'site_popup', id, null, clientIp(req));
          }
          setFlash(session, 'success', 'পপআপের অবস্থা হালনাগাদ হয়েছে।');
          res.redirect(303, adminUrl('popups.php'));
          return;
        }
      } catch (err) {
        setFlash(session, 'error', err instanceof Error ? err.message : 'পপআপ সংরক্ষণ করা যায়নি।');
        res.redirect(303, adminUrl('popups.php'));
        return;
      }
    }

    const popups = [...store.site_popups].sort((a, b) => b.priority - a.priority || b.created_at.localeCompare(a.created_at));
    const editId = Number.parseInt(String(req.query.edit ?? '0'), 10) || 0;
    const edit = editId > 0 ? store.site_popups.find((p) => p.id === editId) ?? null : null;

    const body = `
<section class="adm-panel">
  <h2 class="adm-panel-title">${edit ? `পপআপ সম্পাদনা #${edit.id}` : 'নতুন পপআপ তৈরি করুন'}</h2>
  <p class="form-hint">বাংলাদেশ সময় (এশিয়া/ঢাকা) অনুযায়ী সময় নির্ধারণ করুন। সময় ও প্রদর্শনের শর্ত মিললে পপআপ স্বয়ংক্রিয়ভাবে দেখাবে।</p>
  <form method="post" action="${e(adminUrl('popups.php'))}" enctype="multipart/form-data">
    ${csrfField(session)}
    <input type="hidden" name="action" value="save">
    ${edit ? `<input type="hidden" name="popup_id" value="${edit.id}">` : ''}
    <div class="popup-admin-grid">
      <div class="form-row">
        <label class="form-label" for="popup_type">পপআপ ধরন</label>
        <select class="form-control" id="popup_type" name="popup_type">
          <option value="text" ${(edit?.popup_type ?? 'text') === 'text' ? 'selected' : ''}>লেখার পপআপ</option>
          <option value="image" ${edit?.popup_type === 'image' ? 'selected' : ''}>ছবির পপআপ (ক্লিকযোগ্য)</option>
        </select>
      </div>
      <div class="form-row">
        <label class="form-label" for="popup_title">শিরোনাম (ঐচ্ছিক)</label>
        <input class="form-control" id="popup_title" name="title" maxlength="180" value="${e(edit?.title ?? '')}" placeholder="ঘোষণার শিরোনাম">
      </div>
    </div>
    <div class="form-row" id="popup-text-field">
      <label class="form-label" for="popup_body">পপআপের বার্তা</label>
      <textarea class="form-control" id="popup_body" name="body_text" rows="5" maxlength="10000" placeholder="আপনার ঘোষণা লিখুন...">${e(edit?.body_text ?? '')}</textarea>
    </div>
    <div class="form-row" id="popup-image-field">
      <label class="form-label" for="popup_image">পপআপের ছবি</label>
      <input class="form-control" type="file" id="popup_image" name="popup_image" accept="image/jpeg,image/png,image/webp,image/x-icon" data-preview-target="popup-image-preview">
      <span class="form-hint">JPG, PNG অথবা WebP ছবি ব্যবহার করুন। সর্বোচ্চ ৫ মেগাবাইট।</span>
      <div class="popup-preview" id="popup-image-preview">${
        edit?.image_url ? `<img src="${e(safeUrl(edit.image_url))}" alt="বর্তমান পপআপের ছবি">` : ''
      }</div>
      <div class="form-row">
        <label class="form-label" for="image_link_url">ছবিতে ক্লিক করলে যে লিংকে যাবে (ঐচ্ছিক)</label>
        <input class="form-control" id="image_link_url" name="image_link_url" type="text" value="${e(edit?.image_link_url ?? '')}" placeholder="https://example.com">
      </div>
      <div class="form-row">
        <label class="form-label" for="image_link_target">ছবির লিংক খুলবে</label>
        <select class="form-control" id="image_link_target" name="image_link_target">
          <option value="_self" ${(edit?.image_link_target ?? '_self') === '_self' ? 'selected' : ''}>একই ট্যাবে</option>
          <option value="_blank" ${edit?.image_link_target === '_blank' ? 'selected' : ''}>নতুন ট্যাবে</option>
        </select>
      </div>
    </div>
    <div class="popup-admin-grid">
      <div class="form-row">
        <label class="form-label" for="button_text">বাটনের লেখা (ঐচ্ছিক)</label>
        <input class="form-control" id="button_text" name="button_text" maxlength="80" value="${e(edit?.button_text ?? '')}" placeholder="আরও জানুন">
      </div>
      <div class="form-row">
        <label class="form-label" for="button_url">বাটনের লিংক</label>
        <input class="form-control" id="button_url" name="button_url" value="${e(edit?.button_url ?? '')}" placeholder="/gallery.php অথবা https://...">
      </div>
    </div>
    <div class="popup-admin-grid">
      <div class="form-row">
        <label class="form-label" for="starts_at">শুরুর সময় (বাংলাদেশ)</label>
        <input class="form-control" type="datetime-local" id="starts_at" name="starts_at" value="${e(formatPopupDhakaLocal(edit?.starts_at))}">
      </div>
      <div class="form-row">
        <label class="form-label" for="ends_at">শেষ হওয়ার সময় (বাংলাদেশ)</label>
        <input class="form-control" type="datetime-local" id="ends_at" name="ends_at" value="${e(formatPopupDhakaLocal(edit?.ends_at))}">
      </div>
    </div>
    <div class="popup-admin-grid">
      <div class="form-row">
        <label class="form-label" for="delay_seconds">কত সেকেন্ড পরে দেখাবে</label>
        <input class="form-control" type="number" min="0" max="60" id="delay_seconds" name="delay_seconds" value="${edit?.delay_seconds ?? 3}">
      </div>
      <div class="form-row">
        <label class="form-label" for="frequency">কতবার দেখাবে</label>
        <select class="form-control" id="frequency" name="frequency">
          <option value="once_session" ${(edit?.frequency ?? 'once_session') === 'once_session' ? 'selected' : ''}>প্রতি ব্রাউজার সেশনে একবার</option>
          <option value="once_day" ${edit?.frequency === 'once_day' ? 'selected' : ''}>প্রতিদিন একবার</option>
          <option value="every_page" ${edit?.frequency === 'every_page' ? 'selected' : ''}>প্রতিটি উপযুক্ত পেজ দেখার সময়</option>
        </select>
      </div>
    </div>
    <div class="popup-admin-grid">
      <div class="form-row">
        <label class="form-label" for="display_scope">কোথায় দেখাবে</label>
        <select class="form-control" id="display_scope" name="display_scope">
          <option value="all" ${(edit?.display_scope ?? 'all') === 'all' ? 'selected' : ''}>সব পাবলিক পেজে</option>
          <option value="home" ${edit?.display_scope === 'home' ? 'selected' : ''}>শুধু মূল পাতায়</option>
        </select>
      </div>
      <div class="form-row">
        <label class="form-label" for="device_target">ডিভাইস</label>
        <select class="form-control" id="device_target" name="device_target">
          <option value="all" ${(edit?.device_target ?? 'all') === 'all' ? 'selected' : ''}>সব ডিভাইসে</option>
          <option value="desktop" ${edit?.device_target === 'desktop' ? 'selected' : ''}>শুধু কম্পিউটারে</option>
          <option value="mobile" ${edit?.device_target === 'mobile' ? 'selected' : ''}>শুধু মোবাইলে</option>
        </select>
      </div>
    </div>
    <div class="popup-admin-grid">
      <div class="form-row">
        <label class="form-label" for="priority">অগ্রাধিকার (বেশি হলে আগে দেখাবে)</label>
        <input class="form-control" type="number" min="-1000" max="1000" id="priority" name="priority" value="${edit?.priority ?? 0}">
      </div>
      <div class="form-row">
        <label class="form-check"><input type="checkbox" name="is_active" value="1" ${!edit || edit.is_active ? 'checked' : ''}><span>এই পপআপ চালু করুন</span></label>
      </div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn btn-green" type="submit">${edit ? 'পপআপ সংরক্ষণ করুন' : 'নতুন পপআপ তৈরি করুন'}</button>
      ${edit ? `<a class="btn btn-outline" href="${e(adminUrl('popups.php'))}">সম্পাদনা বাতিল</a>` : ''}
    </div>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">পপআপ ব্যবস্থাপনা</h2>
  <div class="table-wrap"><table class="adm-table">
    <thead><tr><th>পপআপ</th><th>ধরন</th><th>সময়সূচি</th><th>লক্ষ্য</th><th>প্রদর্শনের হার</th><th>অবস্থা</th><th>করণীয়</th></tr></thead>
    <tbody>
      ${
        popups.length === 0
          ? '<tr><td colspan="7">এখনো কোনো পপআপ তৈরি করা হয়নি।</td></tr>'
          : popups
              .map(
                (p) => `<tr>
                <td><strong>${e(p.title || 'শিরোনামহীন পপআপ')}</strong><br><span class="form-hint">অগ্রাধিকার ${p.priority}</span></td>
                <td>${e(p.popup_type === 'image' ? 'ছবি' : 'লেখা')}</td>
                <td>${e(formatPopupDhakaLocal(p.starts_at) || 'সঙ্গে সঙ্গে')}<br><span class="form-hint">${e(
                  formatPopupDhakaLocal(p.ends_at) || 'শেষের সময় নির্ধারিত নয়'
                )}</span></td>
                <td>${e(p.display_scope === 'home' ? 'শুধু মূল পাতা' : 'সব পাবলিক পেজ')} / ${e(
                  p.device_target === 'desktop' ? 'শুধু কম্পিউটার' : p.device_target === 'mobile' ? 'শুধু মোবাইল' : 'সব ডিভাইস'
                )}</td>
                <td>${e(p.frequency === 'once_day' ? 'প্রতিদিন একবার' : p.frequency === 'every_page' ? 'প্রতিটি পেজে' : 'প্রতি সেশনে একবার')}</td>
                <td><span class="popup-status ${p.is_active ? 'is-active' : 'is-off'}">${p.is_active ? 'Active' : 'বন্ধ'}</span></td>
                <td><div style="display:flex;gap:6px;flex-wrap:wrap">
                  <a class="btn btn-outline btn-sm" href="${e(adminUrl('popups.php'))}?edit=${p.id}">সম্পাদনা</a>
                  <form method="post" action="${e(adminUrl('popups.php'))}">${csrfField(session)}<input type="hidden" name="action" value="toggle"><input type="hidden" name="popup_id" value="${p.id}"><button class="btn btn-outline btn-sm" type="submit">${
                    p.is_active ? 'বন্ধ করুন' : 'চালু করুন'
                  }</button></form>
                  <form method="post" action="${e(adminUrl('popups.php'))}" data-confirm="এই পপআপটি স্থায়ীভাবে মুছে ফেলবেন?">${csrfField(session)}<input type="hidden" name="action" value="delete"><input type="hidden" name="popup_id" value="${p.id}"><button class="btn btn-danger btn-sm" type="submit">মুছুন</button></form>
                </div></td>
              </tr>`
              )
              .join('')
      }
    </tbody>
  </table></div>
</section>`;

    res
      .type('html')
      .send(
        renderAdminLayout(
          session,
          admin,
          'popups',
          'পপআপ ব্যবস্থাপনা',
          body,
          `<script src="${e(adminUrl('assets/js/popups.js'))}?v=1" defer></script>`
        )
      );
  });

  // --------------------------------------------------------------------------
  // Categories Manager
  // --------------------------------------------------------------------------
  app.all(`${ADMIN_BASE_PATH}/categories.php`, (req: Request, res: Response) => {
    const auth = requireAdminAuth(req, res);
    if (!auth) return;
    const { session, admin } = auth;
    if (!checkAdminAccess(req, res, session, admin, { activePage: 'categories', requiredPermission: 'manage_categories' })) return;

    if (req.method === 'POST') {
      if (!requireValidCsrf(req, res, session)) return;
      const action = String(req.body?.action ?? '');
      const scope = req.body?.scope === 'post' ? 'post' : 'gallery';
      const table = scope === 'post' ? 'post_categories' : 'gallery_categories';
      const list = scope === 'post' ? store.post_categories : store.gallery_categories;

      try {
        const now = new Date().toISOString();
        if (action === 'create') {
          const name = String(req.body?.name ?? '').trim();
          const sortOrder = Number.parseInt(String(req.body?.sort_order ?? '0'), 10) || 0;
          if (!name) throw new Error('বিভাগের নাম লিখতে হবে।');
          const slug = slugify(name);
          const id = nextIdFor(table);
          if (scope === 'post') {
            store.post_categories.push({ id, name, slug, sort_order: sortOrder, is_active: true, created_at: now, updated_at: now });
          } else {
            store.gallery_categories.push({
              id,
              name,
              slug,
              description: null,
              cover_image_url: null,
              sort_order: sortOrder,
              is_active: true,
              created_at: now,
              updated_at: now,
            });
          }
          logAdminActivity(admin.id, `${scope}_category_created`, table, id, null, clientIp(req));
          setFlash(session, 'success', 'বিভাগ যোগ করা হয়েছে।');
        } else if (action === 'update') {
          const id = Number.parseInt(String(req.body?.id ?? '0'), 10) || 0;
          const name = String(req.body?.name ?? '').trim();
          const sortOrder = Number.parseInt(String(req.body?.sort_order ?? '0'), 10) || 0;
          const isActive = Boolean(req.body?.is_active);
          if (!name) throw new Error('বিভাগের নাম ফাঁকা রাখা যাবে না।');
          const row = list.find((c) => c.id === id);
          if (!row) throw new Error('বিভাগটি পাওয়া যায়নি।');
          row.name = name;
          row.slug = slugify(name);
          row.sort_order = sortOrder;
          row.is_active = isActive;
          row.updated_at = now;
          logAdminActivity(admin.id, `${scope}_category_updated`, table, id, null, clientIp(req));
          setFlash(session, 'success', 'বিভাগ হালনাগাদ হয়েছে।');
        } else if (action === 'delete') {
          const id = Number.parseInt(String(req.body?.id ?? '0'), 10) || 0;
          const idx = list.findIndex((c) => c.id === id);
          if (idx >= 0) {
            list.splice(idx, 1);
            if (scope === 'post') {
              for (const p of store.posts) if (p.category_id === id) p.category_id = null;
            } else {
              for (const g of store.gallery_images) if (g.category_id === id) g.category_id = null;
            }
            logAdminActivity(admin.id, `${scope}_category_deleted`, table, id, null, clientIp(req));
          }
          setFlash(session, 'success', 'বিভাগ মুছে ফেলা হয়েছে।');
        }
      } catch (err) {
        setFlash(session, 'error', err instanceof Error ? err.message : 'বিভাগ সংরক্ষণ করা যায়নি।');
      }
      res.redirect(303, adminUrl('categories.php'));
      return;
    }

    const galleryCats = [...store.gallery_categories].sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
    const postCats = [...store.post_categories].sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);

    const renderCatPanel = (title: string, scope: 'gallery' | 'post', cats: typeof galleryCats | typeof postCats, placeholder: string) => `
<section class="adm-panel">
  <h2 class="adm-panel-title">${title}</h2>
  <form method="post" action="${e(adminUrl('categories.php'))}" style="display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap;">
    ${csrfField(session)}
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="scope" value="${scope}">
    <input class="form-control" type="text" name="name" placeholder="${e(placeholder)}" required style="flex: 1; min-width: 160px;">
    <input class="form-control" type="number" name="sort_order" value="0" style="width: 84px;" title="Sort order">
    <button type="submit" class="btn btn-green">Add</button>
  </form>
  <div class="table-wrap"><table class="adm-table">
    <thead><tr><th>নাম ও ক্রম</th><th>করণীয়</th></tr></thead>
    <tbody>
      ${cats
        .map(
          (c) => `<tr>
          <td>
            <form method="post" action="${e(adminUrl('categories.php'))}" style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
              ${csrfField(session)}
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="scope" value="${scope}">
              <input type="hidden" name="id" value="${c.id}">
              <input class="form-control" type="text" name="name" value="${e(c.name)}" style="max-width: 170px;">
              <input class="form-control" type="number" name="sort_order" value="${c.sort_order}" style="width: 70px;">
              <label class="form-check" style="font-size: 0.8rem;"><input type="checkbox" name="is_active" value="1" ${c.is_active ? 'checked' : ''}><span>চালু</span></label>
              <button type="submit" class="btn btn-outline btn-sm">Save</button>
            </form>
          </td>
          <td>
            <form method="post" action="${e(adminUrl('categories.php'))}" data-confirm="এই বিভাগটি মুছে ফেলবেন?">
              ${csrfField(session)}<input type="hidden" name="action" value="delete"><input type="hidden" name="scope" value="${scope}"><input type="hidden" name="id" value="${c.id}">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>`
        )
        .join('')}
    </tbody>
  </table></div>
</section>`;

    const body = `<div class="adm-grid-2">${renderCatPanel(
      'গ্যালারির বিভাগসমূহ',
      'gallery',
      galleryCats,
      'নতুন গ্যালারি বিভাগের নাম'
    )}${renderCatPanel('পোস্টের বিভাগসমূহ', 'post', postCats, 'নতুন পোস্ট বিভাগের নাম')}</div>`;

    res.type('html').send(renderAdminLayout(session, admin, 'categories', 'বিভাগসমূহ', body));
  });

  // --------------------------------------------------------------------------
  // Header & Navigation Manager
  // --------------------------------------------------------------------------
  app.all(`${ADMIN_BASE_PATH}/header.php`, maybeMultipart, (req: Request, res: Response) => {
    const auth = requireAdminAuth(req, res);
    if (!auth) return;
    const { session, admin } = auth;
    if (!checkAdminAccess(req, res, session, admin, { activePage: 'header', requiredPermission: 'manage_navigation' })) return;

    const headerMenuId = store.navigation_menus.find((m) => m.location === 'header')?.id ?? 1;

    if (req.method === 'POST') {
      if (!requireValidCsrf(req, res, session)) return;
      const action = String(req.body?.action ?? '');

      try {
        if (action === 'save_header_brand') {
          const siteName = String(req.body?.site_name ?? '').trim();
          const bgColor = sanitizeHexColor(req.body?.header_bg_color, '#14532d');
          const textColor = sanitizeHexColor(req.body?.header_text_color, '#ffffff');
          if (!siteName) throw new Error('ওয়েবসাইটের নাম লিখতে হবে।');

          const logoFile = getSingleUploadedFile(req, 'logo_file');
          if (logoFile) {
            const up = storeUploadedFile(logoFile, BUCKET_WEBSITE_ASSETS, ALLOWED_LOGO_MIMES, MAX_ASSET_UPLOAD_BYTES, 'logo');
            deleteStoredFile(BUCKET_WEBSITE_ASSETS, store.website_settings.logo_path);
            store.website_settings.logo_path = up.path;
            store.website_settings.logo_url = up.url;
          }
          store.website_settings.site_name = siteName;
          store.website_settings.header_bg_color = bgColor;
          store.website_settings.header_text_color = textColor;
          store.website_settings.updated_at = new Date().toISOString();
          logAdminActivity(admin.id, 'header_brand_updated', 'website_settings', 1, null, clientIp(req));
          setFlash(session, 'success', 'হেডারের নকশা ও পরিচিতি হালনাগাদ হয়েছে।');
        } else if (action === 'add_nav_item' || action === 'update_nav_item') {
          const itemId = Number.parseInt(String(req.body?.item_id ?? '0'), 10) || 0;
          let parentId = Number.parseInt(String(req.body?.parent_id ?? '0'), 10) || null;
          const label = String(req.body?.label ?? '').trim();
          let url = String(req.body?.url ?? '/').trim();
          if (url === '' || url === '#') url = '/';
          const rawType = String(req.body?.item_type ?? 'link');
          const itemType = (['link', 'dropdown', 'mega'].includes(rawType) ? rawType : 'link') as 'link' | 'dropdown' | 'mega';
          const megaDesc = String(req.body?.mega_description ?? '').trim();
          const newTab = Boolean(req.body?.open_in_new_tab);
          const visible = Boolean(req.body?.is_visible);
          const order = Number.parseInt(String(req.body?.sort_order ?? '0'), 10) || 0;
          if (!label) throw new Error('মেনুর নাম লিখতে হবে।');

          const now = new Date().toISOString();
          if (action === 'add_nav_item') {
            const newId = nextIdFor('navigation_items');
            store.navigation_items.push({
              id: newId,
              menu_id: headerMenuId,
              parent_id: parentId,
              label,
              url,
              item_type: itemType,
              mega_description: megaDesc !== '' ? megaDesc : null,
              open_in_new_tab: newTab,
              sort_order: order,
              is_visible: visible,
              created_at: now,
              updated_at: now,
            });
            logAdminActivity(admin.id, 'nav_item_created', 'navigation_items', newId, null, clientIp(req));
            setFlash(session, 'success', 'মেনুর আইটেম যোগ করা হয়েছে।');
          } else {
            if (parentId === itemId) parentId = null;
            const existing = store.navigation_items.find((ni) => ni.id === itemId && ni.menu_id === headerMenuId);
            if (!existing) throw new Error('মেনু আইটেম পাওয়া যায়নি।');
            existing.parent_id = parentId;
            existing.label = label;
            existing.url = url;
            existing.item_type = itemType;
            existing.mega_description = megaDesc !== '' ? megaDesc : null;
            existing.open_in_new_tab = newTab;
            existing.sort_order = order;
            existing.is_visible = visible;
            existing.updated_at = now;
            logAdminActivity(admin.id, 'nav_item_updated', 'navigation_items', itemId, null, clientIp(req));
            setFlash(session, 'success', 'মেনুর আইটেম হালনাগাদ হয়েছে।');
          }
        } else if (action === 'delete_nav_item') {
          const itemId = Number.parseInt(String(req.body?.item_id ?? '0'), 10) || 0;
          store.navigation_items = store.navigation_items.filter(
            (ni) => !(ni.menu_id === headerMenuId && (ni.id === itemId || ni.parent_id === itemId))
          );
          logAdminActivity(admin.id, 'nav_item_deleted', 'navigation_items', itemId, null, clientIp(req));
          setFlash(session, 'success', 'মেনুর আইটেম মুছে ফেলা হয়েছে।');
        }
      } catch (err) {
        setFlash(session, 'error', err instanceof Error ? err.message : 'হেডার হালনাগাদ করা যায়নি।');
      }
      res.redirect(303, adminUrl('header.php'));
      return;
    }

    const settings = store.website_settings;
    const navItems = store.navigation_items
      .filter((ni) => ni.menu_id === headerMenuId)
      .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
    const editId = Number.parseInt(String(req.query.edit ?? '0'), 10) || 0;
    const editItem = editId > 0 ? navItems.find((ni) => ni.id === editId) ?? null : null;
    const typeLabels: Record<string, string> = { link: 'লিংক', dropdown: 'ড্রপডাউন মেনু', mega: 'মেগা মেনু' };

    const body = `
<section class="adm-panel">
  <h2 class="adm-panel-title">হেডারের পরিচিতি ও রং</h2>
  <form method="post" action="${e(adminUrl('header.php'))}" enctype="multipart/form-data">
    ${csrfField(session)}
    <input type="hidden" name="action" value="save_header_brand">
    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="site_name">ওয়েবসাইটের নাম</label>
        <input class="form-control" type="text" id="site_name" name="site_name" value="${e(settings.site_name)}" required>
      </div>
      <div class="form-row">
        <label class="form-label" for="logo_file">ওয়েবসাইটের লোগো আপলোড / পরিবর্তন</label>
        <input class="form-control" type="file" id="logo_file" name="logo_file" data-preview-target="header_logo_preview" accept="image/jpeg,image/png,image/webp">
        <div class="adm-media-preview" id="header_logo_preview">
          ${
            settings.logo_url
              ? `<img src="${e(safeUrl(settings.logo_url))}" alt="Current header logo" class="adm-preview-media adm-preview-logo">
                 <span class="form-hint">বর্তমান লোগো চালু আছে। পরিবর্তন করতে নতুন ফাইল আপলোড করুন।</span>`
              : ''
          }
        </div>
      </div>
    </div>
    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="header_bg_color">হেডারের পেছনের রং</label>
        <input class="form-control" type="color" id="header_bg_color" name="header_bg_color" value="${e(
          sanitizeHexColor(settings.header_bg_color, '#14532d')
        )}" style="height: 44px; padding: 4px;">
      </div>
      <div class="form-row">
        <label class="form-label" for="header_text_color">হেডারের লেখার রং</label>
        <input class="form-control" type="color" id="header_text_color" name="header_text_color" value="${e(
          sanitizeHexColor(settings.header_text_color, '#ffffff')
        )}" style="height: 44px; padding: 4px;">
      </div>
    </div>
    <button type="submit" class="btn btn-green">হেডারের পরিবর্তন সংরক্ষণ করুন</button>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">${editItem ? 'মেনুর আইটেম সম্পাদনা' : 'নতুন মেনু আইটেম যোগ করুন'}</h2>
  <form method="post" action="${e(adminUrl('header.php'))}">
    ${csrfField(session)}
    <input type="hidden" name="action" value="${editItem ? 'update_nav_item' : 'add_nav_item'}">
    ${editItem ? `<input type="hidden" name="item_id" value="${editItem.id}">` : ''}
    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="label">মেনুর নাম</label>
        <input class="form-control" type="text" id="label" name="label" value="${e(editItem?.label ?? '')}" required>
      </div>
      <div class="form-row">
        <label class="form-label" for="url">লিংক (ওয়েবসাইটের ভেতরের বা বাইরের)</label>
        <input class="form-control" type="text" id="url" name="url" value="${e(editItem?.url ?? '/')}" required placeholder="/gallery.php অথবা https://...">
      </div>
    </div>
    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="item_type">মেনুর ধরন</label>
        <select class="form-control" name="item_type" id="item_type">
          <option value="link" ${(editItem?.item_type ?? 'link') === 'link' ? 'selected' : ''}>সাধারণ লিংক</option>
          <option value="dropdown" ${editItem?.item_type === 'dropdown' ? 'selected' : ''}>ড্রপডাউন মেনু</option>
          <option value="mega" ${editItem?.item_type === 'mega' ? 'selected' : ''}>মেগা মেনু</option>
        </select>
      </div>
      <div class="form-row">
        <label class="form-label" for="parent_id">মূল মেনু (সাব-মেনুর জন্য ঐচ্ছিক)</label>
        <select class="form-control" name="parent_id" id="parent_id">
          <option value="">প্রধান মেনু আইটেম</option>
          ${navItems
            .filter((ni) => ni.parent_id === null && (!editItem || ni.id !== editItem.id))
            .map((ni) => `<option value="${ni.id}" ${editItem?.parent_id === ni.id ? 'selected' : ''}>${e(ni.label)}</option>`)
            .join('')}
        </select>
      </div>
    </div>
    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="mega_description">মেগা মেনু / সাব-মেনুর বিবরণ (ঐচ্ছিক)</label>
        <input class="form-control" type="text" id="mega_description" name="mega_description" value="${e(editItem?.mega_description ?? '')}">
      </div>
      <div class="form-row">
        <label class="form-label" for="sort_order">ক্রম</label>
        <input class="form-control" type="number" id="sort_order" name="sort_order" value="${editItem?.sort_order ?? 0}">
      </div>
    </div>
    <div class="form-row" style="display: flex; gap: 20px; flex-wrap: wrap;">
      <label class="form-check"><input type="checkbox" name="is_visible" value="1" ${!editItem || editItem.is_visible ? 'checked' : ''}><span>হেডারে দেখাবে</span></label>
      <label class="form-check"><input type="checkbox" name="open_in_new_tab" value="1" ${editItem?.open_in_new_tab ? 'checked' : ''}><span>নতুন ট্যাবে খুলবে</span></label>
    </div>
    <div style="display: flex; gap: 10px;">
      <button type="submit" class="btn btn-green">${editItem ? 'মেনুর আইটেম হালনাগাদ করুন' : 'মেনুর আইটেম যোগ করুন'}</button>
      ${editItem ? `<a href="${e(adminUrl('header.php'))}" class="btn btn-outline">বাতিল</a>` : ''}
    </div>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">হেডারের মেনু কাঠামো</h2>
  <div class="table-wrap"><table class="adm-table">
    <thead><tr><th>নাম</th><th>লিংক</th><th>ধরন</th><th>ক্রম</th><th>দৃশ্যমান</th><th>করণীয়</th></tr></thead>
    <tbody>
      ${navItems
        .map(
          (ni) => `<tr>
          <td>${ni.parent_id ? '↳ ' : ''}<strong>${e(ni.label)}</strong></td>
          <td>${e(ni.url)}</td>
          <td>${e(typeLabels[ni.item_type] || ni.item_type)}</td>
          <td class="num-tab">${ni.sort_order}</td>
          <td>${ni.is_visible ? 'দৃশ্যমান' : 'লুকানো'}</td>
          <td><div style="display: flex; gap: 6px;">
            <a href="${e(adminUrl('header.php'))}?edit=${ni.id}" class="btn btn-outline btn-sm">সম্পাদনা</a>
            <form method="post" action="${e(adminUrl('header.php'))}" data-confirm="মেনুর এই আইটেম মুছে ফেলবেন?">
              ${csrfField(session)}<input type="hidden" name="action" value="delete_nav_item"><input type="hidden" name="item_id" value="${ni.id}">
              <button type="submit" class="btn btn-danger btn-sm">মুছুন</button>
            </form>
          </div></td>
        </tr>`
        )
        .join('')}
    </tbody>
  </table></div>
</section>`;

    res.type('html').send(renderAdminLayout(session, admin, 'header', 'হেডার ও নেভিগেশন', body));
  });

  // --------------------------------------------------------------------------
  // Footer Settings Manager
  // --------------------------------------------------------------------------
  app.all(`${ADMIN_BASE_PATH}/footer.php`, maybeMultipart, (req: Request, res: Response) => {
    const auth = requireAdminAuth(req, res);
    if (!auth) return;
    const { session, admin } = auth;
    if (!checkAdminAccess(req, res, session, admin, { activePage: 'footer', requiredPermission: 'manage_navigation' })) return;

    const footerMenuId = store.navigation_menus.find((m) => m.location === 'footer')?.id ?? 2;

    if (req.method === 'POST') {
      if (!requireValidCsrf(req, res, session)) return;
      const action = String(req.body?.action ?? '');

      try {
        if (action === 'save_footer') {
          const logoFile = getSingleUploadedFile(req, 'footer_logo');
          if (logoFile) {
            const up = storeUploadedFile(logoFile, BUCKET_WEBSITE_ASSETS, ALLOWED_LOGO_MIMES, MAX_ASSET_UPLOAD_BYTES, 'footer');
            deleteStoredFile(BUCKET_WEBSITE_ASSETS, store.footer_settings.logo_path);
            store.footer_settings.logo_path = up.path;
            store.footer_settings.logo_url = up.url;
          }
          store.footer_settings.site_name = String(req.body?.site_name ?? 'Purbo Nijmaona Fakir Bari').trim();
          store.footer_settings.description = String(req.body?.description ?? '').trim();
          store.footer_settings.contact_address = String(req.body?.contact_address ?? '').trim();
          store.footer_settings.contact_phone = String(req.body?.contact_phone ?? '').trim();
          store.footer_settings.contact_email = String(req.body?.contact_email ?? '').trim();
          store.footer_settings.facebook_url = String(req.body?.facebook_url ?? '').trim();
          store.footer_settings.instagram_url = String(req.body?.instagram_url ?? '').trim();
          store.footer_settings.youtube_url = String(req.body?.youtube_url ?? '').trim();
          store.footer_settings.x_url = String(req.body?.x_url ?? '').trim();
          store.footer_settings.copyright_text = String(req.body?.copyright_text ?? '').trim();
          store.footer_settings.show_social_links = Boolean(req.body?.show_social_links);
          store.footer_settings.show_contact_info = Boolean(req.body?.show_contact_info);
          store.footer_settings.is_visible = Boolean(req.body?.is_visible);
          store.footer_settings.updated_at = new Date().toISOString();

          store.website_settings.footer_bg_color = sanitizeHexColor(req.body?.footer_bg_color, '#14532d');
          store.website_settings.footer_text_color = sanitizeHexColor(req.body?.footer_text_color, '#ecfdf5');
          store.website_settings.updated_at = new Date().toISOString();

          logAdminActivity(admin.id, 'footer_settings_updated', 'footer_settings', 1, null, clientIp(req));
          setFlash(session, 'success', 'ফুটারের সেটিংস হালনাগাদ হয়েছে।');
        } else if (action === 'add_link' || action === 'update_link') {
          const id = Number.parseInt(String(req.body?.item_id ?? '0'), 10) || 0;
          const label = String(req.body?.label ?? '').trim();
          const url = String(req.body?.url ?? '/').trim();
          const order = Number.parseInt(String(req.body?.sort_order ?? '0'), 10) || 0;
          if (!label) throw new Error('লিংকের নাম লিখতে হবে।');
          const now = new Date().toISOString();

          if (action === 'add_link') {
            const newId = nextIdFor('navigation_items');
            store.navigation_items.push({
              id: newId,
              menu_id: footerMenuId,
              parent_id: null,
              label,
              url,
              item_type: 'link',
              mega_description: null,
              open_in_new_tab: false,
              sort_order: order,
              is_visible: true,
              created_at: now,
              updated_at: now,
            });
            setFlash(session, 'success', 'ফুটারের লিংক যোগ করা হয়েছে।');
          } else {
            const row = store.navigation_items.find((ni) => ni.id === id && ni.menu_id === footerMenuId);
            if (row) {
              row.label = label;
              row.url = url;
              row.sort_order = order;
              row.updated_at = now;
            }
            setFlash(session, 'success', 'ফুটারের লিংক হালনাগাদ হয়েছে।');
          }
          logAdminActivity(admin.id, 'footer_links_updated', 'navigation_items', id || null, null, clientIp(req));
        } else if (action === 'delete_link') {
          const id = Number.parseInt(String(req.body?.item_id ?? '0'), 10) || 0;
          store.navigation_items = store.navigation_items.filter((ni) => !(ni.id === id && ni.menu_id === footerMenuId));
          setFlash(session, 'success', 'ফুটারের লিংক মুছে ফেলা হয়েছে।');
        }
      } catch (err) {
        setFlash(session, 'error', err instanceof Error ? err.message : 'ফুটার সেটিংস সংরক্ষণ করা যায়নি।');
      }
      res.redirect(303, adminUrl('footer.php'));
      return;
    }

    const footer = store.footer_settings;
    const site = store.website_settings;
    const footerLinks = store.navigation_items
      .filter((ni) => ni.menu_id === footerMenuId)
      .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);

    const body = `
<section class="adm-panel">
  <h2 class="adm-panel-title">ফুটারের লেখা, রং ও প্রদর্শন</h2>
  <form method="post" action="${e(adminUrl('footer.php'))}" enctype="multipart/form-data">
    ${csrfField(session)}
    <input type="hidden" name="action" value="save_footer">
    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="site_name">ফুটারে ওয়েবসাইটের নাম</label>
        <input class="form-control" type="text" id="site_name" name="site_name" value="${e(footer.site_name)}" required>
      </div>
      <div class="form-row">
        <label class="form-label" for="footer_logo">Footer Logo (Optional)</label>
        <input class="form-control" type="file" id="footer_logo" name="footer_logo" data-preview-target="footer_logo_preview" accept="image/jpeg,image/png,image/webp">
        <div class="adm-media-preview" id="footer_logo_preview">
          ${footer.logo_url ? `<img src="${e(safeUrl(footer.logo_url))}" alt="Current footer logo" class="adm-preview-media adm-preview-logo">` : ''}
        </div>
      </div>
    </div>
    <div class="form-row">
      <label class="form-label" for="description">ফুটারের সংক্ষিপ্ত বিবরণ</label>
      <textarea class="form-control" id="description" name="description">${e(footer.description ?? '')}</textarea>
    </div>
    <div class="adm-grid-2">
      <div class="form-row"><label class="form-label" for="contact_address">ঠিকানা</label><input class="form-control" type="text" id="contact_address" name="contact_address" value="${e(footer.contact_address ?? '')}"></div>
      <div class="form-row"><label class="form-label" for="contact_phone">যোগাযোগের ফোন</label><input class="form-control" type="text" id="contact_phone" name="contact_phone" value="${e(footer.contact_phone ?? '')}"></div>
    </div>
    <div class="adm-grid-2">
      <div class="form-row"><label class="form-label" for="contact_email">যোগাযোগের ইমেইল</label><input class="form-control" type="email" id="contact_email" name="contact_email" value="${e(footer.contact_email ?? '')}"></div>
      <div class="form-row"><label class="form-label" for="copyright_text">স্বত্ব সংরক্ষণের লেখা</label><input class="form-control" type="text" id="copyright_text" name="copyright_text" value="${e(footer.copyright_text)}" required></div>
    </div>
    <div class="adm-grid-2">
      <div class="form-row"><label class="form-label" for="facebook_url">ফেসবুক লিংক</label><input class="form-control" type="url" id="facebook_url" name="facebook_url" value="${e(footer.facebook_url ?? '')}"></div>
      <div class="form-row"><label class="form-label" for="instagram_url">ইনস্টাগ্রাম লিংক</label><input class="form-control" type="url" id="instagram_url" name="instagram_url" value="${e(footer.instagram_url ?? '')}"></div>
    </div>
    <div class="adm-grid-2">
      <div class="form-row"><label class="form-label" for="youtube_url">ইউটিউব লিংক</label><input class="form-control" type="url" id="youtube_url" name="youtube_url" value="${e(footer.youtube_url ?? '')}"></div>
      <div class="form-row"><label class="form-label" for="x_url">এক্স লিংক</label><input class="form-control" type="url" id="x_url" name="x_url" value="${e(footer.x_url ?? '')}"></div>
    </div>
    <div class="adm-grid-2">
      <div class="form-row"><label class="form-label" for="footer_bg_color">ফুটারের পেছনের রং</label><input class="form-control" type="color" id="footer_bg_color" name="footer_bg_color" value="${e(sanitizeHexColor(site.footer_bg_color, '#14532d'))}" style="height: 44px; padding: 4px;"></div>
      <div class="form-row"><label class="form-label" for="footer_text_color">ফুটারের লেখার রং</label><input class="form-control" type="color" id="footer_text_color" name="footer_text_color" value="${e(sanitizeHexColor(site.footer_text_color, '#ecfdf5'))}" style="height: 44px; padding: 4px;"></div>
    </div>
    <div class="form-row" style="display: flex; gap: 20px; flex-wrap: wrap;">
      <label class="form-check"><input type="checkbox" name="is_visible" value="1" ${footer.is_visible ? 'checked' : ''}><span>ফুটার দেখাবে</span></label>
      <label class="form-check"><input type="checkbox" name="show_contact_info" value="1" ${footer.show_contact_info ? 'checked' : ''}><span>যোগাযোগের তথ্য দেখাবে</span></label>
      <label class="form-check"><input type="checkbox" name="show_social_links" value="1" ${footer.show_social_links ? 'checked' : ''}><span>সামাজিক যোগাযোগের লিংক দেখাবে</span></label>
    </div>
    <button type="submit" class="btn btn-green">Save ফুটার সেটিংস</button>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">ফুটারের দ্রুত লিংক</h2>
  <form method="post" action="${e(adminUrl('footer.php'))}" style="display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap;">
    ${csrfField(session)}
    <input type="hidden" name="action" value="add_link">
    <input class="form-control" type="text" name="label" placeholder="লিংকের নাম" required style="flex: 1; min-width: 150px;">
    <input class="form-control" type="text" name="url" placeholder="/posts.php" required style="flex: 1; min-width: 150px;">
    <input class="form-control" type="number" name="sort_order" value="0" style="width: 84px;">
    <button type="submit" class="btn btn-green">লিংক যোগ করুন</button>
  </form>
  <div class="table-wrap"><table class="adm-table">
    <thead><tr><th>নাম &amp; লিংক</th><th>করণীয়</th></tr></thead>
    <tbody>
      ${footerLinks
        .map(
          (fl) => `<tr>
          <td>
            <form method="post" action="${e(adminUrl('footer.php'))}" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
              ${csrfField(session)}<input type="hidden" name="action" value="update_link"><input type="hidden" name="item_id" value="${fl.id}">
              <input class="form-control" type="text" name="label" value="${e(fl.label)}" style="max-width: 180px;">
              <input class="form-control" type="text" name="url" value="${e(fl.url)}" style="max-width: 220px;">
              <input class="form-control" type="number" name="sort_order" value="${fl.sort_order}" style="width: 74px;">
              <button type="submit" class="btn btn-outline btn-sm">Save</button>
            </form>
          </td>
          <td>
            <form method="post" action="${e(adminUrl('footer.php'))}" data-confirm="ফুটারের এই লিংক মুছে ফেলবেন?">
              ${csrfField(session)}<input type="hidden" name="action" value="delete_link"><input type="hidden" name="item_id" value="${fl.id}">
              <button type="submit" class="btn btn-danger btn-sm">মুছুন</button>
            </form>
          </td>
        </tr>`
        )
        .join('')}
    </tbody>
  </table></div>
</section>`;

    res.type('html').send(renderAdminLayout(session, admin, 'footer', 'ফুটার সেটিংস', body));
  });

  // --------------------------------------------------------------------------
  // Website Settings
  // --------------------------------------------------------------------------
  app.all(`${ADMIN_BASE_PATH}/settings.php`, maybeMultipart, (req: Request, res: Response) => {
    const auth = requireAdminAuth(req, res);
    if (!auth) return;
    const { session, admin } = auth;
    if (!checkAdminAccess(req, res, session, admin, { activePage: 'settings', requiredPermission: 'manage_settings' })) return;

    if (req.method === 'POST') {
      if (!requireValidCsrf(req, res, session)) return;
      try {
        const siteName = String(req.body?.site_name ?? '').trim();
        if (!siteName) throw new Error('ওয়েবসাইটের নাম লিখতে হবে।');

        const logoFile = getSingleUploadedFile(req, 'logo_file');
        if (logoFile) {
          const up = storeUploadedFile(logoFile, BUCKET_WEBSITE_ASSETS, ALLOWED_LOGO_MIMES, MAX_ASSET_UPLOAD_BYTES, 'logo');
          deleteStoredFile(BUCKET_WEBSITE_ASSETS, store.website_settings.logo_path);
          store.website_settings.logo_path = up.path;
          store.website_settings.logo_url = up.url;
        }
        const favFile = getSingleUploadedFile(req, 'favicon_file');
        if (favFile) {
          const up = storeUploadedFile(favFile, BUCKET_WEBSITE_ASSETS, ALLOWED_FAVICON_MIMES, MAX_ASSET_UPLOAD_BYTES, 'favicon');
          deleteStoredFile(BUCKET_WEBSITE_ASSETS, store.website_settings.favicon_path);
          store.website_settings.favicon_path = up.path;
          store.website_settings.favicon_url = up.url;
        }

        store.website_settings.site_name = siteName;
        store.website_settings.site_description = String(req.body?.site_description ?? '').trim();
        store.website_settings.accent_color = sanitizeHexColor(req.body?.accent_color, '#15803d');
        store.website_settings.seo_title = String(req.body?.seo_title ?? '').trim();
        store.website_settings.seo_description = String(req.body?.seo_description ?? '').trim();
        store.website_settings.social_facebook = String(req.body?.social_facebook ?? '').trim();
        store.website_settings.social_instagram = String(req.body?.social_instagram ?? '').trim();
        store.website_settings.social_youtube = String(req.body?.social_youtube ?? '').trim();
        store.website_settings.updated_at = new Date().toISOString();

        logAdminActivity(admin.id, 'website_settings_updated', 'website_settings', 1, null, clientIp(req));
        setFlash(session, 'success', 'ওয়েবসাইটের সেটিংস সংরক্ষণ করা হয়েছে।');
      } catch (err) {
        setFlash(session, 'error', err instanceof Error ? err.message : 'সেটিংস সংরক্ষণ করা যায়নি।');
      }
      res.redirect(303, adminUrl('settings.php'));
      return;
    }

    const s = store.website_settings;
    const body = `
<section class="adm-panel">
  <h2 class="adm-panel-title">General, Branding &amp; SEO Settings</h2>
  <form method="post" action="${e(adminUrl('settings.php'))}" enctype="multipart/form-data">
    ${csrfField(session)}
    <div class="adm-grid-2">
      <div class="form-row"><label class="form-label" for="site_name">ওয়েবসাইটের নাম</label><input class="form-control" type="text" id="site_name" name="site_name" value="${e(s.site_name)}" required></div>
      <div class="form-row"><label class="form-label" for="accent_color">প্রধান সবুজ রং</label><input class="form-control" type="color" id="accent_color" name="accent_color" value="${e(sanitizeHexColor(s.accent_color, '#15803d'))}" style="height: 44px; padding: 4px;"></div>
    </div>
    <div class="form-row"><label class="form-label" for="site_description">ওয়েবসাইটের বিবরণ</label><textarea class="form-control" id="site_description" name="site_description">${e(s.site_description ?? '')}</textarea></div>
    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="logo_file">ওয়েবসাইটের লোগো</label>
        <input class="form-control" type="file" id="logo_file" name="logo_file" data-preview-target="settings_logo_preview" accept="image/jpeg,image/png,image/webp">
        <div class="adm-media-preview" id="settings_logo_preview">${
          s.logo_url ? `<img src="${e(safeUrl(s.logo_url))}" alt="বর্তমান ওয়েবসাইটের লোগো" class="adm-preview-media adm-preview-logo">` : ''
        }</div>
      </div>
      <div class="form-row">
        <label class="form-label" for="favicon_file">Favicon (.ico / .png / .webp)</label>
        <input class="form-control" type="file" id="favicon_file" name="favicon_file" data-preview-target="settings_favicon_preview" accept="image/png,image/x-icon,image/vnd.microsoft.icon,image/webp">
        <div class="adm-media-preview" id="settings_favicon_preview">${
          s.favicon_url ? `<img src="${e(safeUrl(s.favicon_url))}" alt="বর্তমান ফেভিকন" class="adm-preview-media adm-preview-favicon">` : ''
        }</div>
      </div>
    </div>
    <div class="adm-grid-2">
      <div class="form-row"><label class="form-label" for="seo_title">সার্চ ফলাফলের শিরোনাম</label><input class="form-control" type="text" id="seo_title" name="seo_title" value="${e(s.seo_title ?? '')}"></div>
      <div class="form-row"><label class="form-label" for="seo_description">সার্চ ফলাফলের বিবরণ</label><input class="form-control" type="text" id="seo_description" name="seo_description" value="${e(s.seo_description ?? '')}"></div>
    </div>
    <div class="adm-grid-2">
      <div class="form-row"><label class="form-label" for="social_facebook">ফেসবুক পেজের লিংক</label><input class="form-control" type="url" id="social_facebook" name="social_facebook" value="${e(s.social_facebook ?? '')}"></div>
      <div class="form-row"><label class="form-label" for="social_instagram">ইনস্টাগ্রাম প্রোফাইলের লিংক</label><input class="form-control" type="url" id="social_instagram" name="social_instagram" value="${e(s.social_instagram ?? '')}"></div>
    </div>
    <div class="form-row"><label class="form-label" for="social_youtube">ইউটিউব চ্যানেলের লিংক</label><input class="form-control" type="url" id="social_youtube" name="social_youtube" value="${e(s.social_youtube ?? '')}"></div>
    <button type="submit" class="btn btn-green">ওয়েবসাইট সেটিংস সংরক্ষণ করুন</button>
  </form>
</section>`;

    res.type('html').send(renderAdminLayout(session, admin, 'settings', 'ওয়েবসাইট সেটিংস', body));
  });

  // --------------------------------------------------------------------------
  // Multi-Admin Accounts & Permissions (Super Admin only)
  // --------------------------------------------------------------------------
  app.all(`${ADMIN_BASE_PATH}/admins.php`, (req: Request, res: Response) => {
    const auth = requireAdminAuth(req, res);
    if (!auth) return;
    const { session, admin } = auth;
    if (!checkAdminAccess(req, res, session, admin, { activePage: 'admins', requiredSuperAdmin: true })) return;

    const allPermissions = allAdminPermissions();
    const validRoles: AdminRole[] = ['super_admin', 'admin', 'editor'];

    if (req.method === 'POST') {
      if (!requireValidCsrf(req, res, session)) return;
      const action = String(req.body?.action ?? '');

      try {
        if (action === 'create' || action === 'update') {
          const adminId = Number.parseInt(String(req.body?.admin_id ?? '0'), 10) || 0;
          const fullName = String(req.body?.full_name ?? '').trim();
          const username = String(req.body?.username ?? '').trim();
          const email = String(req.body?.email ?? '').trim();
          const rawRole = String(req.body?.role ?? 'admin');
          const role: AdminRole = validRoles.includes(rawRole as AdminRole) ? (rawRole as AdminRole) : 'admin';
          const isActive = Boolean(req.body?.is_active);
          const mustChange = Boolean(req.body?.must_change_password);
          const password = String(req.body?.password ?? '');
          const rawPerms = Array.isArray(req.body?.permissions)
            ? req.body.permissions
            : typeof req.body?.permissions === 'string'
              ? [req.body.permissions]
              : [];
          const permissions = role === 'super_admin' ? Object.keys(allPermissions) : decodeAdminPermissions(rawPerms);

          if (!fullName) throw new Error('পুরো নাম লিখতে হবে।');
          if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new Error('সঠিক ইমেইল ঠিকানা লিখুন।');

          const now = new Date().toISOString();
          if (action === 'create') {
            if (username.length < 3 || !/^[a-zA-Z0-9_.-]+$/.test(username)) {
              throw new Error('ব্যবহারকারীর নাম কমপক্ষে ৩ অক্ষরের হতে হবে। ইংরেজি অক্ষর, সংখ্যা, ডট, আন্ডারস্কোর অথবা হাইফেন ব্যবহার করুন।');
            }
            const pwErrs = validatePasswordStrength(password);
            if (pwErrs.length > 0) throw new Error(pwErrs.join(' '));
            if (role !== 'super_admin' && permissions.length === 0) {
              throw new Error('অন্তত একটি অনুমতি নির্বাচন করুন।');
            }
            if (
              store.admins.some(
                (a) => a.username.toLowerCase() === username.toLowerCase() || a.email.toLowerCase() === email.toLowerCase()
              )
            ) {
              throw new Error('এই ব্যবহারকারীর নাম অথবা ইমেইল দিয়ে একটি অ্যাডমিন অ্যাকাউন্ট আগে থেকেই রয়েছে।');
            }

            const newId = nextIdFor('admins');
            store.admins.push({
              id: newId,
              username,
              email,
              password_hash: bcrypt.hashSync(password, 10),
              full_name: fullName,
              role,
              permissions,
              must_change_password: mustChange,
              is_active: isActive,
              created_by: admin.id,
              last_login_at: null,
              created_at: now,
              updated_at: now,
            });
            logAdminActivity(admin.id, 'admin_account_created', 'admin', newId, { username, role, permissions }, clientIp(req));
            setFlash(session, 'success', `অ্যাডমিন অ্যাকাউন্ট '@${username}' (#${newId}) সফলভাবে তৈরি হয়েছে।`);
            res.redirect(303, adminUrl('admins.php'));
            return;
          } else {
            const existing = store.admins.find((a) => a.id === adminId);
            if (!existing) throw new Error('অ্যাডমিন অ্যাকাউন্ট পাওয়া যায়নি।');
            if (adminId === admin.id) {
              if (role !== 'super_admin') throw new Error('নিজের সুপার অ্যাডমিন ভূমিকা কমাতে পারবেন না।');
              if (!isActive) throw new Error('নিজের সক্রিয় অ্যাকাউন্ট বন্ধ করতে পারবেন না।');
            }
            if (existing.role === 'super_admin' && (role !== 'super_admin' || !isActive)) {
              const otherSupers = store.admins.filter((a) => a.role === 'super_admin' && a.is_active && a.id !== adminId).length;
              if (otherSupers < 1) throw new Error('অন্তত একজন সক্রিয় সুপার অ্যাডমিন থাকতে হবে।');
            }
            if (role !== 'super_admin' && permissions.length === 0) {
              throw new Error('অন্তত একটি অনুমতি নির্বাচন করুন।');
            }
            if (store.admins.some((a) => a.email.toLowerCase() === email.toLowerCase() && a.id !== adminId)) {
              throw new Error('এই ইমেইল ঠিকানাটি অন্য একটি অ্যাডমিন অ্যাকাউন্টে ব্যবহার হচ্ছে।');
            }

            if (password !== '') {
              const pwErrs = validatePasswordStrength(password);
              if (pwErrs.length > 0) throw new Error(pwErrs.join(' '));
              existing.password_hash = bcrypt.hashSync(password, 10);
              existing.must_change_password = mustChange;
            }
            existing.full_name = fullName;
            existing.email = email;
            existing.role = role;
            existing.permissions = permissions;
            existing.is_active = isActive;
            existing.updated_at = now;

            logAdminActivity(
              admin.id,
              'admin_account_updated',
              'admin',
              adminId,
              { role, permissions, password_reset: password !== '' },
              clientIp(req)
            );
            setFlash(session, 'success', `অ্যাডমিন অ্যাকাউন্ট '@${existing.username}' হালনাগাদ হয়েছে।`);
            res.redirect(303, adminUrl('admins.php'));
            return;
          }
        } else if (action === 'toggle_active') {
          const adminId = Number.parseInt(String(req.body?.admin_id ?? '0'), 10) || 0;
          if (adminId === admin.id) throw new Error('নিজের অ্যাকাউন্ট বন্ধ করতে পারবেন না।');
          const target = store.admins.find((a) => a.id === adminId);
          if (!target) throw new Error('অ্যাডমিন অ্যাকাউন্ট পাওয়া যায়নি।');
          const newActive = !target.is_active;
          if (!newActive && target.role === 'super_admin') {
            const otherSupers = store.admins.filter((a) => a.role === 'super_admin' && a.is_active && a.id !== adminId).length;
            if (otherSupers < 1) throw new Error('শেষ সক্রিয় সুপার অ্যাডমিনকে বন্ধ করা যাবে না।');
          }
          target.is_active = newActive;
          target.updated_at = new Date().toISOString();
          logAdminActivity(admin.id, newActive ? 'admin_enabled' : 'admin_disabled', 'admin', adminId, null, clientIp(req));
          setFlash(session, 'success', `অ্যাডমিন '@${target.username}' ${newActive ? 'চালু করা হয়েছে।' : 'বন্ধ করা হয়েছে।'}`);
          res.redirect(303, adminUrl('admins.php'));
          return;
        } else if (action === 'delete') {
          const adminId = Number.parseInt(String(req.body?.admin_id ?? '0'), 10) || 0;
          if (adminId === admin.id) throw new Error('নিজের অ্যাকাউন্ট মুছে ফেলতে পারবেন না।');
          const idx = store.admins.findIndex((a) => a.id === adminId);
          if (idx >= 0) {
            const target = store.admins[idx];
            if (target.role === 'super_admin') {
              const otherSupers = store.admins.filter((a) => a.role === 'super_admin' && a.is_active && a.id !== adminId).length;
              if (otherSupers < 1) throw new Error('শেষ সক্রিয় সুপার অ্যাডমিনকে মুছে ফেলা যাবে না।');
            }
            store.admins.splice(idx, 1);
            logAdminActivity(admin.id, 'admin_account_deleted', 'admin', adminId, { username: target.username }, clientIp(req));
            setFlash(session, 'success', `অ্যাডমিন অ্যাকাউন্ট '@${target.username}' মুছে ফেলা হয়েছে।`);
          }
          res.redirect(303, adminUrl('admins.php'));
          return;
        }
      } catch (err) {
        setFlash(session, 'error', err instanceof Error ? err.message : 'অ্যাডমিন সংরক্ষণ করা যায়নি।');
        const target =
          action === 'update' && req.body?.admin_id
            ? `${adminUrl('admins.php')}?edit=${Number.parseInt(String(req.body.admin_id), 10) || 0}`
            : adminUrl('admins.php');
        res.redirect(303, target);
        return;
      }
    }

    const roleOrder: Record<AdminRole, number> = { super_admin: 0, admin: 1, editor: 2 };
    const admins = [...store.admins].sort((a, b) => roleOrder[a.role] - roleOrder[b.role] || a.id - b.id);
    const editId = Number.parseInt(String(req.query.edit ?? '0'), 10) || 0;
    const editAdmin = editId > 0 ? store.admins.find((a) => a.id === editId) ?? null : null;
    const editPerms = editAdmin
      ? editAdmin.role === 'super_admin'
        ? Object.keys(allPermissions)
        : decodeAdminPermissions(editAdmin.permissions)
      : ['manage_posts', 'manage_gallery'];

    const roleLabels: Record<AdminRole, string> = {
      admin: 'অ্যাডমিন',
      editor: 'সম্পাদক',
      super_admin: 'সুপার অ্যাডমিন',
    };

    const body = `
<section class="adm-panel">
  <h2 class="adm-panel-title">${
    editAdmin ? `অ্যাডমিনের অনুমতি সম্পাদনা — @${e(editAdmin.username)}` : 'নতুন অ্যাডমিন যোগ করুন ও অনুমতি নির্ধারণ করুন'
  }</h2>
  <p style="margin-top: 0; color: var(--adm-muted); font-size: 0.9rem;">
    একাধিক অ্যাডমিন তৈরি করুন এবং প্রত্যেকের জন্য কোন কোন বিভাগ পরিচালনার অনুমতি থাকবে তা নির্ধারণ করুন।
  </p>
  <form method="post" action="${e(adminUrl('admins.php'))}" autocomplete="off">
    ${csrfField(session)}
    <input type="hidden" name="action" value="${editAdmin ? 'update' : 'create'}">
    ${editAdmin ? `<input type="hidden" name="admin_id" value="${editAdmin.id}">` : ''}

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="admin_full_name">পুরো নাম</label>
        <input class="form-control" type="text" id="admin_full_name" name="full_name" value="${e(editAdmin?.full_name ?? '')}" required placeholder="যেমন: রহিম উদ্দিন">
      </div>
      <div class="form-row">
        <label class="form-label" for="admin_username">ব্যবহারকারীর নাম</label>
        <input class="form-control" type="text" id="admin_username" name="username" value="${e(editAdmin?.username ?? '')}" ${
          editAdmin ? 'readonly' : 'required'
        } pattern="[a-zA-Z0-9_.-]{3,}" placeholder="যেমন: rahim_editor">
        ${editAdmin ? '<span class="form-hint">তৈরি করার পর ব্যবহারকারীর নাম পরিবর্তন করা যাবে না।</span>' : ''}
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="admin_email">ইমেইল ঠিকানা</label>
        <input class="form-control" type="email" id="admin_email" name="email" value="${e(editAdmin?.email ?? '')}" required placeholder="admin@example.com">
      </div>
      <div class="form-row">
        <label class="form-label" for="admin_role_select">অ্যাকাউন্টের ভূমিকা</label>
        <select class="form-control" id="admin_role_select" name="role">
          <option value="admin" ${(editAdmin?.role ?? 'admin') === 'admin' ? 'selected' : ''}>অ্যাডমিন (নিচে অনুমতি নির্ধারণ করুন)</option>
          <option value="editor" ${editAdmin?.role === 'editor' ? 'selected' : ''}>সম্পাদক (নিচে অনুমতি নির্ধারণ করুন)</option>
          <option value="super_admin" ${editAdmin?.role === 'super_admin' ? 'selected' : ''}>সুপার অ্যাডমিন (সম্পূর্ণ প্রবেশাধিকার ও অ্যাডমিন পরিচালনা)</option>
        </select>
      </div>
    </div>

    <div class="form-row">
      <label class="form-label" for="admin_password_input">${
        editAdmin ? 'নতুন পাসওয়ার্ড (ঐচ্ছিক — বর্তমান পাসওয়ার্ড রাখতে ফাঁকা রাখুন)' : 'প্রাথমিক পাসওয়ার্ড'
      }</label>
      <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
        <input class="form-control" type="text" id="admin_password_input" name="password" ${
          editAdmin ? '' : 'required'
        } minlength="10" style="flex: 1; min-width: 240px;" placeholder="${
          editAdmin ? 'পাসওয়ার্ড পরিবর্তন না করতে চাইলে ফাঁকা রাখুন' : 'কমপক্ষে ১০ অক্ষর; বড় হাতের, ছোট হাতের অক্ষর, সংখ্যা ও বিশেষ চিহ্ন দিন'
        }">
        <button type="button" class="btn btn-outline" data-generate-password="admin_password_input">এলোমেলো পাসওয়ার্ড তৈরি করুন</button>
      </div>
    </div>

    <div class="form-row" id="admin_permissions_box">
      <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 10px;">
        <label class="form-label" style="margin: 0;">অ্যাডমিন প্যানেলে অনুমোদিত কাজ</label>
        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
          <button type="button" class="btn btn-outline btn-sm" data-perm-preset="posts">শুধু পোস্ট</button>
          <button type="button" class="btn btn-outline btn-sm" data-perm-preset="gallery">শুধু ছবি</button>
          <button type="button" class="btn btn-outline btn-sm" data-perm-preset="both">পোস্ট ও ছবি</button>
          <button type="button" class="btn btn-outline btn-sm" data-perm-preset="all">সব কনটেন্ট</button>
          <button type="button" class="btn btn-outline btn-sm" data-perm-preset="none">মুছে ফেলুন</button>
        </div>
      </div>
      <div class="adm-grid-2" style="gap: 10px;">
        ${Object.entries(allPermissions)
          .map(
            ([permKey, meta]) => `<label class="form-check" style="align-items: flex-start; border: 1px solid var(--adm-border); border-radius: 8px; padding: 10px 12px; background: #fbfdfb;">
            <input type="checkbox" class="adm-perm-checkbox" name="permissions[]" value="${e(permKey)}" ${
              editPerms.includes(permKey) ? 'checked' : ''
            } style="margin-top: 3px;">
            <span>
              <strong style="display: block; font-size: 0.9rem;">${e(meta.label)}</strong>
              <span style="display: block; font-size: 0.78rem; color: var(--adm-muted);">${e(meta.description)}</span>
            </span>
          </label>`
          )
          .join('')}
      </div>
    </div>

    <div class="form-row" style="display: flex; gap: 20px; flex-wrap: wrap;">
      <label class="form-check"><input type="checkbox" name="is_active" value="1" ${!editAdmin || editAdmin.is_active ? 'checked' : ''}><span>অ্যাকাউন্ট চালু (লগইন করতে পারবে)</span></label>
      <label class="form-check"><input type="checkbox" name="must_change_password" value="1" ${!editAdmin || editAdmin.must_change_password ? 'checked' : ''}><span>লগইনের পর পাসওয়ার্ড পরিবর্তন করতে বলবে</span></label>
    </div>

    <div style="display: flex; gap: 10px;">
      <button type="submit" class="btn btn-green">${editAdmin ? 'অ্যাডমিন ও অনুমতি সংরক্ষণ করুন' : 'অ্যাডমিন অ্যাকাউন্ট তৈরি করুন'}</button>
      ${editAdmin ? `<a href="${e(adminUrl('admins.php'))}" class="btn btn-outline">সম্পাদনা বাতিল</a>` : ''}
    </div>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">নিবন্ধিত অ্যাডমিন (${admins.length})</h2>
  <div class="table-wrap"><table class="adm-table">
    <thead><tr><th>অ্যাডমিন</th><th>ভূমিকা</th><th>অনুমোদিত কাজ</th><th>অবস্থা</th><th>সর্বশেষ লগইন</th><th>করণীয়</th></tr></thead>
    <tbody>
      ${admins
        .map((adm) => {
          const isSup = adm.role === 'super_admin';
          const perms = isSup ? Object.keys(allPermissions) : decodeAdminPermissions(adm.permissions);
          const permLabels = perms.map((k) => allPermissions[k]?.label || k);
          return `<tr>
            <td><strong>${e(adm.full_name)}</strong><div style="font-size: 0.8rem; color: var(--adm-muted);">@${e(adm.username)} · ${e(adm.email)}</div></td>
            <td>${e(roleLabels[adm.role] || adm.role)}</td>
            <td style="max-width: 280px; font-size: 0.84rem;">${
              isSup ? 'সম্পূর্ণ প্রবেশাধিকার (সব বিভাগ ও ব্যবহারকারী)' : e(permLabels.length > 0 ? permLabels.join(', ') : 'শুধু ড্যাশবোর্ড ও পাসওয়ার্ড')
            }</td>
            <td>${adm.is_active ? 'চালু' : 'বন্ধ'}${
              adm.must_change_password ? '<div style="font-size: 0.76rem; color: var(--adm-muted);">Temp password</div>' : ''
            }</td>
            <td class="num-tab">${e(adm.last_login_at ? formatDate(adm.last_login_at, 'd/m/Y H:i') : 'কখনো লগইন করেননি')}</td>
            <td><div style="display: flex; gap: 6px; flex-wrap: wrap;">
              <a href="${e(adminUrl('admins.php'))}?edit=${adm.id}" class="btn btn-outline btn-sm">অনুমতি সম্পাদনা</a>
              ${
                adm.id !== admin.id
                  ? `<form method="post" action="${e(adminUrl('admins.php'))}">${csrfField(session)}<input type="hidden" name="action" value="toggle_active"><input type="hidden" name="admin_id" value="${adm.id}"><button type="submit" class="btn btn-outline btn-sm">${
                      adm.is_active ? 'বন্ধ করুন' : 'চালু করুন'
                    }</button></form>
                     <form method="post" action="${e(adminUrl('admins.php'))}" data-confirm="অ্যাডমিন অ্যাকাউন্ট '@${e(adm.username)}' স্থায়ীভাবে মুছে ফেলবেন?">${csrfField(session)}<input type="hidden" name="action" value="delete"><input type="hidden" name="admin_id" value="${adm.id}"><button type="submit" class="btn btn-danger btn-sm">মুছুন</button></form>`
                  : ''
              }
            </div></td>
          </tr>`;
        })
        .join('')}
    </tbody>
  </table></div>
</section>`;

    res.type('html').send(renderAdminLayout(session, admin, 'admins', 'অ্যাডমিন ব্যবহারকারী ও অনুমতি', body));
  });

  // --------------------------------------------------------------------------
  // Rate Limits Manager (Super Admin only)
  // --------------------------------------------------------------------------
  app.all(`${ADMIN_BASE_PATH}/rate-limits.php`, (req: Request, res: Response) => {
    const auth = requireAdminAuth(req, res);
    if (!auth) return;
    const { session, admin } = auth;
    if (!checkAdminAccess(req, res, session, admin, { activePage: 'rate_limits', requiredSuperAdmin: true })) return;

    if (req.method === 'POST') {
      if (!requireValidCsrf(req, res, session)) return;
      const action = String(req.body?.action ?? '');

      try {
        if (action === 'update_rule') {
          const scope = String(req.body?.scope ?? '').trim() as RateLimitScope;
          const maxRequests = Math.max(1, Math.min(100000, Number.parseInt(String(req.body?.max_requests ?? '1'), 10) || 1));
          const windowValue = Math.max(1, Math.min(100000, Number.parseInt(String(req.body?.window_value ?? '1'), 10) || 1));
          const windowUnitRaw = String(req.body?.window_unit ?? 'minutes').trim();
          const windowUnit = VALID_RATE_UNITS.includes(windowUnitRaw as typeof VALID_RATE_UNITS[number])
            ? (windowUnitRaw as typeof VALID_RATE_UNITS[number])
            : 'minutes';
          const isEnabled = Boolean(req.body?.is_enabled);
          const rule = store.rate_limit_rules.find((r) => r.scope === scope);
          if (!rule) throw new Error('অবৈধ অনুরোধ সীমার ক্ষেত্র।');
          if (windowSeconds(windowValue, windowUnit) > 86400 * 30) {
            throw new Error('সময়সীমা ৩০ দিনের বেশি হতে পারবে না।');
          }
          rule.max_requests = maxRequests;
          rule.window_value = windowValue;
          rule.window_unit = windowUnit;
          rule.is_enabled = isEnabled;
          rule.updated_by = admin.id;
          rule.updated_at = new Date().toISOString();

          logAdminActivity(
            admin.id,
            'rate_limit_rule_updated',
            'rate_limit_rules',
            null,
            { scope, max_requests: maxRequests, window_value: windowValue, window_unit: windowUnit, is_enabled: isEnabled },
            clientIp(req)
          );
          setFlash(session, 'success', `'${scope}' ক্ষেত্রের অনুরোধ সীমা হালনাগাদ হয়েছে।`);
        } else if (action === 'reset_scope') {
          const scope = String(req.body?.scope ?? '').trim() as RateLimitScope;
          const cleared = resetRateLimitScope(scope);
          logAdminActivity(admin.id, 'rate_limit_buckets_reset', 'rate_limit_buckets', null, { scope, cleared }, clientIp(req));
          setFlash(session, 'success', `${cleared}টি সক্রিয় অনুরোধ সীমার হিসাব '${scope}' ক্ষেত্র থেকে মুছে ফেলা হয়েছে।`);
        } else if (action === 'purge_expired') {
          const purged = purgeExpiredRateLimits();
          setFlash(session, 'success', `${purged}টি মেয়াদোত্তীর্ণ অনুরোধ সীমার তথ্য মুছে ফেলা হয়েছে।`);
        }
      } catch (err) {
        setFlash(session, 'error', err instanceof Error ? err.message : 'অনুরোধ সীমা হালনাগাদ করা যায়নি।');
      }
      res.redirect(303, adminUrl('rate-limits.php'));
      return;
    }

    const nowMs = Date.now();
    const unitLabels: Record<string, string> = { seconds: 'সেকেন্ড', minutes: 'মিনিট', hours: 'ঘণ্টা', days: 'দিন' };
    const rules = store.rate_limit_rules.map((r) => {
      const activeBuckets = store.rate_limit_buckets.filter((b) => b.scope === r.scope && new Date(b.window_expires_at).getTime() > nowMs);
      const blockedBuckets = activeBuckets.filter((b) => b.request_count >= r.max_requests).length;
      return { ...r, active_buckets: activeBuckets.length, blocked_buckets: blockedBuckets };
    });

    const body = `
<section class="adm-panel">
  <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
    <div>
      <h2 class="adm-panel-title" style="margin-bottom: 4px;">সার্ভারভিত্তিক অনুরোধ সীমা</h2>
      <p style="margin: 0; color: var(--adm-muted); font-size: 0.9rem;">
        IP ঠিকানা ও অ্যাকাউন্ট পরিচয়ের ভিত্তিতে অনুরোধের সীমা কার্যকর হয়। নির্ধারিত সময় শেষ হলে সীমা স্বয়ংক্রিয়ভাবে পুনরায় চালু হয়।
      </p>
    </div>
    <form method="post" action="${e(adminUrl('rate-limits.php'))}">
      ${csrfField(session)}
      <input type="hidden" name="action" value="purge_expired">
      <button type="submit" class="btn btn-outline btn-sm">মেয়াদোত্তীর্ণ তথ্য মুছুন</button>
    </form>
  </div>
</section>

<section class="adm-panel">
  <div class="table-wrap"><table class="adm-table">
    <thead><tr><th>এন্ডপয়েন্ট / ক্ষেত্র</th><th>সর্বোচ্চ অনুরোধ</th><th>সময়সীমা</th><th>সময়ের একক</th><th>অবস্থা</th><th>সক্রিয় / অবরুদ্ধ</th><th>করণীয়</th></tr></thead>
    <tbody>
      ${rules
        .map(
          (rule) => `<tr>
          <td style="min-width: 220px;"><strong>${e(rule.label)}</strong><div style="font-size: 0.78rem; color: var(--adm-muted);">${e(
            rule.description ?? ''
          )}</div></td>
          <td>
            <form method="post" action="${e(adminUrl('rate-limits.php'))}" id="rl-form-${e(rule.scope)}">
              ${csrfField(session)}<input type="hidden" name="action" value="update_rule"><input type="hidden" name="scope" value="${e(rule.scope)}">
            </form>
            <input class="form-control num-tab" type="number" name="max_requests" form="rl-form-${e(rule.scope)}" min="1" max="100000" value="${rule.max_requests}" style="width: 96px;">
          </td>
          <td><input class="form-control num-tab" type="number" name="window_value" form="rl-form-${e(rule.scope)}" min="1" max="100000" value="${rule.window_value}" style="width: 90px;"></td>
          <td>
            <select class="form-control" name="window_unit" form="rl-form-${e(rule.scope)}" style="width: 120px;">
              ${VALID_RATE_UNITS.map((u) => `<option value="${e(u)}" ${rule.window_unit === u ? 'selected' : ''}>${e(unitLabels[u] || u)}</option>`).join('')}
            </select>
          </td>
          <td><label class="form-check"><input type="checkbox" name="is_enabled" value="1" form="rl-form-${e(rule.scope)}" ${
            rule.is_enabled ? 'checked' : ''
          }><span>চালু</span></label></td>
          <td class="num-tab">
            ${rule.active_buckets} সক্রিয়
            ${rule.blocked_buckets > 0 ? `<div style="font-size: 0.78rem; color: var(--adm-danger); font-weight: 600;">${rule.blocked_buckets} অবরুদ্ধ</div>` : ''}
          </td>
          <td>
            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
              <button type="submit" form="rl-form-${e(rule.scope)}" class="btn btn-green btn-sm">সংরক্ষণ</button>
              <form method="post" action="${e(adminUrl('rate-limits.php'))}" data-confirm="সব সক্রিয় অনুরোধ সীমার হিসাব পুনরায় সেট করুন: ${e(
            rule.label
          )}?">
                ${csrfField(session)}<input type="hidden" name="action" value="reset_scope"><input type="hidden" name="scope" value="${e(rule.scope)}">
                <button type="submit" class="btn btn-outline btn-sm">পুনরায় সেট</button>
              </form>
            </div>
          </td>
        </tr>`
        )
        .join('')}
    </tbody>
  </table></div>
</section>`;

    res.type('html').send(renderAdminLayout(session, admin, 'rate_limits', 'অনুরোধ সীমা সেটিংস', body));
  });

  // --------------------------------------------------------------------------
  // Security & Password Change
  // --------------------------------------------------------------------------
  app.all(`${ADMIN_BASE_PATH}/security.php`, (req: Request, res: Response) => {
    const auth = requireAdminAuth(req, res);
    if (!auth) return;
    let { session, admin } = auth;
    if (!checkAdminAccess(req, res, session, admin, { activePage: 'security' })) return;

    let errors: string[] = [];
    if (req.method === 'POST') {
      if (!requireValidCsrf(req, res, session)) return;
      const action = String(req.body?.action ?? '');
      if (action === 'change_password') {
        const currentPw = String(req.body?.current_password ?? '');
        const newPw = String(req.body?.new_password ?? '');
        const confirmPw = String(req.body?.confirm_password ?? '');
        const logoutAfter = Boolean(req.body?.logout_after_change);

        if (!bcrypt.compareSync(currentPw, admin.password_hash)) {
          errors.push('বর্তমান পাসওয়ার্ড সঠিক নয়।');
        } else if (newPw !== confirmPw) {
          errors.push('নতুন পাসওয়ার্ড ও নিশ্চিত পাসওয়ার্ড মেলেনি।');
        } else {
          const pwErrs = validatePasswordStrength(newPw);
          if (pwErrs.length > 0) {
            errors = pwErrs;
          } else {
            admin.password_hash = bcrypt.hashSync(newPw, 10);
            admin.must_change_password = false;
            admin.updated_at = new Date().toISOString();
            logAdminActivity(admin.id, 'password_changed', 'admin', admin.id, null, clientIp(req));
            if (logoutAfter) {
              session.admin_id = null;
              session = regenerateSession(req, res, session);
              setFlash(session, 'success', 'পাসওয়ার্ড নিরাপদভাবে পরিবর্তন হয়েছে। নতুন পাসওয়ার্ড দিয়ে লগইন করুন।');
              res.redirect(303, adminUrl('login.php'));
              return;
            }
            session = regenerateSession(req, res, session);
            setFlash(session, 'success', 'অ্যাডমিনের পাসওয়ার্ড সফলভাবে পরিবর্তন হয়েছে।');
            res.redirect(303, adminUrl('security.php'));
            return;
          }
        }
      }
    }

    const secActions = new Set(['admin_login', 'admin_logout', 'password_changed', 'password_reset_completed']);
    const logs = store.activity_logs.filter((l) => l.admin_id === admin.id && secActions.has(l.action)).slice(0, 12);

    const body = `
<section class="adm-panel">
  <h2 class="adm-panel-title">অ্যাডমিনের পাসওয়ার্ড পরিবর্তন</h2>
  <p style="color: var(--adm-muted); margin-top: 0; font-size: 0.9rem;">
    পাসওয়ার্ড bcrypt পদ্ধতিতে নিরাপদভাবে হ্যাশ করা হয় এবং কখনোই সাধারণ লেখার আকারে সংরক্ষণ করা হয় না।
  </p>
  ${
    admin.must_change_password
      ? '<div class="alert alert-error" role="status">আপনার অ্যাকাউন্টে এখনো প্রাথমিক অথবা অস্থায়ী পাসওয়ার্ড ব্যবহার হচ্ছে। নিচে বর্তমান পাসওয়ার্ড লিখে নতুন স্থায়ী পাসওয়ার্ড নির্ধারণ করুন।</div>'
      : ''
  }
  ${errors.length > 0 ? `<div class="alert alert-error">${errors.map((err) => `<div>${e(err)}</div>`).join('')}</div>` : ''}
  <form method="post" action="${e(adminUrl('security.php'))}" style="max-width: 520px;">
    ${csrfField(session)}
    <input type="hidden" name="action" value="change_password">
    <div class="form-row"><label class="form-label" for="current_password">বর্তমান পাসওয়ার্ড</label><input class="form-control" type="password" id="current_password" name="current_password" required autocomplete="current-password"></div>
    <div class="form-row">
      <label class="form-label" for="new_password_input">নতুন পাসওয়ার্ড</label>
      <input class="form-control" type="password" id="new_password_input" name="new_password" required minlength="10" autocomplete="new-password">
      <span class="form-hint" id="pw_strength_feedback">কমপক্ষে ১০ অক্ষর দিন; বড় হাতের অক্ষর, ছোট হাতের অক্ষর, সংখ্যা ও বিশেষ চিহ্ন থাকতে হবে।</span>
    </div>
    <div class="form-row"><label class="form-label" for="confirm_password">নতুন পাসওয়ার্ড নিশ্চিত করুন</label><input class="form-control" type="password" id="confirm_password" name="confirm_password" required minlength="10" autocomplete="new-password"></div>
    <div class="form-row"><label class="form-check"><input type="checkbox" name="logout_after_change" value="1" checked><span>পাসওয়ার্ড পরিবর্তনের পর লগআউট করুন (সুপারিশকৃত)</span></label></div>
    <button type="submit" class="btn btn-green">পাসওয়ার্ড হালনাগাদ করুন</button>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">সাম্প্রতিক নিরাপত্তা কার্যক্রম</h2>
  <div class="table-wrap"><table class="adm-table">
    <thead><tr><th>ঘটনা</th><th>আইপি ঠিকানা</th><th>সময়</th></tr></thead>
    <tbody>
      ${logs
        .map(
          (log) => `<tr>
          <td>${e(adminActivityLabel(log.action))}</td>
          <td class="num-tab">${e(log.ip_address || '')}</td>
          <td class="num-tab">${e(formatDate(log.created_at, 'd/m/Y H:i:s'))}</td>
        </tr>`
        )
        .join('')}
    </tbody>
  </table></div>
</section>`;

    res.type('html').send(renderAdminLayout(session, admin, 'security', 'নিরাপত্তা ও পাসওয়ার্ড', body));
  });
}
