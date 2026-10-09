Saradeeeb UI Recovery v1.3

هذه الحزمة تحتوي على مجلد app كامل (وليس ملفات تحديث جزئية)، إضافة إلى assets و index.php و .htaccess.
لا تحتوي على config/local.php ولا database ولا install.php، لذلك لا تغيّر إعدادات قاعدة البيانات أو بياناتها.

الهدف:
- استعادة ملفات التطبيق الأساسية إذا تم استبدال مجلد app بالكامل أثناء تحديث v1.2.
- الإبقاء على تصميم لوحة التحكم الجديد.
- إبقاء تسجيل الدخول على الدومين الحالي تلقائيًا.

طريقة آمنة على Hostinger:
1) ارفع ZIP داخل /public_html.
2) فك الضغط إلى مجلد جديد باسم saradeeeb-fix (لا تفكه مباشرة فوق الموقع).
3) افتح saradeeeb-fix.
4) انقل app و assets و index.php و .htaccess إلى /public_html.
5) اختر Replace all files in destination folder عند التعارض.
6) لا تلمس config/local.php ولا قاعدة البيانات.
