export type AdminRole = 'super_admin' | 'admin' | 'editor';

export interface AdminRecord {
  id: number;
  username: string;
  email: string;
  password_hash: string;
  full_name: string;
  role: AdminRole;
  permissions: string[];
  must_change_password: boolean;
  is_active: boolean;
  created_by: number | null;
  last_login_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface LoginAttempt {
  id: number;
  ip_address: string;
  identifier: string;
  success: boolean;
  attempted_at: string;
}

export interface PasswordReset {
  id: number;
  admin_id: number;
  token_hash: string;
  expires_at: string;
  used_at: string | null;
  created_at: string;
}

export interface PostCategory {
  id: number;
  name: string;
  slug: string;
  sort_order: number;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export type PostType = 'text' | 'image' | 'video' | 'custom';
export type PublishStatus = 'draft' | 'published';

export interface PostRecord {
  id: number;
  category_id: number | null;
  admin_id: number | null;
  post_type: PostType;
  title: string | null;
  slug: string;
  description: string;
  content_html: string | null;
  code_document: string | null;
  media_bucket: string | null;
  media_path: string | null;
  media_url: string | null;
  media_mime: string | null;
  html_content: string | null;
  css_content: string | null;
  js_content: string | null;
  status: PublishStatus;
  published_at: string | null;
  created_at: string;
  updated_at: string;
}

export type PageType = 'landing' | 'about';

export interface ContentPage {
  id: number;
  page_type: PageType;
  title: string;
  slug: string;
  html_content: string;
  css_content: string;
  js_content: string;
  code_document: string | null;
  meta_description: string | null;
  status: PublishStatus;
  admin_id: number | null;
  published_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface GalleryCategory {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  cover_image_url: string | null;
  sort_order: number;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface GalleryImage {
  id: number;
  category_id: number | null;
  admin_id: number | null;
  title: string | null;
  description: string | null;
  storage_bucket: string;
  storage_path: string;
  image_url: string;
  mime_type: string | null;
  file_size_bytes: number | null;
  is_featured: boolean;
  is_slider_item: boolean;
  sort_order: number;
  status: 'published' | 'hidden';
  uploaded_at: string;
  updated_at: string;
}

export interface HeroBanner {
  id: number;
  title: string | null;
  subtitle: string | null;
  button_text: string | null;
  button_url: string | null;
  storage_bucket: string;
  storage_path: string;
  image_url: string;
  sort_order: number;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface NavigationMenu {
  id: number;
  location: 'header' | 'footer';
  name: string;
  created_at: string;
  updated_at: string;
}

export type NavItemType = 'link' | 'dropdown' | 'mega';

export interface NavigationItem {
  id: number;
  menu_id: number;
  parent_id: number | null;
  label: string;
  url: string;
  item_type: NavItemType;
  mega_description: string | null;
  open_in_new_tab: boolean;
  sort_order: number;
  is_visible: boolean;
  created_at: string;
  updated_at: string;
  children?: NavigationItem[];
}

export interface FooterSettings {
  id: 1;
  logo_path: string | null;
  logo_url: string | null;
  site_name: string;
  description: string | null;
  contact_address: string | null;
  contact_phone: string | null;
  contact_email: string | null;
  facebook_url: string | null;
  instagram_url: string | null;
  youtube_url: string | null;
  x_url: string | null;
  copyright_text: string;
  show_social_links: boolean;
  show_contact_info: boolean;
  is_visible: boolean;
  updated_at: string;
}

export interface WebsiteSettings {
  id: 1;
  site_name: string;
  site_description: string | null;
  logo_path: string | null;
  logo_url: string | null;
  favicon_path: string | null;
  favicon_url: string | null;
  header_bg_color: string;
  header_text_color: string;
  footer_bg_color: string;
  footer_text_color: string;
  accent_color: string;
  hero_interval_ms: number;
  seo_title: string | null;
  seo_description: string | null;
  social_facebook: string | null;
  social_instagram: string | null;
  social_youtube: string | null;
  updated_at: string;
}

export interface ActivityLog {
  id: number;
  admin_id: number | null;
  action: string;
  entity_type: string | null;
  entity_id: number | null;
  details: Record<string, unknown> | null;
  ip_address: string | null;
  created_at: string;
}

export type RateLimitScope =
  | 'admin_login'
  | 'password_recovery'
  | 'public_api'
  | 'gallery_api'
  | 'post_submission'
  | 'admin_api';

export type RateLimitUnit = 'seconds' | 'minutes' | 'hours' | 'days';

export interface RateLimitRule {
  id: number;
  scope: RateLimitScope;
  label: string;
  description: string | null;
  max_requests: number;
  window_value: number;
  window_unit: RateLimitUnit;
  is_enabled: boolean;
  updated_by: number | null;
  created_at: string;
  updated_at: string;
}

export interface RateLimitBucket {
  id: number;
  scope: RateLimitScope;
  bucket_type: 'ip' | 'account';
  bucket_key: string;
  request_count: number;
  window_started_at: string;
  window_expires_at: string;
  last_request_at: string;
}

export interface SitePopup {
  id: number;
  title: string | null;
  popup_type: 'text' | 'image';
  body_text: string | null;
  image_bucket: string | null;
  image_path: string | null;
  image_url: string | null;
  image_link_url: string | null;
  image_link_target: '_self' | '_blank';
  button_text: string | null;
  button_url: string | null;
  button_target: '_self' | '_blank';
  starts_at: string | null;
  ends_at: string | null;
  delay_seconds: number;
  frequency: 'every_page' | 'once_session' | 'once_day';
  display_scope: 'all' | 'home';
  device_target: 'all' | 'desktop' | 'mobile';
  priority: number;
  is_active: boolean;
  admin_id: number | null;
  created_at: string;
  updated_at: string;
}

export interface StoredObject {
  bucket: string;
  path: string;
  mime: string;
  size: number;
  buffer: Buffer;
}

export interface AdminSession {
  id: string;
  admin_id: number | null;
  admin_login_at: number;
  admin_last_active: number;
  csrf_token: string;
  flash: { type: 'success' | 'error'; message: string } | null;
}
