<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
use App\Core\Database;
$id=(int)($_SESSION['user_id']??0);
$role=$id?Database::one('SELECT role FROM users WHERE id=?',[$id]):null;
if(!$role || ($role['role']??'')!=='admin'){
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:Tahoma;padding:40px;direction:rtl"><h2>سجّل الدخول كمدير كامل أولًا، ثم افتح upgrade-v4.php مرة أخرى.</h2><a href="/login">تسجيل الدخول</a></body>';
    exit;
}
$statements=[
"ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(60) NULL AFTER email",
"ALTER TABLE pages ADD COLUMN IF NOT EXISTS template ENUM('standard','wide','landing') NOT NULL DEFAULT 'standard' AFTER sort_order",
"ALTER TABLE page_translations ADD COLUMN IF NOT EXISTS content_mode ENUM('visual','html') NOT NULL DEFAULT 'visual' AFTER content",
"ALTER TABLE page_translations ADD COLUMN IF NOT EXISTS custom_css LONGTEXT NULL AFTER content_mode",
"ALTER TABLE page_translations ADD COLUMN IF NOT EXISTS custom_js LONGTEXT NULL AFTER custom_css",
"CREATE TABLE IF NOT EXISTS writer_applications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(160) NOT NULL,email VARCHAR(190) NOT NULL,phone VARCHAR(60) NOT NULL,country VARCHAR(120) NULL,city VARCHAR(120) NULL,topics VARCHAR(500) NULL,experience TEXT NULL,sample_url VARCHAR(500) NULL,bio TEXT NOT NULL,status ENUM('new','reviewed','approved','rejected') NOT NULL DEFAULT 'new',admin_note TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(status),INDEX(email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];
$done=[];
try{
 foreach($statements as $i=>$sql){Database::exec($sql);$done[]=$i+1;}
 echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><style>body{font-family:Tahoma;background:#f5f8f8;color:#173034;padding:40px}.box{max-width:720px;margin:auto;background:#fff;border:1px solid #d7e4e5;border-radius:20px;padding:30px}a{color:#157176}</style><div class="box"><h1>تم تحديث قاعدة بيانات سراديب v4</h1><p>تم تنفيذ '.count($done).' خطوات بنجاح.</p><p><a href="/admin">العودة إلى لوحة التحكم</a></p><strong>بعد التأكد من عمل الموقع احذف ملف upgrade-v4.php.</strong></div></html>';
}catch(Throwable $e){http_response_code(500);echo 'Upgrade failed: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8');}
