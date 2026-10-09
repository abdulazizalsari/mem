# Saradeeeb Custom CMS — Final Architecture

## Product identity
Editorial storytelling platform focused on mystery, hidden history, legends, crimes, beliefs, philosophy and reader stories. Arabic is the source language; English, Spanish and Russian are first-class independent translations.

## Roles
- admin: full control.
- editor: editorial review + publish.
- trusted_writer: own content + direct publish.
- writer: own content, submit for review.

## Article workflow
Draft → Review → Changes requested → Published → Archived.
Translations are stored independently under one article group.

## URL model
- `/ar/article/{localized-slug}`
- `/en/article/{localized-slug}`
- `/es/article/{localized-slug}`
- `/ru/article/{localized-slug}`

No automatic IP/browser-language redirects. The reader chooses language and the article-level switch links directly to an available counterpart.

## Performance approach
Server-rendered PHP, no runtime JavaScript framework, local CSS/JS, responsive images can be added during content migration, lazy loading for editorial imagery, no Elementor/WordPress plugin overhead.

## Security approach
Prepared statements, HTML escaping, HTML allow-list for article body, CSRF tokens, role checks, session rotation on login, secure cookie options, Argon2id password hashing, MIME-verified uploads, internal directory blocking via `.htaccess`.

## Migration
WordPress WXR import is provided. Final migration should validate legacy slugs, featured images, embedded image URLs, categories, publication dates and 301 redirects before DNS/domain cutover.
