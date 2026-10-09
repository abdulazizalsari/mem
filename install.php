<?php
declare(strict_types=1);

$root = __DIR__;
$configPath = $root . '/private/config.php';
$scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/install.php')));
$installBase = ($scriptDir === '/' || $scriptDir === '.') ? '' : '/' . trim($scriptDir, '/');
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');

ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
session_name('mihrab_install_session');
session_set_cookie_params(['lifetime'=>0,'path'=>$installBase ?: '/','secure'=>$isHttps,'httponly'=>true,'samesite'=>'Strict']);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['install_csrf'])) $_SESSION['install_csrf'] = bin2hex(random_bytes(32));

$requiredExtensions = ['pdo_mysql' => 'PDO MySQL', 'mbstring' => 'Mbstring', 'fileinfo' => 'Fileinfo'];
$missingExtensions = [];
foreach ($requiredExtensions as $extension => $label) {
    if (!extension_loaded($extension)) $missingExtensions[] = $label;
}
if ($missingExtensions) {
    http_response_code(500);
    exit('لا يمكن تثبيت محراب قبل تفعيل امتدادات PHP التالية: ' . implode('، ', $missingExtensions));
}
if (file_exists($configPath) || getenv('DB_NAME') !== false || (($externalConfig = getenv('MIHRAB_CONFIG_FILE')) !== false && trim((string)$externalConfig) !== '' && is_file((string)$externalConfig))) {
    http_response_code(404);
    exit;
}

function installer_slug(string $text): string {
    $original = trim($text);
    $text = trim(mb_strtolower($original, 'UTF-8'));
    $text = preg_replace('/[^\p{L}\p{N}]+/u','-',$text) ?? '';
    $text = trim($text,'-');
    return $text !== '' ? $text : 'item-'.substr(hash('sha256',$original),0,8);
}

