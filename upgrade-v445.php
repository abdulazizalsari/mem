<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
use App\Core\Database;

$uid=(int)($_SESSION['user_id']??0);
$role=$uid?Database::one('SELECT role FROM users WHERE id=?',[$uid]):null;
if(!$role || ($role['role']??'')!=='admin'){
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><body dir="rtl" style="font-family:Tahoma;padding:40px"><h2>سجّل الدخول بحساب المدير الكامل أولًا.</h2><p>بعد تسجيل الدخول افتح هذه الصفحة مرة أخرى.</p><a href="/login">تسجيل الدخول</a></body>';
    exit;
}

function rvTable(string $table): bool {
    $x=Database::one('SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
    return (int)($x['c']??0)>0;
}
function rvCol(string $table,string $column,string $definition): void {
    if(!rvTable($table)) return;
    $x=Database::one('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column]);
    if((int)($x['c']??0)===0) Database::exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}
function rvWidenLocale(string $table): void {
    if(!rvTable($table)) return;
    try{Database::exec("ALTER TABLE `{$table}` MODIFY `locale` VARCHAR(20) NOT NULL");}catch(Throwable){}
}

try{
    // Core columns used by current controllers/views.
    rvCol('users','phone','VARCHAR(60) NULL AFTER `email`');
    rvCol('users','email_verified_at','DATETIME NULL AFTER `status`');
    rvCol('users','activation_token_hash','CHAR(64) NULL AFTER `email_verified_at`');
    rvCol('users','activation_expires_at','DATETIME NULL AFTER `activation_token_hash`');
    rvCol('users','password_reset_token_hash','CHAR(64) NULL AFTER `activation_expires_at`');
    rvCol('users','password_reset_expires_at','DATETIME NULL AFTER `password_reset_token_hash`');
    rvCol('users','two_factor_enabled','TINYINT(1) NOT NULL DEFAULT 0 AFTER `password_reset_expires_at`');
    rvCol('users','two_factor_secret','VARCHAR(128) NULL AFTER `two_factor_enabled`');
    rvCol('users','two_factor_recovery_codes','TEXT NULL AFTER `two_factor_secret`');
    rvCol('users','last_login_at','DATETIME NULL AFTER `avatar`');
    rvCol('users','updated_at','TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');

    rvCol('articles','category_id','BIGINT UNSIGNED NULL AFTER `author_id`');
    rvCol('articles','status',"ENUM('draft','review','changes','published','archived') NOT NULL DEFAULT 'draft' AFTER `category_id`");
    rvCol('articles','is_featured','TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`');
    rvCol('articles','featured_image','VARCHAR(500) NULL AFTER `is_featured`');
    rvCol('articles','legacy_path','VARCHAR(255) NULL AFTER `featured_image`');
    rvCol('articles','views','BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `legacy_path`');
    rvCol('articles','published_at','DATETIME NULL AFTER `views`');
    rvCol('articles','updated_at','TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');

    rvCol('article_translations','excerpt','TEXT NULL AFTER `slug`');
    rvCol('article_translations','seo_title','VARCHAR(255) NULL AFTER `content`');
    rvCol('article_translations','seo_description','VARCHAR(320) NULL AFTER `seo_title`');
    rvCol('article_translations','seo_keywords','VARCHAR(500) NULL AFTER `seo_description`');
    rvCol('article_translations','hashtags','VARCHAR(500) NULL AFTER `seo_keywords`');
    rvCol('article_translations','og_image','VARCHAR(500) NULL AFTER `hashtags`');
    rvCol('article_translations','status',"ENUM('draft','published') NOT NULL DEFAULT 'draft' AFTER `og_image`");
    rvCol('article_translations','updated_at','TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');

    rvCol('pages','template',"ENUM('standard','wide','landing') NOT NULL DEFAULT 'standard' AFTER `sort_order`");
    rvCol('page_translations','content_mode',"ENUM('visual','html') NOT NULL DEFAULT 'visual' AFTER `content`");
    rvCol('page_translations','custom_css','LONGTEXT NULL AFTER `content_mode`');
    rvCol('page_translations','custom_js','LONGTEXT NULL AFTER `custom_css`');

    Database::exec("CREATE TABLE IF NOT EXISTS auth_throttles (throttle_key CHAR(64) PRIMARY KEY,attempts INT UNSIGNED NOT NULL DEFAULT 0,last_attempt_at DATETIME NOT NULL,locked_until DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    Database::exec("CREATE TABLE IF NOT EXISTS notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,type VARCHAR(80) NOT NULL DEFAULT 'info',title VARCHAR(190) NOT NULL,message TEXT NULL,link VARCHAR(500) NULL,is_read TINYINT(1) NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(user_id,is_read,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    Database::exec("CREATE TABLE IF NOT EXISTS writer_thanks (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,title VARCHAR(190) NULL,message TEXT NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(user_id,active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    Database::exec("CREATE TABLE IF NOT EXISTS audit_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NULL,action VARCHAR(120) NOT NULL,target_type VARCHAR(80) NULL,target_id BIGINT UNSIGNED NULL,details TEXT NULL,ip_address VARCHAR(64) NULL,user_agent VARCHAR(255) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(user_id,created_at),INDEX(action,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    Database::exec("CREATE TABLE IF NOT EXISTS admin_notes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,assigned_to BIGINT UNSIGNED NULL,title VARCHAR(190) NOT NULL,body TEXT NULL,priority ENUM('normal','important','urgent') NOT NULL DEFAULT 'normal',status ENUM('new','doing','done') NOT NULL DEFAULT 'new',visibility ENUM('admin','management') NOT NULL DEFAULT 'admin',due_at DATETIME NULL,pinned TINYINT(1) NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(status,priority,due_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    Database::exec("CREATE TABLE IF NOT EXISTS site_notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,title VARCHAR(190) NOT NULL,message TEXT NOT NULL,locale VARCHAR(20) NOT NULL DEFAULT '*',button_label VARCHAR(100) NULL,button_url VARCHAR(500) NULL,starts_at DATETIME NULL,ends_at DATETIME NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(active,locale,starts_at,ends_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    Database::exec("CREATE TABLE IF NOT EXISTS languages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,code VARCHAR(20) NOT NULL UNIQUE,name VARCHAR(120) NOT NULL,flag VARCHAR(255) NULL,dir ENUM('rtl','ltr') NOT NULL DEFAULT 'ltr',enabled TINYINT(1) NOT NULL DEFAULT 1,is_default TINYINT(1) NOT NULL DEFAULT 0,sort_order INT NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(enabled,sort_order)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if(!rvTable('writer_applications')){
      Database::exec("CREATE TABLE writer_applications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(160) NOT NULL,email VARCHAR(190) NOT NULL,phone VARCHAR(60) NOT NULL,country VARCHAR(120) NULL,city VARCHAR(120) NULL,topics VARCHAR(500) NULL,experience TEXT NULL,sample_url VARCHAR(500) NULL,bio TEXT NOT NULL,status ENUM('new','reviewed','approved','rejected') NOT NULL DEFAULT 'new',admin_note TEXT NULL,verification_token_hash CHAR(64) NULL,verification_expires_at DATETIME NULL,email_verified_at DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(status),INDEX(email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
      rvCol('writer_applications','verification_token_hash','CHAR(64) NULL AFTER `admin_note`');
      rvCol('writer_applications','verification_expires_at','DATETIME NULL AFTER `verification_token_hash`');
      rvCol('writer_applications','email_verified_at','DATETIME NULL AFTER `verification_expires_at`');
    }

    if(!rvTable('article_revisions')){
      Database::exec("CREATE TABLE article_revisions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,article_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,locale VARCHAR(20) NOT NULL,title VARCHAR(255) NULL,excerpt TEXT NULL,content LONGTEXT NULL,seo_title VARCHAR(255) NULL,seo_description VARCHAR(320) NULL,seo_keywords VARCHAR(500) NULL,hashtags VARCHAR(500) NULL,featured_image VARCHAR(500) NULL,category_id BIGINT UNSIGNED NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(article_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if(!rvTable('ads')){
      Database::exec("CREATE TABLE ads (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,slot VARCHAR(80) NOT NULL,locale VARCHAR(20) NOT NULL DEFAULT '*',type ENUM('image','code') NOT NULL DEFAULT 'image',label VARCHAR(120) NULL,image_url VARCHAR(500) NULL,target_url VARCHAR(500) NULL,code MEDIUMTEXT NULL,active TINYINT(1) NOT NULL DEFAULT 1,starts_at DATETIME NULL,ends_at DATETIME NULL,device VARCHAR(20) NOT NULL DEFAULT 'all',views BIGINT UNSIGNED NOT NULL DEFAULT 0,clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(slot,locale,active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
      rvCol('ads','starts_at','DATETIME NULL AFTER `active`'); rvCol('ads','ends_at','DATETIME NULL AFTER `starts_at`'); rvCol('ads','device',"VARCHAR(20) NOT NULL DEFAULT 'all' AFTER `ends_at`"); rvCol('ads','views','BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `device`'); rvCol('ads','clicks','BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `views`');
    }

    if(!rvTable('media')) Database::exec("CREATE TABLE media (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NULL,path VARCHAR(500) NOT NULL,mime VARCHAR(120) NOT NULL,alt_text VARCHAR(255) NULL,title VARCHAR(190) NULL,original_name VARCHAR(255) NULL,width INT UNSIGNED NULL,height INT UNSIGNED NULL,size_bytes BIGINT UNSIGNED NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(user_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    Database::exec("CREATE TABLE IF NOT EXISTS site_blocks (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,type ENUM('image','ad') NOT NULL DEFAULT 'image',slot VARCHAR(80) NOT NULL,locale VARCHAR(20) NOT NULL DEFAULT '*',title VARCHAR(190) NULL,image_url VARCHAR(500) NULL,target_url VARCHAR(500) NULL,ad_id BIGINT UNSIGNED NULL,sort_order INT NOT NULL DEFAULT 0,active TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(slot,locale,active,sort_order)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    rvWidenLocale('category_translations'); rvWidenLocale('article_translations'); rvWidenLocale('page_translations');

    if((int)(Database::one('SELECT COUNT(*) c FROM languages')['c']??0)===0){
      $langs=['ar'=>['العربية','sy','rtl',1,0],'en'=>['English','gb','ltr',0,1],'es'=>['Español','es','ltr',0,2],'ru'=>['Русский','ru','ltr',0,3]];
      foreach($langs as $code=>$v) Database::insert('INSERT INTO languages(code,name,flag,dir,enabled,is_default,sort_order) VALUES(?,?,?,?,1,?,?)',[$code,$v[0],$v[1],$v[2],$v[3],$v[4]]);
    } else {
      // Switch the Arabic language to the new Syrian flag without touching other languages.
      try{Database::exec("UPDATE languages SET flag='sy' WHERE code='ar'");}catch(Throwable){}
    }

    try{Database::exec("UPDATE users SET email_verified_at=COALESCE(email_verified_at,CURRENT_TIMESTAMP) WHERE status='active'");}catch(Throwable){}

    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><style>body{font-family:Tahoma;background:#f4f8f8;color:#173034;padding:40px}.box{max-width:850px;margin:auto;background:#fff;border:1px solid #d7e5e6;border-radius:22px;padding:32px;box-shadow:0 20px 60px #0f4c5018}.ok{padding:14px;border-radius:13px;background:#edf9f9;border:1px solid #c9e9ea}a{color:#157176}</style><div class="box"><h1>تم تحديث قاعدة سراديب v4.4.5 بنجاح</h1><p class="ok">تم فحص وإضافة جميع الأعمدة والجداول المطلوبة للإصدار التجميعي v4.4.5.</p><p><a href="/admin/articles">اختبار صفحة المقالات</a> · <a href="/admin/thanks">اختبار بطاقات الشكر</a> · <a href="/admin">لوحة التحكم</a></p><p><strong>بعد التأكد من العمل احذف upgrade-v445.php.</strong></p></div></html>';
} catch(Throwable $e){
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><body dir="rtl" style="font-family:Tahoma;padding:40px"><h2>تعذر إكمال الإصلاح</h2><pre style="white-space:pre-wrap">'.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8').'</pre></body>';
}
