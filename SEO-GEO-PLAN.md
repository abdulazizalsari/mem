# SEO + GEO operating plan — Saradeeeb

## Architecture

Public, indexable routes are server-rendered PHP pages:

```text
/                         Arabic home
/en/                      English home
/{locale}/article/{slug}  translated article only when published + approved
/{locale}/category/{slug} category archive
/{locale}/writer/{name}   author profile
/sitemap.xml              canonical discovery
/robots.txt               crawler policy
/feed.xml                 freshness feed
/llms.txt                 concise AI-reader entry point
/llms-full.txt            article directory
```

Internal search, authentication, writer/admin dashboards and personal community views are `noindex` or disallowed. Language pages never fall back to Arabic articles when a valid translation is absent.

## Internal linking and page templates

- Every article: one H1, answer-first introduction, author/date, category link, 3 related articles, source citations when claims need evidence, and descriptive image alt text.
- Every category: a short intent-focused introduction and links to its newest translated articles.
- Every author page: credentials, profile links, and only publishable translations in the requested locale.
- Do not mark up invisible FAQs, ratings, products, or local-business information.

## Content clusters and starter ideas

Pillar clusters: ancient civilizations, folklore and beliefs, mysteries and investigations, visitor stories, crime archives, philosophy and cultural history.

1. How oral folklore preserves historical memory
2. A guide to evaluating historical mystery sources
3. The archaeology behind ancient pilgrimage sites
4. How myths change when they cross languages
5. Famous unsolved historical disappearances
6. The ethics of retelling witness stories
7. A timeline of pre-Islamic Arabian belief systems
8. How to distinguish legend from documented history
9. Why abandoned places attract modern folklore
10. The history of protective symbols across cultures
11. A research checklist for a mystery article
12. Women storytellers in Arab oral traditions
13. The psychology of uncertainty and fear
14. How archives preserve criminal-history evidence
15. The cultural history of dreams and omens
16. A comparison of major world flood traditions
17. How to cite sources in narrative nonfiction
18. The history of lost-city claims
19. Why eyewitness accounts conflict
20. A reader's guide to critical thinking about paranormal claims

## Launch checklist

- Set `APP_BASE_URL` to the production HTTPS canonical domain.
- Choose one host form (www or non-www) and enforce it at the proxy/CDN.
- Verify Google Search Console and Bing Webmaster Tools; submit `/sitemap.xml`.
- Set `ai_crawler_policy` to `allow-all` only if training crawlers are intended; the default `search-only` allows answer/search bots and blocks named training bots.
- Configure social URLs, logo, contact, privacy, terms, author bios, and analytics consent.
- Test canonical, hreflang, 301, 404, robots, sitemap, feed, and JSON-LD with production URLs.

## New-page checklist

- Unique translated title and description; one H1; correct canonical.
- Visible author, publication/modified dates, meaningful alt text and internal links.
- The locale's translation is complete, published and approved before public indexing.
- Add Article schema only to articles; include citations for factual claims.

## 90-day roadmap

**Days 1–30:** Search Console/Bing setup, Core Web Vitals baseline, redirect/404 audit, metadata and author-profile cleanup.

**Days 31–60:** Publish one pillar and 4–6 supporting articles per priority cluster; improve internal links and citations; review translation completeness.

**Days 61–90:** Earn relevant editorial mentions, refresh pages with observed queries, evaluate crawl/index coverage and AI-referral analytics. Never purchase links, cloak content, or keyword-stuff.