function installer_valid_url(string $url): bool {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http','https'], true);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = (string)($_POST['_csrf'] ?? '');
        if ($csrf === '' || !hash_equals((string)$_SESSION['install_csrf'], $csrf)) throw new InvalidArgumentException('انتهت صلاحية نموذج التثبيت. أعد تحميل الصفحة.');

        $host = trim((string)($_POST['db_host'] ?? 'localhost'));
        $port = trim((string)($_POST['db_port'] ?? '3306'));
        $name = trim((string)($_POST['db_name'] ?? ''));
        $user = trim((string)($_POST['db_user'] ?? ''));
        $pass = (string)($_POST['db_pass'] ?? '');
        $siteUrl = rtrim(trim((string)($_POST['site_url'] ?? '')), '/');
        $adminName = trim((string)($_POST['admin_name'] ?? 'المدير'));
        $adminEmail = trim((string)($_POST['admin_email'] ?? ''));
        $adminPass = (string)($_POST['admin_password'] ?? '');

        if ($host === '' || str_contains($host, ';') || str_contains($host, "\0")) throw new InvalidArgumentException('خادم قاعدة البيانات غير صالح.');
        if (!preg_match('/^\d{1,5}$/', $port) || (int)$port < 1 || (int)$port > 65535) throw new InvalidArgumentException('منفذ قاعدة البيانات غير صالح.');
        if ($name === '' || $user === '' || str_contains($name, ';') || str_contains($name, "\0")) throw new InvalidArgumentException('تحقق من اسم قاعدة البيانات والمستخدم.');
        if (!installer_valid_url($siteUrl)) throw new InvalidArgumentException('رابط الموقع يجب أن يكون رابط http/https كاملًا.');
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || strlen($adminPass) < 10 || !preg_match('/[A-Z]/',$adminPass) || !preg_match('/[a-z]/',$adminPass) || !preg_match('/\d/',$adminPass) || !preg_match('/[^A-Za-z0-9]/',$adminPass)) throw new InvalidArgumentException('تحقق من بريد المدير. كلمة المرور يجب أن تكون 10 أحرف على الأقل وتحتوي حرفًا كبيرًا وصغيرًا ورقمًا ورمزًا.');
        if (!is_dir(dirname($configPath)) || !is_writable(dirname($configPath))) throw new RuntimeException('مجلد private غير قابل للكتابة أثناء التثبيت.');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_TIMEOUT=>8,
        ]);

        $schema = file_get_contents($root . '/schema.sql');
        if ($schema === false) throw new RuntimeException('تعذر قراءة مخطط قاعدة البيانات.');
        foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [])) as $sql) $pdo->exec($sql);

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("INSERT INTO users(name,email,password_hash,role,status,created_at) VALUES(?,?,?,'super_admin','active',NOW())");
            $st->execute([$adminName,$adminEmail,password_hash($adminPass,PASSWORD_ARGON2ID)]);

            $defaults = [
                'site_name'=>'محراب','primary_color'=>'#123B3A','secondary_color'=>'#287A64','accent_color'=>'#C6A15B','background_color'=>'#F7F4ED','text_color'=>'#202826','radius'=>'10','font_family'=>'Tajawal',
                'admin_email'=>$adminEmail,'reviews_enabled'=>'1','seo_indexing'=>'1','title_suffix'=>'محراب','session_idle_minutes'=>'120','login_max_attempts'=>'5','login_lock_minutes'=>'15',
                'footer_about'=>'دليل عربي منظم يساعد المستخدم على الوصول إلى معلومات الرقاة ومراكز الرقية بصورة أوضح وأسهل.',
                'meta_description'=>'محراب — دليل عربي منظم للرقاة ومراكز الرقية، تُضاف ملفاته وتُراجع بياناتها بواسطة إدارة المنصة.',
                'blog_title'=>'مركز التوعية','blog_intro'=>'مقالات تساعد المستخدم على اتخاذ خطوات أكثر وضوحًا وحماية معلوماته الشخصية.','blog_per_page'=>'12',
                'blog_show_author'=>'1','blog_show_date'=>'1','blog_show_reading_time'=>'1','blog_show_views'=>'1','blog_show_share'=>'1','blog_show_related'=>'1','blog_show_toc'=>'1','blog_default_image'=>'','blog_intro_page_slug'=>''
            ];
            $st = $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
            foreach($defaults as $k=>$v) $st->execute([$k,$v]);

            $countries = ['السعودية','مصر','الأردن','سوريا','لبنان','فلسطين','العراق','الكويت','قطر','البحرين','الإمارات','عُمان','اليمن','المغرب','الجزائر','تونس','ليبيا','السودان','موريتانيا','الصومال','جيبوتي','جزر القمر'];
            $st = $pdo->prepare("INSERT IGNORE INTO countries(name,slug,is_active) VALUES(?,?,1)");
            foreach($countries as $c) $st->execute([$c,installer_slug($c)]);

            $specs=['الرقية الشرعية','العين والحسد','السحر','الوسواس','الحجامة','مركز رقية'];
            $st=$pdo->prepare("INSERT IGNORE INTO specialties(name,slug,is_active) VALUES(?,?,1)");
            foreach($specs as $s) $st->execute([$s,installer_slug($s)]);

            $cats=['تنبيهات وتحذيرات','الرقية الشرعية','الدجل والشعوذة','كيف تختار راقيًا أو مركزًا بعناية','أسئلة شائعة','التوعية الصحية'];
            $st=$pdo->prepare("INSERT IGNORE INTO article_categories(name,slug) VALUES(?,?)");
            foreach($cats as $c) $st->execute([$c,installer_slug($c)]);

            $pdo->exec("INSERT IGNORE INTO languages(code,name,is_default,is_active,sort_order) VALUES('ar','العربية',1,1,0)");

            $nav = [
                ['header','الرئيسية','/',10,1,0],['header','دليل الرقاة','/directory?type=person',20,1,0],['header','المراكز','/directory?type=center',30,1,0],['header','المقالات','/articles',40,1,0],['header','عن محراب','/about',50,1,0],
                ['footer','الدليل','/directory',10,1,0],['footer','مركز التوعية','/articles',20,1,0],['footer','كيف نراجع الملفات؟','/how-we-verify',30,1,0],['footer','الخصوصية','/privacy',40,1,0],['footer','الشروط','/terms',50,1,0],
            ];
            $navSt=$pdo->prepare("INSERT INTO navigation_items(location,label,url,sort_order,is_active,new_tab,created_at,updated_at) VALUES(?,?,?,?,?,?,NOW(),NOW())");
            foreach($nav as $n) $navSt->execute($n);
            // Keep the legacy seed for rollback, then mirror it into the central menu system created by schema 006.
            try {
                $pdo->exec("INSERT INTO menu_items(menu_id,item_type,label,url,target,display_style,is_active,show_desktop,show_mobile,sort_order,legacy_navigation_id,created_at,updated_at) SELECT m.id,CASE WHEN n.url LIKE 'http://%' OR n.url LIKE 'https://%' THEN 'external' ELSE 'custom' END,n.label,n.url,IF(n.new_tab=1,'new','same'),IF(n.is_cta=1,'primary','link'),n.is_active,1,1,n.sort_order,n.id,NOW(),NOW() FROM navigation_items n JOIN menus m ON m.slug=CASE n.location WHEN 'header' THEN 'main-navigation' WHEN 'footer' THEN 'footer-navigation' ELSE 'social-navigation' END WHERE NOT EXISTS(SELECT 1 FROM menu_items mi WHERE mi.legacy_navigation_id=n.id)");
                $pdo->exec("UPDATE menu_items mi JOIN pages p ON p.route_path=mi.url AND p.deleted_at IS NULL SET mi.item_type='page',mi.page_id=p.id,mi.url=NULL WHERE mi.item_type='custom' AND mi.url LIKE '/%'");
            } catch (Throwable $e) { /* Schema 006 is optional during a partial/legacy install. */ }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $basePath = (string)(parse_url($siteUrl, PHP_URL_PATH) ?? '');
        $basePath = ($basePath === '' || $basePath === '/') ? '' : '/' . trim($basePath, '/');
        $config = "<?php\nreturn " . var_export([
            'db'=>['host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass,'charset'=>'utf8mb4'],
            'app'=>['url'=>$siteUrl,'base_path'=>$basePath,'name'=>'محراب','locale'=>'ar','timezone'=>'Asia/Riyadh','debug'=>false,'environment'=>'production'],
            'storage'=>['public_path'=>'uploads','public_url'=>'/uploads','private_path'=>'storage','backup_path'=>'storage/backups'],
            'mail'=>['driver'=>'smtp','host'=>'','port'=>587,'encryption'=>'tls','username'=>'','password'=>'','from_address'=>'','from_name'=>'محراب'],
            'security'=>['trust_proxy'=>false,'hsts'=>false,'hsts_max_age'=>15552000],
        ], true) . ";\n";
        $tmp = tempnam(dirname($configPath), 'mihrab-config-');
        if ($tmp === false || file_put_contents($tmp, $config, LOCK_EX) === false) throw new RuntimeException('تعذر إنشاء ملف إعدادات التشغيل.');
        @chmod($tmp, 0600);
        if (!rename($tmp, $configPath)) { @unlink($tmp); throw new RuntimeException('تعذر تثبيت ملف إعدادات التشغيل.'); }
        @chmod($configPath, 0600);

        session_regenerate_id(true);
        header('Location: ' . ($installBase ?: '') . '/admin/login?installed=1');
        exit;
    } catch (Throwable $e) {
        error_log('Mihrab installer error: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'تعذر إكمال التثبيت. راجع بيانات قاعدة البيانات وصلاحيات المجلدات ثم راجع سجل أخطاء PHP إذا استمرت المشكلة.';
    }
}
?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تثبيت محراب</title><link rel="stylesheet" href="<?= htmlspecialchars(($installBase ?: '').'/assets/css/app.css',ENT_QUOTES,'UTF-8') ?>"></head>
<body><section class="login-page"><form class="login-card" method="post"><h1>تثبيت محراب</h1><?php if($error): ?><div class="flash error"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
<input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)$_SESSION['install_csrf'],ENT_QUOTES,'UTF-8') ?>">
<label>خادم MySQL<input name="db_host" value="localhost" required></label><label>المنفذ<input name="db_port" inputmode="numeric" value="3306" required></label><label>اسم قاعدة البيانات<input name="db_name" required></label><label>مستخدم قاعدة البيانات<input name="db_user" required></label><label>كلمة مرور قاعدة البيانات<input type="password" name="db_pass" autocomplete="new-password"></label><label>رابط الموقع<input name="site_url" placeholder="https://example.com أو https://example.com/mihrab" required></label><hr><label>اسم المدير<input name="admin_name" required></label><label>بريد المدير<input type="email" name="admin_email" required></label><label>كلمة مرور المدير<input type="password" name="admin_password" minlength="10" autocomplete="new-password" required></label><button class="btn btn-primary full">تثبيت محراب</button></form></section></body></html>
