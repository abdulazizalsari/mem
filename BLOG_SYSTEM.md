# نظام المدونة والمقالات — محراب v4

تم تطوير نظام المقالات الموجود داخل محراب ليبقى جزءًا من نفس الهوية ونفس لوحة الإدارة ونفس مكتبة الوسائط.

## أهم المسارات العامة
- `/articles` — المدونة.
- `/article/{slug}` — المقال.
- `/articles/category/{slug}` — التصنيف.
- `/articles/tag/{slug}` — الوسم.
- `/author/{id}` — مقالات الكاتب.

## أهم مسارات الإدارة
- `/admin/articles`
- `/admin/article/new`
- `/admin/article-categories`
- `/admin/article-tags`
- `/admin/blog-settings`

## تحديث موقع قائم
بعد Backup وعلى Staging أولًا:

```bash
# طبّق migrations/005_blog_system.sql باستخدام أداة قاعدة البيانات في الاستضافة
php tools/blog_preflight.php
```

ثم اختبر إنشاء مقال ومعاينته ونشره وفتحه من المدونة والتصنيف والبحث، وتحقق من Canonical وOpen Graph وSitemap.

## ملاحظة البحث
بحث المقالات في `/articles` يستخدم بيانات المقالات الحالية. لم يتم إنشاء محرك بحث عالمي منفصل ضمن v4 حتى لا يتكرر نظام مركزي غير موجود فعليًا في baseline.
