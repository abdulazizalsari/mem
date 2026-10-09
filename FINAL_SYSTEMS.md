# محراب v6 — الأنظمة النهائية

هذه الوثيقة تلخص الطبقات التي تم دمجها في النسخة النهائية فوق v5 بدون إنشاء أنظمة موازية للبحث أو الوسائط أو الصفحات أو المدونة.

## الجغرافيا

- Country → Region/Province/State → City → Center/Provider.
- المحافظة على العنوان الحر: الحي، الشارع، المعلم، العنوان التفصيلي، الرمز البريدي.
- إحداثيات Latitude/Longitude اختيارية.
- استيراد/تصدير جغرافي CSV/XLSX مع Preview قبل التأكيد.
- تعطيل السجل الجغرافي بدل الحذف الخطر عندما يكون مرتبطًا ببيانات.
- صفحات عامة منظمة للدولة والمنطقة والمدينة.

## المركز / مقدم الخدمة

- حالات المحتوى منفصلة عن التحقق: Published / Suspended / Archived لا تعني Verified.
- Verification مستقل وله سجل تاريخي.
- Recommended وFeatured مستقلان عن Verification.
- Slug history + 301.
- Trash / Restore / Permanent Delete محمي، مع فحص العلاقات قبل الحذف النهائي.
- إحصاءات حقيقية للمشاهدات ونقرات الاتصال/واتساب.

## البطاقة المركزية

`templates/listing_card.php` هو Component العرض الموحد، ويستخدم في القوائم والبحث والصفحات الجغرافية والمحتوى المرتبط. يتعامل مع الصورة المفقودة، التقييم غير الموجود، بيانات الاتصال الناقصة، وحالات التحقق/التوصية/التمييز كل على حدة. إعدادات العرض العامة موجودة في إعدادات الموقع بدل تصميم بطاقة مختلفة لكل مركز.

## التقييمات والشكاوى والبلاغات

- التقييمات تمر بمراجعة Pending/Approved/Rejected/Flagged.
- متوسط التقييم يحسب من Approved فقط.
- الشكاوى خاصة بالإدارة ولها Status/Priority/Assignee/Internal Note + History.
- بلاغ تصحيح البيانات يذهب للمراجعة ولا يغيّر بيانات المركز آليًا.
- النماذج العامة تستخدم CSRF + Validation + Rate Limiting.

## البريد

تمت إضافة قوالب بريد قابلة للتعديل للأحداث التشغيلية الشائعة، مع متغيرات محدودة ومعروفة. عناوين الإدارة تأتي من الإعدادات ولا تُكتب داخل الكود.

## الاستيراد والتصدير

- مركز Import/Export واحد للمراكز.
- XLSX أساسي وCSV مدعوم عند الحاجة.
- Stable IDs، وعدم المطابقة بالاسم وحده.
- Empty cell يحتفظ بالقيمة القديمة افتراضيًا.
- Preview إلزامي قبل Confirm.
- Geography/Category validation.
- Backup/Rollback point قبل تنفيذ Import المؤكد.
- Import history/report.
- Export يمكن فلترته بالحالة والجغرافيا والتصنيف والبحث الحالي.

## النسخ الاحتياطي والاستعادة

- Database / Media / Full.
- تخزين النسخ في مساحة خاصة غير عامة.
- Registry + SHA-256 عند توفره + Manifest validation.
- Restore للمدير الأعلى فقط.
- Compatibility check + Pre-Restore backup + Maintenance mode + Restore + Cache clear + System checks.
- Retention وسياسة Scheduled Backup قابلة للضبط.

## البحث

يتم استخدام محرك البحث المركزي الموجود بدل إنشاء محرك آخر. v6 يضيف إليه الحقول الجغرافية والعنوان والكلمات المفتاحية وحالات النشر، وSearch Analytics وSynonyms. النتائج العامة تستبعد السجلات غير المنشورة أو المحذوفة.

## SEO / GEO / AEO

- Metadata / Canonical / Robots.
- Sitemap / robots.txt / llms.txt / RSS / Web Manifest.
- JSON-LD مركزي حسب نوع الصفحة والبيانات الحقيقية.
- صفحات البحث الديناميكية المفلترة لا تُفهرس عشوائيًا.
- صفحات الدول والمدن والمراكز والمقالات تستخدم URLs مستقرة.
- إعداد سياسة AI crawlers وIndexNow من طبقة SEO.

راجع `SEO_GUIDE.md` للتفاصيل وخطة 90 يومًا.

## الصلاحيات والأمان

- Authorization على الخادم لكل إجراء حساس.
- صلاحيات مستقلة للإدارة/التحقق/الاستيراد-التصدير/الصيانة/النسخ.
- Audit Log للأحداث المهمة.
- رفع الملفات وإدارة الوسائط تستخدم المكتبة المركزية الموجودة.
- لا كلمات مرور أو Tokens أو Secrets داخل النسخ القابلة للتنزيل أو السجلات الإدارية.

## الصيانة والمراقبة

- System Status.
- Safe log viewer.
- Cache clear.
- Maintenance Mode.
- Search Analytics.
- Scheduled Backup CLI.
- IndexNow submission CLI.
- Final read-only preflight CLI.

## ملفات الإصدار

- Migration للموقع القائم: `migrations/007_final_project.sql`
- فحص نهائي: `php tools/final_preflight.php`
- تدريب Staging شامل وآمن افتراضيًا: `php tools/final_staging_drill.php`
- Scheduled backup: `php tools/scheduled_backup.php`
- IndexNow: `php tools/indexnow_submit.php <URL...>`
