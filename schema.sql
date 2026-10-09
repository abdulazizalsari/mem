CREATE TABLE IF NOT EXISTS settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(120) NOT NULL UNIQUE,
  `value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','admin','editor','moderator') NOT NULL DEFAULT 'admin',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS countries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL UNIQUE,
  slug VARCHAR(190) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS regions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  country_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uniq_region(country_id,name),
  CONSTRAINT fk_region_country FOREIGN KEY(country_id) REFERENCES countries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  region_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uniq_city(region_id,name),
  CONSTRAINT fk_city_region FOREIGN KEY(region_id) REFERENCES regions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS specialties (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL UNIQUE,
  slug VARCHAR(190) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS listings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type ENUM('person','center') NOT NULL DEFAULT 'person',
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(220) NOT NULL UNIQUE,
  phone VARCHAR(80) NULL,
  whatsapp VARCHAR(80) NULL,
  image VARCHAR(255) NULL,
  short_bio VARCHAR(500) NULL,
  bio MEDIUMTEXT NULL,
  country_id INT UNSIGNED NOT NULL,
  region_id INT UNSIGNED NULL,
  city_id INT UNSIGNED NULL,
  primary_specialty_id INT UNSIGNED NULL,
  address VARCHAR(500) NULL,
  map_url VARCHAR(700) NULL,
  phone_verified TINYINT(1) NOT NULL DEFAULT 1,
  address_verified TINYINT(1) NOT NULL DEFAULT 1,
  last_verified_at DATE NULL,
  admin_notes TEXT NULL,
  review_state ENUM('unreviewed','data_reviewed','needs_update') NOT NULL DEFAULT 'data_reviewed',
  seo_title VARCHAR(255) NULL,
  seo_description VARCHAR(500) NULL,
  status ENUM('published','needs_update','suspended','hidden') NOT NULL DEFAULT 'published',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_listing_status(status),
  INDEX idx_listing_type_status(type,status),
  INDEX idx_listing_country(country_id),
  INDEX idx_listing_region(region_id),
  INDEX idx_listing_city(city_id),
  INDEX idx_listing_phone(phone),
  CONSTRAINT fk_listing_country FOREIGN KEY(country_id) REFERENCES countries(id),
  CONSTRAINT fk_listing_region FOREIGN KEY(region_id) REFERENCES regions(id) ON DELETE SET NULL,
  CONSTRAINT fk_listing_city FOREIGN KEY(city_id) REFERENCES cities(id) ON DELETE SET NULL,
  CONSTRAINT fk_listing_specialty FOREIGN KEY(primary_specialty_id) REFERENCES specialties(id) ON DELETE SET NULL,
  CONSTRAINT fk_listing_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS listing_specialties (
  listing_id BIGINT UNSIGNED NOT NULL,
  specialty_id INT UNSIGNED NOT NULL,
  PRIMARY KEY(listing_id,specialty_id),
  CONSTRAINT fk_ls_listing FOREIGN KEY(listing_id) REFERENCES listings(id) ON DELETE CASCADE,
  CONSTRAINT fk_ls_specialty FOREIGN KEY(specialty_id) REFERENCES specialties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  listing_id BIGINT UNSIGNED NOT NULL,
  reviewer_name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(80) NULL,
  rating TINYINT UNSIGNED NOT NULL,
  message TEXT NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL,
  INDEX idx_review_status(status),
  CONSTRAINT fk_review_listing FOREIGN KEY(listing_id) REFERENCES listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS complaints (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  listing_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(80) NULL,
  category VARCHAR(160) NOT NULL,
  severity ENUM('normal','medium','high') NOT NULL DEFAULT 'normal',
  message TEXT NOT NULL,
  evidence VARCHAR(255) NULL,
  status ENUM('new','reviewing','closed','action_taken') NOT NULL DEFAULT 'new',
  created_at DATETIME NOT NULL,
  INDEX idx_complaint_status(status),
  INDEX idx_complaint_severity(severity),
  CONSTRAINT fk_complaint_listing FOREIGN KEY(listing_id) REFERENCES listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  listing_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(80) NULL,
  category VARCHAR(160) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('new','reviewing','fixed','closed') NOT NULL DEFAULT 'new',
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_report_listing FOREIGN KEY(listing_id) REFERENCES listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS article_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL UNIQUE,
  slug VARCHAR(190) NOT NULL UNIQUE,
  description VARCHAR(700) NULL,
  image VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  seo_title VARCHAR(255) NULL,
  seo_description VARCHAR(500) NULL,
  INDEX idx_article_category_active(is_active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS articles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(240) NOT NULL,
  slug VARCHAR(260) NOT NULL UNIQUE,
  excerpt VARCHAR(700) NULL,
  content_html MEDIUMTEXT NOT NULL,
  image VARCHAR(255) NULL,
  category_id INT UNSIGNED NULL,
  status ENUM('draft','pending_review','published','scheduled','private','archived') NOT NULL DEFAULT 'draft',
  featured TINYINT(1) NOT NULL DEFAULT 0,
  published_at DATETIME NULL,
  views BIGINT UNSIGNED NOT NULL DEFAULT 0,
  seo_title VARCHAR(255) NULL,
  seo_description VARCHAR(500) NULL,
  canonical_url VARCHAR(700) NULL,
  og_title VARCHAR(255) NULL,
  og_description VARCHAR(500) NULL,
  og_image VARCHAR(255) NULL,
  robots VARCHAR(40) NOT NULL DEFAULT 'index,follow',
  references_text MEDIUMTEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  INDEX idx_article_status(status),
  INDEX idx_article_category(category_id),
  INDEX idx_article_publish(status,published_at),
  INDEX idx_article_featured(featured,status,published_at),
  INDEX idx_article_deleted(deleted_at),
  CONSTRAINT fk_article_category FOREIGN KEY(category_id) REFERENCES article_categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_article_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_article_updated_user FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS article_tags (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  slug VARCHAR(190) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_article_tag_name(name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS article_tag_map (
  article_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY(article_id,tag_id),
  INDEX idx_article_tag_map_tag(tag_id,article_id),
  CONSTRAINT fk_article_tag_map_article FOREIGN KEY(article_id) REFERENCES articles(id) ON DELETE CASCADE,
  CONSTRAINT fk_article_tag_map_tag FOREIGN KEY(tag_id) REFERENCES article_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS article_revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, article_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NULL, snapshot_json LONGTEXT NOT NULL, created_at DATETIME NOT NULL,
  INDEX idx_article_revision_article(article_id,id),
  CONSTRAINT fk_article_revision_article FOREIGN KEY(article_id) REFERENCES articles(id) ON DELETE CASCADE,
  CONSTRAINT fk_article_revision_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS article_slug_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, article_id BIGINT UNSIGNED NOT NULL, old_slug VARCHAR(260) NOT NULL UNIQUE, created_at DATETIME NOT NULL,
  INDEX idx_article_slug_history_article(article_id),
  CONSTRAINT fk_article_slug_history_article FOREIGN KEY(article_id) REFERENCES articles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS article_autosaves (
  article_id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, draft_json LONGTEXT NOT NULL, updated_at DATETIME NOT NULL,
  CONSTRAINT fk_article_autosave_article FOREIGN KEY(article_id) REFERENCES articles(id) ON DELETE CASCADE,
  CONSTRAINT fk_article_autosave_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(120) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id BIGINT UNSIGNED NULL,
  details TEXT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_audit_created(created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Integrations and tracking
CREATE TABLE IF NOT EXISTS integrations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_key VARCHAR(80) NOT NULL UNIQUE,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  consent_category VARCHAR(32) NOT NULL DEFAULT 'necessary',
  config_json MEDIUMTEXT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_integration_enabled(enabled),
  CONSTRAINT fk_integration_user FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ad_placements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  placement_key VARCHAR(80) NOT NULL UNIQUE,
  provider VARCHAR(80) NOT NULL DEFAULT 'adsense',
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  slot_id VARCHAR(120) NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_ad_placement_user FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Media library
CREATE TABLE IF NOT EXISTS media_folders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, parent_id BIGINT UNSIGNED NULL, created_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  INDEX idx_media_folder_parent(parent_id), CONSTRAINT fk_media_folder_parent FOREIGN KEY(parent_id) REFERENCES media_folders(id) ON DELETE SET NULL, CONSTRAINT fk_media_folder_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  path VARCHAR(255) NOT NULL UNIQUE, original_name VARCHAR(255) NULL, title VARCHAR(255) NULL, mime_type VARCHAR(120) NULL, size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0, width INT UNSIGNED NULL, height INT UNSIGNED NULL, file_hash CHAR(64) NULL, folder_id BIGINT UNSIGNED NULL, alt_text VARCHAR(255) NULL, caption VARCHAR(500) NULL, description TEXT NULL, created_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL,
  INDEX idx_media_created(created_at), INDEX idx_media_mime(mime_type), INDEX idx_media_folder(folder_id), INDEX idx_media_deleted(deleted_at), INDEX idx_media_hash(file_hash),
  CONSTRAINT fk_media_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL, CONSTRAINT fk_media_folder FOREIGN KEY(folder_id) REFERENCES media_folders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_tags (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS media_tag_map (media_id BIGINT UNSIGNED NOT NULL, tag_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY(media_id,tag_id), CONSTRAINT fk_media_tag_media FOREIGN KEY(media_id) REFERENCES media(id) ON DELETE CASCADE, CONSTRAINT fk_media_tag_tag FOREIGN KEY(tag_id) REFERENCES media_tags(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS media_usages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, media_id BIGINT UNSIGNED NOT NULL, entity_type VARCHAR(60) NOT NULL, entity_id BIGINT UNSIGNED NULL, field_name VARCHAR(80) NULL, created_at DATETIME NOT NULL, INDEX idx_media_usage_media(media_id), INDEX idx_media_usage_entity(entity_type,entity_id), CONSTRAINT fk_media_usage_media FOREIGN KEY(media_id) REFERENCES media(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Header, footer and social navigation managed from admin
CREATE TABLE IF NOT EXISTS navigation_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  location ENUM('header','footer','social') NOT NULL,
  label VARCHAR(160) NOT NULL,
  url VARCHAR(700) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  new_tab TINYINT(1) NOT NULL DEFAULT 0,
  is_cta TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_nav_location(location,is_active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Persistent login throttling (stronger than session-only throttling)
CREATE TABLE IF NOT EXISTS login_attempts (
  identity_hash CHAR(64) PRIMARY KEY,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_attempt_at DATETIME NOT NULL,
  locked_until DATETIME NULL,
  INDEX idx_login_lock(locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Future-ready language registry. Arabic remains the default.
CREATE TABLE IF NOT EXISTS languages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(12) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- Optional per-user permission overrides. Role defaults remain the baseline.
CREATE TABLE IF NOT EXISTS user_permissions (
  user_id BIGINT UNSIGNED NOT NULL,
  permission VARCHAR(80) NOT NULL,
  allowed TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY(user_id,permission),
  CONSTRAINT fk_user_permission_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Mihrab 003: page editor + hardened authentication support
-- Safe additive migration. No existing columns/tables are dropped.

CREATE TABLE IF NOT EXISTS pages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(240) NOT NULL,
  slug VARCHAR(220) NOT NULL UNIQUE,
  status ENUM('draft','published','private') NOT NULL DEFAULT 'draft',
  direction ENUM('rtl','ltr','auto') NOT NULL DEFAULT 'rtl',
  content_html MEDIUMTEXT NOT NULL,
  content_json LONGTEXT NULL,
  custom_css MEDIUMTEXT NULL,
  custom_js MEDIUMTEXT NULL,
  seo_title VARCHAR(255) NULL,
  seo_description VARCHAR(500) NULL,
  favicon VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  published_at DATETIME NULL,
  INDEX idx_pages_status(status),
  CONSTRAINT fk_pages_created_by FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pages_updated_by FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_password_reset_user(user_id),
  INDEX idx_password_reset_expiry(expires_at),
  CONSTRAINT fk_password_reset_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_verifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_email_verify_user(user_id),
  CONSTRAINT fk_email_verify_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  session_hash CHAR(64) NOT NULL UNIQUE,
  ip_hash CHAR(64) NULL,
  user_agent VARCHAR(500) NULL,
  last_seen_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_admin_sessions_user(user_id),
  INDEX idx_admin_sessions_expiry(expires_at),
  CONSTRAINT fk_admin_sessions_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS two_factor_auth (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  secret_enc TEXT NOT NULL,
  backup_codes_hash LONGTEXT NULL,
  enabled_at DATETIME NOT NULL,
  CONSTRAINT fk_2fa_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_rate_limits (
  identity_hash CHAR(64) PRIMARY KEY,
  action VARCHAR(40) NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  locked_until DATETIME NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_auth_rate_action(action),
  INDEX idx_auth_rate_lock(locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New permissions use the existing user_permissions table from migration 002.
CREATE TABLE IF NOT EXISTS user_security (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  email_verified_at DATETIME NULL,
  password_changed_at DATETIME NULL,
  CONSTRAINT fk_user_security_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signup_invites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  role ENUM('admin','editor','moderator') NOT NULL DEFAULT 'editor',
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_signup_invite_email(email),
  CONSTRAINT fk_signup_invite_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mihrab 006: central pages + menus CMS
-- For existing installations apply migrations/006_pages_menus_cms.sql instead of re-running schema.sql.
ALTER TABLE pages
  MODIFY COLUMN status ENUM('draft','published','scheduled','private','archived') NOT NULL DEFAULT 'draft',
  ADD COLUMN page_type ENUM('content','system','dynamic','landing','custom') NOT NULL DEFAULT 'content' AFTER slug,
  ADD COLUMN language_code VARCHAR(12) NOT NULL DEFAULT 'ar' AFTER page_type,
  ADD COLUMN template VARCHAR(60) NOT NULL DEFAULT 'default' AFTER language_code,
  ADD COLUMN route_path VARCHAR(500) NULL AFTER template,
  ADD COLUMN system_key VARCHAR(120) NULL AFTER route_path,
  ADD COLUMN icon_key VARCHAR(80) NULL AFTER system_key,
  ADD COLUMN og_title VARCHAR(255) NULL AFTER seo_description,
  ADD COLUMN og_description VARCHAR(500) NULL AFTER og_title,
  ADD COLUMN og_image VARCHAR(255) NULL AFTER og_description,
  ADD COLUMN canonical_url VARCHAR(700) NULL AFTER og_image,
  ADD COLUMN robots VARCHAR(40) NOT NULL DEFAULT 'index,follow' AFTER canonical_url,
  ADD COLUMN scheduled_at DATETIME NULL AFTER published_at,
  ADD COLUMN deleted_at DATETIME NULL AFTER scheduled_at,
  ADD INDEX idx_pages_type_status(page_type,status), ADD INDEX idx_pages_language(language_code,status), ADD INDEX idx_pages_deleted(deleted_at), ADD INDEX idx_pages_route(route_path(190)), ADD UNIQUE KEY uq_pages_system_key(system_key);
UPDATE pages SET route_path=CONCAT('/page/',slug) WHERE route_path IS NULL OR route_path='';
CREATE TABLE IF NOT EXISTS page_revisions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,page_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NULL,snapshot_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL,INDEX idx_page_revision(page_id,id),CONSTRAINT fk_page_revision_page FOREIGN KEY(page_id) REFERENCES pages(id) ON DELETE CASCADE,CONSTRAINT fk_page_revision_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS page_url_history (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,page_id BIGINT UNSIGNED NOT NULL,old_path VARCHAR(500) NOT NULL,created_at DATETIME NOT NULL,UNIQUE KEY uq_page_old_path(old_path(190)),INDEX idx_page_url_history_page(page_id),CONSTRAINT fk_page_url_history_page FOREIGN KEY(page_id) REFERENCES pages(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS menus (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(160) NOT NULL,slug VARCHAR(160) NOT NULL UNIQUE,description VARCHAR(500) NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,created_by BIGINT UNSIGNED NULL,updated_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,CONSTRAINT fk_menu_created_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,CONSTRAINT fk_menu_updated_user FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS menu_locations (location_key VARCHAR(80) PRIMARY KEY,label VARCHAR(160) NOT NULL,menu_id BIGINT UNSIGNED NULL,updated_by BIGINT UNSIGNED NULL,updated_at DATETIME NULL,CONSTRAINT fk_menu_location_menu FOREIGN KEY(menu_id) REFERENCES menus(id) ON DELETE SET NULL,CONSTRAINT fk_menu_location_user FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS menu_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,menu_id BIGINT UNSIGNED NOT NULL,parent_id BIGINT UNSIGNED NULL,item_type ENUM('page','article_category','custom','external','label','dynamic') NOT NULL DEFAULT 'custom',page_id BIGINT UNSIGNED NULL,article_category_id INT UNSIGNED NULL,label VARCHAR(160) NOT NULL,url VARCHAR(700) NULL,icon_key VARCHAR(80) NULL,target ENUM('same','new') NOT NULL DEFAULT 'same',rel_value VARCHAR(160) NULL,display_style ENUM('link','primary','outline','soft') NOT NULL DEFAULT 'link',is_active TINYINT(1) NOT NULL DEFAULT 1,show_desktop TINYINT(1) NOT NULL DEFAULT 1,show_mobile TINYINT(1) NOT NULL DEFAULT 1,sort_order INT NOT NULL DEFAULT 0,legacy_navigation_id INT UNSIGNED NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,INDEX idx_menu_item_menu(menu_id,parent_id,is_active,sort_order),INDEX idx_menu_item_page(page_id),INDEX idx_menu_item_article_category(article_category_id),UNIQUE KEY uq_menu_legacy(legacy_navigation_id),CONSTRAINT fk_menu_item_menu FOREIGN KEY(menu_id) REFERENCES menus(id) ON DELETE CASCADE,CONSTRAINT fk_menu_item_parent FOREIGN KEY(parent_id) REFERENCES menu_items(id) ON DELETE CASCADE,CONSTRAINT fk_menu_item_page FOREIGN KEY(page_id) REFERENCES pages(id) ON DELETE SET NULL,CONSTRAINT fk_menu_item_article_category FOREIGN KEY(article_category_id) REFERENCES article_categories(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO menus(name,slug,description,is_active,created_at,updated_at) SELECT 'القائمة الرئيسية','main-navigation','القائمة الأساسية للهيدر والجوال',1,NOW(),NOW() FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM menus WHERE slug='main-navigation');
INSERT INTO menus(name,slug,description,is_active,created_at,updated_at) SELECT 'الفوتر','footer-navigation','روابط الفوتر',1,NOW(),NOW() FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM menus WHERE slug='footer-navigation');
INSERT INTO menus(name,slug,description,is_active,created_at,updated_at) SELECT 'الشبكات الاجتماعية','social-navigation','روابط الشبكات الاجتماعية',1,NOW(),NOW() FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM menus WHERE slug='social-navigation');
INSERT INTO menu_locations(location_key,label,menu_id,updated_at) SELECT 'header','الهيدر',(SELECT id FROM menus WHERE slug='main-navigation' LIMIT 1),NOW() FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM menu_locations WHERE location_key='header');
INSERT INTO menu_locations(location_key,label,menu_id,updated_at) SELECT 'mobile','قائمة الجوال',(SELECT id FROM menus WHERE slug='main-navigation' LIMIT 1),NOW() FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM menu_locations WHERE location_key='mobile');
INSERT INTO menu_locations(location_key,label,menu_id,updated_at) SELECT 'footer','الفوتر',(SELECT id FROM menus WHERE slug='footer-navigation' LIMIT 1),NOW() FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM menu_locations WHERE location_key='footer');
INSERT INTO menu_locations(location_key,label,menu_id,updated_at) SELECT 'social','الشبكات الاجتماعية',(SELECT id FROM menus WHERE slug='social-navigation' LIMIT 1),NOW() FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM menu_locations WHERE location_key='social');
INSERT INTO menu_locations(location_key,label,menu_id,updated_at) SELECT 'secondary_nav','تنقل ثانوي',NULL,NOW() FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM menu_locations WHERE location_key='secondary_nav');
INSERT INTO menu_locations(location_key,label,menu_id,updated_at) SELECT 'sidebar','القائمة الجانبية',NULL,NOW() FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM menu_locations WHERE location_key='sidebar');
INSERT INTO menu_items(menu_id,item_type,label,url,target,display_style,is_active,show_desktop,show_mobile,sort_order,legacy_navigation_id,created_at,updated_at)
SELECT m.id,CASE WHEN n.url LIKE 'http://%' OR n.url LIKE 'https://%' THEN 'external' ELSE 'custom' END,n.label,n.url,IF(n.new_tab=1,'new','same'),IF(n.is_cta=1,'primary','link'),n.is_active,1,1,n.sort_order,n.id,NOW(),NOW()
FROM navigation_items n JOIN menus m ON m.slug=CASE n.location WHEN 'header' THEN 'main-navigation' WHEN 'footer' THEN 'footer-navigation' ELSE 'social-navigation' END
WHERE NOT EXISTS(SELECT 1 FROM menu_items mi WHERE mi.legacy_navigation_id=n.id);
-- Add the existing header CTA to the central menu so it is no longer hard-coded in the template.
INSERT INTO menu_items(menu_id,item_type,label,url,target,display_style,is_active,show_desktop,show_mobile,sort_order,created_at,updated_at)
SELECT m.id,'custom','ابحث في الدليل','/directory','same','primary',1,1,1,60,NOW(),NOW()
FROM menus m WHERE m.slug='main-navigation'
  AND NOT EXISTS(SELECT 1 FROM menu_items mi WHERE mi.menu_id=m.id AND mi.label='ابحث في الدليل');

INSERT INTO pages(title,slug,page_type,language_code,template,route_path,system_key,status,direction,content_html,content_json,created_at,updated_at,published_at) SELECT 'الدليل','system-directory','system','ar','default','/directory','directory','published','rtl','', '[]',NOW(),NOW(),NOW() FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM pages WHERE system_key='directory' OR slug='system-directory');
INSERT INTO pages(title,slug,page_type,language_code,template,route_path,system_key,status,direction,content_html,content_json,created_at,updated_at,published_at) SELECT 'المقالات','system-articles','system','ar','default','/articles','articles','published','rtl','', '[]',NOW(),NOW(),NOW() FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM pages WHERE system_key='articles' OR slug='system-articles');
INSERT INTO settings(`key`,`value`) VALUES ('cms_default_language','ar'),('cms_max_menu_depth','3') ON DUPLICATE KEY UPDATE `key`=VALUES(`key`);

-- Bring current editable content pages into the central page registry while preserving their public URLs.
INSERT INTO pages(title,slug,page_type,language_code,template,route_path,status,direction,content_html,content_json,seo_title,seo_description,created_at,updated_at,published_at)
SELECT 'عن محراب','about','content','ar','content','/about','published','rtl',
'<h2>ما هو محراب؟</h2><p>محراب دليل معلومات عربي للرقاة ومراكز الرقية. لا يسمح الموقع بإضافة الملفات ذاتيًا من الواجهة العامة؛ إدارة محراب هي التي تنشئ الملفات وتراجع المعلومات التي تعرضها وفق آلية العمل المعتمدة لديها.</p><h2>ما الذي لا يعنيه الظهور في الدليل؟</h2><p>وجود الملف أو مراجعة بياناته لا يمثل اعتمادًا طبيًا أو حكوميًا، ولا يضمن نتيجة علاجية. الغرض هو تنظيم المعلومات وتسهيل الوصول إليها بصورة أوضح.</p>',
'[{"type":"html","html":"<h2>ما هو محراب؟</h2><p>محراب دليل معلومات عربي للرقاة ومراكز الرقية. لا يسمح الموقع بإضافة الملفات ذاتيًا من الواجهة العامة؛ إدارة محراب هي التي تنشئ الملفات وتراجع المعلومات التي تعرضها وفق آلية العمل المعتمدة لديها.</p><h2>ما الذي لا يعنيه الظهور في الدليل؟</h2><p>وجود الملف أو مراجعة بياناته لا يمثل اعتمادًا طبيًا أو حكوميًا، ولا يضمن نتيجة علاجية. الغرض هو تنظيم المعلومات وتسهيل الوصول إليها بصورة أوضح.</p>"}]',
'عن محراب','تعرف على هدف منصة محراب وطريقة إدارة الدليل.',NOW(),NOW(),NOW()
FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM pages WHERE (route_path='/about' AND deleted_at IS NULL) OR slug='about');

INSERT INTO pages(title,slug,page_type,language_code,template,route_path,status,direction,content_html,content_json,seo_title,seo_description,created_at,updated_at,published_at)
SELECT 'سياسة الخصوصية','privacy','content','ar','content','/privacy','published','rtl',
'<h2>البيانات التي نستقبلها</h2><p>قد نستقبل بيانات يرسلها الزائر عبر التقييمات أو الشكاوى أو بلاغات تصحيح البيانات. تستخدم هذه المعلومات للمراجعة والإدارة ولا تُعرض بيانات المشتكين الحساسة للعامة.</p><h2>التحليلات والتسويق والإعلانات</h2><p>عند تفعيل أدوات غير ضرورية، يطلب محراب اختيارك قبل تحميلها عندما تكون الموافقة مطلوبة. يمكنك تغيير قرارك لاحقًا من زر «إعدادات الخصوصية».</p><h2>المرفقات</h2><p>مرفقات الشكاوى تحفظ للاستخدام الإداري ولا ينبغي نشرها للعامة. تحدد الإدارة مدة الاحتفاظ وفق الحاجة والقوانين المنطبقة.</p>',
'[{"type":"html","html":"<h2>البيانات التي نستقبلها</h2><p>قد نستقبل بيانات يرسلها الزائر عبر التقييمات أو الشكاوى أو بلاغات تصحيح البيانات. تستخدم هذه المعلومات للمراجعة والإدارة ولا تُعرض بيانات المشتكين الحساسة للعامة.</p><h2>التحليلات والتسويق والإعلانات</h2><p>عند تفعيل أدوات غير ضرورية، يطلب محراب اختيارك قبل تحميلها عندما تكون الموافقة مطلوبة. يمكنك تغيير قرارك لاحقًا من زر «إعدادات الخصوصية».</p><h2>المرفقات</h2><p>مرفقات الشكاوى تحفظ للاستخدام الإداري ولا ينبغي نشرها للعامة. تحدد الإدارة مدة الاحتفاظ وفق الحاجة والقوانين المنطبقة.</p>"}]',
'سياسة الخصوصية','سياسة الخصوصية لموقع محراب.',NOW(),NOW(),NOW()
FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM pages WHERE (route_path='/privacy' AND deleted_at IS NULL) OR slug='privacy');

INSERT INTO pages(title,slug,page_type,language_code,template,route_path,status,direction,content_html,content_json,seo_title,seo_description,created_at,updated_at,published_at)
SELECT 'الشروط والأحكام','terms','content','ar','content','/terms','published','rtl',
'<h2>طبيعة الدليل</h2><p>محراب منصة معلومات ودليل. إضافة ملف أو مراجعة بياناته لا تعني اعتمادًا طبيًا أو رسميًا ولا تضمن نتيجة علاجية.</p><h2>المعلومات</h2><p>نسعى لعرض بيانات واضحة وقابلة للتحديث، ويمكن للزوار إرسال بلاغ عند اكتشاف معلومات قديمة أو غير صحيحة.</p><h2>الصحة والسلامة</h2><p>محتوى محراب لا يحل محل التشخيص أو الرعاية الطبية أو النفسية المختصة عند الحاجة.</p>',
'[{"type":"html","html":"<h2>طبيعة الدليل</h2><p>محراب منصة معلومات ودليل. إضافة ملف أو مراجعة بياناته لا تعني اعتمادًا طبيًا أو رسميًا ولا تضمن نتيجة علاجية.</p><h2>المعلومات</h2><p>نسعى لعرض بيانات واضحة وقابلة للتحديث، ويمكن للزوار إرسال بلاغ عند اكتشاف معلومات قديمة أو غير صحيحة.</p><h2>الصحة والسلامة</h2><p>محتوى محراب لا يحل محل التشخيص أو الرعاية الطبية أو النفسية المختصة عند الحاجة.</p>"}]',
'الشروط والأحكام','الشروط والأحكام لموقع محراب.',NOW(),NOW(),NOW()
FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM pages WHERE (route_path='/terms' AND deleted_at IS NULL) OR slug='terms');

INSERT INTO pages(title,slug,page_type,language_code,template,route_path,system_key,status,direction,content_html,content_json,seo_title,seo_description,created_at,updated_at,published_at)
SELECT 'كيف نراجع الملفات؟','system-how-we-verify','system','ar','default','/how-we-verify','how-we-verify','published','rtl','', '[]','كيف يضيف محراب الملفات ويراجع البيانات؟','تعرف على معنى الإضافة والمراجعة داخل محراب وحدود ما تعنيه للمستخدم.',NOW(),NOW(),NOW()
FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM pages WHERE system_key='how-we-verify' OR slug='system-how-we-verify');
UPDATE menu_items mi JOIN pages p ON p.route_path=mi.url AND p.deleted_at IS NULL SET mi.item_type='page',mi.page_id=p.id,mi.url=NULL WHERE mi.item_type='custom' AND mi.url LIKE '/%';
-- Mihrab v6 final systems: geography + operations + import/export + search analytics + SEO support
SET NAMES utf8mb4;

ALTER TABLE countries
  ADD COLUMN native_name VARCHAR(160) NULL AFTER name,
  ADD COLUMN iso2 CHAR(2) NULL AFTER native_name,
  ADD COLUMN iso3 CHAR(3) NULL AFTER iso2,
  ADD COLUMN phone_code VARCHAR(16) NULL AFTER iso3,
  ADD COLUMN flag VARCHAR(255) NULL AFTER phone_code,
  ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER is_active,
  ADD INDEX idx_country_active_sort (is_active,sort_order,name);

ALTER TABLE regions
  ADD COLUMN type VARCHAR(60) NULL AFTER name,
  ADD COLUMN admin_level TINYINT UNSIGNED NULL AFTER type,
  ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER is_active,
  ADD INDEX idx_region_country_active (country_id,is_active,sort_order,name);

ALTER TABLE cities
  ADD COLUMN country_id INT UNSIGNED NULL AFTER id,
  ADD COLUMN latitude DECIMAL(10,7) NULL AFTER slug,
  ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude,
  ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER is_active,
  ADD INDEX idx_city_country (country_id),
  ADD INDEX idx_city_region_active (region_id,is_active,sort_order,name);
UPDATE cities ci JOIN regions r ON r.id=ci.region_id SET ci.country_id=r.country_id WHERE ci.country_id IS NULL;
ALTER TABLE cities
  MODIFY country_id INT UNSIGNED NOT NULL,
  ADD CONSTRAINT fk_city_country FOREIGN KEY(country_id) REFERENCES countries(id) ON DELETE CASCADE;

ALTER TABLE listings
  MODIFY status ENUM('draft','published','needs_update','suspended','archived','hidden') NOT NULL DEFAULT 'published',
  ADD COLUMN provider_name VARCHAR(190) NULL AFTER name,
  ADD COLUMN district VARCHAR(190) NULL AFTER address,
  ADD COLUMN street VARCHAR(190) NULL AFTER district,
  ADD COLUMN landmark VARCHAR(255) NULL AFTER street,
  ADD COLUMN postal_code VARCHAR(40) NULL AFTER landmark,
  ADD COLUMN email VARCHAR(190) NULL AFTER whatsapp,
  ADD COLUMN website VARCHAR(700) NULL AFTER email,
  ADD COLUMN search_keywords VARCHAR(700) NULL AFTER short_bio,
  ADD COLUMN latitude DECIMAL(10,7) NULL AFTER map_url,
  ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude,
  ADD COLUMN verification_status ENUM('unverified','pending','verified','rejected','needs_update') NOT NULL DEFAULT 'unverified' AFTER review_state,
  ADD COLUMN verified_by BIGINT UNSIGNED NULL AFTER verification_status,
  ADD COLUMN verified_at DATETIME NULL AFTER verified_by,
  ADD COLUMN verification_note VARCHAR(700) NULL AFTER verified_at,
  ADD COLUMN recommended TINYINT(1) NOT NULL DEFAULT 0 AFTER verification_note,
  ADD COLUMN recommended_by BIGINT UNSIGNED NULL AFTER recommended,
  ADD COLUMN recommended_at DATETIME NULL AFTER recommended_by,
  ADD COLUMN recommendation_note VARCHAR(700) NULL AFTER recommended_at,
  ADD COLUMN featured TINYINT(1) NOT NULL DEFAULT 0 AFTER recommendation_note,
  ADD COLUMN published_at DATETIME NULL AFTER status,
  ADD COLUMN archived_at DATETIME NULL AFTER published_at,
  ADD COLUMN deleted_at DATETIME NULL AFTER archived_at,
  ADD COLUMN views BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN contact_clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN whatsapp_clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN call_clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ADD INDEX idx_listing_public (status,deleted_at,country_id,region_id,city_id),
  ADD INDEX idx_listing_verification (verification_status,recommended,featured),
  ADD CONSTRAINT fk_listing_verified_by FOREIGN KEY(verified_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_listing_recommended_by FOREIGN KEY(recommended_by) REFERENCES users(id) ON DELETE SET NULL;
UPDATE listings SET published_at=COALESCE(published_at,created_at) WHERE status='published';

CREATE TABLE IF NOT EXISTS listing_verification_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  listing_id BIGINT UNSIGNED NOT NULL,
  status ENUM('unverified','pending','verified','rejected','needs_update') NOT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  internal_note VARCHAR(1000) NULL,
  public_note VARCHAR(700) NULL,
  method VARCHAR(120) NULL,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_verification_listing(listing_id,created_at),
  CONSTRAINT fk_verification_listing FOREIGN KEY(listing_id) REFERENCES listings(id) ON DELETE CASCADE,
  CONSTRAINT fk_verification_user FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS listing_slug_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  listing_id BIGINT UNSIGNED NOT NULL,
  old_slug VARCHAR(220) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_listing_old_slug(old_slug),
  CONSTRAINT fk_listing_slug_history FOREIGN KEY(listing_id) REFERENCES listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mihrab v7: globally sourced locations and manual fallback values.
ALTER TABLE countries
  ADD COLUMN name_en VARCHAR(160) NULL AFTER name,
  ADD COLUMN name_native VARCHAR(160) NULL AFTER name_en,
  ADD COLUMN source VARCHAR(40) NULL AFTER flag,
  ADD COLUMN source_id VARCHAR(100) NULL AFTER source,
  ADD COLUMN created_at DATETIME NULL,
  ADD COLUMN updated_at DATETIME NULL,
  ADD INDEX idx_country_search_en (name_en),
  ADD UNIQUE KEY uq_country_source (source,source_id);
ALTER TABLE regions
  ADD COLUMN name_en VARCHAR(160) NULL AFTER name,
  ADD COLUMN name_native VARCHAR(160) NULL AFTER name_en,
  ADD COLUMN code VARCHAR(40) NULL AFTER admin_level,
  ADD COLUMN source VARCHAR(40) NULL AFTER code,
  ADD COLUMN source_id VARCHAR(100) NULL AFTER source,
  ADD COLUMN created_at DATETIME NULL,
  ADD COLUMN updated_at DATETIME NULL,
  ADD INDEX idx_region_search_country_en (country_id,name_en),
  ADD UNIQUE KEY uq_region_source (source,source_id);
ALTER TABLE cities
  ADD COLUMN name_en VARCHAR(160) NULL AFTER name,
  ADD COLUMN name_native VARCHAR(160) NULL AFTER name_en,
  ADD COLUMN source VARCHAR(40) NULL AFTER longitude,
  ADD COLUMN source_id VARCHAR(100) NULL AFTER source,
  ADD COLUMN created_at DATETIME NULL,
  ADD COLUMN updated_at DATETIME NULL,
  ADD INDEX idx_city_search_region_en (region_id,name_en),
  ADD UNIQUE KEY uq_city_source (source,source_id);
ALTER TABLE listings
  ADD COLUMN custom_country VARCHAR(160) NULL AFTER country_id,
  ADD COLUMN custom_region VARCHAR(160) NULL AFTER region_id,
  ADD COLUMN custom_city VARCHAR(160) NULL AFTER city_id;

-- Mihrab v8: stored automatic article cover metadata. Existing article images are custom overrides.
ALTER TABLE articles
  ADD COLUMN cover_mode ENUM('auto','custom') NOT NULL DEFAULT 'auto' AFTER image,
  ADD COLUMN cover_short_title VARCHAR(240) NULL AFTER cover_mode,
  ADD COLUMN cover_template VARCHAR(40) NOT NULL DEFAULT 'mihrab-classic' AFTER cover_short_title,
  ADD COLUMN generated_cover VARCHAR(255) NULL AFTER cover_template,
  ADD COLUMN generated_cover_signature CHAR(64) NULL AFTER generated_cover,
  ADD COLUMN generated_cover_updated_at DATETIME NULL AFTER generated_cover_signature,
  ADD INDEX idx_article_cover_mode(cover_mode,generated_cover_updated_at);

ALTER TABLE reviews MODIFY status ENUM('pending','approved','rejected','flagged') NOT NULL DEFAULT 'pending';
ALTER TABLE complaints
  MODIFY status ENUM('new','reviewing','need_info','resolved','rejected','escalated','closed','action_taken') NOT NULL DEFAULT 'new',
  ADD COLUMN assigned_admin_id BIGINT UNSIGNED NULL AFTER status,
  ADD COLUMN priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER assigned_admin_id,
  ADD COLUMN internal_note TEXT NULL AFTER priority,
  ADD INDEX idx_complaint_assignee(assigned_admin_id,status,priority),
  ADD CONSTRAINT fk_complaint_assignee FOREIGN KEY(assigned_admin_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS complaint_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  complaint_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  old_status VARCHAR(40) NULL,
  new_status VARCHAR(40) NULL,
  note VARCHAR(1500) NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_complaint_history(complaint_id,created_at),
  CONSTRAINT fk_complaint_history_complaint FOREIGN KEY(complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
  CONSTRAINT fk_complaint_history_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS center_import_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  filename VARCHAR(255) NOT NULL,
  status ENUM('preview','running','completed','failed','rolled_back') NOT NULL DEFAULT 'preview',
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  created_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_count INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count INT UNSIGNED NOT NULL DEFAULT 0,
  backup_name VARCHAR(255) NULL,
  report_json MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  INDEX idx_import_jobs(created_at,status),
  CONSTRAINT fk_import_job_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS search_synonyms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  term VARCHAR(190) NOT NULL,
  synonym VARCHAR(190) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_search_synonym(term,synonym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS search_queries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  query_text VARCHAR(190) NOT NULL,
  normalized_query VARCHAR(190) NOT NULL,
  result_count INT UNSIGNED NOT NULL DEFAULT 0,
  content_scope VARCHAR(40) NOT NULL DEFAULT 'all',
  country_id INT UNSIGNED NULL,
  region_id INT UNSIGNED NULL,
  city_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_search_normalized(normalized_query,created_at),
  INDEX idx_search_no_results(result_count,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backup_registry (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  backup_name VARCHAR(255) NOT NULL UNIQUE,
  backup_type ENUM('database','media','full') NOT NULL DEFAULT 'full',
  reason VARCHAR(255) NULL,
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  checksum_sha256 CHAR(64) NULL,
  app_version VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  status ENUM('ready','failed','restored') NOT NULL DEFAULT 'ready',
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_backup_registry_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Final v6 editable operational email templates
CREATE TABLE IF NOT EXISTS email_templates (
  template_key VARCHAR(80) PRIMARY KEY,
  subject VARCHAR(255) NOT NULL,
  body_text TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_email_template_user FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO email_templates(template_key,subject,body_text,is_active,updated_at) VALUES
('new_review_admin','تقييم جديد — {{center}}','تم استلام تقييم جديد مرتبط بالسجل: {{center}}\nرقم الطلب: {{id}}\nراجع لوحة الإدارة لاتخاذ الإجراء المناسب.',1,NOW()),
('new_complaint_admin','شكوى جديدة — {{center}}','تم استلام شكوى جديدة مرتبطة بالسجل: {{center}}\nرقم الطلب: {{id}}\nراجع لوحة الإدارة لاتخاذ الإجراء المناسب.',1,NOW()),
('data_report_admin','بلاغ تصحيح بيانات — {{center}}','تم استلام بلاغ تصحيح بيانات مرتبط بالسجل: {{center}}\nرقم الطلب: {{id}}\nراجع لوحة الإدارة لاتخاذ الإجراء المناسب.',1,NOW()),
('complaint_status_user','تحديث شكوى محراب #{{id}}','تم تحديث حالة الشكوى رقم #{{id}} إلى: {{status}}.\n{{note}}',1,NOW());
