<?php
declare(strict_types=1);

require __DIR__.'/app/bootstrap.php';

use App\Core\Database;

$uid=(int)($_SESSION['user_id']??0);
$role=$uid?Database::one('SELECT role FROM users WHERE id=?',[$uid]):null;
if(!$role || ($role['role']??'')!=='admin'){
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><body dir="rtl" style="font-family:Tahoma;padding:40px"><h2>سجّل الدخول كمدير كامل أولًا ثم افتح upgrade-v44.php.</h2><a href="/login">تسجيل الدخول</a></body>';
    exit;
}

function table44(string $table): bool {
    $x=Database::one('SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
    return (int)($x['c']??0)>0;
}
function col44(string $table,string $column,string $definition): void {
    if(!table44($table)) return;
    $x=Database::one('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column]);
    if((int)($x['c']??0)===0) Database::exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}
function widenLocale44(string $table): void {
    if(!table44($table)) return;
    try{ Database::exec("ALTER TABLE `{$table}` MODIFY `locale` VARCHAR(20) NOT NULL"); }catch(Throwable){}
}

try{
    // v4 core additions. This file is intentionally self-contained so older patches do not have to be run first.
    col44('users','phone','VARCHAR(60) NULL AFTER `email`');
    col44('pages','template',"ENUM('standard','wide','landing') NOT NULL DEFAULT 'standard' AFTER `sort_order`");
    col44('page_translations','content_mode',"ENUM('visual','html') NOT NULL DEFAULT 'visual' AFTER `content`");
    col44('page_translations','custom_css','LONGTEXT NULL AFTER `content_mode`');
    col44('page_translations','custom_js','LONGTEXT NULL AFTER `custom_css`');

    Database::exec("CREATE TABLE IF NOT EXISTS writer_applications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,email VARCHAR(190) NOT NULL,phone VARCHAR(60) NOT NULL,
        country VARCHAR(120) NULL,city VARCHAR(120) NULL,topics VARCHAR(500) NULL,experience TEXT NULL,
        sample_url VARCHAR(500) NULL,bio TEXT NOT NULL,
        status ENUM('new','reviewed','approved','rejected') NOT NULL DEFAULT 'new',admin_note TEXT NULL,
        verification_token_hash CHAR(64) NULL,verification_expires_at DATETIME NULL,email_verified_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(status),INDEX(email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v4.2 writer/account/editorial additions.
    col44('article_translations','hashtags','VARCHAR(500) NULL AFTER `seo_description`');
    col44('article_translations','seo_keywords','VARCHAR(500) NULL AFTER `seo_description`');
    col44('users','email_verified_at','DATETIME NULL AFTER `status`');
    col44('users','activation_token_hash','CHAR(64) NULL AFTER `email_verified_at`');
    col44('users','activation_expires_at','DATETIME NULL AFTER `activation_token_hash`');
    col44('users','password_reset_token_hash','CHAR(64) NULL AFTER `activation_expires_at`');
    col44('users','password_reset_expires_at','DATETIME NULL AFTER `password_reset_token_hash`');

    Database::exec("CREATE TABLE IF NOT EXISTS notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,
        type VARCHAR(80) NOT NULL DEFAULT 'info',title VARCHAR(190) NOT NULL,message TEXT NULL,link VARCHAR(500) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id,is_read,created_at),CONSTRAINT fk_notifications_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Login throttling exists in recent installs; create it for older ones too.
    Database::exec("CREATE TABLE IF NOT EXISTS auth_throttles (
        throttle_key CHAR(64) PRIMARY KEY,attempts INT UNSIGNED NOT NULL DEFAULT 0,
        last_attempt_at DATETIME NOT NULL,locked_until DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v4.3 operations/editorial additions.
    if(!table44('ads')){
        Database::exec("CREATE TABLE ads (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,slot VARCHAR(80) NOT NULL,locale VARCHAR(20) NOT NULL DEFAULT '*',
            type ENUM('image','code') NOT NULL DEFAULT 'image',label VARCHAR(120) NULL,image_url VARCHAR(500) NULL,
            target_url VARCHAR(500) NULL,code MEDIUMTEXT NULL,active TINYINT(1) NOT NULL DEFAULT 1,
            starts_at DATETIME NULL,ends_at DATETIME NULL,device VARCHAR(20) NOT NULL DEFAULT 'all',
            views BIGINT UNSIGNED NOT NULL DEFAULT 0,clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(slot,locale,active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    col44('ads','starts_at','DATETIME NULL AFTER `active`');
    col44('ads','ends_at','DATETIME NULL AFTER `starts_at`');
    col44('ads','device',"VARCHAR(20) NOT NULL DEFAULT 'all' AFTER `ends_at`");

    Database::exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NULL,action VARCHAR(120) NOT NULL,
        target_type VARCHAR(80) NULL,target_id BIGINT UNSIGNED NULL,details TEXT NULL,ip_address VARCHAR(64) NULL,user_agent VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(user_id,created_at),INDEX(action,created_at),
        CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    Database::exec("CREATE TABLE IF NOT EXISTS admin_notes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,assigned_to BIGINT UNSIGNED NULL,
        title VARCHAR(190) NOT NULL,body TEXT NULL,priority ENUM('normal','important','urgent') NOT NULL DEFAULT 'normal',
        status ENUM('new','doing','done') NOT NULL DEFAULT 'new',visibility ENUM('admin','management') NOT NULL DEFAULT 'admin',
        due_at DATETIME NULL,pinned TINYINT(1) NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(status,priority,due_at),
        CONSTRAINT fk_note_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_note_assignee FOREIGN KEY(assigned_to) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    Database::exec("CREATE TABLE IF NOT EXISTS writer_thanks (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,title VARCHAR(190) NULL,message TEXT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(user_id,active),
        CONSTRAINT fk_thank_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    Database::exec("CREATE TABLE IF NOT EXISTS site_notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,title VARCHAR(190) NOT NULL,message TEXT NOT NULL,
        locale VARCHAR(20) NOT NULL DEFAULT '*',button_label VARCHAR(100) NULL,button_url VARCHAR(500) NULL,
        starts_at DATETIME NULL,ends_at DATETIME NULL,active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(active,locale,starts_at,ends_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // v4.4 languages, media, placements, security, revisions, reporting.
    Database::exec("CREATE TABLE IF NOT EXISTS languages (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,code VARCHAR(20) NOT NULL UNIQUE,name VARCHAR(120) NOT NULL,
        flag VARCHAR(32) NULL,dir ENUM('rtl','ltr') NOT NULL DEFAULT 'ltr',enabled TINYINT(1) NOT NULL DEFAULT 1,
        is_default TINYINT(1) NOT NULL DEFAULT 0,sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(enabled,sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    col44('users','two_factor_enabled','TINYINT(1) NOT NULL DEFAULT 0 AFTER `password_reset_expires_at`');
    col44('users','two_factor_secret','VARCHAR(128) NULL AFTER `two_factor_enabled`');
    col44('users','two_factor_recovery_codes','TEXT NULL AFTER `two_factor_secret`');

    col44('writer_applications','verification_token_hash','CHAR(64) NULL AFTER `admin_note`');
    col44('writer_applications','verification_expires_at','DATETIME NULL AFTER `verification_token_hash`');
    col44('writer_applications','email_verified_at','DATETIME NULL AFTER `verification_expires_at`');

    if(!table44('article_revisions')){
        Database::exec("CREATE TABLE article_revisions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,article_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,
            locale VARCHAR(20) NOT NULL,title VARCHAR(255) NULL,excerpt TEXT NULL,content LONGTEXT NULL,
            seo_title VARCHAR(255) NULL,seo_description VARCHAR(320) NULL,seo_keywords VARCHAR(500) NULL,hashtags VARCHAR(500) NULL,
            featured_image VARCHAR(500) NULL,category_id BIGINT UNSIGNED NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(article_id) REFERENCES articles(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    col44('article_revisions','excerpt','TEXT NULL AFTER `title`');
    col44('article_revisions','seo_title','VARCHAR(255) NULL AFTER `content`');
    col44('article_revisions','seo_description','VARCHAR(320) NULL AFTER `seo_title`');
    col44('article_revisions','seo_keywords','VARCHAR(500) NULL AFTER `seo_description`');
    col44('article_revisions','hashtags','VARCHAR(500) NULL AFTER `seo_keywords`');
    col44('article_revisions','featured_image','VARCHAR(500) NULL AFTER `hashtags`');
    col44('article_revisions','category_id','BIGINT UNSIGNED NULL AFTER `featured_image`');

    col44('ads','views','BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `device`');
    col44('ads','clicks','BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `views`');

    if(!table44('media')){
        Database::exec("CREATE TABLE media (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NULL,path VARCHAR(500) NOT NULL,mime VARCHAR(120) NOT NULL,
            alt_text VARCHAR(255) NULL,title VARCHAR(190) NULL,original_name VARCHAR(255) NULL,width INT UNSIGNED NULL,height INT UNSIGNED NULL,
            size_bytes BIGINT UNSIGNED NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    col44('media','title','VARCHAR(190) NULL AFTER `alt_text`');
    col44('media','original_name','VARCHAR(255) NULL AFTER `title`');
    col44('media','width','INT UNSIGNED NULL AFTER `original_name`');
    col44('media','height','INT UNSIGNED NULL AFTER `width`');
    col44('media','size_bytes','BIGINT UNSIGNED NULL AFTER `height`');
    col44('media','updated_at','TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');

    Database::exec("CREATE TABLE IF NOT EXISTS site_blocks (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,type ENUM('image','ad') NOT NULL DEFAULT 'image',slot VARCHAR(80) NOT NULL,
        locale VARCHAR(20) NOT NULL DEFAULT '*',title VARCHAR(190) NULL,image_url VARCHAR(500) NULL,target_url VARCHAR(500) NULL,
        ad_id BIGINT UNSIGNED NULL,sort_order INT NOT NULL DEFAULT 0,active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(slot,locale,active,sort_order),CONSTRAINT fk_siteblock_ad FOREIGN KEY(ad_id) REFERENCES ads(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    widenLocale44('category_translations');
    widenLocale44('article_translations');
    widenLocale44('page_translations');
    if(table44('ads')){ try{Database::exec("ALTER TABLE ads MODIFY locale VARCHAR(20) NOT NULL DEFAULT '*'");}catch(Throwable){} }
    if(table44('site_notifications')){ try{Database::exec("ALTER TABLE site_notifications MODIFY locale VARCHAR(20) NOT NULL DEFAULT '*'");}catch(Throwable){} }

    if((int)(Database::one('SELECT COUNT(*) c FROM languages')['c']??0)===0){
        $i=0;
        foreach(config('locales',[]) as $code=>$info){
            $flag=['ar'=>'🇸🇦','en'=>'🇬🇧','es'=>'🇪🇸','ru'=>'🇷🇺'][$code]??'🌐';
            Database::insert('INSERT INTO languages(code,name,flag,dir,enabled,is_default,sort_order) VALUES(?,?,?,?,1,?,?)',[
                $code,$info['name']??strtoupper($code),$flag,$info['dir']??'ltr',$code===(string)config('default_locale','ar')?1:0,$i++
            ]);
        }
    }

    // Existing live accounts/applications should keep working after the one-step upgrade.
    Database::exec("UPDATE users SET email_verified_at=COALESCE(email_verified_at,CURRENT_TIMESTAMP) WHERE status='active'");
    Database::exec("UPDATE writer_applications SET email_verified_at=COALESCE(email_verified_at,created_at) WHERE verification_token_hash IS NULL AND email_verified_at IS NULL");

    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><style>body{font-family:Tahoma;background:#f5f8f8;color:#173034;padding:40px}.box{max-width:860px;margin:auto;background:#fff;border:1px solid #d7e4e5;border-radius:22px;padding:32px;box-shadow:0 20px 60px #0f4c5015}a{color:#157176}.ok{background:#edf9f9;border:1px solid #c5e7e8;border-radius:14px;padding:15px}</style><div class="box"><h1>تم تحديث سراديب v4.4 بنجاح</h1><p class="ok">هذا التحديث شامل ويمكن تشغيله مباشرة حتى لو لم تُشغّل ترقيات v4 وv4.2 وv4.3 السابقة.</p><p>تم تجهيز إدارة اللغات، مكتبة الوسائط، أقسام الصور والإعلانات، استرجاع النسخ الاحتياطية، التحقق بخطوتين، نسخ المقالات، إحصاءات الإعلانات، تأكيد بريد طلب الكاتب، الملاحظات والإشعارات وسجل النشاط.</p><p><a href="/admin">العودة إلى لوحة الإدارة</a></p><strong>بعد التأكد من عمل النظام احذف upgrade-v44.php.</strong></div></html>';
}catch(Throwable $e){
    http_response_code(500);
    echo 'Upgrade failed: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8');
}
