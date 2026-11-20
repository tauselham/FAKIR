import 'dotenv/config';
import cookieParser from 'cookie-parser';
import express from 'express';
import path from 'node:path';
import { registerAdminRoutes } from './server/adminRoutes.ts';
import {
  handleCustomPageRoute,
  registerPublicRoutes,
  renderPublicFooter,
  renderPublicHeader,
} from './server/publicRoutes.ts';
import { getDemoAdminCredentials, store } from './server/store.ts';

const app = express();
const PORT = Number(process.env.PORT || 3000);

app.disable('x-powered-by');
app.use(cookieParser());
app.use(express.urlencoded({ extended: true, limit: '5mb' }));
app.use(express.json({ limit: '5mb' }));

// Serve uploaded media objects stored in memory (mirrors Supabase Storage public URLs)
app.get('/uploads/:bucket/*', (req, res) => {
  const bucket = String(req.params.bucket || '');
  const objPath = String((req.params as Record<string, string>)['0'] || '');
  if (!bucket || !objPath || objPath.includes('..') || objPath.includes('\0')) {
    res.status(404).type('text').send('404 Not Found');
    return;
  }
  const obj = store.storage.get(`${bucket}/${objPath}`);
  if (!obj) {
    res.status(404).type('text').send('404 Not Found');
    return;
  }
  res.setHeader('Content-Type', obj.mime);
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('Cache-Control', 'public, max-age=31536000, immutable');
  res.send(obj.buffer);
});

// Admin panel (/448662/*) and /admin block
registerAdminRoutes(app);

// Public pages & JSON APIs (/, /posts.php, /post.php, /post/:slug, /gallery.php, /api-gallery.php, /api-posts.php, /content-frame.php)
registerPublicRoutes(app);

// Block direct exposure of internal PHP/DB/source files & hidden dotfiles (mirrors router.php)
app.use((req, res, next) => {
  const reqPath = req.path;
  if (
    /^\/(?:includes|database|scripts|tests|storage|server|src|node_modules)(?:\/|$)/i.test(reqPath) ||
    /\/\./.test(reqPath) ||
    /\.(?:php|sql|md|env|htaccess|ts|tsx|json|lock|txt)$/i.test(reqPath)
  ) {
    res.status(404).type('text').send('404 Not Found');
    return;
  }
  next();
});

// Serve static assets from public/ (CSS, JS, seeded images) without directory index override
app.use(
  express.static(path.resolve(process.cwd(), 'public'), {
    index: false,
    maxAge: '1h',
  })
);

// Single-segment published custom pages (mirrors public/page.php via router.php rewrite)
app.get('*', handleCustomPageRoute);

// Fallback 404 page
app.use((req, res) => {
  res
    .status(404)
    .type('html')
    .send(
      renderPublicHeader({ customPageTitle: 'পেজটি পাওয়া যায়নি' }) +
        '<div class="container section"><div class="empty-state"><h1>পেজটি পাওয়া যায়নি (404)</h1><p>আপনি যে লিংকটি খুঁজছেন তা পাওয়া যায়নি।</p><p><a class="btn-primary" href="/">মূল পাতায় ফিরে যান</a></p></div></div>' +
        renderPublicFooter(req.path)
    );
});

app.listen(PORT, '0.0.0.0', () => {
  const demo = getDemoAdminCredentials();
  console.log(`Purbo Nijmaona Fakir Bari server running at http://0.0.0.0:${PORT}`);
  console.log(`Admin panel: http://0.0.0.0:${PORT}/448662 (user: ${demo.username})`);
});
