<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
use App\Core\Database;

$id=(int)($_SESSION['user_id']??0);
$role=$id?Database::one('SELECT role FROM users WHERE id=?',[$id]):null;
if(!$role || ($role['role']??'')!=='admin'){
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:Tahoma;padding:40px;direction:rtl"><h2>سجّل الدخول كمدير كامل أولًا، ثم افتح upgrade-v42.php.</h2><a href="/login">تسجيل الدخول</a></body>';
    exit;
}

function addColumnIfMissing(string $table,string $column,string $definition): void {
    $x=Database::one('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column]);
    if((int)($x['c']??0)===0) Database::exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}

try{
    addColumnIfMissing('article_translations','hashtags','VARCHAR(500) NULL AFTER `seo_description`');
    addColumnIfMissing('users','email_verified_at','DATETIME NULL AFTER `status`');
    addColumnIfMissing('users','activation_token_hash','CHAR(64) NULL AFTER `email_verified_at`');
    addColumnIfMissing('users','activation_expires_at','DATETIME NULL AFTER `activation_token_hash`');
    addColumnIfMissing('users','password_reset_token_hash','CHAR(64) NULL AFTER `activation_expires_at`');
    addColumnIfMissing('users','password_reset_expires_at','DATETIME NULL AFTER `password_reset_token_hash`');

    Database::exec("CREATE TABLE IF NOT EXISTS notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        type VARCHAR(80) NOT NULL DEFAULT 'info',
        title VARCHAR(190) NOT NULL,
        message TEXT NULL,
        link VARCHAR(500) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id,is_read,created_at),
        CONSTRAINT fk_notifications_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Existing active accounts remain usable. New writer accounts are verified through the activation flow.
    Database::exec("UPDATE users SET email_verified_at=COALESCE(email_verified_at,CURRENT_TIMESTAMP) WHERE status='active'");

    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><style>body{font-family:Tahoma;background:#f3f7f7;color:#183438;padding:40px}.box{max-width:760px;margin:auto;background:#fff;border:1px solid #d5e4e5;border-radius:20px;padding:30px;box-shadow:0 20px 60px rgba(15,72,76,.08)}a{color:#157176}</style><div class="box"><h1>تم تحديث سراديب v4.2 بنجاح</h1><p>تم تجهيز الهشتاغات، إشعارات المراجعة، تأكيد البريد، استعادة كلمة المرور، وتحديثات محرر المقالات والترجمة.</p><p><a href="/admin/articles">الذهاب إلى المقالات</a></p><strong>بعد التأكد من عمل النظام احذف ملف upgrade-v42.php.</strong></div></html>';
}catch(Throwable $e){
    http_response_code(500);
    echo 'Upgrade failed: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8');
}
