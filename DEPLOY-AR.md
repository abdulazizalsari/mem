# رفع الإصلاح — سراديب على Hostinger

الحزمة لا تحتوي: config/local.php، install.php، سكربتات upgrade/repair، ولا محتوى uploads/storage. لذلك لا تمس إعداداتك ولا بياناتك.

1. نسخة احتياطية: اضغط مجلد الدومين ZIP، وصدّر القاعدة من phpMyAdmin (Export).
2. من File Manager ادخل مجلد الدومين (Document Root لـ 99.plipax.com).
3. ارفع هذا الـZIP هناك، ثم Extract مباشرة داخل المجلد واختر استبدال الملفات المتعارضة.
4. تأكد أن المسار النهائي: <مجلد الدومين>/app/bootstrap.php (بدون مجلد متداخل app/app).
5. افتح الموقع. الإصلاح داخل app/migrations.php يضيف العمود تلقائيًا عند أول زيارة. وإن بقي الخطأ، نفّذ database/fix-categories-active.sql من تبويب SQL في phpMyAdmin على القاعدة الصحيحة.
6. لا تشغّل install.php ولا تحذف أي شيء.
