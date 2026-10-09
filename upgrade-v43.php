<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
use App\Core\Database;

$id=(int)($_SESSION['user_id']??0);
$role=$id?Database::one('SELECT role FROM users WHERE id=?',[$id]):null;
if(!$role || ($role['role']??'')!=='admin'){
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:Tahoma;padding:40px;direction:rtl"><h2>سجّل الدخول كمدير كامل أولًا، ثم افتح upgrade-v43.php.</h2><a href="/login">تسجيل الدخول</a></body>';
    exit;
}

function addColumnIfMissing43(string $table,string $column,string $definition): void {
    $x=Database::one('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column]);
    if((int)($x['c']??0)===0) Database::exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}

try{
    addColumnIfMissing43('article_translations','seo_keywords','VARCHAR(500) NULL AFTER `seo_description`');
    addColumnIfMissing43('ads','starts_at','DATETIME NULL AFTER `active`');
    addColumnIfMissing43('ads','ends_at','DATETIME NULL AFTER `starts_at`');
    addColumnIfMissing43('ads','device','VARCHAR(20) NOT NULL DEFAULT "all" AFTER `ends_at`');

    Database::exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        action VARCHAR(120) NOT NULL,
        target_type VARCHAR(80) NULL,
        target_id BIGINT UNSIGNED NULL,
        details TEXT NULL,
        ip_address VARCHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id,created_at), INDEX(action,created_at),
        CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    Database::exec("CREATE TABLE IF NOT EXISTS admin_notes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        assigned_to BIGINT UNSIGNED NULL,
        title VARCHAR(190) NOT NULL,
        body TEXT NULL,
        priority ENUM('normal','important','urgent') NOT NULL DEFAULT 'normal',
        status ENUM('new','doing','done') NOT NULL DEFAULT 'new',
        visibility ENUM('admin','management') NOT NULL DEFAULT 'admin',
        due_at DATETIME NULL,
        pinned TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(status,priority,due_at),
        CONSTRAINT fk_note_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_note_assignee FOREIGN KEY(assigned_to) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    Database::exec("CREATE TABLE IF NOT EXISTS writer_thanks (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(190) NULL,
        message TEXT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(user_id,active),
        CONSTRAINT fk_thank_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    Database::exec("CREATE TABLE IF NOT EXISTS site_notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(190) NOT NULL,
        message TEXT NOT NULL,
        locale VARCHAR(8) NOT NULL DEFAULT '*',
        button_label VARCHAR(100) NULL,
        button_url VARCHAR(500) NULL,
        starts_at DATETIME NULL,
        ends_at DATETIME NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(active,locale,starts_at,ends_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><style>body{font-family:Tahoma;background:#f3f7f7;color:#183438;padding:40px}.box{max-width:780px;margin:auto;background:#fff;border:1px solid #d5e4e5;border-radius:20px;padding:30px;box-shadow:0 20px 60px rgba(15,72,76,.08)}a{color:#157176}</style><div class="box"><h1>تم تحديث سراديب v4.3 بنجاح</h1><p>تم تجهيز سجل النشاط، الملاحظات والمهام، بطاقة شكر الكاتب، كلمات SEO، الإشعارات المنبثقة، الحفظ التلقائي، وتحسينات النسخ الاحتياطي والإعلانات.</p><p><a href="/admin">العودة إلى لوحة الإدارة</a></p><strong>بعد التأكد من عمل النظام احذف ملف upgrade-v43.php.</strong></div></html>';
}catch(Throwable $e){
    http_response_code(500);
    echo 'Upgrade failed: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8');
}
