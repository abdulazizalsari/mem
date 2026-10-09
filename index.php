<?php
declare(strict_types=1);
require __DIR__ . '/private/bootstrap.php';

$path = url_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!str_starts_with($path,'/admin') && !in_array($path,['/robots.txt','/sitemap.xml','/llms.txt','/feed.xml','/site.webmanifest'],true) && setting_bool($db,'maintenance_mode',false)) {
    http_response_code(503);header('Retry-After: 3600');header('Cache-Control: no-store');
    $message=setting($db,'maintenance_message','الموقع تحت الصيانة، نعود قريبًا.');
    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>صيانة | محراب</title><style>body{font-family:Tahoma,Arial;background:#F7F4ED;color:#202826;display:grid;place-items:center;min-height:100vh;margin:0}.box{max-width:600px;background:#fff;border:1px solid #DDE4E1;border-radius:20px;padding:32px;text-align:center}h1{color:#123B3A}</style><div class="box"><h1>محراب تحت الصيانة</h1><p>'.e($message).'</p></div></html>';exit;
}

function fetch_locations(PDO $db, bool $activeOnly = true, bool $includeRegions = true, bool $includeCities = true): array {
    $countryWhere = $activeOnly ? ' WHERE is_active=1' : '';
    $regionWhere = $activeOnly ? ' WHERE is_active=1' : '';
    $cityWhere = $activeOnly ? ' WHERE is_active=1' : '';
    return [
        'countries'=>$db->query("SELECT * FROM countries{$countryWhere} ORDER BY name")->fetchAll(),
        'regions'=>$includeRegions?$db->query("SELECT * FROM regions{$regionWhere} ORDER BY name")->fetchAll():[],
        'cities'=>$includeCities?$db->query("SELECT * FROM cities{$cityWhere} ORDER BY name")->fetchAll():[],
    ];
}

function audit(PDO $db, int $userId, string $action, string $type='', int $id=0, string $details=''): void {
    $st=$db->prepare("INSERT INTO audit_logs(user_id,action,entity_type,entity_id,details,created_at) VALUES(?,?,?,?,?,NOW())");
    $st->execute([$userId?:null,$action,$type,$id?:null,mb_substr($details,0,2000,'UTF-8')]);
}

function safe_article_html(string $html): string {
    $allowed = '<p><br><h2><h3><h4><ul><ol><li><strong><b><em><i><a><blockquote><hr>';
    $html = strip_tags($html, $allowed);
    $html = preg_replace('/\son\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i','',$html) ?? $html;
    $html = preg_replace('/javascript\s*:/i','',$html) ?? $html;
    // Remove attributes from all allowed text tags; only anchors may keep a validated href.
    $html = preg_replace('/<(p|br|h2|h3|h4|ul|ol|li|strong|b|em|i|blockquote|hr)\b[^>]*>/i','<$1>',$html) ?? $html;
    $html = preg_replace_callback('/<a\b([^>]*)>/i', static function(array $m): string {
        if (!preg_match('/href\s*=\s*("|\')([^"\']+)\1/i', $m[1], $hrefMatch)) return '<a>';
        $href = html_entity_decode($hrefMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!(str_starts_with($href,'/') || valid_http_url($href))) return '<a>';
        return '<a href="'.e($href).'" rel="noopener noreferrer">';
    }, $html) ?? $html;
    return $html;
}

function require_listing(PDO $db, int $listingId): array {
    $sql="SELECT id,slug,name,status".(final_v6_schema($db)?",deleted_at":"")." FROM listings WHERE id=? LIMIT 1";$st=$db->prepare($sql);
    $st->execute([$listingId]);$row=$st->fetch();
    if(!$row || $row['status']!=='published' || (final_v6_schema($db)&&!empty($row['deleted_at']))) { http_response_code(404); exit('السجل غير موجود.'); }
    return $row;
}

function validate_location_selection(PDO $db, int $country, ?int $region, ?int $city): void {
    $st=$db->prepare('SELECT id FROM countries WHERE id=? LIMIT 1');$st->execute([$country]);if(!$st->fetch()) throw new InvalidArgumentException('الدولة غير صحيحة.');
    if($region){$st=$db->prepare('SELECT id FROM regions WHERE id=? AND country_id=? LIMIT 1');$st->execute([$region,$country]);if(!$st->fetch()) throw new InvalidArgumentException('المحافظة/الولاية لا تتبع الدولة المحددة.');}
    if($city){$sql='SELECT ci.id FROM cities ci JOIN regions r ON r.id=ci.region_id WHERE ci.id=? AND r.country_id=?'.($region?' AND r.id=?':'').' LIMIT 1';$st=$db->prepare($sql);$params=[$city,$country];if($region)$params[]=$region;$st->execute($params);if(!$st->fetch()) throw new InvalidArgumentException('المدينة لا تتبع الموقع المحدد.');}
}

/* Public technical endpoints */
if ($path === '/robots.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    if (!setting_bool($db,'seo_indexing',true)) { echo "User-agent: *\nDisallow: /\n"; exit; }
    echo "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /install.php\nDisallow: /search\nDisallow: /directory?\n\n";
    $aiPolicy=setting($db,'ai_crawler_policy','search_only');
    $trainingBots=['GPTBot','Google-Extended','ClaudeBot','CCBot','Applebot-Extended'];
    $searchBots=['OAI-SearchBot','ChatGPT-User','Claude-SearchBot','Claude-User','PerplexityBot'];
    foreach($trainingBots as $bot){echo "User-agent: {$bot}\n".($aiPolicy==='allow_all'?"Allow: /\n":"Disallow: /\n")."\n";}
    foreach($searchBots as $bot){echo "User-agent: {$bot}\n".($aiPolicy==='block_all'?"Disallow: /\n":"Allow: /\n")."\n";}
    echo 'Sitemap: '.base_url('/sitemap.xml')."\n";exit;
}

if ($path === '/llms.txt') {
    header('Content-Type: text/markdown; charset=UTF-8');
    $site=setting($db,'site_name','محراب');$desc=setting($db,'meta_description','دليل عربي منظم للرقاة ومراكز الرقية.');
    echo '# '.$site."\n\n".$desc."\n\n## الصفحات الرئيسية\n- ".base_url('/directory')." — دليل المراكز ومقدمي الخدمة\n- ".base_url('/articles')." — المقالات\n- ".base_url('/about')." — عن الموقع\n\n## السياسة\nالمعلومات المعروضة تأتي من سجلات الموقع المنشورة، وحالة التحقق مستقلة عن حالة النشر.\n";exit;
}

if ($path === '/feed.xml') {
    header('Content-Type: application/rss+xml; charset=UTF-8');$xml=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_XML1,'UTF-8');$site=setting($db,'site_name','محراب');
    $cond=blog_public_condition($db,'a');$items=$db->query("SELECT a.title,a.slug,a.excerpt,a.published_at,a.updated_at FROM articles a WHERE {$cond} ORDER BY COALESCE(a.published_at,a.created_at) DESC LIMIT 30")->fetchAll();
    echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>'.$xml($site).'</title><link>'.$xml(base_url('/')).'</link><description>'.$xml(setting($db,'meta_description','')).'</description>';
    foreach($items as $it)echo '<item><title>'.$xml($it['title']).'</title><link>'.$xml(base_url('/article/'.$it['slug'])).'</link><guid>'.$xml(base_url('/article/'.$it['slug'])).'</guid><pubDate>'.gmdate(DATE_RSS,strtotime((string)($it['published_at']?:$it['updated_at']))).'</pubDate><description>'.$xml((string)$it['excerpt']).'</description></item>';
    echo '</channel></rss>';exit;
}

if ($path === '/site.webmanifest') {
    header('Content-Type: application/manifest+json; charset=UTF-8');
    echo json_encode(site_webmanifest($db),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}

if (preg_match('#^/([a-f0-9]{40})\.txt$#',$path,$km) && hash_equals(setting($db,'indexnow_key',''),$km[1])) { header('Content-Type: text/plain; charset=UTF-8');echo $km[1];exit; }

if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=UTF-8');
    $xml = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $today=date('Y-m-d');
    $urls = [
        ['loc'=>base_url('/'),'lastmod'=>$today],['loc'=>base_url('/directory'),'lastmod'=>$today],
        ['loc'=>base_url('/articles'),'lastmod'=>$today],['loc'=>base_url('/how-we-verify'),'lastmod'=>$today],
        ['loc'=>base_url('/about'),'lastmod'=>$today],['loc'=>base_url('/privacy'),'lastmod'=>$today],['loc'=>base_url('/terms'),'lastmod'=>$today],
    ];
    foreach ($db->query("SELECT c.slug,MAX(l.updated_at) updated_at FROM countries c JOIN listings l ON l.country_id=c.id AND l.status='published'".(final_v6_schema($db)?" AND l.deleted_at IS NULL":"")." WHERE c.is_active=1 GROUP BY c.id,c.slug")->fetchAll() as $row) $urls[]=['loc'=>base_url('/country/'.$row['slug']),'lastmod'=>substr((string)$row['updated_at'],0,10)?:$today];
    foreach ($db->query("SELECT ci.slug,MAX(l.updated_at) updated_at FROM cities ci JOIN listings l ON l.city_id=ci.id AND l.status='published'".(final_v6_schema($db)?" AND l.deleted_at IS NULL":"")." WHERE ci.is_active=1 GROUP BY ci.id,ci.slug")->fetchAll() as $row) $urls[]=['loc'=>base_url('/city/'.$row['slug']),'lastmod'=>substr((string)$row['updated_at'],0,10)?:$today];
    if(final_v6_schema($db)) foreach ($db->query("SELECT r.slug,MAX(l.updated_at) updated_at FROM regions r JOIN listings l ON l.region_id=r.id AND l.status='published' AND l.deleted_at IS NULL WHERE r.is_active=1 GROUP BY r.id,r.slug")->fetchAll() as $row) $urls[]=['loc'=>base_url('/region/'.$row['slug']),'lastmod'=>substr((string)$row['updated_at'],0,10)?:$today];
    foreach ($db->query("SELECT slug,updated_at FROM listings WHERE status='published'".(final_v6_schema($db)?" AND deleted_at IS NULL":"")." ORDER BY id DESC")->fetchAll() as $row) $urls[]=['loc'=>base_url('/person/'.$row['slug']),'lastmod'=>substr((string)$row['updated_at'],0,10)];
    $articleSitemapCondition=blog_public_condition($db,'a');
    foreach ($db->query("SELECT a.slug,a.updated_at FROM articles a WHERE {$articleSitemapCondition} ORDER BY a.id DESC")->fetchAll() as $row) $urls[]=['loc'=>base_url('/article/'.$row['slug']),'lastmod'=>substr((string)$row['updated_at'],0,10)];
    if(blog_has_v4_schema($db)){try{foreach($db->query("SELECT ac.slug,MAX(a.updated_at) updated_at FROM article_categories ac JOIN articles a ON a.category_id=ac.id WHERE ac.is_active=1 AND ".blog_public_condition($db,'a')." GROUP BY ac.id,ac.slug")->fetchAll() as $row)$urls[]=['loc'=>base_url('/articles/category/'.$row['slug']),'lastmod'=>substr((string)$row['updated_at'],0,10)?:$today];}catch(Throwable $e){}}
    try{if(cms_has_v5_schema($db)){foreach ($db->query("SELECT slug,route_path,updated_at FROM pages WHERE deleted_at IS NULL AND page_type IN('content','landing','custom') AND robots LIKE 'index%' AND (status='published' OR (status='scheduled' AND scheduled_at IS NOT NULL AND scheduled_at<=NOW())) ORDER BY id DESC")->fetchAll() as $row) $urls[]=['loc'=>base_url(cms_page_public_path($row)),'lastmod'=>substr((string)$row['updated_at'],0,10)];}else{foreach ($db->query("SELECT slug,updated_at FROM pages WHERE status='published' ORDER BY id DESC")->fetchAll() as $row) $urls[]=['loc'=>base_url('/page/'.$row['slug']),'lastmod'=>substr((string)$row['updated_at'],0,10)];}}catch(Throwable $e){}
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    foreach ($urls as $item) echo '  <url><loc>'.$xml($item['loc']).'</loc><lastmod>'.$xml($item['lastmod'])."</lastmod></url>\n";
    echo '</urlset>';
    exit;
}

/* Small, indexed location responses for public type-ahead fields. Never send the global cities table to a visitor. */
if ($path === '/api/locations/search') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: public, max-age=300');
    $type=(string)($_GET['type']??'');$q=mb_substr(trim((string)($_GET['q']??'')),0,100,'UTF-8');$country=(int)($_GET['country_id']??0);$region=(int)($_GET['region_id']??0);$items=[];
    try {
        if(!location_is_arab_country($db,$country))throw new InvalidArgumentException('الدليل العام يعرض الدول العربية فقط.');
        if($type==='region' && $country){$st=$db->prepare('SELECT id,name,name_en,name_native FROM regions WHERE country_id=? AND is_active=1 AND (name LIKE ? OR name_en LIKE ? OR name_native LIKE ?) ORDER BY name LIMIT 120');$like='%'.$q.'%';$st->execute([$country,$like,$like,$like]);$items=$st->fetchAll();}
        elseif($type==='city' && $country && ($q!==''||$region)){ $sql='SELECT ci.id,ci.name,ci.name_en,ci.name_native,r.name region_name FROM cities ci JOIN regions r ON r.id=ci.region_id WHERE r.country_id=? AND ci.is_active=1 AND r.is_active=1';$params=[$country];if($region){$sql.=' AND ci.region_id=?';$params[]=$region;}if($q!==''){$sql.=' AND (ci.name LIKE ? OR ci.name_en LIKE ? OR ci.name_native LIKE ?)';$like='%'.$q.'%';array_push($params,$like,$like,$like);}$sql.=' ORDER BY ci.name LIMIT 40';$st=$db->prepare($sql);$st->execute($params);$items=$st->fetchAll();}
        else { throw new InvalidArgumentException('طلب موقع غير مكتمل.'); }
        echo json_encode(['items'=>$items],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    } catch(Throwable $e) { http_response_code(400);echo json_encode(['items'=>[],'error'=>'تعذر تحميل خيارات الموقع.'],JSON_UNESCAPED_UNICODE); }
    exit;
}

/* Public pages */
if ($path === '/') {
    $countries = location_arab_countries($db,true);
    $cities = [];
    $homeDeleted=final_v6_schema($db)?" AND l.deleted_at IS NULL":'';$countriesWithCounts = $db->query("SELECT c.id,c.name,c.slug,COUNT(l.id) total FROM countries c JOIN listings l ON l.country_id=c.id AND l.status='published'{$homeDeleted} WHERE c.is_active=1 GROUP BY c.id,c.name,c.slug ORDER BY total DESC,c.name LIMIT 12")->fetchAll();
    $homeStats=final_v6_schema($db)?listing_card_stats_sql():'';$latestListings = $db->query("SELECT l.*,c.name country_name,c.slug country_slug,ci.name city_name,ci.slug city_slug,s.name primary_specialty{$homeStats} FROM listings l JOIN countries c ON c.id=l.country_id LEFT JOIN cities ci ON ci.id=l.city_id LEFT JOIN specialties s ON s.id=l.primary_specialty_id WHERE l.status='published'{$homeDeleted} ORDER BY l.updated_at DESC LIMIT 6")->fetchAll();
    $homeArticleCondition=blog_public_condition($db,'a');
    $latestArticles = $db->query("SELECT a.*,ac.name category_name,ac.slug category_slug,u.name author_name FROM articles a LEFT JOIN article_categories ac ON ac.id=a.category_id LEFT JOIN users u ON u.id=a.created_by WHERE {$homeArticleCondition} ORDER BY COALESCE(a.published_at,a.created_at) DESC LIMIT 3")->fetchAll();
    $siteName=setting($db,'site_name','محراب');
    $description=setting($db,'meta_description','محراب دليل عربي منظم للرقاة ومراكز الرقية، تُراجع بيانات ملفاته بواسطة إدارة المنصة.');
    $jsonLd=['@context'=>'https://schema.org','@type'=>'WebSite','name'=>$siteName,'url'=>base_url('/'),'description'=>$description,'potentialAction'=>['@type'=>'SearchAction','target'=>base_url('/directory?q={search_term_string}'),'query-input'=>'required name=search_term_string']];
    render('home', compact('countries','cities','countriesWithCounts','latestListings','latestArticles','jsonLd') + ['title'=>$siteName.' | دليل الرقاة ومراكز الرقية','description'=>$description,'canonical'=>base_url('/')]);
    exit;
}

if ($path === '/directory') {
    $loc=['countries'=>location_arab_countries($db,true),'regions'=>[],'cities'=>[]];$specialties=$db->query("SELECT * FROM specialties WHERE is_active=1 ORDER BY name")->fetchAll();
    $filters=['q'=>mb_substr(trim((string)($_GET['q']??'')),0,120,'UTF-8'),'country_id'=>(int)($_GET['country_id']??0),'region_id'=>(int)($_GET['region_id']??0),'city_id'=>(int)($_GET['city_id']??0),'specialty_id'=>(int)($_GET['specialty_id']??0),'type'=>in_array(($_GET['type']??''),['person','center'],true)?(string)$_GET['type']:'','verified'=>!empty($_GET['verified'])?1:0];if($filters['country_id']&&!location_is_arab_country($db,$filters['country_id'])){$filters['country_id']=0;$filters['region_id']=0;$filters['city_id']=0;}
    $params=[];$where=["l.status='published'"];if(final_v6_schema($db))$where[]='l.deleted_at IS NULL';
    $q=$filters['q'];if($q!==''){$like='%'.$q.'%';$fields=final_v6_schema($db)?['l.name','l.provider_name','l.short_bio','l.address','l.district','l.street','l.landmark','l.search_keywords','c.name','r.name','ci.name','s.name']:['l.name','l.short_bio','l.address','c.name','r.name','ci.name','s.name'];$parts=[];foreach($fields as $f){$parts[]="$f LIKE ?";$params[]=$like;}$where[]='('.implode(' OR ',$parts).')';}
    foreach(['country_id','region_id','city_id'] as $f)if($filters[$f]){$where[]="l.$f=?";$params[]=$filters[$f];}
    if($filters['type']){$where[]='l.type=?';$params[]=$filters['type'];}if($filters['specialty_id']){$where[]='(l.primary_specialty_id=? OR EXISTS(SELECT 1 FROM listing_specialties ls WHERE ls.listing_id=l.id AND ls.specialty_id=?))';$params[]=$filters['specialty_id'];$params[]=$filters['specialty_id'];}if(final_v6_schema($db)&&$filters['verified'])$where[]="l.verification_status='verified'";
    $whereSql=implode(' AND ',$where);$count=$db->prepare("SELECT COUNT(*) FROM listings l JOIN countries c ON c.id=l.country_id LEFT JOIN regions r ON r.id=l.region_id LEFT JOIN cities ci ON ci.id=l.city_id LEFT JOIN specialties s ON s.id=l.primary_specialty_id WHERE $whereSql");$count->execute($params);$total=(int)$count->fetchColumn();$pg=pagination_meta($total,page_number(),24);
    $scoreSql='0';$scoreParams=[];if($q!==''){$scoreSql="CASE WHEN l.name=? THEN 1000 WHEN l.name LIKE ? THEN 800 ".(final_v6_schema($db)?"WHEN l.provider_name=? THEN 650 ":"")."WHEN ci.name=? THEN 550 WHEN s.name=? THEN 500 WHEN r.name=? THEN 430 WHEN l.address LIKE ? THEN 320 ".(final_v6_schema($db)?"WHEN l.search_keywords LIKE ? THEN 220 ":"")."WHEN l.short_bio LIKE ? THEN 120 ELSE 0 END";$scoreParams=[$q,$q.'%'];if(final_v6_schema($db))$scoreParams[]=$q;array_push($scoreParams,$q,$q,$q,'%'.$q.'%');if(final_v6_schema($db))$scoreParams[]='%'.$q.'%';$scoreParams[]='%'.$q.'%';}
    $stats=final_v6_schema($db)?listing_card_stats_sql():'';$sql="SELECT l.*,c.name country_name,c.slug country_slug,r.name region_name,ci.name city_name,ci.slug city_slug,s.name primary_specialty{$stats},{$scoreSql} relevance FROM listings l JOIN countries c ON c.id=l.country_id LEFT JOIN regions r ON r.id=l.region_id LEFT JOIN cities ci ON ci.id=l.city_id LEFT JOIN specialties s ON s.id=l.primary_specialty_id WHERE $whereSql ORDER BY relevance DESC,l.updated_at DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}";$st=$db->prepare($sql);$st->execute(array_merge($scoreParams,$params));$listings=$st->fetchAll();if(final_v6_schema($db))search_log($db,$q,$total,$filters);
    $selectedRegion=null;$selectedCity=null;if($filters['region_id']){$pick=$db->prepare('SELECT id,name FROM regions WHERE id=? AND country_id=? AND is_active=1 LIMIT 1');$pick->execute([$filters['region_id'],$filters['country_id']]);$selectedRegion=$pick->fetch()?:null;}if($filters['city_id']){$pick=$db->prepare('SELECT ci.id,ci.name,ci.region_id FROM cities ci JOIN regions r ON r.id=ci.region_id WHERE ci.id=? AND r.country_id=? AND ci.is_active=1 AND r.is_active=1 LIMIT 1');$pick->execute([$filters['city_id'],$filters['country_id']]);$selectedCity=$pick->fetch()?:null;if($selectedCity&&!$selectedRegion){$pick=$db->prepare('SELECT id,name FROM regions WHERE id=? LIMIT 1');$pick->execute([(int)$selectedCity['region_id']]);$selectedRegion=$pick->fetch()?:null;}}
    $systemMeta=cms_system_page_meta($db,'directory');$directoryTitle=(string)(($systemMeta['seo_title']??'')?:($systemMeta['title']??'دليل الرقاة ومراكز الرقية'));$directoryDescription=(string)(($systemMeta['seo_description']??'')?:'ابحث في دليل محراب حسب الدولة والمدينة والتخصص والعنوان.');$hasSearchFilters=$q!==''||$filters['country_id']||$filters['region_id']||$filters['city_id']||$filters['specialty_id']||$filters['type']||$filters['verified']||page_number()>1;$robots=$hasSearchFilters?'noindex,follow':'index,follow';render('directory',['title'=>$directoryTitle.' | محراب','description'=>$directoryDescription,'canonical'=>base_url('/directory'),'robots'=>$robots,'ogTitle'=>$systemMeta['og_title']??'','ogDescription'=>$systemMeta['og_description']??'','ogImage'=>$systemMeta['og_image']??'','listings'=>$listings,'pagination'=>$pg,'filters'=>$filters,'specialties'=>$specialties,'countries'=>$loc['countries'],'regions'=>[],'cities'=>[],'selectedRegion'=>$selectedRegion,'selectedCity'=>$selectedCity]);exit;
}

if (preg_match('#^/country/([^/]+)$#u',$path,$m)) {
    $st=$db->prepare("SELECT * FROM countries WHERE slug=? AND is_active=1 LIMIT 1");$st->execute([$m[1]]);$location=$st->fetch();
    if(!$location){http_response_code(404);render('404',['title'=>'الدولة غير موجودة | محراب']);exit;}
    $page=page_number();$countryCond=final_v6_schema($db)?" AND deleted_at IS NULL":'';$count=$db->prepare("SELECT COUNT(*) FROM listings WHERE country_id=? AND status='published'{$countryCond}");$count->execute([$location['id']]);$pg=pagination_meta((int)$count->fetchColumn(),$page,24);
    $countryStats=final_v6_schema($db)?listing_card_stats_sql():'';$st=$db->prepare("SELECT l.*,c.name country_name,c.slug country_slug,ci.name city_name,ci.slug city_slug,s.name primary_specialty{$countryStats} FROM listings l JOIN countries c ON c.id=l.country_id LEFT JOIN cities ci ON ci.id=l.city_id LEFT JOIN specialties s ON s.id=l.primary_specialty_id WHERE l.country_id=? AND l.status='published'".(final_v6_schema($db)?" AND l.deleted_at IS NULL":"")." ORDER BY l.updated_at DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");$st->execute([$location['id']]);$listings=$st->fetchAll();
    $st=$db->prepare("SELECT ci.id,ci.name,ci.slug,COUNT(l.id) total FROM cities ci JOIN regions r ON r.id=ci.region_id LEFT JOIN listings l ON l.city_id=ci.id AND l.status='published' WHERE r.country_id=? AND ci.is_active=1 GROUP BY ci.id,ci.name,ci.slug HAVING total>0 ORDER BY total DESC,ci.name");$st->execute([$location['id']]);$childLocations=$st->fetchAll();
    $title='الرقاة ومراكز الرقية في '.$location['name'];$desc='دليل محراب للرقاة ومراكز الرقية في '.$location['name'].' مع بيانات مرتبة ومراجعة من إدارة المنصة.';
    render('location',['title'=>$title.' | محراب','description'=>$desc,'canonical'=>base_url('/country/'.$location['slug']),'location'=>$location,'locationType'=>'country','heading'=>$title,'listings'=>$listings,'childLocations'=>$childLocations,'pagination'=>$pg]);exit;
}

if (preg_match('#^/region/([^/]+)$#u',$path,$m)) {
    $st=$db->prepare("SELECT r.*,c.name country_name,c.slug country_slug FROM regions r JOIN countries c ON c.id=r.country_id WHERE r.slug=? AND r.is_active=1 AND c.is_active=1 LIMIT 1");$st->execute([$m[1]]);$location=$st->fetch();if(!$location){http_response_code(404);render('404',['title'=>'المنطقة غير موجودة | محراب']);exit;}
    $cond=final_v6_schema($db)?" AND l.deleted_at IS NULL":'';$count=$db->prepare("SELECT COUNT(*) FROM listings l WHERE l.region_id=? AND l.status='published'{$cond}");$count->execute([$location['id']]);$pg=pagination_meta((int)$count->fetchColumn(),page_number(),24);$stats=final_v6_schema($db)?listing_card_stats_sql():'';$st=$db->prepare("SELECT l.*,c.name country_name,c.slug country_slug,ci.name city_name,ci.slug city_slug,s.name primary_specialty{$stats} FROM listings l JOIN countries c ON c.id=l.country_id LEFT JOIN cities ci ON ci.id=l.city_id LEFT JOIN specialties s ON s.id=l.primary_specialty_id WHERE l.region_id=? AND l.status='published'{$cond} ORDER BY l.updated_at DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");$st->execute([$location['id']]);$listings=$st->fetchAll();$ch=$db->prepare("SELECT ci.id,ci.name,ci.slug,COUNT(l.id) total FROM cities ci LEFT JOIN listings l ON l.city_id=ci.id AND l.status='published' WHERE ci.region_id=? AND ci.is_active=1 GROUP BY ci.id,ci.name,ci.slug ORDER BY total DESC,ci.name");$ch->execute([$location['id']]);$childLocations=$ch->fetchAll();$title='الرقاة ومراكز الرقية في '.$location['name'];render('location',['title'=>$title.' | محراب','description'=>'دليل المراكز ومقدمي الخدمة في '.$location['name'].'، '.$location['country_name'],'canonical'=>base_url('/region/'.$location['slug']),'location'=>$location,'locationType'=>'region','heading'=>$title,'listings'=>$listings,'childLocations'=>$childLocations,'pagination'=>$pg]);exit;
}

if (preg_match('#^/city/([^/]+)$#u',$path,$m)) {
    $st=$db->prepare("SELECT ci.*,r.name region_name,c.id country_id,c.name country_name,c.slug country_slug FROM cities ci JOIN regions r ON r.id=ci.region_id JOIN countries c ON c.id=r.country_id WHERE ci.slug=? AND ci.is_active=1 AND r.is_active=1 AND c.is_active=1 LIMIT 1");$st->execute([$m[1]]);$location=$st->fetch();
    if(!$location){http_response_code(404);render('404',['title'=>'المدينة غير موجودة | محراب']);exit;}
    $page=page_number();$cityCond=final_v6_schema($db)?" AND deleted_at IS NULL":'';$count=$db->prepare("SELECT COUNT(*) FROM listings WHERE city_id=? AND status='published'{$cityCond}");$count->execute([$location['id']]);$pg=pagination_meta((int)$count->fetchColumn(),$page,24);
    $cityStats=final_v6_schema($db)?listing_card_stats_sql():'';$st=$db->prepare("SELECT l.*,c.name country_name,c.slug country_slug,ci.name city_name,ci.slug city_slug,s.name primary_specialty{$cityStats} FROM listings l JOIN countries c ON c.id=l.country_id LEFT JOIN cities ci ON ci.id=l.city_id LEFT JOIN specialties s ON s.id=l.primary_specialty_id WHERE l.city_id=? AND l.status='published'".(final_v6_schema($db)?" AND l.deleted_at IS NULL":"")." ORDER BY l.updated_at DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");$st->execute([$location['id']]);$listings=$st->fetchAll();
    $title='الرقاة ومراكز الرقية في '.$location['name'];$desc='اعرض بيانات الرقاة ومراكز الرقية في '.$location['name'].'، '.$location['country_name'].' من خلال دليل محراب.';
    render('location',['title'=>$title.' | محراب','description'=>$desc,'canonical'=>base_url('/city/'.$location['slug']),'location'=>$location,'locationType'=>'city','heading'=>$title,'listings'=>$listings,'childLocations'=>[],'pagination'=>$pg]);exit;
}

if (preg_match('#^/go/(\d+)/(call|whatsapp|email|website)$#',$path,$gm)) {
    $id=(int)$gm[1];$channel=(string)$gm[2];rate_limit('center-contact',60);
    $cond=final_v6_schema($db)?" AND deleted_at IS NULL":'';$st=$db->prepare("SELECT id,phone,whatsapp,email,website FROM listings WHERE id=? AND status='published'{$cond} LIMIT 1");$st->execute([$id]);$row=$st->fetch();
    if(!$row){http_response_code(404);exit('غير موجود');}
    $target='';
    if($channel==='call'&&!empty($row['phone'])){$phone=preg_replace('/[^0-9+]/','',(string)$row['phone'])??'';$target=$phone!==''?'tel:'.$phone:'';}
    elseif($channel==='whatsapp'&&!empty($row['whatsapp'])){$digits=preg_replace('/\D+/','',(string)$row['whatsapp'])??'';$target=$digits!==''?'https://wa.me/'.$digits:'';}
    elseif($channel==='email'&&!empty($row['email'])&&filter_var($row['email'],FILTER_VALIDATE_EMAIL))$target='mailto:'.$row['email'];
    elseif($channel==='website')$target=safe_external_url((string)($row['website']??''));
    if($target===''){http_response_code(404);exit('وسيلة التواصل غير متاحة');}
    if(final_v6_schema($db)){
        if($channel==='call')$db->exec('UPDATE listings SET contact_clicks=contact_clicks+1,call_clicks=call_clicks+1 WHERE id='.$id);
        elseif($channel==='whatsapp')$db->exec('UPDATE listings SET contact_clicks=contact_clicks+1,whatsapp_clicks=whatsapp_clicks+1 WHERE id='.$id);
        else $db->exec('UPDATE listings SET contact_clicks=contact_clicks+1 WHERE id='.$id);
    }
    header('Cache-Control: no-store');header('Location: '.$target,true,302);exit;
}

if (preg_match('#^/person/([^/]+)$#u',$path,$m)) {
    $cond=final_v6_schema($db)?" AND l.deleted_at IS NULL":'';
    $st=$db->prepare("SELECT l.*,c.name country_name,c.slug country_slug,r.name region_name,r.slug region_slug,ci.name city_name,ci.slug city_slug,s.name primary_specialty FROM listings l JOIN countries c ON c.id=l.country_id LEFT JOIN regions r ON r.id=l.region_id LEFT JOIN cities ci ON ci.id=l.city_id LEFT JOIN specialties s ON s.id=l.primary_specialty_id WHERE l.slug=? AND l.status='published'{$cond} LIMIT 1");$st->execute([$m[1]]);$listing=$st->fetch();
    if(!$listing && final_v6_schema($db)){$old=$db->prepare("SELECT l.slug FROM listing_slug_history h JOIN listings l ON l.id=h.listing_id WHERE h.old_slug=? AND l.status='published' AND l.deleted_at IS NULL LIMIT 1");$old->execute([$m[1]]);$newSlug=$old->fetchColumn();if($newSlug){header('Location: '.base_url('/person/'.rawurlencode((string)$newSlug)),true,301);exit;}}
    if(!$listing){http_response_code(404);render('404',['title'=>'الملف غير موجود | محراب']);exit;}
    if(final_v6_schema($db))$db->exec('UPDATE listings SET views=views+1 WHERE id='.(int)$listing['id']);
    $st=$db->prepare("SELECT s.* FROM specialties s JOIN listing_specialties ls ON ls.specialty_id=s.id WHERE ls.listing_id=? ORDER BY s.name");$st->execute([$listing['id']]);$specialties=$st->fetchAll();
    $reviews=[];$reviewSummary=['count'=>0,'avg'=>0.0];
    if(setting_bool($db,'reviews_enabled',true)){$st=$db->prepare("SELECT reviewer_name,rating,message,created_at FROM reviews WHERE listing_id=? AND status='approved' ORDER BY id DESC LIMIT 12");$st->execute([$listing['id']]);$reviews=$st->fetchAll();$st=$db->prepare("SELECT COUNT(*) total,COALESCE(AVG(rating),0) avg_rating FROM reviews WHERE listing_id=? AND status='approved'");$st->execute([$listing['id']]);$sum=$st->fetch();$reviewSummary=['count'=>(int)$sum['total'],'avg'=>(float)$sum['avg_rating']];}
    $relatedListings=listing_related($db,$listing,4);
    $entityType=$listing['type']==='center'?'Organization':'Person';$entity=['@type'=>$entityType,'@id'=>base_url('/person/'.$listing['slug']).'#entity','name'=>$listing['name'],'url'=>base_url('/person/'.$listing['slug'])];
    if(!empty($listing['image']))$entity['image']=media_url((string)$listing['image'],true);if(!empty($listing['short_bio']))$entity['description']=$listing['short_bio'];
    if($listing['type']==='center'){$entity['address']=['@type'=>'PostalAddress','streetAddress'=>(string)($listing['address']??''),'addressLocality'=>(string)($listing['city_name']?:$listing['region_name']),'addressRegion'=>(string)($listing['region_name']??''),'addressCountry'=>(string)$listing['country_name']];if(!empty($listing['phone']))$entity['telephone']=$listing['phone'];if(!empty($listing['website'])&&safe_external_url((string)$listing['website']))$entity['sameAs']=[safe_external_url((string)$listing['website'])];if(!empty($listing['latitude'])&&!empty($listing['longitude']))$entity['geo']=['@type'=>'GeoCoordinates','latitude'=>(float)$listing['latitude'],'longitude'=>(float)$listing['longitude']];}
    if($reviewSummary['count']>0){$entity['aggregateRating']=['@type'=>'AggregateRating','ratingValue'=>round($reviewSummary['avg'],1),'reviewCount'=>$reviewSummary['count'],'bestRating'=>5,'worstRating'=>1];}
    $crumbs=[['@type'=>'ListItem','position'=>1,'name'=>'الرئيسية','item'=>base_url('/')],['@type'=>'ListItem','position'=>2,'name'=>$listing['country_name'],'item'=>base_url('/country/'.$listing['country_slug'])]];if(!empty($listing['city_slug']))$crumbs[]=['@type'=>'ListItem','position'=>3,'name'=>$listing['city_name'],'item'=>base_url('/city/'.$listing['city_slug'])];$crumbs[]=['@type'=>'ListItem','position'=>count($crumbs)+1,'name'=>$listing['name'],'item'=>base_url('/person/'.$listing['slug'])];
    $jsonLd=['@context'=>'https://schema.org','@graph'=>[$entity,['@type'=>'BreadcrumbList','itemListElement'=>$crumbs]]];
    $desc=$listing['seo_description']?:($listing['short_bio']?:('بيانات '.($listing['type']==='center'?'مركز ':'مقدم الخدمة ').$listing['name'].' في محراب.'));$pageTitle=$listing['seo_title']?:($listing['name'].' | محراب');
    render('person',['title'=>$pageTitle,'description'=>$desc,'canonical'=>base_url('/person/'.$listing['slug']),'ogImage'=>!empty($listing['image'])?media_url((string)$listing['image'],true):'','jsonLd'=>$jsonLd,'listing'=>$listing,'specialties'=>$specialties,'reviews'=>$reviews,'reviewSummary'=>$reviewSummary,'relatedListings'=>$relatedListings]);exit;
}

$blogCategorySlug=null;$blogTagSlug=null;$blogAuthorId=0;
if(preg_match('#^/articles/category/([^/]+)$#u',$path,$bm))$blogCategorySlug=$bm[1];
if(preg_match('#^/articles/tag/([^/]+)$#u',$path,$bm))$blogTagSlug=$bm[1];
if(preg_match('#^/author/(\d+)$#',$path,$bm))$blogAuthorId=(int)$bm[1];

if ($path === '/articles' || $blogCategorySlug!==null || $blogTagSlug!==null || $blogAuthorId>0) {
    $blog=blog_settings($db);$publicCondition=blog_public_condition($db,'a');$category=false;$tag=false;$author=false;$params=[];$where=[$publicCondition];
    $filters=['q'=>mb_substr(trim((string)($_GET['q']??'')),0,120,'UTF-8'),'sort'=>in_array($_GET['sort']??'latest',['latest','popular','oldest'],true)?(string)($_GET['sort']??'latest'):'latest','category_id'=>0];
    try{$categories=$db->query('SELECT * FROM article_categories WHERE is_active=1 ORDER BY sort_order,name')->fetchAll();}catch(Throwable $e){$categories=$db->query('SELECT * FROM article_categories ORDER BY name')->fetchAll();}

    if($blogCategorySlug!==null){$st=$db->prepare('SELECT * FROM article_categories WHERE slug=?'.(blog_has_v4_schema($db)?' AND is_active=1':'').' LIMIT 1');$st->execute([$blogCategorySlug]);$category=$st->fetch();if(!$category){http_response_code(404);render('404',['title'=>'التصنيف غير موجود | محراب']);exit;}$filters['category_id']=(int)$category['id'];$where[]='a.category_id=?';$params[]=(int)$category['id'];}
    elseif((int)($_GET['category']??0)>0){$filters['category_id']=(int)$_GET['category'];$where[]='a.category_id=?';$params[]=$filters['category_id'];}

    if($blogTagSlug!==null && !blog_has_v4_schema($db)){http_response_code(404);render('404',['title'=>'الوسم غير موجود | محراب']);exit;}
    if($blogTagSlug!==null && blog_has_v4_schema($db)){$st=$db->prepare('SELECT * FROM article_tags WHERE slug=? LIMIT 1');$st->execute([$blogTagSlug]);$tag=$st->fetch();if(!$tag){http_response_code(404);render('404',['title'=>'الوسم غير موجود | محراب']);exit;}$where[]='EXISTS(SELECT 1 FROM article_tag_map atm WHERE atm.article_id=a.id AND atm.tag_id=?)';$params[]=(int)$tag['id'];}
    if($blogAuthorId>0){$st=$db->prepare("SELECT id,name FROM users WHERE id=? AND status='active' LIMIT 1");$st->execute([$blogAuthorId]);$author=$st->fetch();if(!$author){http_response_code(404);render('404',['title'=>'الكاتب غير موجود | محراب']);exit;}$where[]='a.created_by=?';$params[]=$blogAuthorId;}
    if($filters['q']!==''){$like='%'.$filters['q'].'%';$searchSql='(a.title LIKE ? OR a.excerpt LIKE ? OR a.content_html LIKE ? OR EXISTS(SELECT 1 FROM article_categories cq WHERE cq.id=a.category_id AND cq.name LIKE ?) OR EXISTS(SELECT 1 FROM users au WHERE au.id=a.created_by AND au.name LIKE ?)';array_push($params,$like,$like,$like,$like,$like);if(blog_has_v4_schema($db)){$searchSql.=' OR EXISTS(SELECT 1 FROM article_tag_map sm JOIN article_tags stg ON stg.id=sm.tag_id WHERE sm.article_id=a.id AND stg.name LIKE ?)';$params[]=$like;}$searchSql.=')';$where[]=$searchSql;}

    $whereSql=implode(' AND ',$where);$order=['latest'=>'COALESCE(a.published_at,a.created_at) DESC','popular'=>'a.views DESC,COALESCE(a.published_at,a.created_at) DESC','oldest'=>'COALESCE(a.published_at,a.created_at) ASC'][$filters['sort']];
    $count=$db->prepare("SELECT COUNT(*) FROM articles a WHERE {$whereSql}");$count->execute($params);$pg=pagination_meta((int)$count->fetchColumn(),page_number(),(int)$blog['per_page']);$pagination=$pg;
    $sql="SELECT a.*,ac.name category_name,ac.slug category_slug,u.name author_name FROM articles a LEFT JOIN article_categories ac ON ac.id=a.category_id LEFT JOIN users u ON u.id=a.created_by WHERE {$whereSql} ORDER BY {$order} LIMIT {$pg['per_page']} OFFSET {$pg['offset']}";
    $st=$db->prepare($sql);$st->execute($params);$articles=$st->fetchAll();

    $featuredArticle=false;$blogIntroPage=false;
    if($path==='/articles' && $filters['q']==='' && !$filters['category_id'] && blog_has_v4_schema($db)){
        $st=$db->query("SELECT a.*,ac.name category_name,ac.slug category_slug,u.name author_name FROM articles a LEFT JOIN article_categories ac ON ac.id=a.category_id LEFT JOIN users u ON u.id=a.created_by WHERE ".blog_public_condition($db,'a')." AND a.featured=1 ORDER BY COALESCE(a.published_at,a.created_at) DESC LIMIT 1");$featuredArticle=$st->fetch()?:false;
        if($blog['intro_page_slug']!==''){try{$st=$db->prepare("SELECT content_html FROM pages WHERE slug=? AND status='published' LIMIT 1");$st->execute([$blog['intro_page_slug']]);$blogIntroPage=$st->fetch()?:false;}catch(Throwable $e){}}
    }
    if($category){$title=(string)($category['seo_title']?:$category['name']).' | محراب';$description=(string)($category['seo_description']?:($category['description']?:('مقالات تصنيف '.$category['name'].' في محراب')));$canonical=base_url('/articles/category/'.$category['slug']);}
    elseif($tag){$title='وسم '.$tag['name'].' | محراب';$description='مقالات مرتبطة بوسم '.$tag['name'].' في محراب';$canonical=base_url('/articles/tag/'.$tag['slug']);}
    elseif($author){$title='مقالات '.$author['name'].' | محراب';$description='المقالات المنشورة بواسطة '.$author['name'].' في محراب';$canonical=base_url('/author/'.$author['id']);}
    else{$systemMeta=cms_system_page_meta($db,'articles');$baseTitle=(string)(($systemMeta['seo_title']??'')?:($systemMeta['title']??$blog['title']));$title=$baseTitle.' | محراب';$description=(string)(($systemMeta['seo_description']??'')?:$blog['intro']);$canonical=!empty($systemMeta['canonical_url'])?(string)$systemMeta['canonical_url']:base_url('/articles');}
    $robots=$filters['q']!==''?'noindex,follow':'index,follow';
    render('articles',compact('title','description','canonical','robots','articles','categories','pagination','filters','category','tag','author','blog','featuredArticle','blogIntroPage'));exit;
}

if (preg_match('#^/article/([^/]+)$#u',$path,$m)) {
    $publicCondition=blog_public_condition($db,'a');
    $st=$db->prepare("SELECT a.*,ac.name category_name,ac.slug category_slug,u.name author_name FROM articles a LEFT JOIN article_categories ac ON ac.id=a.category_id LEFT JOIN users u ON u.id=a.created_by WHERE a.slug=? AND {$publicCondition} LIMIT 1");$st->execute([$m[1]]);$article=$st->fetch();
    if(!$article){$newSlug=blog_find_old_slug($db,$m[1]);if($newSlug){header('Location: '.internal_url('/article/'.$newSlug),true,301);exit;}http_response_code(404);render('404',['title'=>'المقال غير موجود | محراب']);exit;}
    $db->prepare('UPDATE articles SET views=views+1 WHERE id=?')->execute([$article['id']]);$article['views']=(int)$article['views']+1;
    $content=blog_content_with_toc((string)$article['content_html']);$contentHtml=$content['html'];$toc=$content['toc'];$tags=blog_get_tags($db,(int)$article['id']);$related=blog_related_articles($db,$article,3);$references=blog_references((string)($article['references_text']??''));$blog=blog_settings($db);
    $canonicalUrl=!empty($article['canonical_url'])&&valid_http_url((string)$article['canonical_url'])?(string)$article['canonical_url']:base_url('/article/'.$article['slug']);
    $ogImage=(string)(($article['og_image']??'') ?: article_cover_path($article) ?: $blog['default_image']);
    $articleSchema=['@type'=>'Article','headline'=>$article['title'],'datePublished'=>$article['published_at']?:$article['created_at'],'dateModified'=>$article['updated_at'],'mainEntityOfPage'=>$canonicalUrl,'author'=>['@type'=>'Person','name'=>(($article['author_name']??'')?:'فريق محراب')],'publisher'=>['@type'=>'Organization','name'=>setting($db,'site_name','محراب')]];
    if($ogImage!=='')$articleSchema['image']=[str_starts_with($ogImage,'uploads/')?media_url($ogImage,true):$ogImage];
    $jsonLd=['@context'=>'https://schema.org','@graph'=>[$articleSchema,['@type'=>'BreadcrumbList','itemListElement'=>[['@type'=>'ListItem','position'=>1,'name'=>'الرئيسية','item'=>base_url('/')],['@type'=>'ListItem','position'=>2,'name'=>'المقالات','item'=>base_url('/articles')],['@type'=>'ListItem','position'=>3,'name'=>$article['title'],'item'=>$canonicalUrl]]]]];
    render('article',['title'=>(($article['seo_title']??'')?:($article['title'].' | محراب')),'description'=>(($article['seo_description']??'')?:(($article['excerpt']??'')?:blog_excerpt((string)$article['content_html'],180))),'canonical'=>$canonicalUrl,'robots'=>(string)($article['robots']??'index,follow'),'ogImage'=>$ogImage,'ogTitle'=>(string)($article['og_title']??''),'ogDescription'=>(string)($article['og_description']??''),'jsonLd'=>$jsonLd,'article'=>$article,'contentHtml'=>$contentHtml,'toc'=>$toc,'tags'=>$tags,'related'=>$related,'references'=>$references,'blog'=>$blog]);exit;
}

if ($path === '/how-we-verify') { $systemMeta=cms_system_page_meta($db,'how-we-verify');render('how',['title'=>(string)(($systemMeta['seo_title']??'')?:'كيف يضيف محراب الملفات ويراجع البيانات؟').' | محراب','description'=>(string)(($systemMeta['seo_description']??'')?:'تعرف على معنى الإضافة والمراجعة داخل محراب وحدود ما تعنيه للمستخدم.'),'canonical'=>(!empty($systemMeta['canonical_url'])?(string)$systemMeta['canonical_url']:base_url('/how-we-verify')),'robots'=>$systemMeta['robots']??'index,follow','ogTitle'=>$systemMeta['og_title']??'','ogDescription'=>$systemMeta['og_description']??'','ogImage'=>$systemMeta['og_image']??'']); exit; }
if ($path === '/about') {
    $managed=cms_page_by_route($db,'/about');if($managed){$canonical=trim((string)($managed['canonical_url']??''))?:base_url('/about');render('custom_page',['title'=>$managed['seo_title']?:($managed['title'].' | محراب'),'description'=>$managed['seo_description']?:'','canonical'=>$canonical,'ogTitle'=>$managed['og_title']??'','ogDescription'=>$managed['og_description']??'','ogImage'=>$managed['og_image']??'','robots'=>$managed['robots']??'index,follow','pageFavicon'=>$managed['favicon']?:'','page'=>$managed]);exit;}
    $content='<h2>ما هو محراب؟</h2><p>محراب دليل معلومات عربي للرقاة ومراكز الرقية. لا يسمح الموقع بإضافة الملفات ذاتيًا من الواجهة العامة؛ إدارة محراب هي التي تنشئ الملفات وتراجع المعلومات التي تعرضها وفق آلية العمل المعتمدة لديها.</p><h2>ما الذي لا يعنيه الظهور في الدليل؟</h2><p>وجود الملف أو مراجعة بياناته لا يمثل اعتمادًا طبيًا أو حكوميًا، ولا يضمن نتيجة علاجية. الغرض هو تنظيم المعلومات وتسهيل الوصول إليها بصورة أوضح.</p>';
    render('static',['title'=>'عن محراب','description'=>'تعرف على هدف منصة محراب وطريقة إدارة الدليل.','canonical'=>base_url('/about'),'heading'=>'عن محراب','intro'=>'دليل عربي منظم يضع الوضوح وسهولة الوصول إلى المعلومات في المقدمة.','content'=>$content]);exit;
}

foreach (['/review'=>'review','/complaint'=>'complaint','/data-report'=>'report'] as $route=>$type) {
    if ($path === $route) {
        $listingId=(int)($_GET['listing'] ?? $_POST['listing_id'] ?? 0);$listing=require_listing($db,$listingId);
        if($type==='review'&&!setting_bool($db,'reviews_enabled',true)){http_response_code(404);render('404',['title'=>'التقييمات غير متاحة | محراب']);exit;}
        if(is_post()){
            verify_csrf(); rate_limit($type,15);
            $name=mb_substr(trim((string)($_POST['name']??'')),0,160,'UTF-8');$email=trim((string)($_POST['email']??''));$phone=mb_substr(trim((string)($_POST['phone']??'')),0,80,'UTF-8');$message=mb_substr(trim((string)($_POST['message']??'')),0,5000,'UTF-8');
            if(!$name||mb_strlen($message,'UTF-8')<5){flash('error','يرجى كتابة الاسم والتفاصيل بشكل واضح.');redirect($route.'?listing='.$listingId);}
            if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)){flash('error','البريد الإلكتروني غير صحيح.');redirect($route.'?listing='.$listingId);}
            if($type==='review'){
                $rating=max(1,min(5,(int)($_POST['rating']??0)));$st=$db->prepare("INSERT INTO reviews(listing_id,reviewer_name,email,phone,rating,message,status,created_at) VALUES(?,?,?,?,?,?,'pending',NOW())");$st->execute([$listingId,$name,$email?:null,$phone?:null,$rating,$message]);
            }elseif($type==='complaint'){
                $categories=['معلومات غير صحيحة','انتحال شخصية','نشاط مشبوه','طلب أموال بطريقة غير مناسبة','سلوك غير مناسب','أخرى'];$severities=['normal','medium','high'];$category=in_array($_POST['category']??'', $categories,true)?(string)$_POST['category']:'أخرى';$severity=in_array($_POST['severity']??'', $severities,true)?(string)$_POST['severity']:'normal';
                $evidence=null;if(!empty($_FILES['evidence']['tmp_name']))$evidence=upload_private_image('evidence','evidence');
                if(final_v6_schema($db)){$priority=$severity==='high'?'high':($severity==='medium'?'normal':'low');$st=$db->prepare("INSERT INTO complaints(listing_id,name,email,phone,category,severity,message,evidence,status,priority,created_at) VALUES(?,?,?,?,?,?,?,?, 'new',?,NOW())");$st->execute([$listingId,$name,$email?:null,$phone?:null,$category,$severity,$message,$evidence,$priority]);}
                else{$st=$db->prepare("INSERT INTO complaints(listing_id,name,email,phone,category,severity,message,evidence,status,created_at) VALUES(?,?,?,?,?,?,?,?, 'new',NOW())");$st->execute([$listingId,$name,$email?:null,$phone?:null,$category,$severity,$message,$evidence]);}
            }else{
                $categories=['معلومات غير صحيحة','رقم غير صحيح','عنوان غير صحيح','بيانات قديمة','انتحال شخصية','أخرى'];$category=in_array($_POST['category']??'', $categories,true)?(string)$_POST['category']:'أخرى';
                $st=$db->prepare("INSERT INTO data_reports(listing_id,name,email,phone,category,message,status,created_at) VALUES(?,?,?,?,?,?,'new',NOW())");$st->execute([$listingId,$name,$email?:null,$phone?:null,$category,$message]);
            }
            $recordId=(int)$db->lastInsertId();audit($db,0,'public_'.$type.'_submitted',$type,$recordId,'listing='.$listingId);$labels=['review'=>'تقييم جديد','complaint'=>'شكوى جديدة','report'=>'بلاغ تصحيح بيانات'];$templateKeys=['review'=>'new_review_admin','complaint'=>'new_complaint_admin','report'=>'data_report_admin'];[$mailSubject,$mailBody]=email_template_text($db,$templateKeys[$type],['center'=>(string)$listing['name'],'id'=>$recordId],$labels[$type].' — '.(string)$listing['name'],'تم استلام '.$labels[$type].' مرتبط بالسجل: '.(string)$listing['name']."\nرقم الطلب: ".$recordId."\nراجع لوحة الإدارة لاتخاذ الإجراء المناسب.");send_admin_notification($db,$config,$mailSubject,$mailBody);
            flash('success','تم استلام طلبك وسيتم مراجعته.');redirect('/person/'.$listing['slug']);
        }
        $headings=['review'=>'تقييم التجربة','complaint'=>'تقديم شكوى','report'=>'الإبلاغ عن بيانات غير صحيحة'];$intros=['review'=>'شارك تجربتك بموضوعية. التقييم لا يُنشر قبل مراجعته.','complaint'=>'اكتب تفاصيل الشكوى بدقة، ويمكن إرفاق صورة عند توفرها.','report'=>'استخدم هذا النموذج للإبلاغ عن رقم أو عنوان أو معلومة تحتاج تحديثًا.'];
        render('form_public',['title'=>$headings[$type].' | محراب','heading'=>$headings[$type],'intro'=>$intros[$type],'type'=>$type,'listingId'=>$listingId,'listing'=>$listing,'robots'=>'noindex,nofollow']);exit;
    }
}


// CMS v5: custom content routes are resolved centrally without hard-coding the current domain.
if (cms_has_v5_schema($db)) {
    $cmsPage = cms_page_by_route($db,$path);
    if ($cmsPage) {
        $canonical = trim((string)($cmsPage['canonical_url']??'')) ?: base_url(cms_page_public_path($cmsPage));
        render('custom_page',[
            'title'=>$cmsPage['seo_title']?:($cmsPage['title'].' | محراب'),
            'description'=>$cmsPage['seo_description']?:'',
            'canonical'=>$canonical,
            'ogTitle'=>$cmsPage['og_title']?:($cmsPage['seo_title']?:$cmsPage['title']),
            'ogDescription'=>$cmsPage['og_description']?:$cmsPage['seo_description'],
            'ogImage'=>$cmsPage['og_image']?:'',
            'robots'=>$cmsPage['robots']?:'index,follow',
            'pageFavicon'=>$cmsPage['favicon']?:'',
            'page'=>$cmsPage
        ]);exit;
    }
    if ($target=cms_redirect_target($db,$path)) { header('Location: '.internal_url($target),true,301); exit; }
}

if (preg_match('#^/page/([^/]+)$#u',$path,$m)) {
    try {
        if(cms_has_v5_schema($db)){
            $st=$db->prepare("SELECT * FROM pages WHERE slug=? AND page_type IN('content','landing','custom') AND deleted_at IS NULL AND (status='published' OR (status='scheduled' AND scheduled_at IS NOT NULL AND scheduled_at<=NOW())) LIMIT 1");
        }else{
            $st=$db->prepare("SELECT * FROM pages WHERE slug=? AND status='published' LIMIT 1");
        }
        $st->execute([$m[1]]);$page=$st->fetch();
    } catch (Throwable $e) { $page=false; }
    if(!$page){http_response_code(404);render('404',['title'=>'الصفحة غير موجودة | محراب']);exit;}
    $canonical=(cms_has_v5_schema($db)&&!empty($page['canonical_url']))?(string)$page['canonical_url']:base_url(cms_page_public_path($page));
    render('custom_page',['title'=>$page['seo_title']?:($page['title'].' | محراب'),'description'=>$page['seo_description']?:'','canonical'=>$canonical,'ogTitle'=>$page['og_title']??'','ogDescription'=>$page['og_description']??'','ogImage'=>$page['og_image']??'','robots'=>$page['robots']??'index,follow','pageFavicon'=>$page['favicon']?:'','page'=>$page]);exit;
}

if ($path === '/privacy' || $path === '/terms') {
    $heading=$path==='/privacy'?'سياسة الخصوصية':'الشروط والأحكام';
    $content=$path==='/privacy'
        ? '<h2>البيانات التي نستقبلها</h2><p>قد نستقبل بيانات يرسلها الزائر عبر التقييمات أو الشكاوى أو بلاغات تصحيح البيانات. تستخدم هذه المعلومات للمراجعة والإدارة ولا تُعرض بيانات المشتكين الحساسة للعامة.</p><h2>التحليلات والتسويق والإعلانات</h2><p>عند تفعيل أدوات غير ضرورية، يطلب محراب اختيارك قبل تحميلها عندما تكون الموافقة مطلوبة. يمكنك تغيير قرارك لاحقًا من زر «إعدادات الخصوصية».</p><h2>المرفقات</h2><p>مرفقات الشكاوى تحفظ للاستخدام الإداري ولا ينبغي نشرها للعامة. تحدد الإدارة مدة الاحتفاظ وفق الحاجة والقوانين المنطبقة.</p>'
        : '<h2>طبيعة الدليل</h2><p>محراب منصة معلومات ودليل. إضافة ملف أو مراجعة بياناته لا تعني اعتمادًا طبيًا أو رسميًا ولا تضمن نتيجة علاجية.</p><h2>المعلومات</h2><p>نسعى لعرض بيانات واضحة وقابلة للتحديث، ويمكن للزوار إرسال بلاغ عند اكتشاف معلومات قديمة أو غير صحيحة.</p><h2>الصحة والسلامة</h2><p>محتوى محراب لا يحل محل التشخيص أو الرعاية الطبية أو النفسية المختصة عند الحاجة.</p>';
    render('static',['title'=>$heading.' | محراب','description'=>$heading.' لموقع محراب.','canonical'=>base_url($path),'heading'=>$heading,'intro'=>'نص تشغيلي يجب مراجعته قانونيًا بحسب الدول التي يخدمها الموقع قبل الإطلاق الواسع.','content'=>$content]);exit;
}

/* ADMIN AUTH */
if ($path === '/admin/login') {
    if(current_user($db)) redirect('/admin');
    if(is_post()){
        verify_csrf();$email=mb_strtolower(trim((string)($_POST['email']??'')),'UTF-8');$pass=(string)($_POST['password']??'');
        auth_rate_check($db,'login',$email,5,3600,900);
        $st=$db->prepare("SELECT * FROM users WHERE email=? AND status='active' LIMIT 1");$st->execute([$email]);$u=$st->fetch();
        if($u&&password_verify($pass,$u['password_hash'])){
            if(password_needs_rehash((string)$u['password_hash'],PASSWORD_ARGON2ID)){$db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pass,PASSWORD_ARGON2ID),(int)$u['id']]);}
            auth_rate_clear($db,'login',$email);login_clear_attempts($db,$email);session_regenerate_id(true);
            $two=auth_2fa($db,(int)$u['id']);
            if($two){$_SESSION['pending_2fa_user_id']=(int)$u['id'];$_SESSION['pending_2fa_at']=time();audit($db,(int)$u['id'],'login_password_ok','user',(int)$u['id'],'2fa required');redirect('/admin/2fa');}
            $_SESSION['user_id']=$u['id'];$_SESSION['last_activity']=time();$_SESSION['last_regeneration']=time();auth_register_session($db,(int)$u['id']);if(!empty($_POST['remember'])){$params=session_get_cookie_params();setcookie(session_name(),session_id(),['expires'=>time()+2592000,'path'=>$params['path']?:'/','domain'=>$params['domain']??'','secure'=>(bool)$params['secure'],'httponly'=>true,'samesite'=>'Lax']);}audit($db,(int)$u['id'],'login','user',(int)$u['id'],'success');redirect('/admin');
        }
        auth_rate_hit($db,'login',$email);login_record_failure($db,$email,5,15);audit($db,0,'login_failed','user',0,hash('sha256',$email));flash('error','بيانات الدخول غير صحيحة.');redirect('/admin/login');
    }
    auth_render('admin_login',['title'=>'تسجيل الدخول | محراب']);exit;
}
if ($path === '/admin/2fa') {
    $pending=(int)($_SESSION['pending_2fa_user_id']??0);if(!$pending||time()-(int)($_SESSION['pending_2fa_at']??0)>600)redirect('/admin/login');
    if(is_post()){verify_csrf();auth_rate_check($db,'2fa',(string)$pending,8,900,900);$record=auth_2fa($db,$pending);$code=trim((string)($_POST['code']??''));$ok=$record&&(auth_totp_verify((string)$record['secret_enc'],$code)||auth_verify_backup_code($db,$pending,$code,$record));if($ok){auth_rate_clear($db,'2fa',(string)$pending);unset($_SESSION['pending_2fa_user_id'],$_SESSION['pending_2fa_at']);$_SESSION['user_id']=$pending;$_SESSION['last_activity']=time();$_SESSION['last_regeneration']=time();session_regenerate_id(true);auth_register_session($db,$pending);audit($db,$pending,'login_2fa','user',$pending,'success');redirect('/admin');}auth_rate_hit($db,'2fa',(string)$pending);flash('error','رمز التحقق غير صحيح.');redirect('/admin/2fa');}
    auth_render('admin_2fa_challenge',['title'=>'التحقق بخطوتين | محراب']);exit;
}
if ($path === '/admin/forgot-password') {
    if(is_post()){verify_csrf();$email=mb_strtolower(trim((string)($_POST['email']??'')),'UTF-8');auth_rate_check($db,'password_reset',$email,3,3600,3600);auth_rate_hit($db,'password_reset',$email);if(filter_var($email,FILTER_VALIDATE_EMAIL)){$st=$db->prepare("SELECT id,name,email FROM users WHERE email=? AND status='active' LIMIT 1");$st->execute([$email]);$u=$st->fetch();if($u){[$token,$hash]=auth_new_token();$db->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([(int)$u['id']]);$db->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at,created_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 15 MINUTE),NOW())')->execute([(int)$u['id'],$hash]);$url=base_url('/admin/reset-password?token='.rawurlencode($token));[$html,$text]=auth_email_template('استعادة كلمة مرور محراب','وصلنا طلب لتغيير كلمة مرور حساب الإدارة. الرابط صالح لمدة 15 دقيقة ويعمل مرة واحدة فقط.','تعيين كلمة مرور جديدة',$url);mailer_send((string)$u['email'],'استعادة كلمة مرور محراب',$html,$text);audit($db,(int)$u['id'],'password_reset_requested','user',(int)$u['id'],'');}}flash('success','إذا كان البريد مسجلًا فستصلك رسالة الاستعادة خلال دقائق.');redirect('/admin/forgot-password');}
    auth_render('admin_forgot_password',['title'=>'استعادة كلمة المرور | محراب']);exit;
}
if ($path === '/admin/reset-password') {
    $token=trim((string)($_GET['token']??$_POST['token']??''));$row=false;if($token!==''){try{$st=$db->prepare('SELECT p.*,u.email FROM password_reset_tokens p JOIN users u ON u.id=p.user_id WHERE p.token_hash=? AND p.used_at IS NULL AND p.expires_at>NOW() LIMIT 1');$st->execute([hash('sha256',$token)]);$row=$st->fetch();}catch(Throwable $e){}}
    if(!$row){http_response_code(400);auth_render('admin_status',['title'=>'رابط غير صالح | محراب','statusTitle'=>'الرابط غير صالح أو انتهت صلاحيته','statusText'=>'اطلب رابط استعادة جديدًا من صفحة تسجيل الدخول.']);exit;}
    if(is_post()){verify_csrf();$password=(string)($_POST['password']??'');$confirm=(string)($_POST['confirm']??'');$errors=auth_password_errors($password);if($password!==$confirm)$errors[]='تأكيد مطابق';if($errors){flash('error','متطلبات كلمة المرور: '.implode('، ',$errors));redirect('/admin/reset-password?token='.rawurlencode($token));}$hash=password_hash($password,PASSWORD_ARGON2ID);$db->beginTransaction();try{$db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash,(int)$row['user_id']]);$db->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([(int)$row['user_id']]);$db->prepare('INSERT INTO user_security(user_id,password_changed_at) VALUES(?,NOW()) ON DUPLICATE KEY UPDATE password_changed_at=NOW()')->execute([(int)$row['user_id']]);auth_revoke_all_sessions($db,(int)$row['user_id']);audit($db,(int)$row['user_id'],'password_reset_completed','user',(int)$row['user_id'],'');$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}[$html,$text]=auth_email_template('تم تغيير كلمة المرور','تم تغيير كلمة مرور حساب إدارة محراب. إذا لم تكن أنت من قام بهذا الإجراء فتواصل مع مدير النظام فورًا.');mailer_send((string)$row['email'],'تنبيه أمني من محراب',$html,$text);flash('success','تم تغيير كلمة المرور. يمكنك تسجيل الدخول الآن.');redirect('/admin/login');}
    auth_render('admin_reset_password',['title'=>'كلمة مرور جديدة | محراب','token'=>$token]);exit;
}
if ($path === '/admin/signup') {
    $token=trim((string)($_GET['token']??$_POST['token']??''));$invite=false;if($token!==''){try{$st=$db->prepare('SELECT * FROM signup_invites WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1');$st->execute([hash('sha256',$token)]);$invite=$st->fetch();}catch(Throwable $e){}}
    if(!$invite){http_response_code(403);auth_render('admin_status',['title'=>'التسجيل مغلق | محراب','statusTitle'=>'التسجيل العام غير متاح','statusText'=>'حسابات الإدارة تُنشأ فقط بواسطة دعوة آمنة من مدير النظام.']);exit;}
    if(is_post()){verify_csrf();$name=mb_substr(trim((string)($_POST['name']??'')),0,160,'UTF-8');$pass=(string)($_POST['password']??'');$confirm=(string)($_POST['confirm']??'');$errors=auth_password_errors($pass);if($pass!==$confirm)$errors[]='تأكيد مطابق';if(!$name)$errors[]='الاسم';if($errors){flash('error','تحقق من: '.implode('، ',$errors));redirect('/admin/signup?token='.rawurlencode($token));}$db->beginTransaction();try{$db->prepare("INSERT INTO users(name,email,password_hash,role,status,created_at) VALUES(?,?,?,?, 'active',NOW())")->execute([$name,$invite['email'],password_hash($pass,PASSWORD_ARGON2ID),$invite['role']]);$uid=(int)$db->lastInsertId();$db->prepare('INSERT INTO user_security(user_id,email_verified_at,password_changed_at) VALUES(?,NOW(),NOW())')->execute([$uid]);$db->prepare('UPDATE signup_invites SET used_at=NOW() WHERE id=?')->execute([(int)$invite['id']]);audit($db,$uid,'signup_invite_completed','user',$uid,'');$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();flash('error','تعذر إنشاء الحساب. قد يكون البريد مستخدمًا مسبقًا.');redirect('/admin/signup?token='.rawurlencode($token));}flash('success','تم إنشاء الحساب. سجل الدخول الآن.');redirect('/admin/login');}
    auth_render('admin_signup',['title'=>'إنشاء حساب | محراب','token'=>$token,'invite'=>$invite]);exit;
}
if ($path === '/admin/logout') { if($u=current_user($db))auth_revoke_session($db,(int)$u['id']); $_SESSION=[]; if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);}session_destroy();redirect('/admin/login'); }

if (str_starts_with($path,'/admin')) {
    $user=require_admin($db);
    require_once __DIR__.'/private/site_credit_admin.php';

    if ($path === '/admin/api/locations/search') {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, private');
        if (!can($user, 'locations')) { http_response_code(403); echo json_encode(['error'=>'غير مصرح']); exit; }
        if (!location_v7_schema($db)) { http_response_code(503); echo json_encode(['error'=>'تحتاج قاعدة البيانات إلى migration 008.']); exit; }
        try {
            if ($method !== 'GET') throw new InvalidArgumentException('الطلب غير مدعوم.');
            $type = (string)($_GET['type'] ?? '');
            $q = (string)($_GET['q'] ?? '');
            $countryId = max(0, (int)($_GET['country_id'] ?? 0)) ?: null;
            $regionId = max(0, (int)($_GET['region_id'] ?? 0)) ?: null;
            $featured=(string)($_GET['featured']??'');
            $items=$type==='country'&&$featured==='arab'&&trim($q)===''?location_arab_countries($db,false):location_search($db,$type,$q,$countryId,$regionId,false);
            echo json_encode(['items'=>$items], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch (InvalidArgumentException $e) {
            http_response_code(422); echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if($path==='/admin/account'){
        if(is_post()){
            verify_csrf();$action=(string)($_POST['action']??'password');
            if($action==='password'){
                $current=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');$confirm=(string)($_POST['confirm_password']??'');$st=$db->prepare('SELECT password_hash,email FROM users WHERE id=? LIMIT 1');$st->execute([(int)$user['id']]);$account=$st->fetch();
                if(!$account||!password_verify($current,(string)$account['password_hash'])){flash('error','كلمة المرور الحالية غير صحيحة.');redirect('/admin/account');}
                $errors=auth_password_errors($new);if($new!==$confirm)$errors[]='تأكيد مطابق';if($errors){flash('error','متطلبات كلمة المرور: '.implode('، ',$errors));redirect('/admin/account');}
                $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new,PASSWORD_ARGON2ID),(int)$user['id']]);$db->prepare('INSERT INTO user_security(user_id,password_changed_at) VALUES(?,NOW()) ON DUPLICATE KEY UPDATE password_changed_at=NOW()')->execute([(int)$user['id']]);auth_revoke_other_sessions($db,(int)$user['id']);session_regenerate_id(true);audit($db,(int)$user['id'],'password_change','user',(int)$user['id'],'self-service');[$html,$text]=auth_email_template('تم تغيير كلمة المرور','تم تغيير كلمة مرور حساب إدارة محراب بنجاح.');mailer_send((string)$account['email'],'تنبيه أمني من محراب',$html,$text);flash('success','تم تغيير كلمة المرور وتسجيل الخروج من الأجهزة الأخرى.');redirect('/admin/account');
            }
            if($action==='enable_2fa'){
                $secret=(string)($_SESSION['2fa_setup_secret']??'');$code=trim((string)($_POST['code']??''));if($secret===''||!auth_totp_verify($secret,$code)){flash('error','رمز المصادقة غير صحيح.');redirect('/admin/account');}[$backup,$hashes]=auth_backup_codes();$db->prepare('INSERT INTO two_factor_auth(user_id,secret_enc,backup_codes_hash,enabled_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE secret_enc=VALUES(secret_enc),backup_codes_hash=VALUES(backup_codes_hash),enabled_at=NOW()')->execute([(int)$user['id'],$secret,json_encode($hashes)]);unset($_SESSION['2fa_setup_secret']);$_SESSION['2fa_backup_codes']=$backup;audit($db,(int)$user['id'],'2fa_enabled','user',(int)$user['id'],'');flash('success','تم تفعيل التحقق بخطوتين. احفظ الرموز الاحتياطية المعروضة أدناه.');redirect('/admin/account');
            }
            if($action==='disable_2fa'){$current=(string)($_POST['current_password']??'');$st=$db->prepare('SELECT password_hash FROM users WHERE id=?');$st->execute([(int)$user['id']]);if(!password_verify($current,(string)$st->fetchColumn())){flash('error','كلمة المرور الحالية غير صحيحة.');redirect('/admin/account');}$db->prepare('DELETE FROM two_factor_auth WHERE user_id=?')->execute([(int)$user['id']]);audit($db,(int)$user['id'],'2fa_disabled','user',(int)$user['id'],'');flash('success','تم تعطيل التحقق بخطوتين.');redirect('/admin/account');}
            if($action==='revoke_other_sessions'){auth_revoke_other_sessions($db,(int)$user['id']);audit($db,(int)$user['id'],'sessions_revoked','user',(int)$user['id'],'others');flash('success','تم تسجيل الخروج من الجلسات الأخرى.');redirect('/admin/account');}
        }
        $twoFactor=auth_2fa($db,(int)$user['id']);if(!$twoFactor && empty($_SESSION['2fa_setup_secret']))$_SESSION['2fa_setup_secret']=auth_totp_secret();$setupSecret=(string)($_SESSION['2fa_setup_secret']??'');$backupCodes=$_SESSION['2fa_backup_codes']??[];unset($_SESSION['2fa_backup_codes']);$sessions=auth_active_sessions($db,(int)$user['id']);
        admin_render('admin_account',['title'=>'حسابي','twoFactor'=>$twoFactor,'setupSecret'=>$setupSecret,'backupCodes'=>$backupCodes,'sessions'=>$sessions]);exit;
    }

    if($path==='/admin/evidence' && can($user,'complaints')){
        $id=(int)($_GET['id']??0);$st=$db->prepare('SELECT evidence FROM complaints WHERE id=? LIMIT 1');$st->execute([$id]);$row=$st->fetch();$rel=(string)($row['evidence']??'');
        if(!$rel||str_contains($rel,'..')||!str_starts_with($rel,'evidence/')){http_response_code(404);exit('غير موجود');}
        $file=configured_storage_path('private',$rel);if(!is_file($file)){http_response_code(404);exit('غير موجود');}
        $info=@getimagesize($file);header('Content-Type: '.($info['mime']??'application/octet-stream'));header('X-Content-Type-Options: nosniff');header('Content-Disposition: inline; filename="evidence"');readfile($file);exit;
    }

    if($path==='/admin'){
        $stats=['listings'=>(int)$db->query("SELECT COUNT(*) FROM listings WHERE status='published'")->fetchColumn(),'complaints'=>(int)$db->query("SELECT COUNT(*) FROM complaints WHERE status='new'")->fetchColumn(),'reviews'=>(int)$db->query("SELECT COUNT(*) FROM reviews WHERE status='pending'")->fetchColumn(),'articles'=>(int)$db->query("SELECT COUNT(*) FROM articles WHERE status='published'")->fetchColumn(),'stale'=>(int)$db->query("SELECT COUNT(*) FROM listings WHERE status='needs_update' OR review_state='needs_update' OR (last_verified_at IS NOT NULL AND last_verified_at < DATE_SUB(CURDATE(),INTERVAL 12 MONTH))")->fetchColumn(),'reports'=>(int)$db->query("SELECT COUNT(*) FROM data_reports WHERE status='new'")->fetchColumn(),'high'=>(int)$db->query("SELECT COUNT(*) FROM complaints WHERE severity='high' AND status IN('new','reviewing')")->fetchColumn(),'users'=>(int)$db->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn()];
        if(final_v6_schema($db)){try{$stats['verified']=(int)$db->query("SELECT COUNT(*) FROM listings WHERE verification_status='verified' AND deleted_at IS NULL")->fetchColumn();$stats['verification_pending']=(int)$db->query("SELECT COUNT(*) FROM listings WHERE verification_status='pending' AND deleted_at IS NULL")->fetchColumn();$stats['suspended']=(int)$db->query("SELECT COUNT(*) FROM listings WHERE status='suspended' AND deleted_at IS NULL")->fetchColumn();$stats['countries']=(int)$db->query("SELECT COUNT(*) FROM countries WHERE is_active=1")->fetchColumn();$stats['cities']=(int)$db->query("SELECT COUNT(*) FROM cities WHERE is_active=1")->fetchColumn();}catch(Throwable $e){}}
        $activity=[];$trend=[];try{$activity=$db->query("SELECT a.action,a.entity_type,a.details,a.created_at,u.name user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 8")->fetchAll();$trend=$db->query("SELECT DATE(created_at) d,COUNT(*) c FROM audit_logs WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) GROUP BY DATE(created_at) ORDER BY d")->fetchAll();}catch(Throwable $e){}
        admin_render('admin_dashboard',['title'=>'لوحة المتابعة','stats'=>$stats,'activity'=>$activity,'trend'=>$trend]);exit;
    }

    if($path==='/admin/pages' && can($user,'pages')){
        if(!cms_has_v5_schema($db)){try{$pages=$db->query('SELECT id,title,slug,status,seo_description,updated_at FROM pages ORDER BY updated_at DESC,id DESC')->fetchAll();}catch(Throwable $e){$pages=[];}flash('error','نظام الصفحات المركزي يحتاج تطبيق migration 006_pages_menus_cms.sql أولًا.');admin_render('admin_pages',['title'=>'الصفحات','pages'=>$pages,'legacyMode'=>true,'filters'=>[],'pagination'=>['page'=>1,'pages'=>1,'total'=>count($pages)]]);exit;}
        if(is_post()){
            verify_csrf();$action=(string)($_POST['action']??'');$id=(int)($_POST['id']??0);
            try{
                if(!$id)throw new InvalidArgumentException('الصفحة غير محددة.');$st=$db->prepare('SELECT * FROM pages WHERE id=? LIMIT 1');$st->execute([$id]);$pageRow=$st->fetch();if(!$pageRow)throw new InvalidArgumentException('الصفحة غير موجودة.');
                if($action==='trash'){if(($pageRow['page_type']??'')==='system')throw new InvalidArgumentException('لا يمكن حذف صفحة نظام أساسية.');$usage=cms_page_menu_usage($db,$id);cms_save_page_revision($db,$pageRow,(int)$user['id']);$db->prepare('UPDATE pages SET deleted_at=NOW(),updated_by=?,updated_at=NOW() WHERE id=?')->execute([(int)$user['id'],$id]);audit($db,(int)$user['id'],'page_trash','page',$id,'menu_usage='.$usage);flash('success',$usage?'نقلت الصفحة إلى السلة. تنبيه: ما زالت مرتبطة بعناصر قائمة وسيتم إخفاؤها تلقائيًا.':'نقلت الصفحة إلى السلة.');}
                elseif($action==='restore'){$db->prepare('UPDATE pages SET deleted_at=NULL,updated_by=?,updated_at=NOW() WHERE id=?')->execute([(int)$user['id'],$id]);audit($db,(int)$user['id'],'page_restore','page',$id,'');flash('success','تمت استعادة الصفحة.');}
                elseif($action==='publish'){if(($pageRow['page_type']??'')==='system')throw new InvalidArgumentException('صفحة النظام تُدار من إعداداتها الآمنة.');$db->prepare("UPDATE pages SET status='published',published_at=COALESCE(published_at,NOW()),deleted_at=NULL,updated_by=?,updated_at=NOW() WHERE id=?")->execute([(int)$user['id'],$id]);flash('success','تم نشر الصفحة.');}
                elseif($action==='draft'){if(($pageRow['page_type']??'')==='system')throw new InvalidArgumentException('صفحة النظام لا تُحوّل إلى مسودة من هنا.');$db->prepare("UPDATE pages SET status='draft',updated_by=?,updated_at=NOW() WHERE id=?")->execute([(int)$user['id'],$id]);flash('success','تم إلغاء نشر الصفحة وحفظها كمسودة.');}
                else throw new InvalidArgumentException('الإجراء غير معروف.');
            }catch(Throwable $e){flash('error',$e instanceof InvalidArgumentException?$e->getMessage():'تعذر تنفيذ الإجراء.');}
            redirect('/admin/pages'.(!empty($_GET['trash'])?'?trash=1':''));
        }
        $filters=['q'=>mb_substr(trim((string)($_GET['q']??'')),0,120,'UTF-8'),'status'=>(string)($_GET['status']??''),'type'=>(string)($_GET['type']??''),'lang'=>(string)($_GET['lang']??''),'trash'=>!empty($_GET['trash'])];$where=[];$params=[];
        $where[]=$filters['trash']?'p.deleted_at IS NOT NULL':'p.deleted_at IS NULL';
        if($filters['q']!==''){$where[]='(p.title LIKE ? OR p.slug LIKE ? OR p.route_path LIKE ?)';$like='%'.$filters['q'].'%';array_push($params,$like,$like,$like);}
        if(in_array($filters['status'],['draft','published','scheduled','private','archived'],true)){$where[]='p.status=?';$params[]=$filters['status'];}
        if(in_array($filters['type'],['content','system','dynamic','landing','custom'],true)){$where[]='p.page_type=?';$params[]=$filters['type'];}
        if($filters['lang']!==''){$where[]='p.language_code=?';$params[]=$filters['lang'];}
        $whereSql=implode(' AND ',$where);$count=$db->prepare("SELECT COUNT(*) FROM pages p WHERE {$whereSql}");$count->execute($params);$pg=pagination_meta((int)$count->fetchColumn(),page_number(),30);
        $st=$db->prepare("SELECT p.*,(SELECT COUNT(*) FROM menu_items mi WHERE mi.page_id=p.id) menu_usage FROM pages p WHERE {$whereSql} ORDER BY p.updated_at DESC,p.id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");$st->execute($params);$pages=$st->fetchAll();
        try{$languages=$db->query('SELECT code,name FROM languages WHERE is_active=1 ORDER BY is_default DESC,sort_order,id')->fetchAll();}catch(Throwable $e){$languages=[['code'=>'ar','name'=>'العربية']];}
        admin_render('admin_pages',['title'=>'الصفحات','pages'=>$pages,'filters'=>$filters,'pagination'=>$pg,'languages'=>$languages]);exit;
    }

    if($path==='/admin/page/preview' && can($user,'pages')){
        $id=(int)($_GET['id']??0);$st=$db->prepare('SELECT * FROM pages WHERE id=? AND deleted_at IS NULL LIMIT 1');$st->execute([$id]);$page=$st->fetch();if(!$page){http_response_code(404);render('404',['title'=>'الصفحة غير موجودة | محراب']);exit;}
        if(in_array((string)($page['page_type']??'content'),['system','dynamic'],true)){redirect(cms_page_public_path($page));}
        render('custom_page',['title'=>$page['seo_title']?:($page['title'].' | محراب'),'description'=>$page['seo_description']?:'','canonical'=>base_url(cms_page_public_path($page)),'ogTitle'=>$page['og_title']?:$page['seo_title'],'ogDescription'=>$page['og_description']?:$page['seo_description'],'ogImage'=>$page['og_image']?:'','robots'=>'noindex,nofollow','pageFavicon'=>$page['favicon']?:'','page'=>$page,'previewMode'=>true]);exit;
    }

    if($path==='/admin/page/revisions' && can($user,'pages')){
        if(!cms_has_v5_schema($db)){flash('error','طبّق migration 006 أولًا.');redirect('/admin/pages');}$id=(int)($_GET['id']??$_POST['id']??0);$st=$db->prepare('SELECT * FROM pages WHERE id=? LIMIT 1');$st->execute([$id]);$page=$st->fetch();if(!$page){http_response_code(404);admin_render('admin_not_found',['title'=>'الصفحة غير موجودة']);exit;}
        if(is_post()){
            verify_csrf();$revisionId=(int)($_POST['revision_id']??0);$rs=$db->prepare('SELECT snapshot_json FROM page_revisions WHERE id=? AND page_id=? LIMIT 1');$rs->execute([$revisionId,$id]);$snap=json_decode((string)$rs->fetchColumn(),true);if(!is_array($snap)){flash('error','النسخة غير موجودة.');redirect('/admin/page/revisions?id='.$id);}cms_save_page_revision($db,$page,(int)$user['id']);
            $editable=['title','slug','language_code','template','route_path','icon_key','status','direction','content_html','content_json','custom_css','custom_js','seo_title','seo_description','og_title','og_description','og_image','canonical_url','robots','scheduled_at'];$set=[];$vals=[];foreach($editable as $key){if(array_key_exists($key,$snap)){$set[]="`{$key}`=?";$vals[]=$snap[$key];}}$set[]='updated_by=?';$vals[]=(int)$user['id'];$set[]='updated_at=NOW()';$vals[]=$id;$db->prepare('UPDATE pages SET '.implode(',',$set).' WHERE id=?')->execute($vals);audit($db,(int)$user['id'],'page_revision_restore','page',$id,'revision='.$revisionId);flash('success','تمت استعادة النسخة السابقة.');redirect('/admin/page/edit?id='.$id);
        }
        $rs=$db->prepare('SELECT r.*,u.name user_name FROM page_revisions r LEFT JOIN users u ON u.id=r.user_id WHERE r.page_id=? ORDER BY r.id DESC LIMIT 50');$rs->execute([$id]);admin_render('admin_page_revisions',['title'=>'سجل نسخ الصفحة','page'=>$page,'revisions'=>$rs->fetchAll()]);exit;
    }

    if(in_array($path,['/admin/page/new','/admin/page/edit'],true) && can($user,'pages')){
        $v5=cms_has_v5_schema($db);$id=(int)($_GET['id']??$_POST['id']??0);$item=null;if($id){$st=$db->prepare('SELECT * FROM pages WHERE id=? LIMIT 1');$st->execute([$id]);$item=$st->fetch();if(!$item){http_response_code(404);admin_render('admin_not_found',['title'=>'الصفحة غير موجودة']);exit;}}
        if(is_post()){
            verify_csrf();$isProtected=$item&&in_array((string)($item['page_type']??''),['system','dynamic'],true);$title=mb_substr(trim((string)($_POST['title']??'')),0,240,'UTF-8');$slug=slugify(mb_substr(trim((string)($_POST['slug']??'')),0,220,'UTF-8')?:$title);$pageType=$isProtected?(string)$item['page_type']:(in_array($_POST['page_type']??'', ['content','landing','custom'],true)?(string)$_POST['page_type']:'content');$language=mb_substr(strtolower(trim((string)($_POST['language_code']??'ar'))),0,12,'UTF-8');$template=in_array($_POST['template']??'', ['default','full_width','landing','content','form','custom_layout'],true)?(string)$_POST['template']:'default';$status=$isProtected?(string)$item['status']:(in_array($_POST['status']??'', ['draft','published','scheduled','private','archived'],true)?(string)$_POST['status']:'draft');$direction=page_builder_allowed_direction((string)($_POST['direction']??'rtl'));
            $route=$v5?trim((string)($_POST['route_path']??'')):('/page/'.$slug);if($route==='')$route='/page/'.$slug;$route=$route==='/'?'/':'/'.trim($route,'/');if($v5&&$item&&!$isProtected){$oldDefault='/page/'.(string)$item['slug'];$oldRoute=trim((string)($item['route_path']??''))?:$oldDefault;if($route===$oldRoute&&$oldRoute===$oldDefault&&$slug!==(string)$item['slug'])$route='/page/'.$slug;}if(!cms_route_path_is_safe($route,$isProtected)){flash('error','مسار الصفحة غير صالح أو محجوز.');redirect($id?'/admin/page/edit?id='.$id:'/admin/page/new');}
            $html=$isProtected?(string)($item['content_html']??''):page_builder_sanitize_html((string)($_POST['content_html']??''));$json=$isProtected?(string)($item['content_json']??'[]'):mb_substr((string)($_POST['content_json']??'[]'),0,1000000,'UTF-8');json_decode($json,true);if(json_last_error()!==JSON_ERROR_NONE)$json='[]';$css=$isProtected?(string)($item['custom_css']??''):page_builder_sanitize_css((string)($_POST['custom_css']??''));$js=$isProtected?(string)($item['custom_js']??''):(($user['role']==='super_admin')?page_builder_sanitize_js((string)($_POST['custom_js']??'')):((string)($item['custom_js']??'')));
            $seoTitle=mb_substr(trim((string)($_POST['seo_title']??'')),0,255,'UTF-8');$seoDescription=mb_substr(trim((string)($_POST['seo_description']??'')),0,500,'UTF-8');$favicon=mb_substr(trim((string)($_POST['favicon']??'')),0,255,'UTF-8');$icon=cms_icon_key((string)($_POST['icon_key']??''));$ogTitle=mb_substr(trim((string)($_POST['og_title']??'')),0,255,'UTF-8');$ogDescription=mb_substr(trim((string)($_POST['og_description']??'')),0,500,'UTF-8');$ogImage=mb_substr(trim((string)($_POST['og_image']??'')),0,255,'UTF-8');$canonical=mb_substr(trim((string)($_POST['canonical_url']??'')),0,700,'UTF-8');if($canonical!==''&&!valid_http_url($canonical))$canonical='';$robots=!empty($_POST['robots_index'])?'index,follow':'noindex,follow';$scheduledAt=trim((string)($_POST['scheduled_at']??''));$scheduledAt=$scheduledAt!==''?str_replace('T',' ',mb_substr($scheduledAt,0,16,'UTF-8')).':00':null;if($status==='scheduled'&&!$scheduledAt){flash('error','حدد موعد النشر للصفحة المجدولة.');redirect($id?'/admin/page/edit?id='.$id:'/admin/page/new');}
            if($title===''||(!$isProtected&&$html==='')){flash('error','العنوان ومحتوى الصفحة مطلوبان.');redirect($id?'/admin/page/edit?id='.$id:'/admin/page/new');}if($v5){$routeCheck=$db->prepare('SELECT id FROM pages WHERE route_path=? AND deleted_at IS NULL AND id<>? LIMIT 1');$routeCheck->execute([$route,$id]);if($routeCheck->fetch()){flash('error','هذا المسار مستخدم بواسطة صفحة أخرى.');redirect($id?'/admin/page/edit?id='.$id:'/admin/page/new');}}if($user['role']==='super_admin'&&!$isProtected)save_setting($db,'page_builder_custom_js',!empty($_POST['allow_custom_js'])?'1':'0');
            try{
                if($id){if($v5)cms_save_page_revision($db,$item,(int)$user['id']);$oldPath=$v5?cms_page_public_path($item):('/page/'.(string)$item['slug']);if($v5){$st=$db->prepare('UPDATE pages SET title=?,slug=?,page_type=?,language_code=?,template=?,route_path=?,icon_key=?,status=?,direction=?,content_html=?,content_json=?,custom_css=?,custom_js=?,seo_title=?,seo_description=?,og_title=?,og_description=?,og_image=?,canonical_url=?,robots=?,favicon=?,scheduled_at=?,updated_by=?,updated_at=NOW(),published_at=IF(?=\'published\',COALESCE(published_at,NOW()),published_at) WHERE id=?');$st->execute([$title,$slug,$pageType,$language,$template,$route,$icon?:null,$status,$direction,$html,$json,$css,$js,$seoTitle?:null,$seoDescription?:null,$ogTitle?:null,$ogDescription?:null,$ogImage?:null,$canonical?:null,$robots,$favicon?:null,$scheduledAt,(int)$user['id'],$status,$id]);cms_record_old_path($db,$id,$oldPath,$route);}else{$st=$db->prepare('UPDATE pages SET title=?,slug=?,status=?,direction=?,content_html=?,content_json=?,custom_css=?,custom_js=?,seo_title=?,seo_description=?,favicon=?,updated_by=?,updated_at=NOW(),published_at=IF(?=\'published\',COALESCE(published_at,NOW()),published_at) WHERE id=?');$st->execute([$title,$slug,$status,$direction,$html,$json,$css,$js,$seoTitle?:null,$seoDescription?:null,$favicon?:null,(int)$user['id'],$status,$id]);}}
                else{if($v5){$st=$db->prepare('INSERT INTO pages(title,slug,page_type,language_code,template,route_path,status,direction,content_html,content_json,custom_css,custom_js,seo_title,seo_description,og_title,og_description,og_image,canonical_url,robots,favicon,icon_key,scheduled_at,created_by,updated_by,created_at,updated_at,published_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),IF(?=\'published\',NOW(),NULL))');$st->execute([$title,$slug,$pageType,$language,$template,$route,$status,$direction,$html,$json,$css,$js,$seoTitle?:null,$seoDescription?:null,$ogTitle?:null,$ogDescription?:null,$ogImage?:null,$canonical?:null,$robots,$favicon?:null,$icon?:null,$scheduledAt,(int)$user['id'],(int)$user['id'],$status]);}else{$st=$db->prepare('INSERT INTO pages(title,slug,status,direction,content_html,content_json,custom_css,custom_js,seo_title,seo_description,favicon,created_by,updated_by,created_at,updated_at,published_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),IF(?=\'published\',NOW(),NULL))');$st->execute([$title,$slug,$status,$direction,$html,$json,$css,$js,$seoTitle?:null,$seoDescription?:null,$favicon?:null,(int)$user['id'],(int)$user['id'],$status]);}$id=(int)$db->lastInsertId();}
                audit($db,(int)$user['id'],$item?'page_update':'page_create','page',$id,$title);flash('success','تم حفظ الصفحة.');redirect('/admin/page/edit?id='.$id);
            }catch(Throwable $e){flash('error','تعذر حفظ الصفحة. تأكد أن Slug والمسار غير مستخدمين وأن migration 006 مطبق.');redirect($id?'/admin/page/edit?id='.$id:'/admin/page/new');}
        }
        try{$languages=$db->query('SELECT code,name FROM languages WHERE is_active=1 ORDER BY is_default DESC,sort_order,id')->fetchAll();}catch(Throwable $e){$languages=[['code'=>'ar','name'=>'العربية']];}$usage=$id&&$v5?cms_page_menu_usage($db,$id):0;
        admin_render('admin_page_editor',['title'=>$id?'تحرير الصفحة':'صفحة جديدة','item'=>$item,'languages'=>$languages,'cmsV5'=>$v5,'menuUsage'=>$usage,'isProtectedPage'=>$item&&in_array((string)($item['page_type']??''),['system','dynamic'],true)]);exit;
    }

    if($path==='/admin/listings' && can($user,'listings')){
        if(!final_v6_schema($db)){flash('error','طبّق migration 007_final_project.sql أولًا.');redirect('/admin');}
        if(is_post()){
            verify_csrf();$action=(string)($_POST['action']??'bulk');
            try{
                if($action==='permanent_delete'){
                    if(($user['role']??'')!=='super_admin')throw new RuntimeException('الحذف النهائي متاح للمدير العام فقط.');
                    $id=(int)($_POST['id']??0);$confirm=(string)($_POST['confirm_text']??'');if(!$id||$confirm!=='DELETE')throw new InvalidArgumentException('اكتب DELETE لتأكيد الحذف النهائي.');
                    $st=$db->prepare('SELECT id,name,deleted_at FROM listings WHERE id=? LIMIT 1');$st->execute([$id]);$row=$st->fetch();if(!$row||empty($row['deleted_at']))throw new InvalidArgumentException('يجب نقل السجل إلى السلة أولًا.');
                    $relations=0;foreach(['reviews','complaints','data_reports'] as $table){$st=$db->prepare("SELECT COUNT(*) FROM {$table} WHERE listing_id=?");$st->execute([$id]);$relations+=(int)$st->fetchColumn();}if($relations>0)throw new RuntimeException('لا يمكن الحذف النهائي لوجود تقييمات أو شكاوى أو بلاغات مرتبطة. أبقِ السجل في السلة للحفاظ على السجل التشغيلي.');
                    $backup=backup_create_typed($db,$config,__DIR__,'full','pre-permanent-center-delete');backup_register($db,$backup,(int)$user['id']);$db->prepare('DELETE FROM listings WHERE id=?')->execute([$id]);audit($db,(int)$user['id'],'listing_permanent_delete','listing',$id,'backup='.$backup['name'].' name='.$row['name']);flash('success','تم الحذف النهائي بعد إنشاء نسخة احتياطية.');redirect('/admin/listings?trash=1');
                }
                $ids=array_values(array_unique(array_filter(array_map('intval',is_array($_POST['ids']??null)?$_POST['ids']:[]))));if($action==='single'&&!empty($_POST['id']))$ids=[(int)$_POST['id']];$ids=array_slice($ids,0,100);if(!$ids)throw new InvalidArgumentException('حدد سجلًا واحدًا على الأقل.');$place=implode(',',array_fill(0,count($ids),'?'));$bulk=(string)($_POST['bulk_action']??$_POST['single_action']??'');
                $allowed=['publish','draft','suspend','archive','feature','unfeature','trash','restore','verify','unverify'];if(!in_array($bulk,$allowed,true))throw new InvalidArgumentException('الإجراء غير معروف.');if(in_array($bulk,['verify','unverify'],true)&&!can($user,'verification'))throw new RuntimeException('لا تملك صلاحية إدارة التحقق.');
                $backupName='';if($bulk==='trash'&&count($ids)>=10){$b=backup_create_typed($db,$config,__DIR__,'full','pre-bulk-center-trash');backup_register($db,$b,(int)$user['id']);$backupName=$b['name'];}
                if($bulk==='publish'){$sql="UPDATE listings SET status='published',published_at=COALESCE(published_at,NOW()),deleted_at=NULL,updated_at=NOW() WHERE id IN ({$place})";$vals=$ids;}
                elseif($bulk==='draft'){$sql="UPDATE listings SET status='draft',updated_at=NOW() WHERE id IN ({$place})";$vals=$ids;}
                elseif($bulk==='suspend'){$sql="UPDATE listings SET status='suspended',updated_at=NOW() WHERE id IN ({$place})";$vals=$ids;}
                elseif($bulk==='archive'){$sql="UPDATE listings SET status='archived',archived_at=NOW(),updated_at=NOW() WHERE id IN ({$place})";$vals=$ids;}
                elseif($bulk==='feature'){$sql="UPDATE listings SET featured=1,updated_at=NOW() WHERE id IN ({$place})";$vals=$ids;}
                elseif($bulk==='unfeature'){$sql="UPDATE listings SET featured=0,updated_at=NOW() WHERE id IN ({$place})";$vals=$ids;}
                elseif($bulk==='trash'){$sql="UPDATE listings SET deleted_at=NOW(),status='hidden',updated_at=NOW() WHERE id IN ({$place})";$vals=$ids;}
                elseif($bulk==='restore'){$sql="UPDATE listings SET deleted_at=NULL,status='draft',updated_at=NOW() WHERE id IN ({$place})";$vals=$ids;}
                elseif($bulk==='verify'){$sql="UPDATE listings SET verification_status='verified',verified_by=?,verified_at=NOW(),updated_at=NOW() WHERE id IN ({$place})";$vals=[(int)$user['id'],...$ids];}
                else{$sql="UPDATE listings SET verification_status='unverified',verified_by=NULL,verified_at=NULL,updated_at=NOW() WHERE id IN ({$place})";$vals=$ids;}
                $db->prepare($sql)->execute($vals);
                if(in_array($bulk,['verify','unverify'],true)){$status=$bulk==='verify'?'verified':'unverified';$vh=$db->prepare('INSERT INTO listing_verification_history(listing_id,status,reviewed_by,internal_note,created_at) VALUES(?,?,?,?,NOW())');foreach($ids as $listingId)$vh->execute([$listingId,$status,(int)$user['id'],'إجراء جماعي']);}
                audit($db,(int)$user['id'],'listing_bulk','listing',0,$bulk.' ids='.implode(',',$ids).($backupName?' backup='.$backupName:''));flash('success','تم تنفيذ الإجراء على '.count($ids).' سجل.');
            }catch(Throwable $e){flash('error',$e instanceof InvalidArgumentException||$e instanceof RuntimeException?$e->getMessage():'تعذر تنفيذ الإجراء.');}
            redirect('/admin/listings'.(!empty($_GET['trash'])?'?trash=1':''));
        }
        $filters=['q'=>mb_substr(trim((string)($_GET['q']??'')),0,120,'UTF-8'),'status'=>(string)($_GET['status']??''),'verification'=>(string)($_GET['verification']??''),'country_id'=>(int)($_GET['country_id']??0),'region_id'=>(int)($_GET['region_id']??0),'city_id'=>(int)($_GET['city_id']??0),'specialty_id'=>(int)($_GET['specialty_id']??0),'featured'=>isset($_GET['featured'])?(string)$_GET['featured']:'','trash'=>!empty($_GET['trash'])];$where=[$filters['trash']?'l.deleted_at IS NOT NULL':'l.deleted_at IS NULL'];$params=[];
        if($filters['q']!==''){$like='%'.$filters['q'].'%';$where[]='(l.name LIKE ? OR l.provider_name LIKE ? OR l.phone LIKE ? OR l.whatsapp LIKE ? OR l.address LIKE ?)';array_push($params,$like,$like,$like,$like,$like);}if(in_array($filters['status'],['draft','published','needs_update','suspended','archived','hidden'],true)){$where[]='l.status=?';$params[]=$filters['status'];}if(in_array($filters['verification'],['unverified','pending','verified','rejected','needs_update'],true)){$where[]='l.verification_status=?';$params[]=$filters['verification'];}
        foreach(['country_id','region_id','city_id'] as $f)if($filters[$f]){$where[]="l.{$f}=?";$params[]=$filters[$f];}if($filters['specialty_id']){$where[]='(l.primary_specialty_id=? OR EXISTS(SELECT 1 FROM listing_specialties ls WHERE ls.listing_id=l.id AND ls.specialty_id=?))';$params[]=$filters['specialty_id'];$params[]=$filters['specialty_id'];}if(in_array($filters['featured'],['0','1'],true)){$where[]='l.featured=?';$params[]=(int)$filters['featured'];}
        $whereSql=implode(' AND ',$where);$count=$db->prepare("SELECT COUNT(*) FROM listings l WHERE {$whereSql}");$count->execute($params);$pg=pagination_meta((int)$count->fetchColumn(),page_number(),50);$sql="SELECT l.*,c.name country_name,r.name region_name,ci.name city_name,s.name primary_specialty,(SELECT COUNT(*) FROM reviews rv WHERE rv.listing_id=l.id) reviews_count,(SELECT COUNT(*) FROM complaints cp WHERE cp.listing_id=l.id) complaints_count FROM listings l JOIN countries c ON c.id=l.country_id LEFT JOIN regions r ON r.id=l.region_id LEFT JOIN cities ci ON ci.id=l.city_id LEFT JOIN specialties s ON s.id=l.primary_specialty_id WHERE {$whereSql} ORDER BY l.updated_at DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}";$st=$db->prepare($sql);$st->execute($params);$listings=$st->fetchAll();$loc=fetch_locations($db,false);$specialties=$db->query('SELECT id,name FROM specialties ORDER BY name')->fetchAll();admin_render('admin_listings',['title'=>'إدارة المراكز ومقدمي الخدمة','listings'=>$listings,'pagination'=>$pg,'filters'=>$filters,'countries'=>$loc['countries'],'regions'=>$loc['regions'],'cities'=>$loc['cities'],'specialties'=>$specialties]);exit;
    }

    if(in_array($path,['/admin/listing/new','/admin/listing/edit'],true) && can($user,'listings')){
        if(!final_v6_schema($db)){flash('error','طبّق migration 007_final_project.sql أولًا.');redirect('/admin/listings');}
        $canVerify=can($user,'verification');
        $id=(int)($_GET['id']??$_POST['id']??0);
        $item=[];$selectedSpecialties=[];$verificationHistory=[];
        if($id){
            $st=$db->prepare('SELECT * FROM listings WHERE id=?');$st->execute([$id]);$item=$st->fetch()?:[];
            if(!$item){flash('error','السجل غير موجود.');redirect('/admin/listings');}
            $st=$db->prepare('SELECT specialty_id FROM listing_specialties WHERE listing_id=?');$st->execute([$id]);$selectedSpecialties=array_map('intval',array_column($st->fetchAll(),'specialty_id'));
            $vh=$db->prepare('SELECT h.*,u.name reviewer_name FROM listing_verification_history h LEFT JOIN users u ON u.id=h.reviewed_by WHERE h.listing_id=? ORDER BY h.id DESC LIMIT 30');$vh->execute([$id]);$verificationHistory=$vh->fetchAll();
        }
        $locationSelection=[];
        if(!empty($item['country_id'])){$st=$db->prepare('SELECT c.name country_name,r.name region_name,ci.name city_name FROM countries c LEFT JOIN regions r ON r.id=? LEFT JOIN cities ci ON ci.id=? WHERE c.id=?');$st->execute([(int)($item['region_id']??0),(int)($item['city_id']??0),(int)$item['country_id']]);$locationSelection=$st->fetch()?:[];}
        $specialties=$db->query('SELECT * FROM specialties WHERE is_active=1 ORDER BY name')->fetchAll();
        $media=$db->query("SELECT id,path,alt_text,original_name,title FROM media WHERE deleted_at IS NULL AND mime_type LIKE 'image/%' ORDER BY id DESC LIMIT 200")->fetchAll();
        if(is_post()){
            verify_csrf();
            try{
                $name=mb_substr(trim((string)($_POST['name']??'')),0,190,'UTF-8');
                $country=(int)($_POST['country_id']??0);
                if(!$name||!$country)throw new InvalidArgumentException('الاسم والدولة مطلوبان.');
                $type=in_array($_POST['type']??'', ['person','center'],true)?(string)$_POST['type']:'person';
                $status=in_array($_POST['status']??'', ['draft','published','needs_update','suspended','archived','hidden'],true)?(string)$_POST['status']:'draft';
                $reviewState=in_array($_POST['review_state']??'', ['unreviewed','data_reviewed','needs_update'],true)?(string)$_POST['review_state']:'data_reviewed';
                $verification=$canVerify&&in_array($_POST['verification_status']??'', ['unverified','pending','verified','rejected','needs_update'],true)?(string)$_POST['verification_status']:(string)($item['verification_status']??'unverified');
                $verificationNote=$canVerify?mb_substr(trim((string)($_POST['verification_note']??'')),0,700,'UTF-8'):(string)($item['verification_note']??'');
                $recommended=$canVerify?(!empty($_POST['recommended'])?1:0):(int)($item['recommended']??0);
                $recommendationNote=$canVerify?mb_substr(trim((string)($_POST['recommendation_note']??'')),0,700,'UTF-8'):(string)($item['recommendation_note']??'');
                $region=(int)($_POST['region_id']??0)?:null;$city=(int)($_POST['city_id']??0)?:null;
                validate_location_selection($db,$country,$region,$city);
                $mapUrl=trim((string)($_POST['map_url']??''));if($mapUrl!==''&&!valid_http_url($mapUrl))throw new InvalidArgumentException('رابط الخريطة غير صالح.');
                $website=safe_external_url((string)($_POST['website']??''));if(trim((string)($_POST['website']??''))!==''&&$website==='')throw new InvalidArgumentException('رابط الموقع غير صالح.');
                $email=trim((string)($_POST['email']??''));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('البريد الإلكتروني غير صالح.');
                $lat=trim((string)($_POST['latitude']??''));$lng=trim((string)($_POST['longitude']??''));
                if($lat!==''&&(!is_numeric($lat)||(float)$lat<-90||(float)$lat>90))throw new InvalidArgumentException('Latitude غير صالح.');
                if($lng!==''&&(!is_numeric($lng)||(float)$lng<-180||(float)$lng>180))throw new InvalidArgumentException('Longitude غير صالح.');
                $requestedSlug=trim((string)($_POST['slug']??''));
                $slugSource=$requestedSlug!==''?$requestedSlug:((string)($item['slug']??'')!==''?(string)$item['slug']:$name);
                $slug=unique_slug($db,'listings',$slugSource,$id);
                $image=(string)($item['image']??'');$mediaPath=trim((string)($_POST['media_path']??''));
                if($mediaPath!==''&&str_starts_with($mediaPath,'uploads/'))$image=$mediaPath;
                if(!empty($_FILES['image']['tmp_name'])){$image=upload_image('image','listing')??$image;media_register($db,$image,$_FILES['image'],(int)$user['id'],$name);}
                $vals=[
                    $type,$name,mb_substr(trim((string)($_POST['provider_name']??'')),0,190,'UTF-8')?:null,$slug,
                    mb_substr(trim((string)($_POST['phone']??'')),0,80,'UTF-8')?:null,mb_substr(trim((string)($_POST['whatsapp']??'')),0,80,'UTF-8')?:null,$email?:null,$website?:null,$image?:null,
                    mb_substr(trim((string)($_POST['short_bio']??'')),0,500,'UTF-8')?:null,mb_substr(trim((string)($_POST['search_keywords']??'')),0,700,'UTF-8')?:null,trim((string)($_POST['bio']??''))?:null,
                    $country,$region,$city,(int)($_POST['primary_specialty_id']??0)?:null,
                    mb_substr(trim((string)($_POST['address']??'')),0,500,'UTF-8')?:null,mb_substr(trim((string)($_POST['district']??'')),0,190,'UTF-8')?:null,mb_substr(trim((string)($_POST['street']??'')),0,190,'UTF-8')?:null,mb_substr(trim((string)($_POST['landmark']??'')),0,255,'UTF-8')?:null,mb_substr(trim((string)($_POST['postal_code']??'')),0,40,'UTF-8')?:null,
                    $mapUrl?:null,$lat===''?null:(float)$lat,$lng===''?null:(float)$lng,
                    !empty($_POST['phone_verified'])?1:0,!empty($_POST['address_verified'])?1:0,($_POST['last_verified_at']??'')?:null,trim((string)($_POST['admin_notes']??''))?:null,$reviewState,
                    $verification,$verificationNote?:null,$recommended,$recommendationNote?:null,!empty($_POST['featured'])?1:0,
                    mb_substr(trim((string)($_POST['seo_title']??'')),0,255,'UTF-8')?:null,mb_substr(trim((string)($_POST['seo_description']??'')),0,500,'UTF-8')?:null,$status
                ];
                $oldVer=(string)($item['verification_status']??'unverified');$oldRecommended=(int)($item['recommended']??0);
                $db->beginTransaction();
                try{
                    if($id){
                        $oldSlug=(string)$item['slug'];
                        $sql="UPDATE listings SET type=?,name=?,provider_name=?,slug=?,phone=?,whatsapp=?,email=?,website=?,image=?,short_bio=?,search_keywords=?,bio=?,country_id=?,region_id=?,city_id=?,primary_specialty_id=?,address=?,district=?,street=?,landmark=?,postal_code=?,map_url=?,latitude=?,longitude=?,phone_verified=?,address_verified=?,last_verified_at=?,admin_notes=?,review_state=?,verification_status=?,verification_note=?,recommended=?,recommendation_note=?,featured=?,seo_title=?,seo_description=?,status=?,published_at=IF(?='published',COALESCE(published_at,NOW()),published_at),archived_at=IF(?='archived',NOW(),IF(?<>'archived',NULL,archived_at)),updated_at=NOW() WHERE id=?";
                        $db->prepare($sql)->execute([...$vals,$status,$status,$status,$id]);
                        if($oldSlug!==$slug)$db->prepare('INSERT IGNORE INTO listing_slug_history(listing_id,old_slug,created_at) VALUES(?,?,NOW())')->execute([$id,$oldSlug]);
                    }else{
                        $sql="INSERT INTO listings(type,name,provider_name,slug,phone,whatsapp,email,website,image,short_bio,search_keywords,bio,country_id,region_id,city_id,primary_specialty_id,address,district,street,landmark,postal_code,map_url,latitude,longitude,phone_verified,address_verified,last_verified_at,admin_notes,review_state,verification_status,verification_note,recommended,recommendation_note,featured,seo_title,seo_description,status,created_by,created_at,updated_at,published_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),IF(?='published',NOW(),NULL))";
                        $db->prepare($sql)->execute([...$vals,(int)$user['id'],$status]);$id=(int)$db->lastInsertId();
                    }
                    $db->prepare('DELETE FROM listing_specialties WHERE listing_id=?')->execute([$id]);
                    $all=array_unique(array_filter(array_map('intval',$_POST['specialties']??[])));if(!empty($_POST['primary_specialty_id']))$all[]=(int)$_POST['primary_specialty_id'];
                    $st=$db->prepare('INSERT IGNORE INTO listing_specialties(listing_id,specialty_id) VALUES(?,?)');foreach(array_unique($all) as $sid)$st->execute([$id,$sid]);
                    if($canVerify&&(!$item||$oldVer!==$verification)){
                        $db->prepare('INSERT INTO listing_verification_history(listing_id,status,reviewed_by,internal_note,created_at) VALUES(?,?,?,?,NOW())')->execute([$id,$verification,(int)$user['id'],$verificationNote?:null]);
                    }
                    $db->prepare("UPDATE listings SET verified_by=IF(verification_status='verified',COALESCE(verified_by,?),NULL),verified_at=IF(verification_status='verified',COALESCE(verified_at,NOW()),NULL),recommended_by=IF(recommended=1,COALESCE(recommended_by,?),NULL),recommended_at=IF(recommended=1,COALESCE(recommended_at,NOW()),NULL) WHERE id=?")->execute([(int)$user['id'],(int)$user['id'],$id]);
                    $db->commit();
                }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
                audit($db,(int)$user['id'],$item?'update':'create','listing',$id,$name);
                if($canVerify&&$oldRecommended!==$recommended)audit($db,(int)$user['id'],'recommendation_changed','listing',$id,$recommended?'recommended':'not_recommended');
                flash('success','تم حفظ السجل بأمان.');redirect('/admin/listings');
            }catch(InvalidArgumentException|RuntimeException $e){flash('error',$e->getMessage());redirect($path.($id?'?id='.$id:''));}
            catch(Throwable $e){flash('error','تعذر الحفظ. تأكد من تطبيق migration 007 ومن سلامة العلاقات.');redirect($path.($id?'?id='.$id:''));}
        }
        admin_render('admin_listing_form',[
            'title'=>$id?'تعديل السجل':'إضافة شخص أو مركز','item'=>$item,'locationSelection'=>$locationSelection,
            'specialties'=>$specialties,'selectedSpecialties'=>$selectedSpecialties,'media'=>$media,'verificationHistory'=>$verificationHistory,'canVerify'=>$canVerify,
            'listingStats'=>$id?['views'=>(int)($item['views']??0),'contact_clicks'=>(int)($item['contact_clicks']??0),'whatsapp_clicks'=>(int)($item['whatsapp_clicks']??0),'call_clicks'=>(int)($item['call_clicks']??0)]:null
        ]);exit;
    }

    if($path==='/admin/locations' && can($user,'locations')){
        if(!final_v6_schema($db)){flash('error','طبّق migration 007_final_project.sql أولًا.');redirect('/admin');}
        if(!location_v7_schema($db)){flash('error','طبّق migration 008_global_location_selector.sql أولًا.');redirect('/admin');}
        if(isset($_GET['export'])){$format=(string)$_GET['export'];$headers=['country_id','country','iso2','region_id','region','region_type','city_id','city','latitude','longitude'];$geoRows=$db->query('SELECT c.id country_id,c.name country,c.iso2,r.id region_id,r.name region,r.type region_type,ci.id city_id,ci.name city,ci.latitude,ci.longitude FROM countries c LEFT JOIN regions r ON r.country_id=c.id LEFT JOIN cities ci ON ci.region_id=r.id ORDER BY c.sort_order,c.name,r.sort_order,r.name,ci.sort_order,ci.name')->fetchAll(PDO::FETCH_ASSOC);if($format==='xlsx'){$matrix=[];foreach($geoRows as $row){$line=[];foreach($headers as $h)$line[]=(string)($row[$h]??'');$matrix[]=$line;}xlsx_download('mihrab-geography.xlsx',$headers,$matrix);}header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="mihrab-geography.csv"');echo "\xEF\xBB\xBF";$out=fopen('php://output','wb');fputcsv($out,$headers);foreach($geoRows as $r)fputcsv($out,array_values($r));fclose($out);exit;}
        $geoPreview=$_SESSION['geo_import_preview']??null;
        if(is_post()){
            verify_csrf();$action=(string)($_POST['action']??'');
            try{
                if(in_array($action,['manual_country','manual_region','manual_city'],true)){
                    $name=location_text((string)($_POST['name']??''));
                    $nameEn=location_text((string)($_POST['name_en']??''));
                    $native=location_text((string)($_POST['name_native']??''));
                    if(!$name) throw new InvalidArgumentException('الاسم العربي أو الاسم المعروض مطلوب.');
                    if($action==='manual_country'){
                        $iso2=strtoupper((string)(location_text((string)($_POST['iso2']??''),2)??''));
                        if(location_exists($db,'countries',$name)) throw new InvalidArgumentException('هذه الدولة موجودة مسبقًا.');
                        $db->prepare('INSERT INTO countries(name,name_en,name_native,native_name,iso2,phone_code,slug,is_active,sort_order,source,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,0,\'manual\',NOW(),NOW())')->execute([$name,$nameEn,$native,$native,$iso2?:null,location_text((string)($_POST['phone_code']??''),16),unique_slug($db,'countries',$name),1]);
                    } elseif($action==='manual_region') {
                        $country=(int)($_POST['country_id']??0);
                        $countryCheck=$db->prepare('SELECT id FROM countries WHERE id=?');$countryCheck->execute([$country]);
                        if(!$country || !$countryCheck->fetchColumn()) throw new InvalidArgumentException('اختر دولة صحيحة أولًا.');
                        if(location_exists($db,'regions',$name,['country_id'=>$country])) throw new InvalidArgumentException('هذه المنطقة موجودة لهذه الدولة.');
                        $type=location_text((string)($_POST['type']??''),60) ?? 'منطقة';
                        $db->prepare('INSERT INTO regions(country_id,name,name_en,name_native,type,slug,is_active,sort_order,source,created_at,updated_at) VALUES(?,?,?,?,?,?,1,0,\'manual\',NOW(),NOW())')->execute([$country,$name,$nameEn,$native,$type,unique_slug($db,'regions',$name)]);
                    } else {
                        $region=(int)($_POST['region_id']??0);$st=$db->prepare('SELECT country_id FROM regions WHERE id=?');$st->execute([$region]);$country=(int)$st->fetchColumn();
                        if(!$country) throw new InvalidArgumentException('اختر منطقة صحيحة أولًا.');
                        if(location_exists($db,'cities',$name,['region_id'=>$region])) throw new InvalidArgumentException('هذه المدينة موجودة لهذه المنطقة.');
                        $lat=trim((string)($_POST['latitude']??''));$lng=trim((string)($_POST['longitude']??''));
                        if($lat!==''&&(!is_numeric($lat)||(float)$lat<-90||(float)$lat>90)) throw new InvalidArgumentException('خط العرض غير صالح.');
                        if($lng!==''&&(!is_numeric($lng)||(float)$lng<-180||(float)$lng>180)) throw new InvalidArgumentException('خط الطول غير صالح.');
                        $db->prepare('INSERT INTO cities(country_id,region_id,name,name_en,name_native,slug,latitude,longitude,is_active,sort_order,source,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,1,0,\'manual\',NOW(),NOW())')->execute([$country,$region,$name,$nameEn,$native,unique_slug($db,'cities',$name),$lat===''?null:(float)$lat,$lng===''?null:(float)$lng]);
                    }
                    audit($db,(int)$user['id'],'manual_location_create','location',0,$action.':'.$name);
                    flash('success','تمت إضافة الموقع اليدوي دون حذف أي بيانات.');redirect('/admin/locations');
                }
                if($action==='geo_preview'){$matrix=spreadsheet_read_upload($_FILES['geo_file']??[]);$geoPreview=geography_import_preview($db,$matrix);$geoPreview['filename']=mb_substr((string)($_FILES['geo_file']['name']??'geography.xlsx'),0,255,'UTF-8');$_SESSION['geo_import_preview']=$geoPreview;flash('success','تم فحص ملف الجغرافيا. راجع المعاينة ثم أكد.');redirect('/admin/locations');}
                if($action==='geo_confirm'){if(!is_array($geoPreview))throw new RuntimeException('لا توجد معاينة جغرافية صالحة.');if(($geoPreview['invalid']??0)>0)throw new RuntimeException('توجد صفوف غير صالحة.');$backup=backup_create_typed($db,$config,__DIR__,'full','pre-geography-import');backup_register($db,$backup,(int)$user['id']);$db->beginTransaction();try{$created=geography_import_apply($db,$geoPreview);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}audit($db,(int)$user['id'],'geography_import','location',0,'countries='.$created['countries'].' regions='.$created['regions'].' cities='.$created['cities'].' backup='.$backup['name']);unset($_SESSION['geo_import_preview']);flash('success','تم استيراد الجغرافيا: '.$created['countries'].' دول، '.$created['regions'].' مناطق، '.$created['cities'].' مدن.');redirect('/admin/locations');}
                if($action==='normalize_slugs'){foreach(['countries','regions','cities'] as $table){$rows=$db->query("SELECT id,name FROM {$table} ORDER BY id")->fetchAll();$stSlug=$db->prepare("UPDATE {$table} SET slug=? WHERE id=?");foreach($rows as $row)$stSlug->execute([unique_slug($db,$table,(string)$row['name'],(int)$row['id']),(int)$row['id']]);}audit($db,(int)$user['id'],'normalize_slugs','location',0,'country/region/city');flash('success','تم تحسين الروابط.');redirect('/admin/locations');}
                if(in_array($action,['country','region','city'],true)){$name=mb_substr(trim((string)($_POST['name']??'')),0,160,'UTF-8');if(!$name)throw new InvalidArgumentException('الاسم مطلوب.');if($action==='country'){$iso2=strtoupper(mb_substr(trim((string)($_POST['iso2']??'')),0,2,'UTF-8'));$db->prepare('INSERT INTO countries(name,native_name,iso2,phone_code,slug,is_active,sort_order) VALUES(?,?,?,?,?,1,0)')->execute([$name,mb_substr(trim((string)($_POST['native_name']??'')),0,160,'UTF-8')?:null,$iso2?:null,mb_substr(trim((string)($_POST['phone_code']??'')),0,16,'UTF-8')?:null,unique_slug($db,'countries',$name)]);}elseif($action==='region'){$country=(int)($_POST['country_id']??0);if(!$country)throw new InvalidArgumentException('اختر الدولة.');$db->prepare('INSERT INTO regions(country_id,name,type,slug,is_active,sort_order) VALUES(?,?,?,?,1,0)')->execute([$country,$name,mb_substr(trim((string)($_POST['type']??'')),0,60,'UTF-8')?:null,unique_slug($db,'regions',$name)]);}else{$region=(int)($_POST['region_id']??0);$st=$db->prepare('SELECT country_id FROM regions WHERE id=?');$st->execute([$region]);$country=(int)$st->fetchColumn();if(!$country)throw new InvalidArgumentException('المنطقة غير صحيحة.');$lat=trim((string)($_POST['latitude']??''));$lng=trim((string)($_POST['longitude']??''));if($lat!==''&&(!is_numeric($lat)||(float)$lat<-90||(float)$lat>90))throw new InvalidArgumentException('Latitude غير صالح.');if($lng!==''&&(!is_numeric($lng)||(float)$lng<-180||(float)$lng>180))throw new InvalidArgumentException('Longitude غير صالح.');$db->prepare('INSERT INTO cities(country_id,region_id,name,slug,latitude,longitude,is_active,sort_order) VALUES(?,?,?,?,?,?,1,0)')->execute([$country,$region,$name,unique_slug($db,'cities',$name),$lat===''?null:(float)$lat,$lng===''?null:(float)$lng]);}flash('success','تمت الإضافة.');redirect('/admin/locations');}
                if(in_array($action,['toggle_country','toggle_region','toggle_city'],true)){$map=['toggle_country'=>'countries','toggle_region'=>'regions','toggle_city'=>'cities'];$table=$map[$action];$id=(int)($_POST['id']??0);if(!$id)throw new InvalidArgumentException('المعرف غير صحيح.');$db->exec("UPDATE {$table} SET is_active=IF(is_active=1,0,1) WHERE id=".$id);flash('success','تم تحديث الحالة دون حذف العلاقات.');redirect('/admin/locations');}
            }catch(Throwable $e){flash('error',$e instanceof InvalidArgumentException||$e instanceof RuntimeException?$e->getMessage():'العنصر موجود مسبقًا أو البيانات غير صحيحة.');redirect('/admin/locations');}
        }
        $locationCounts=['countries'=>(int)$db->query('SELECT COUNT(*) FROM countries')->fetchColumn(),'regions'=>(int)$db->query('SELECT COUNT(*) FROM regions')->fetchColumn(),'cities'=>(int)$db->query('SELECT COUNT(*) FROM cities')->fetchColumn()];
        admin_render('admin_locations',['title'=>'المواقع الجغرافية','geoPreview'=>$geoPreview,'locationCounts'=>$locationCounts]);exit;
    }

    if($path==='/admin/specialties' && can($user,'specialties')){
        if(is_post()){verify_csrf();$action=(string)($_POST['action']??'add');try{if($action==='toggle'){$id=(int)($_POST['id']??0);$db->exec('UPDATE specialties SET is_active=IF(is_active=1,0,1) WHERE id='.$id);}else{$name=mb_substr(trim((string)($_POST['name']??'')),0,160,'UTF-8');if($name)$db->prepare('INSERT INTO specialties(name,slug,is_active) VALUES(?,?,1)')->execute([$name,unique_slug($db,'specialties',$name)]);}flash('success','تم حفظ التغيير.');}catch(Throwable $e){flash('error','تعذر الحفظ أو الاسم موجود مسبقًا.');}redirect('/admin/specialties');}
        $specialties=$db->query('SELECT * FROM specialties ORDER BY name')->fetchAll();admin_render('admin_specialties',['title'=>'التخصصات','specialties'=>$specialties]);exit;
    }

    if($path==='/admin/reviews' && can($user,'reviews')){
        if(is_post()){verify_csrf();$status=in_array($_POST['status']??'', ['pending','approved','rejected','flagged'],true)?(string)$_POST['status']:'pending';$id=(int)($_POST['id']??0);$db->prepare('UPDATE reviews SET status=? WHERE id=?')->execute([$status,$id]);audit($db,(int)$user['id'],'review_moderation','review',$id,$status);flash('success','تم تحديث حالة التقييم.');redirect('/admin/reviews');}
        $filters=['q'=>mb_substr(trim((string)($_GET['q']??'')),0,120,'UTF-8'),'status'=>(string)($_GET['status']??'')];$where=['1=1'];$params=[];if($filters['q']!==''){$like='%'.$filters['q'].'%';$where[]='(r.reviewer_name LIKE ? OR r.message LIKE ? OR l.name LIKE ?)';array_push($params,$like,$like,$like);}if(in_array($filters['status'],['pending','approved','rejected','flagged'],true)){$where[]='r.status=?';$params[]=$filters['status'];}$whereSql=implode(' AND ',$where);$count=$db->prepare("SELECT COUNT(*) FROM reviews r JOIN listings l ON l.id=r.listing_id WHERE {$whereSql}");$count->execute($params);$pg=pagination_meta((int)$count->fetchColumn(),page_number(),50);$st=$db->prepare("SELECT r.*,l.name listing_name FROM reviews r JOIN listings l ON l.id=r.listing_id WHERE {$whereSql} ORDER BY FIELD(r.status,'pending','flagged','approved','rejected'),r.id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");$st->execute($params);$reviews=$st->fetchAll();admin_render('admin_reviews',['title'=>'التقييمات','reviews'=>$reviews,'pagination'=>$pg,'filters'=>$filters]);exit;
    }

    if($path==='/admin/complaints' && can($user,'complaints')){
        if(is_post()){
            verify_csrf();$allowed=['new','reviewing','need_info','resolved','rejected','escalated','closed','action_taken'];$status=in_array($_POST['status']??'',$allowed,true)?(string)$_POST['status']:'reviewing';$priority=in_array($_POST['priority']??'normal',['low','normal','high','urgent'],true)?(string)$_POST['priority']:'normal';$id=(int)($_POST['id']??0);$assigned=(int)($_POST['assigned_admin_id']??0)?:null;$note=mb_substr(trim((string)($_POST['internal_note']??'')),0,1000,'UTF-8');$old=$db->prepare('SELECT status,email,name,listing_id FROM complaints WHERE id=?');$old->execute([$id]);$oldRow=$old->fetch();if(!$oldRow){flash('error','الشكوى غير موجودة.');redirect('/admin/complaints');}$oldStatus=(string)$oldRow['status'];$db->prepare("UPDATE complaints SET status=?,priority=?,assigned_admin_id=?,internal_note=IF(?<>'',?,internal_note) WHERE id=?")->execute([$status,$priority,$assigned,$note,$note,$id]);$db->prepare('INSERT INTO complaint_history(complaint_id,user_id,action,old_status,new_status,note,created_at) VALUES(?,?,?,?,?,?,NOW())')->execute([$id,(int)$user['id'],'status_update',$oldStatus,$status,$note?:null]);audit($db,(int)$user['id'],'complaint_update','complaint',$id,$oldStatus.' -> '.$status);
            if($oldStatus!==$status&&!empty($oldRow['email'])&&filter_var($oldRow['email'],FILTER_VALIDATE_EMAIL)){[$tplSubject,$tplBody]=email_template_text($db,'complaint_status_user',['id'=>$id,'status'=>$status,'note'=>$note!==''?'ملاحظة الإدارة: '.$note:''],'تحديث شكوى محراب #'.$id,'تم تحديث حالة الشكوى رقم #'.$id.' إلى: '.$status.'.'.($note!==''?' ملاحظة الإدارة: '.$note:''));[$html,$text]=auth_email_template($tplSubject,$tplBody);try{mailer_send((string)$oldRow['email'],$tplSubject,$html,$text);}catch(Throwable $e){}}
            flash('success','تم تحديث الشكوى وتسجيل الإجراء في السجل.');redirect('/admin/complaints');
        }
        $filters=['q'=>mb_substr(trim((string)($_GET['q']??'')),0,120,'UTF-8'),'status'=>(string)($_GET['status']??''),'priority'=>(string)($_GET['priority']??'')];$where=['1=1'];$params=[];if($filters['q']!==''){$like='%'.$filters['q'].'%';$where[]='(c.name LIKE ? OR c.email LIKE ? OR c.category LIKE ? OR c.message LIKE ? OR l.name LIKE ?)';array_push($params,$like,$like,$like,$like,$like);}if(in_array($filters['status'],['new','reviewing','need_info','resolved','rejected','escalated','closed','action_taken'],true)){$where[]='c.status=?';$params[]=$filters['status'];}if(in_array($filters['priority'],['low','normal','high','urgent'],true)){$where[]='c.priority=?';$params[]=$filters['priority'];}$whereSql=implode(' AND ',$where);$count=$db->prepare("SELECT COUNT(*) FROM complaints c JOIN listings l ON l.id=c.listing_id WHERE {$whereSql}");$count->execute($params);$pg=pagination_meta((int)$count->fetchColumn(),page_number(),50);$st=$db->prepare("SELECT c.*,l.name listing_name,u.name assigned_admin_name,(SELECT COUNT(*) FROM complaints cx WHERE cx.listing_id=c.listing_id) center_complaints_count FROM complaints c JOIN listings l ON l.id=c.listing_id LEFT JOIN users u ON u.id=c.assigned_admin_id WHERE {$whereSql} ORDER BY FIELD(c.priority,'urgent','high','normal','low'),FIELD(c.status,'new','escalated','reviewing','need_info','resolved','closed'),c.id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");$st->execute($params);$items=$st->fetchAll();$admins=$db->query("SELECT id,name FROM users WHERE status='active' AND role IN('super_admin','admin','moderator') ORDER BY name")->fetchAll();$histories=[];if($items){$ids=array_map('intval',array_column($items,'id'));$place=implode(',',array_fill(0,count($ids),'?'));$hs=$db->prepare("SELECT h.*,u.name user_name FROM complaint_history h LEFT JOIN users u ON u.id=h.user_id WHERE h.complaint_id IN ({$place}) ORDER BY h.id DESC");$hs->execute($ids);foreach($hs->fetchAll() as $h)$histories[(int)$h['complaint_id']][]=$h;}admin_render('admin_complaints',['title'=>'الشكاوى','items'=>$items,'reportMode'=>false,'pagination'=>$pg,'admins'=>$admins,'histories'=>$histories,'filters'=>$filters]);exit;
    }

    if($path==='/admin/data-reports' && can($user,'complaints')){
        if(is_post()){verify_csrf();$status=in_array($_POST['status']??'', ['new','reviewing','fixed','closed'],true)?(string)$_POST['status']:'reviewing';$id=(int)($_POST['id']??0);$db->prepare('UPDATE data_reports SET status=? WHERE id=?')->execute([$status,$id]);audit($db,(int)$user['id'],'status','data_report',$id,$status);redirect('/admin/data-reports');}
        $total=(int)$db->query('SELECT COUNT(*) FROM data_reports')->fetchColumn();$pg=pagination_meta($total,page_number(),50);$items=$db->query("SELECT d.*,l.name listing_name,'normal' severity,NULL evidence FROM data_reports d JOIN listings l ON l.id=d.listing_id ORDER BY d.id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}")->fetchAll();admin_render('admin_complaints',['title'=>'بلاغات البيانات','items'=>$items,'reportMode'=>true,'pagination'=>$pg]);exit;
    }

    if($path==='/admin/import-export' && can($user,'import_export')){
        if(!final_v6_schema($db)){flash('error','طبّق migration 007_final_project.sql أولًا.');redirect('/admin');}
        $download=(string)($_GET['download']??'');
        if($download==='export'){
            $scope=in_array($_GET['scope']??'all',['all','published','draft','suspended','verified','recommended'],true)?(string)($_GET['scope']??'all'):'all';
            $exportFilters=['q'=>mb_substr(trim((string)($_GET['q']??'')),0,190,'UTF-8'),'country_id'=>(int)($_GET['country_id']??0),'region_id'=>(int)($_GET['region_id']??0),'city_id'=>(int)($_GET['city_id']??0),'category_id'=>(int)($_GET['category_id']??0)];
            $rows=center_export_rows($db,$scope,$exportFilters);$headers=center_import_columns();$matrix=[];foreach($rows as $r){$line=[];foreach($headers as $h)$line[]=(string)($r[$h]??'');$matrix[]=$line;}xlsx_download('mihrab-centers-'.date('Y-m-d').'.xlsx',$headers,$matrix);}
        if($download==='template'){
            $headers=center_import_columns();$example=['','مركز تجريبي فقط','اسم مقدم الخدمة','center','draft','unverified','0','0','','','','','','','','','','','','','','','','','','','','','','','','','','',''];
            $instructions=[['الحقل','القاعدة'],['center_id','اتركه فارغًا لإنشاء سجل جديد، واستخدم ID الموجود للتحديث.'],['الخلايا الفارغة','لا تمسح القيمة الحالية افتراضيًا.'],['country_id / region_id / city_id','الأولوية للمعرفات الثابتة. لا يتم إنشاء مدينة تلقائيًا من خطأ إملائي.'],['status','draft / published / needs_update / suspended / archived / hidden'],['verification_status','unverified / pending / verified / rejected / needs_update'],['phone / whatsapp','تعامل كنص للحفاظ على + والأصفار.']];
            $refs=[];foreach($db->query('SELECT c.id country_id,c.name country_name,r.id region_id,r.name region_name,ci.id city_id,ci.name city_name FROM countries c LEFT JOIN regions r ON r.country_id=c.id LEFT JOIN cities ci ON ci.region_id=r.id ORDER BY c.name,r.name,ci.name LIMIT 5000')->fetchAll(PDO::FETCH_ASSOC) as $r)$refs[]=array_values($r);
            xlsx_download('mihrab-centers-template.xlsx',$headers,[$example],[['name'=>'Instructions','headers'=>['Field','Rule'],'rows'=>array_slice($instructions,1)],['name'=>'Reference Values','headers'=>['country_id','country_name','region_id','region_name','city_id','city_name'],'rows'=>$refs]]);
        }
        if(isset($_GET['report'])){$id=(int)$_GET['report'];$st=$db->prepare('SELECT report_json FROM center_import_jobs WHERE id=? LIMIT 1');$st->execute([$id]);$report=json_decode((string)$st->fetchColumn(),true);if(!is_array($report)){http_response_code(404);exit('التقرير غير موجود.');}header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="mihrab-import-report-'.$id.'.csv"');echo "\xEF\xBB\xBF";$out=fopen('php://output','wb');fputcsv($out,['row','record','result','error']);foreach($report as $r)fputcsv($out,[$r['row']??'',$r['record']??'',$r['result']??'',$r['error']??'']);fclose($out);exit;}
        $preview=$_SESSION['center_import_preview']??null;
        if(is_post()){
            verify_csrf();$action=(string)($_POST['action']??'');
            try{
                if($action==='preview'){$matrix=spreadsheet_read_upload($_FILES['file']??[]);$preview=center_import_preview($db,$matrix,!empty($_POST['allow_clear']));$preview['filename']=mb_substr((string)($_FILES['file']['name']??'import.xlsx'),0,255,'UTF-8');$_SESSION['center_import_preview']=$preview;flash('success','تم فحص الملف. راجع المعاينة قبل التأكيد.');redirect('/admin/import-export');}
                if($action==='confirm'){
                    if(!is_array($preview))throw new RuntimeException('لا توجد معاينة صالحة. ارفع الملف من جديد.');if(($preview['invalid']??0)>0)throw new RuntimeException('لا يمكن تأكيد ملف يحتوي أخطاء.');$backup=backup_create_typed($db,$config,__DIR__,'full','pre-center-import');$backupName=(string)$backup['name'];backup_register($db,$backup,(int)$user['id']);
                    $db->prepare('INSERT INTO center_import_jobs(user_id,filename,status,total_rows,backup_name,created_at) VALUES(?,?,?,?,?,NOW())')->execute([(int)$user['id'],(string)$preview['filename'],'running',(int)$preview['total'],$backupName]);$jobId=(int)$db->lastInsertId();$result=center_import_apply($db,$preview,(int)$user['id']);$db->prepare('UPDATE center_import_jobs SET status=?,created_count=?,updated_count=?,skipped_count=?,failed_count=?,report_json=?,completed_at=NOW() WHERE id=?')->execute([$result['failed']?'failed':'completed',$result['created'],$result['updated'],$result['skipped'],$result['failed'],json_encode($result['report'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$jobId]);audit($db,(int)$user['id'],'center_import','import',$jobId,'created='.$result['created'].' updated='.$result['updated'].' failed='.$result['failed'].' backup='.$backupName);unset($_SESSION['center_import_preview']);flash($result['failed']?'error':'success','اكتمل الاستيراد: جديد '.$result['created'].'، محدث '.$result['updated'].'، فشل '.$result['failed'].'.');redirect('/admin/import-export');
                }
            }catch(Throwable $e){flash('error',$e instanceof InvalidArgumentException||$e instanceof RuntimeException?$e->getMessage():'تعذر تنفيذ الاستيراد.');redirect('/admin/import-export');}
        }
        $jobs=$db->query('SELECT * FROM center_import_jobs ORDER BY id DESC LIMIT 50')->fetchAll();$loc=fetch_locations($db,false);$exportSpecialties=$db->query('SELECT id,name FROM specialties WHERE is_active=1 ORDER BY name')->fetchAll();admin_render('admin_import_export',['title'=>'استيراد وتصدير المراكز','preview'=>$preview,'jobs'=>$jobs,'countries'=>$loc['countries'],'regions'=>$loc['regions'],'cities'=>$loc['cities'],'specialties'=>$exportSpecialties]);exit;
    }

    if($path==='/admin/articles/export' && can($user,'articles')){
        $format=in_array($_GET['format']??'csv',['csv','json'],true)?(string)($_GET['format']??'csv'):'csv';$where='1=1';$params=[];if(blog_has_v4_schema($db))$where.=' AND a.deleted_at IS NULL';if(!can($user,'articles_all')){$where.=' AND a.created_by=?';$params[]=(int)$user['id'];}
        $st=$db->prepare("SELECT a.id,a.title,a.slug,a.excerpt,a.status,a.featured,a.published_at,a.views,a.seo_title,a.seo_description,a.created_at,a.updated_at,ac.name category_name,u.name author_name FROM articles a LEFT JOIN article_categories ac ON ac.id=a.category_id LEFT JOIN users u ON u.id=a.created_by WHERE {$where} ORDER BY a.id DESC");$st->execute($params);$rows=$st->fetchAll();
        if($format==='json'){header('Content-Type: application/json; charset=UTF-8');header('Content-Disposition: attachment; filename="mihrab-articles.json"');echo json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);exit;}
        header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="mihrab-articles.csv"');echo "\xEF\xBB\xBF";$out=fopen('php://output','wb');fputcsv($out,['ID','Title','Slug','Excerpt','Status','Featured','Published At','Views','SEO Title','SEO Description','Category','Author','Created At','Updated At']);foreach($rows as $r)fputcsv($out,[$r['id'],$r['title'],$r['slug'],$r['excerpt'],$r['status'],$r['featured']??0,$r['published_at'],$r['views'],$r['seo_title'],$r['seo_description'],$r['category_name'],$r['author_name'],$r['created_at'],$r['updated_at']]);fclose($out);exit;
    }

    if($path==='/admin/articles' && can($user,'articles')){
        if(!blog_has_v4_schema($db)){flash('error','يلزم تطبيق migration 005_blog_system.sql قبل استخدام تطوير المدونة.');}
        if(is_post() && blog_has_v4_schema($db)){
            verify_csrf();$action=(string)($_POST['action']??'');
            if($action==='bulk'){
                $ids=array_values(array_unique(array_filter(array_map('intval',is_array($_POST['ids']??null)?$_POST['ids']:[]))));$ids=array_slice($ids,0,100);
                if(!$ids){flash('error','حدد مقالًا واحدًا على الأقل.');redirect('/admin/articles');}
                $place=implode(',',array_fill(0,count($ids),'?'));$scopeParams=$ids;$scope="id IN ({$place})";
                if(!can($user,'articles_all')){$scope.=' AND created_by=?';$scopeParams[]=(int)$user['id'];}
                $bulk=(string)($_POST['bulk_action']??'');
                try{
                    if($bulk==='publish' && can($user,'articles_publish')){$db->prepare("UPDATE articles SET status='published',published_at=COALESCE(published_at,NOW()),updated_by=?,updated_at=NOW() WHERE {$scope}")->execute([(int)$user['id'],...$scopeParams]);}
                    elseif($bulk==='draft'){$db->prepare("UPDATE articles SET status='draft',updated_by=?,updated_at=NOW() WHERE {$scope}")->execute([(int)$user['id'],...$scopeParams]);}
                    elseif($bulk==='archive' && can($user,'articles_publish')){$db->prepare("UPDATE articles SET status='archived',updated_by=?,updated_at=NOW() WHERE {$scope}")->execute([(int)$user['id'],...$scopeParams]);}
                    elseif($bulk==='trash'){$db->prepare("UPDATE articles SET deleted_at=NOW(),updated_by=?,updated_at=NOW() WHERE {$scope}")->execute([(int)$user['id'],...$scopeParams]);}
                    elseif($bulk==='restore'){$db->prepare("UPDATE articles SET deleted_at=NULL,updated_by=?,updated_at=NOW() WHERE {$scope}")->execute([(int)$user['id'],...$scopeParams]);}
                    elseif($bulk==='category'){$cat=max(0,(int)($_POST['bulk_category_id']??0));$db->prepare("UPDATE articles SET category_id=?,generated_cover_signature=NULL,updated_by=?,updated_at=NOW() WHERE {$scope}")->execute([$cat?:null,(int)$user['id'],...$scopeParams]);}
                    elseif($bulk==='delete' && can($user,'articles_all')){$db->prepare("DELETE FROM articles WHERE deleted_at IS NOT NULL AND {$scope}")->execute($scopeParams);}
                    else throw new InvalidArgumentException('الإجراء غير مسموح أو غير مكتمل.');
                    if($bulk==='category'){$coverSql='SELECT a.*,ac.name category_name FROM articles a LEFT JOIN article_categories ac ON ac.id=a.category_id WHERE a.id IN ('.$place.') AND a.cover_mode=\'auto\'';$coverParams=$ids;if(!can($user,'articles_all')){$coverSql.=' AND a.created_by=?';$coverParams[]=(int)$user['id'];}$coverSt=$db->prepare($coverSql);$coverSt->execute($coverParams);foreach($coverSt->fetchAll() as $coverArticle){try{article_cover_generate($db,$coverArticle,(int)$user['id']);}catch(Throwable $coverError){error_log('Mihrab bulk cover regeneration failed: '.$coverError->getMessage());}}}
                    audit($db,(int)$user['id'],'article_bulk','article',0,$bulk.' ids='.implode(',',$ids));flash('success','تم تنفيذ الإجراء الجماعي.');
                }catch(Throwable $e){flash('error',$e instanceof InvalidArgumentException?$e->getMessage():'تعذر تنفيذ الإجراء الجماعي.');}
                redirect('/admin/articles'.(!empty($_GET['trash'])?'?trash=1':''));
            }
        }
        $filters=['q'=>mb_substr(trim((string)($_GET['q']??'')),0,120,'UTF-8'),'status'=>(string)($_GET['status']??''),'category'=>max(0,(int)($_GET['category']??0)),'trash'=>!empty($_GET['trash'])];
        $params=[];$where=[];
        if(blog_has_v4_schema($db))$where[]=$filters['trash']?'a.deleted_at IS NOT NULL':'a.deleted_at IS NULL';
        if(!can($user,'articles_all')){$where[]='a.created_by=?';$params[]=(int)$user['id'];}
        if($filters['q']!==''){$where[]='(a.title LIKE ? OR a.excerpt LIKE ?)';$like='%'.$filters['q'].'%';array_push($params,$like,$like);}
        $validStatuses=['draft','pending_review','published','scheduled','private','archived'];if(in_array($filters['status'],$validStatuses,true)){$where[]='a.status=?';$params[]=$filters['status'];}
        if($filters['category']){$where[]='a.category_id=?';$params[]=$filters['category'];}
        $whereSql=$where?implode(' AND ',$where):'1=1';$count=$db->prepare("SELECT COUNT(*) FROM articles a WHERE {$whereSql}");$count->execute($params);$pg=pagination_meta((int)$count->fetchColumn(),page_number(),50);
        $st=$db->prepare("SELECT a.*,ac.name category_name,u.name author_name FROM articles a LEFT JOIN article_categories ac ON ac.id=a.category_id LEFT JOIN users u ON u.id=a.created_by WHERE {$whereSql} ORDER BY a.updated_at DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");$st->execute($params);$articles=$st->fetchAll();
        try{$categories=$db->query('SELECT * FROM article_categories ORDER BY sort_order,name')->fetchAll();}catch(Throwable $e){$categories=$db->query('SELECT * FROM article_categories ORDER BY name')->fetchAll();}
        admin_render('admin_articles',['title'=>'المقالات','articles'=>$articles,'pagination'=>$pg,'filters'=>$filters,'categories'=>$categories,'user'=>$user]);exit;
    }

    if($path==='/admin/article/autosave' && can($user,'articles')){
        header('Content-Type: application/json; charset=UTF-8');
        if(!is_post()||!blog_has_v4_schema($db)){http_response_code(400);echo json_encode(['ok'=>false]);exit;}
        verify_csrf();$id=(int)($_POST['id']??0);$st=$db->prepare('SELECT * FROM articles WHERE id=? AND deleted_at IS NULL LIMIT 1');$st->execute([$id]);$article=$st->fetch();
        if(!$article||!blog_can_edit_article($user,$article)){http_response_code(403);echo json_encode(['ok'=>false]);exit;}
        $draft=['title'=>mb_substr(trim((string)($_POST['title']??'')),0,240,'UTF-8'),'excerpt'=>mb_substr(trim((string)($_POST['excerpt']??'')),0,700,'UTF-8'),'content_html'=>blog_sanitize_html((string)($_POST['content_html']??'')),'tags'=>mb_substr(trim((string)($_POST['tags']??'')),0,1000,'UTF-8')];
        $db->prepare('INSERT INTO article_autosaves(article_id,user_id,draft_json,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),draft_json=VALUES(draft_json),updated_at=NOW()')->execute([$id,(int)$user['id'],json_encode($draft,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        echo json_encode(['ok'=>true,'at'=>date('H:i')],JSON_UNESCAPED_UNICODE);exit;
    }

    if(in_array($path,['/admin/article/new','/admin/article/edit'],true) && can($user,'articles')){
        if(!blog_has_v4_schema($db)){flash('error','طبّق migration 005_blog_system.sql أولًا لتفعيل محرر المدونة الجديد.');redirect('/admin/articles');}
        $id=(int)($_GET['id']??$_POST['id']??0);$item=[];
        if($id){$st=$db->prepare('SELECT * FROM articles WHERE id=? AND deleted_at IS NULL LIMIT 1');$st->execute([$id]);$item=$st->fetch()?:[];if(!$item){http_response_code(404);admin_render('admin_not_found',['title'=>'المقال غير موجود']);exit;}if(!blog_can_edit_article($user,$item)){http_response_code(403);admin_render('admin_not_found',['title'=>'لا تملك صلاحية تحرير هذا المقال']);exit;}}
        $categories=$db->query('SELECT * FROM article_categories WHERE is_active=1 ORDER BY sort_order,name')->fetchAll();$media=$db->query("SELECT id,path,alt_text,original_name,title FROM media WHERE deleted_at IS NULL AND mime_type LIKE 'image/%' ORDER BY id DESC LIMIT 200")->fetchAll();$tags=$id?blog_get_tags($db,$id):[];$authors=can($user,'articles_all')?$db->query("SELECT id,name FROM users WHERE status='active' AND role IN('super_admin','admin','editor') ORDER BY name")->fetchAll():[];$autosave=false;if($id){$st=$db->prepare('SELECT draft_json,updated_at FROM article_autosaves WHERE article_id=? AND updated_at>? LIMIT 1');$st->execute([$id,(string)($item['updated_at']??'1970-01-01 00:00:00')]);$autosave=$st->fetch()?:false;}
        if(is_post()){
            verify_csrf();
            try{
                $title=mb_substr(trim((string)($_POST['title']??'')),0,240,'UTF-8');if($title==='')throw new InvalidArgumentException('عنوان المقال مطلوب.');
                $slugInput=trim((string)($_POST['slug']??''));$slug=unique_slug($db,'articles',$slugInput!==''?$slugInput:$title,$id);$oldSlug=(string)($item['slug']??'');
                $content=blog_sanitize_html((string)($_POST['content_html']??''));$excerpt=mb_substr(trim((string)($_POST['excerpt']??'')),0,700,'UTF-8');if($excerpt==='')$excerpt=blog_excerpt($content,300);
                $coverMode=($_POST['cover_mode']??'auto')==='custom'?'custom':'auto';$image=$coverMode==='custom'?(string)($item['image']??''):'';
                if($coverMode==='custom'&&!empty($_POST['remove_image'])){$image='';$coverMode='auto';}$mediaPath=trim((string)($_POST['media_path']??''));if($coverMode==='custom'&&$mediaPath!==''&&str_starts_with($mediaPath,'uploads/')&&!str_contains($mediaPath,'../'))$image=$mediaPath;
                if($coverMode==='custom'&&!empty($_FILES['image']['tmp_name'])){$image=upload_image('image','article')??$image;media_register($db,$image,$_FILES['image'],(int)$user['id'],$title);}
                if($coverMode==='custom'&&$image==='')$coverMode='auto';$coverShortTitle=mb_substr(trim((string)($_POST['cover_short_title']??'')),0,240,'UTF-8');$coverTemplate='mihrab-classic';
                $publishedRaw=trim((string)($_POST['published_at']??''));$published=$publishedRaw!==''?date('Y-m-d H:i:s',(int)strtotime($publishedRaw)):null;$status=blog_allowed_status($user,(string)($_POST['status']??'draft'));
                if($status==='published' && !$published)$published=date('Y-m-d H:i:s');if($status==='scheduled'){if(!$published)throw new InvalidArgumentException('حدد تاريخًا للمقال المجدول.');if(strtotime($published)<=time())$status='published';}
                $featured=can($user,'articles_publish')&&!empty($_POST['featured'])?1:0;$seoTitle=mb_substr(trim((string)($_POST['seo_title']??'')),0,255,'UTF-8');$seoDescription=mb_substr(trim((string)($_POST['seo_description']??'')),0,500,'UTF-8');$canonical=mb_substr(trim((string)($_POST['canonical_url']??'')),0,700,'UTF-8');if($canonical!==''&&!valid_http_url($canonical))throw new InvalidArgumentException('رابط Canonical غير صالح.');
                $ogTitle=mb_substr(trim((string)($_POST['og_title']??'')),0,255,'UTF-8');$ogDescription=mb_substr(trim((string)($_POST['og_description']??'')),0,500,'UTF-8');$ogImage=mb_substr(trim((string)($_POST['og_image']??'')),0,255,'UTF-8');if($ogImage!==''&&!blog_safe_url($ogImage,true))throw new InvalidArgumentException('صورة Open Graph يجب أن تكون رابطًا صالحًا أو مسار uploads.');$robots=in_array($_POST['robots']??'index,follow',['index,follow','noindex,follow','noindex,nofollow'],true)?(string)$_POST['robots']:'index,follow';$references=mb_substr(trim((string)($_POST['references_text']??'')),0,15000,'UTF-8');
                $authorId=(int)($item['created_by']??$user['id']);if(can($user,'articles_all')&&!empty($_POST['created_by'])){$candidate=(int)$_POST['created_by'];$st=$db->prepare("SELECT id FROM users WHERE id=? AND status='active' LIMIT 1");$st->execute([$candidate]);if($st->fetchColumn())$authorId=$candidate;}
                $db->beginTransaction();
                try{
                    if($id){blog_save_revision($db,$item,(int)$user['id']);if($oldSlug!==''&&$oldSlug!==$slug&&blog_is_public_status((string)$item['status'])){$db->prepare('INSERT IGNORE INTO article_slug_history(article_id,old_slug,created_at) VALUES(?,?,NOW())')->execute([$id,$oldSlug]);}$st=$db->prepare('UPDATE articles SET title=?,slug=?,excerpt=?,content_html=?,image=?,cover_mode=?,cover_short_title=?,cover_template=?,category_id=?,status=?,featured=?,published_at=?,seo_title=?,seo_description=?,canonical_url=?,og_title=?,og_description=?,og_image=?,robots=?,references_text=?,created_by=?,updated_by=?,updated_at=NOW() WHERE id=?');$st->execute([$title,$slug,$excerpt,$content,$image?:null,$coverMode,$coverShortTitle?:null,$coverTemplate,(int)($_POST['category_id']??0)?:null,$status,$featured,$published,$seoTitle?:null,$seoDescription?:null,$canonical?:null,$ogTitle?:null,$ogDescription?:null,$ogImage?:null,$robots,$references?:null,$authorId,(int)$user['id'],$id]);}
                    else{$st=$db->prepare('INSERT INTO articles(title,slug,excerpt,content_html,image,cover_mode,cover_short_title,cover_template,category_id,status,featured,published_at,views,seo_title,seo_description,canonical_url,og_title,og_description,og_image,robots,references_text,created_by,updated_by,created_at,updated_at,deleted_at) VALUES(?,?,?,?,?,?,?,?,?,?,?, ?,0,?,?,?,?,?,?,?,?,?,NOW(),NOW(),NULL)');$st->execute([$title,$slug,$excerpt,$content,$image?:null,$coverMode,$coverShortTitle?:null,$coverTemplate,(int)($_POST['category_id']??0)?:null,$status,$featured,$published,$seoTitle?:null,$seoDescription?:null,$canonical?:null,$ogTitle?:null,$ogDescription?:null,$ogImage?:null,$robots,$references?:null,$authorId,(int)$user['id']]);$id=(int)$db->lastInsertId();}
                    blog_sync_tags($db,$id,(string)($_POST['tags']??''));$db->prepare('DELETE FROM article_autosaves WHERE article_id=?')->execute([$id]);$db->commit();
                }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
                if($coverMode==='auto'){try{$st=$db->prepare('SELECT a.*,ac.name category_name FROM articles a LEFT JOIN article_categories ac ON ac.id=a.category_id WHERE a.id=?');$st->execute([$id]);$savedArticle=$st->fetch();if($savedArticle)article_cover_generate($db,$savedArticle,(int)$user['id']);}catch(Throwable $e){error_log('Mihrab cover generation failed for article '.$id.': '.$e->getMessage());}}
                $st=$db->prepare('SELECT * FROM articles WHERE id=?');$st->execute([$id]);$coverArticle=$st->fetch()?:[];blog_sync_media_usage($db,$id,article_cover_path($coverArticle));
                audit($db,(int)$user['id'],$item?'article_update':'article_create','article',$id,$title.' status='.$status);flash('success','تم حفظ المقال بنجاح.');redirect('/admin/article/edit?id='.$id);
            }catch(InvalidArgumentException|RuntimeException $e){flash('error',$e->getMessage());redirect($id?'/admin/article/edit?id='.$id:'/admin/article/new');}catch(Throwable $e){flash('error','تعذر حفظ المقال. تأكد من تطبيق migration 005 وعدم تكرار الرابط المختصر.');redirect($id?'/admin/article/edit?id='.$id:'/admin/article/new');}
        }
        admin_render('admin_article_form',['title'=>$id?'تحرير المقال':'مقال جديد','item'=>$item,'categories'=>$categories,'media'=>$media,'tags'=>$tags,'authors'=>$authors,'autosave'=>$autosave,'user'=>$user]);exit;
    }

    if($path==='/admin/article/preview' && can($user,'articles')){
        $id=(int)($_GET['id']??0);$st=$db->prepare('SELECT a.*,ac.name category_name,ac.slug category_slug,u.name author_name FROM articles a LEFT JOIN article_categories ac ON ac.id=a.category_id LEFT JOIN users u ON u.id=a.created_by WHERE a.id=? AND a.deleted_at IS NULL LIMIT 1');$st->execute([$id]);$article=$st->fetch();if(!$article||!blog_can_edit_article($user,$article)){http_response_code(404);render('404',['title'=>'المقال غير موجود']);exit;}
        $content=blog_content_with_toc((string)$article['content_html']);$contentHtml=$content['html'];$toc=$content['toc'];$tags=blog_get_tags($db,$id);$related=[];$references=blog_references((string)($article['references_text']??''));$blog=blog_settings($db);render('article',['title'=>'معاينة: '.$article['title'],'description'=>$article['excerpt']?:blog_excerpt((string)$article['content_html']),'canonical'=>base_url('/admin/article/preview?id='.$id),'robots'=>'noindex,nofollow','article'=>$article,'contentHtml'=>$contentHtml,'toc'=>$toc,'tags'=>$tags,'related'=>$related,'references'=>$references,'blog'=>$blog]);exit;
    }

    if($path==='/admin/article/revisions' && can($user,'articles')){
        if(!blog_has_v4_schema($db)){redirect('/admin/articles');}$id=(int)($_GET['id']??0);$st=$db->prepare('SELECT * FROM articles WHERE id=? AND deleted_at IS NULL LIMIT 1');$st->execute([$id]);$article=$st->fetch();if(!$article||!blog_can_edit_article($user,$article)){http_response_code(403);admin_render('admin_not_found',['title'=>'لا يمكن الوصول إلى سجل هذا المقال']);exit;}
        if(is_post()){
            verify_csrf();$revisionId=(int)($_POST['revision_id']??0);$st=$db->prepare('SELECT * FROM article_revisions WHERE id=? AND article_id=? LIMIT 1');$st->execute([$revisionId,$id]);$revision=$st->fetch();if(!$revision){flash('error','النسخة المطلوبة غير موجودة.');redirect('/admin/article/revisions?id='.$id);}$snap=json_decode((string)$revision['snapshot_json'],true);if(!is_array($snap)){flash('error','بيانات النسخة غير صالحة.');redirect('/admin/article/revisions?id='.$id);}
            blog_save_revision($db,$article,(int)$user['id']);$restoreSlug=unique_slug($db,'articles',(string)($snap['slug']??$snap['title']??$article['title']),$id);if($article['slug']!==$restoreSlug&&blog_is_public_status((string)$article['status']))$db->prepare('INSERT IGNORE INTO article_slug_history(article_id,old_slug,created_at) VALUES(?,?,NOW())')->execute([$id,$article['slug']]);$restoreStatus=blog_allowed_status($user,(string)($snap['status']??'draft'));
            $restoreMode=($snap['cover_mode']??'auto')==='custom'?'custom':'auto';$restoreImage=$restoreMode==='custom'?(string)($snap['image']??''):'';if($restoreImage==='')$restoreMode='auto';$st=$db->prepare('UPDATE articles SET title=?,slug=?,excerpt=?,content_html=?,image=?,cover_mode=?,cover_short_title=?,cover_template=?,category_id=?,status=?,featured=?,published_at=?,seo_title=?,seo_description=?,canonical_url=?,og_title=?,og_description=?,og_image=?,robots=?,references_text=?,generated_cover_signature=NULL,updated_by=?,updated_at=NOW() WHERE id=?');$st->execute([(string)($snap['title']??$article['title']),$restoreSlug,(string)($snap['excerpt']??''),blog_sanitize_html((string)($snap['content_html']??'')),$restoreImage?:null,$restoreMode,($snap['cover_short_title']??null),($snap['cover_template']??'mihrab-classic'),($snap['category_id']??null),$restoreStatus,!empty($snap['featured'])?1:0,($snap['published_at']??null),($snap['seo_title']??null),($snap['seo_description']??null),($snap['canonical_url']??null),($snap['og_title']??null),($snap['og_description']??null),($snap['og_image']??null),(string)($snap['robots']??'index,follow'),($snap['references_text']??null),(int)$user['id'],$id]);blog_sync_tags($db,$id,implode('، ',is_array($snap['tags']??null)?$snap['tags']:[]));if($restoreMode==='auto'){try{$coverSt=$db->prepare('SELECT a.*,ac.name category_name FROM articles a LEFT JOIN article_categories ac ON ac.id=a.category_id WHERE a.id=?');$coverSt->execute([$id]);if($restored=$coverSt->fetch())article_cover_generate($db,$restored,(int)$user['id']);}catch(Throwable $e){error_log('Mihrab cover regeneration after revision restore failed: '.$e->getMessage());}}$coverSt=$db->prepare('SELECT * FROM articles WHERE id=?');$coverSt->execute([$id]);blog_sync_media_usage($db,$id,article_cover_path($coverSt->fetch()?:[]));audit($db,(int)$user['id'],'article_revision_restore','article',$id,'revision='.$revisionId);flash('success','تمت استعادة النسخة المحددة.');redirect('/admin/article/edit?id='.$id);
        }
        $st=$db->prepare('SELECT r.*,u.name user_name FROM article_revisions r LEFT JOIN users u ON u.id=r.user_id WHERE r.article_id=? ORDER BY r.id DESC LIMIT 50');$st->execute([$id]);$revisions=$st->fetchAll();admin_render('admin_article_revisions',['title'=>'سجل نسخ المقال','article'=>$article,'revisions'=>$revisions]);exit;
    }

    if($path==='/admin/article-categories' && (can($user,'article_taxonomy')||can($user,'articles'))){
        if(!blog_has_v4_schema($db)){flash('error','طبّق migration 005 أولًا.');redirect('/admin/articles');}
        if(is_post()){
            verify_csrf();$action=(string)($_POST['action']??'save');$id=(int)($_POST['id']??0);
            try{
                if($action==='delete'){$db->prepare('DELETE FROM article_categories WHERE id=?')->execute([$id]);flash('success','تم حذف التصنيف مع إبقاء المقالات دون تصنيف.');}
                else{$name=mb_substr(trim((string)($_POST['name']??'')),0,160,'UTF-8');if($name==='')throw new InvalidArgumentException('اسم التصنيف مطلوب.');$slugRaw=trim((string)($_POST['slug']??''));$slug=unique_slug($db,'article_categories',$slugRaw!==''?$slugRaw:$name,$id);$description=mb_substr(trim((string)($_POST['description']??'')),0,700,'UTF-8');$seoTitle=mb_substr(trim((string)($_POST['seo_title']??'')),0,255,'UTF-8');$seoDescription=mb_substr(trim((string)($_POST['seo_description']??'')),0,500,'UTF-8');$active=!empty($_POST['is_active'])?1:0;$sort=(int)($_POST['sort_order']??0);if($id)$db->prepare('UPDATE article_categories SET name=?,slug=?,description=?,is_active=?,sort_order=?,seo_title=?,seo_description=? WHERE id=?')->execute([$name,$slug,$description?:null,$active,$sort,$seoTitle?:null,$seoDescription?:null,$id]);else$db->prepare('INSERT INTO article_categories(name,slug,description,is_active,sort_order,seo_title,seo_description) VALUES(?,?,?,?,?,?,?)')->execute([$name,$slug,$description?:null,$active,$sort,$seoTitle?:null,$seoDescription?:null]);flash('success','تم حفظ التصنيف.');}
            }catch(Throwable $e){flash('error',$e instanceof InvalidArgumentException?$e->getMessage():'تعذر حفظ التصنيف؛ تأكد أن الاسم والرابط غير مستخدمين.');}redirect('/admin/article-categories');
        }
        $categories=$db->query('SELECT ac.*,COUNT(a.id) article_count FROM article_categories ac LEFT JOIN articles a ON a.category_id=ac.id AND a.deleted_at IS NULL GROUP BY ac.id ORDER BY ac.sort_order,ac.name')->fetchAll();admin_render('admin_article_categories',['title'=>'تصنيفات المقالات','categories'=>$categories]);exit;
    }

    if($path==='/admin/article-tags' && (can($user,'article_taxonomy')||can($user,'articles'))){
        if(!blog_has_v4_schema($db)){redirect('/admin/articles');}
        if(is_post()){verify_csrf();$action=(string)($_POST['action']??'save');try{if($action==='delete'){$db->prepare('DELETE FROM article_tags WHERE id=?')->execute([(int)($_POST['id']??0)]);flash('success','تم حذف الوسم.');}else{$name=mb_substr(trim((string)($_POST['name']??'')),0,100,'UTF-8');if($name==='')throw new InvalidArgumentException('اسم الوسم مطلوب.');$slugRaw=trim((string)($_POST['slug']??''));$slug=$slugRaw!==''?slugify($slugRaw):unique_blog_tag_slug($db,$name);$db->prepare('INSERT INTO article_tags(name,slug,created_at,updated_at) VALUES(?,?,NOW(),NOW())')->execute([$name,$slug]);flash('success','تم حفظ الوسم.');}}catch(Throwable $e){flash('error',$e instanceof InvalidArgumentException?$e->getMessage():'الوسم أو الرابط مستخدم مسبقًا.');}redirect('/admin/article-tags');}
        $tags=$db->query('SELECT t.*,COUNT(m.article_id) article_count FROM article_tags t LEFT JOIN article_tag_map m ON m.tag_id=t.id GROUP BY t.id ORDER BY t.name')->fetchAll();admin_render('admin_article_tags',['title'=>'وسوم المقالات','tags'=>$tags]);exit;
    }

    if($path==='/admin/blog-settings' && can($user,'blog_settings')){
        if(is_post()){verify_csrf();$defaultImage=mb_substr(trim((string)($_POST['blog_default_image']??'')),0,255,'UTF-8');if($defaultImage!==''&&!blog_safe_url($defaultImage,true)){flash('error','الصورة الافتراضية غير صالحة.');redirect('/admin/blog-settings');}save_setting($db,'blog_title',mb_substr(trim((string)($_POST['blog_title']??'مركز التوعية')),0,160,'UTF-8'));save_setting($db,'blog_intro',mb_substr(trim((string)($_POST['blog_intro']??'')),0,700,'UTF-8'));save_setting($db,'blog_per_page',(string)max(6,min(48,(int)($_POST['blog_per_page']??12))));save_setting($db,'blog_default_image',$defaultImage);save_setting($db,'blog_intro_page_slug',mb_substr(slugify(trim((string)($_POST['blog_intro_page_slug']??''))),0,220,'UTF-8'));if(trim((string)($_POST['blog_intro_page_slug']??''))==='')save_setting($db,'blog_intro_page_slug','');foreach(['show_author','show_date','show_reading_time','show_views','show_share','show_related','show_toc'] as $k)save_setting($db,'blog_'.$k,!empty($_POST[$k])?'1':'0');audit($db,(int)$user['id'],'blog_settings_update','settings',0,'blog');flash('success','تم حفظ إعدادات المدونة.');redirect('/admin/blog-settings');}
        $blog=blog_settings($db);$media=$db->query("SELECT id,path,alt_text,original_name,title FROM media WHERE deleted_at IS NULL AND mime_type LIKE 'image/%' ORDER BY id DESC LIMIT 200")->fetchAll();admin_render('admin_blog_settings',['title'=>'إعدادات المدونة','blog'=>$blog,'media'=>$media]);exit;
    }

    if($path==='/admin/media' && can($user,'media')){
        $isMediaAdmin=in_array((string)$user['role'],['super_admin','admin'],true);
        if(is_post()){
            verify_csrf();$action=(string)($_POST['action']??'upload');
            try{
                if($action==='upload'){
                    rate_limit('media-upload',2);
                    $files=$_FILES['files']??null;if(!$files||empty($files['name']))throw new InvalidArgumentException('اختر ملفًا واحدًا على الأقل.');
                    $names=is_array($files['name'])?$files['name']:[$files['name']];$ok=0;$failed=0;$folder=max(0,(int)($_POST['folder_id']??0));
                    foreach($names as $i=>$unused){
                        $f=['name'=>is_array($files['name'])?$files['name'][$i]:$files['name'],'tmp_name'=>is_array($files['tmp_name'])?$files['tmp_name'][$i]:$files['tmp_name'],'error'=>is_array($files['error'])?$files['error'][$i]:$files['error'],'size'=>is_array($files['size'])?$files['size'][$i]:$files['size']];
                        if((int)$f['error']===UPLOAD_ERR_NO_FILE)continue;
                        try{$meta=upload_media_file($f,'media');
                            $dup=$db->prepare('SELECT id FROM media WHERE file_hash=? AND deleted_at IS NULL LIMIT 1');$dup->execute([$meta['hash']]);
                            if($dup->fetchColumn()){ $full=media_file_path($meta['path']);if(is_file($full))@unlink($full);$failed++;continue; }
                            $st=$db->prepare('INSERT INTO media(path,original_name,title,mime_type,size_bytes,width,height,file_hash,folder_id,alt_text,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
                            $st->execute([$meta['path'],$meta['original_name'],pathinfo($meta['original_name'],PATHINFO_FILENAME),$meta['mime_type'],$meta['size_bytes'],$meta['width'],$meta['height'],$meta['hash'],$folder?:null,null,$user['id']]);$id=(int)$db->lastInsertId();audit($db,(int)$user['id'],'upload','media',$id,$meta['path']);$ok++;
                        }catch(Throwable $one){$failed++;}
                    }
                    flash($ok?'success':'error','تم رفع '.$ok.' ملف'.($failed?'، وتعذر رفع '.$failed.' (نوع غير مدعوم/مكرر/غير صالح).':''));
                }
                elseif($action==='sync'){
                    $count=0;foreach(glob(configured_storage_path('public','*'))?:[] as $file){if(!is_file($file))continue;$mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);if(!$mime||(!str_starts_with($mime,'image/')&&!str_starts_with($mime,'video/')&&!str_starts_with($mime,'audio/')&&$mime!=='application/pdf'))continue;$rel='uploads/'.basename($file);$info=str_starts_with($mime,'image/')?@getimagesize($file):false;$hash=hash_file('sha256',$file)?:null;$db->prepare('INSERT IGNORE INTO media(path,original_name,title,mime_type,size_bytes,width,height,file_hash,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,NOW(),NOW())')->execute([$rel,basename($file),pathinfo($file,PATHINFO_FILENAME),$mime,filesize($file),$info?$info[0]:null,$info?$info[1]:null,$hash,$user['id']]);$count++;}flash('success','تمت مزامنة '.$count.' ملفًا من uploads.');
                }
                elseif($action==='folder_create'){
                    $name=mb_substr(trim((string)($_POST['name']??'')),0,160,'UTF-8');if($name==='')throw new InvalidArgumentException('اسم المجلد مطلوب.');$parent=max(0,(int)($_POST['parent_id']??0));$db->prepare('INSERT INTO media_folders(name,parent_id,created_by,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())')->execute([$name,$parent?:null,$user['id']]);flash('success','تم إنشاء المجلد.');
                }
                else{
                    $id=max(0,(int)($_POST['id']??0));$st=$db->prepare('SELECT * FROM media WHERE id=? LIMIT 1');$st->execute([$id]);$row=$st->fetch();if(!$row)throw new InvalidArgumentException('ملف الوسائط غير موجود.');if(!$isMediaAdmin&&(int)$row['created_by']!==(int)$user['id'])throw new RuntimeException('لا تملك صلاحية تعديل هذا الملف.');
                    if($action==='update'){
                        $title=mb_substr(trim((string)($_POST['title']??'')),0,255,'UTF-8');$alt=mb_substr(trim((string)($_POST['alt_text']??'')),0,255,'UTF-8');$caption=mb_substr(trim((string)($_POST['caption']??'')),0,500,'UTF-8');$description=mb_substr(trim((string)($_POST['description']??'')),0,4000,'UTF-8');$displayName=mb_substr(trim((string)($_POST['original_name']??'')),0,255,'UTF-8');$folder=max(0,(int)($_POST['folder_id']??0));$db->prepare('UPDATE media SET original_name=?,title=?,alt_text=?,caption=?,description=?,folder_id=?,updated_at=NOW() WHERE id=?')->execute([$displayName?:null,$title?:null,$alt?:null,$caption?:null,$description?:null,$folder?:null,$id]);media_set_tags($db,$id,(string)($_POST['tags']??''));audit($db,(int)$user['id'],'update','media',$id,$row['path']);flash('success','تم تحديث بيانات الملف.');
                    }elseif($action==='trash'){$db->prepare('UPDATE media SET deleted_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$id]);audit($db,(int)$user['id'],'trash','media',$id,$row['path']);flash('success','تم نقل الملف إلى سلة المحذوفات.');}
                    elseif($action==='restore'){$db->prepare('UPDATE media SET deleted_at=NULL,updated_at=NOW() WHERE id=?')->execute([$id]);audit($db,(int)$user['id'],'restore','media',$id,$row['path']);flash('success','تمت استعادة الملف.');}
                    elseif($action==='permanent_delete'){
                        if(!$isMediaAdmin)throw new RuntimeException('الحذف النهائي متاح للمدير فقط.');if(media_usage_count($db,(string)$row['path'])>0)throw new InvalidArgumentException('لا يمكن حذف الملف نهائيًا لأنه مستخدم في الموقع.');$full=media_file_path((string)$row['path']);if(is_file($full))@unlink($full);$db->prepare('DELETE FROM media WHERE id=?')->execute([$id]);audit($db,(int)$user['id'],'delete','media',$id,$row['path']);flash('success','تم حذف الملف نهائيًا.');
                    }
                }
            }catch(InvalidArgumentException|RuntimeException $e){flash('error',$e->getMessage());}catch(Throwable $e){flash('error','تعذر تنفيذ عملية الوسائط. تأكد من تطبيق migration 004.');}
            $back='/admin/media';if(!empty($_GET['trash']))$back.='?trash=1';redirect($back);
        }
        try{
            // Purge metadata for trash older than 30 days only when the physical file is not referenced elsewhere.
            $old=$db->query("SELECT id,path FROM media WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(),INTERVAL 30 DAY) LIMIT 50")->fetchAll();foreach($old as $o){if(media_usage_count($db,(string)$o['path'])===0){$full=media_file_path((string)$o['path']);if(is_file($full))@unlink($full);$db->prepare('DELETE FROM media WHERE id=?')->execute([(int)$o['id']]);}}
            $mediaQ=mb_substr(trim((string)($_GET['q']??'')),0,120,'UTF-8');$mediaKind=(string)($_GET['kind']??'');$folder=max(0,(int)($_GET['folder']??0));$trash=!empty($_GET['trash']);$sort=(string)($_GET['sort']??'newest');$view=in_array($_GET['view']??'grid',['grid','list'],true)?(string)$_GET['view']:'grid';
            $mediaWhere=[$trash?'m.deleted_at IS NOT NULL':'m.deleted_at IS NULL'];$mediaParams=[];
            if($mediaQ!==''){$mediaWhere[]='(m.original_name LIKE ? OR m.title LIKE ? OR m.alt_text LIKE ? OR m.caption LIKE ? OR m.path LIKE ?)';$like='%'.$mediaQ.'%';array_push($mediaParams,$like,$like,$like,$like,$like);}
            if(in_array($mediaKind,['image','video','audio'],true)){$mediaWhere[]='m.mime_type LIKE ?';$mediaParams[]=$mediaKind.'/%';}elseif($mediaKind==='document'){$mediaWhere[]="m.mime_type='application/pdf'";}
            if($folder){$mediaWhere[]='m.folder_id=?';$mediaParams[]=$folder;}
            $order=['newest'=>'m.id DESC','oldest'=>'m.id ASC','name'=>'COALESCE(m.title,m.original_name,m.path) ASC','size'=>'m.size_bytes DESC'][$sort]??'m.id DESC';$where=' WHERE '.implode(' AND ',$mediaWhere);
            $countSt=$db->prepare('SELECT COUNT(*) FROM media m'.$where);$countSt->execute($mediaParams);$pg=pagination_meta((int)$countSt->fetchColumn(),page_number(),30);
            $sql="SELECT m.*,u.name created_by_name,f.name folder_name,(SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') FROM media_tag_map mt JOIN media_tags t ON t.id=mt.tag_id WHERE mt.media_id=m.id) tags FROM media m LEFT JOIN users u ON u.id=m.created_by LEFT JOIN media_folders f ON f.id=m.folder_id".$where.' ORDER BY '.$order.' LIMIT '.$pg['per_page'].' OFFSET '.$pg['offset'];$mediaSt=$db->prepare($sql);$mediaSt->execute($mediaParams);$media=$mediaSt->fetchAll();
            foreach($media as &$m){$m['usage_count']=media_usage_count($db,(string)$m['path']);}$folders=$db->query('SELECT * FROM media_folders ORDER BY parent_id IS NOT NULL,parent_id,name')->fetchAll();$uploaders=$db->query('SELECT id,name FROM users ORDER BY name')->fetchAll();
            admin_render('admin_media',['title'=>'مكتبة الوسائط','media'=>$media,'pagination'=>$pg,'q'=>$mediaQ,'kind'=>$mediaKind,'folder'=>$folder,'folders'=>$folders,'trash'=>$trash,'sort'=>$sort,'view'=>$view,'isMediaAdmin'=>$isMediaAdmin,'uploaders'=>$uploaders]);exit;
        }catch(Throwable $e){flash('error','مكتبة الوسائط المطورة تحتاج تطبيق migrations/004_media_library.sql أولًا.');admin_render('admin_media',['title'=>'مكتبة الوسائط','media'=>[],'pagination'=>['page'=>1,'pages'=>1,'total'=>0],'q'=>'','kind'=>'','folder'=>0,'folders'=>[],'trash'=>false,'sort'=>'newest','view'=>'grid','isMediaAdmin'=>$isMediaAdmin,'migrationMissing'=>true]);exit;}
    }

    if($path==='/admin/navigation' && can($user,'navigation')){
        if(!cms_has_v5_schema($db)){flash('error','نظام القوائم المركزي يحتاج تطبيق migration 006_pages_menus_cms.sql أولًا.');$items=$db->query("SELECT * FROM navigation_items ORDER BY FIELD(location,'header','footer','social'),sort_order,id")->fetchAll();admin_render('admin_navigation',['title'=>'القوائم','items'=>$items,'legacyMode'=>true]);exit;}
        if(is_post()){
            verify_csrf();$action=(string)($_POST['action']??'');
            try{
                if($action==='create_menu'){
                    $name=mb_substr(trim((string)($_POST['name']??'')),0,160,'UTF-8');$slug=slugify((string)($_POST['slug']??$name));$desc=mb_substr(trim((string)($_POST['description']??'')),0,500,'UTF-8');if($name===''||$slug==='')throw new InvalidArgumentException('اسم القائمة مطلوب.');$st=$db->prepare('INSERT INTO menus(name,slug,description,is_active,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,1,?,?,NOW(),NOW())');$st->execute([$name,$slug,$desc?:null,(int)$user['id'],(int)$user['id']]);audit($db,(int)$user['id'],'menu_create','menu',(int)$db->lastInsertId(),$name);flash('success','تم إنشاء القائمة.');
                }elseif($action==='save_menu'){
                    $id=(int)($_POST['menu_id']??0);$name=mb_substr(trim((string)($_POST['name']??'')),0,160,'UTF-8');$desc=mb_substr(trim((string)($_POST['description']??'')),0,500,'UTF-8');if(!$id||$name==='')throw new InvalidArgumentException('بيانات القائمة غير مكتملة.');$db->prepare('UPDATE menus SET name=?,description=?,is_active=?,updated_by=?,updated_at=NOW() WHERE id=?')->execute([$name,$desc?:null,!empty($_POST['is_active'])?1:0,(int)$user['id'],$id]);audit($db,(int)$user['id'],'menu_update','menu',$id,$name);flash('success','تم حفظ القائمة.');
                }elseif($action==='delete_menu'){
                    $id=(int)($_POST['menu_id']??0);if(!$id)throw new InvalidArgumentException('القائمة غير محددة.');$db->prepare('UPDATE menu_locations SET menu_id=NULL,updated_by=?,updated_at=NOW() WHERE menu_id=?')->execute([(int)$user['id'],$id]);$db->prepare('DELETE FROM menus WHERE id=?')->execute([$id]);audit($db,(int)$user['id'],'menu_delete','menu',$id,'');flash('success','تم حذف القائمة وعناصرها دون حذف الصفحات المرتبطة.');
                }elseif($action==='assign_locations'){
                    $locations=cms_menu_locations($db);$allowed=array_column($locations,'location_key');$menuIds=array_map('intval',array_column($db->query('SELECT id FROM menus')->fetchAll(),'id'));foreach($allowed as $key){$mid=(int)($_POST['location'][$key]??0);if($mid&&!in_array($mid,$menuIds,true))$mid=0;$db->prepare('UPDATE menu_locations SET menu_id=?,updated_by=?,updated_at=NOW() WHERE location_key=?')->execute([$mid?:null,(int)$user['id'],$key]);}audit($db,(int)$user['id'],'menu_locations_update','menu',0,'');flash('success','تم حفظ أماكن ظهور القوائم.');
                }elseif($action==='delete_item'){
                    $id=(int)($_POST['id']??0);$menuId=(int)($_POST['menu_id']??0);if(!$id)throw new InvalidArgumentException('العنصر غير محدد.');$db->prepare('DELETE FROM menu_items WHERE id=?')->execute([$id]);audit($db,(int)$user['id'],'menu_item_delete','menu_item',$id,'');flash('success','تم حذف عنصر القائمة فقط، ولم تُحذف الصفحة.');
                }elseif($action==='reorder'){
                    $menuId=(int)($_POST['menu_id']??0);$ids=is_array($_POST['order']??null)?array_values(array_unique(array_filter(array_map('intval',$_POST['order'])))):[];if(!$menuId||!$ids)throw new InvalidArgumentException('ترتيب العناصر غير مكتمل.');$st=$db->prepare('UPDATE menu_items SET sort_order=?,updated_at=NOW() WHERE id=? AND menu_id=?');foreach($ids as $i=>$id)$st->execute([($i+1)*10,$id,$menuId]);flash('success','تم حفظ ترتيب عناصر القائمة.');
                }elseif($action==='save_item'){
                    $id=(int)($_POST['id']??0);$menuId=(int)($_POST['menu_id']??0);$itemType=in_array($_POST['item_type']??'', ['page','article_category','custom','external','label','dynamic'],true)?(string)$_POST['item_type']:'custom';$pageId=max(0,(int)($_POST['page_id']??0));$categoryId=max(0,(int)($_POST['article_category_id']??0));$parentId=max(0,(int)($_POST['parent_id']??0));$label=mb_substr(trim((string)($_POST['label']??'')),0,160,'UTF-8');$url=trim((string)($_POST['url']??''));$target=($_POST['target']??'same')==='new'?'new':'same';$rel=cms_menu_rel((string)($_POST['rel_value']??''),$target);$style=in_array($_POST['display_style']??'', ['link','primary','outline','soft'],true)?(string)$_POST['display_style']:'link';$icon=cms_icon_key((string)($_POST['icon_key']??''));$order=max(-9999,min(9999,(int)($_POST['sort_order']??0)));if(!$menuId)throw new InvalidArgumentException('اختر قائمة.');
                    if($itemType==='page'){$st=$db->prepare('SELECT id,title,route_path,slug FROM pages WHERE id=? AND deleted_at IS NULL LIMIT 1');$st->execute([$pageId]);$linked=$st->fetch();if(!$linked)throw new InvalidArgumentException('الصفحة المرتبطة غير موجودة.');if($label==='')$label=(string)$linked['title'];$url=null;$categoryId=0;}elseif($itemType==='article_category'){$st=$db->prepare('SELECT id,name,slug FROM article_categories WHERE id=? AND is_active=1 LIMIT 1');$st->execute([$categoryId]);$linkedCategory=$st->fetch();if(!$linkedCategory)throw new InvalidArgumentException('تصنيف المقالات غير موجود أو غير نشط.');if($label==='')$label=(string)$linkedCategory['name'];$url=null;$pageId=0;}elseif($itemType==='label'){$url=null;$pageId=0;$categoryId=0;if($label==='')throw new InvalidArgumentException('اسم العنصر مطلوب.');}else{$pageId=0;$categoryId=0;if($label==='')throw new InvalidArgumentException('اسم العنصر مطلوب.');if(!nav_url_is_safe($url))throw new InvalidArgumentException('أدخل رابطًا داخليًا يبدأ بـ / أو رابط http/https صالحًا.');if($itemType==='external'&&!valid_http_url($url))throw new InvalidArgumentException('الرابط الخارجي يجب أن يبدأ بـ http أو https.');}
                    if($parentId){if($parentId===$id)throw new InvalidArgumentException('لا يمكن جعل العنصر أبًا لنفسه.');$maxDepth=max(2,min(4,(int)setting($db,'cms_max_menu_depth','3')));if(cms_menu_depth($db,$menuId,$parentId)>$maxDepth)throw new InvalidArgumentException('وصلت إلى الحد الأقصى المسموح لتداخل القوائم.');}
                    $vals=[$menuId,$parentId?:null,$itemType,$pageId?:null,$categoryId?:null,$label,$url,$icon?:null,$target,$rel?:null,$style,!empty($_POST['is_active'])?1:0,!empty($_POST['show_desktop'])?1:0,!empty($_POST['show_mobile'])?1:0,$order];
                    if($id){$st=$db->prepare('UPDATE menu_items SET menu_id=?,parent_id=?,item_type=?,page_id=?,article_category_id=?,label=?,url=?,icon_key=?,target=?,rel_value=?,display_style=?,is_active=?,show_desktop=?,show_mobile=?,sort_order=?,updated_at=NOW() WHERE id=?');$st->execute([...$vals,$id]);audit($db,(int)$user['id'],'menu_item_update','menu_item',$id,$label);}else{$st=$db->prepare('INSERT INTO menu_items(menu_id,parent_id,item_type,page_id,article_category_id,label,url,icon_key,target,rel_value,display_style,is_active,show_desktop,show_mobile,sort_order,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');$st->execute($vals);audit($db,(int)$user['id'],'menu_item_create','menu_item',(int)$db->lastInsertId(),$label);}flash('success','تم حفظ عنصر القائمة.');
                }else throw new InvalidArgumentException('الإجراء غير معروف.');
            }catch(Throwable $e){flash('error',$e instanceof InvalidArgumentException?$e->getMessage():'تعذر حفظ التغيير. تأكد من صحة البيانات وتطبيق migration 006.');}
            $returnMenu=(int)($_POST['menu_id']??$_GET['menu']??0);redirect('/admin/navigation'.($returnMenu?'?menu='.$returnMenu:''));
        }
        $menus=$db->query('SELECT m.*,(SELECT COUNT(*) FROM menu_items mi WHERE mi.menu_id=m.id) item_count FROM menus m ORDER BY m.name')->fetchAll();$selectedId=(int)($_GET['menu']??($menus[0]['id']??0));$selectedMenu=null;foreach($menus as $mnu)if((int)$mnu['id']===$selectedId){$selectedMenu=$mnu;break;}if(!$selectedMenu&&$menus){$selectedMenu=$menus[0];$selectedId=(int)$selectedMenu['id'];}
        $items=[];$flat=[];if($selectedId){$st=$db->prepare("SELECT mi.*,p.title page_title,p.route_path page_route_path,p.slug page_slug,ac.name category_name,ac.slug category_slug FROM menu_items mi LEFT JOIN pages p ON p.id=mi.page_id LEFT JOIN article_categories ac ON ac.id=mi.article_category_id WHERE mi.menu_id=? ORDER BY mi.parent_id IS NOT NULL,mi.parent_id,mi.sort_order,mi.id");$st->execute([$selectedId]);$rows=$st->fetchAll();$map=[];foreach($rows as $row){$row['children']=[];$map[(int)$row['id']]=$row;}$tree=[];foreach($map as $rid=>&$row){$pid=(int)($row['parent_id']??0);if($pid&&isset($map[$pid]))$map[$pid]['children'][]=&$row;else$tree[]=&$row;}unset($row);$items=$tree;$flat=cms_menu_flatten($tree);}
        $pages=$db->query("SELECT id,title,slug,route_path,page_type,status,language_code FROM pages WHERE deleted_at IS NULL ORDER BY page_type='system' DESC,title")->fetchAll();$categories=$db->query("SELECT id,name,slug FROM article_categories WHERE is_active=1 ORDER BY sort_order,name")->fetchAll();$locations=cms_menu_locations($db);admin_render('admin_navigation',['title'=>'القوائم','menus'=>$menus,'selectedMenu'=>$selectedMenu,'selectedId'=>$selectedId,'items'=>$items,'flatItems'=>$flat,'pages'=>$pages,'categories'=>$categories,'locations'=>$locations]);exit;
    }

    if($path==='/admin/settings' && can($user,'settings')){
        if(is_post()){verify_csrf();try{$colors=['primary_color'=>'#123B3A','secondary_color'=>'#287A64','accent_color'=>'#C6A15B','background_color'=>'#F7F4ED','text_color'=>'#202826'];foreach($colors as $k=>$default){$v=strtoupper(trim((string)($_POST[$k]??$default)));if(!preg_match('/^#[0-9A-F]{6}$/',$v))throw new InvalidArgumentException('أحد ألوان الهوية غير صالح.');save_setting($db,$k,$v);}save_setting($db,'site_name',mb_substr(trim((string)($_POST['site_name']??'محراب')),0,120,'UTF-8'));$email=trim((string)($_POST['admin_email']??''));if($email&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('البريد الإداري غير صحيح.');save_setting($db,'admin_email',$email);save_setting($db,'font_family','Tajawal');save_setting($db,'radius',(string)max(6,min(20,(int)($_POST['radius']??10))));save_setting($db,'footer_about',mb_substr(trim((string)($_POST['footer_about']??'')),0,700,'UTF-8'));save_setting($db,'reviews_enabled',!empty($_POST['reviews_enabled'])?'1':'0');save_setting($db,'card_show_rating',!empty($_POST['card_show_rating'])?'1':'0');save_setting($db,'card_show_phone',!empty($_POST['card_show_phone'])?'1':'0');save_setting($db,'card_show_whatsapp',!empty($_POST['card_show_whatsapp'])?'1':'0');save_setting($db,'card_show_specialty',!empty($_POST['card_show_specialty'])?'1':'0');save_setting($db,'card_show_city',!empty($_POST['card_show_city'])?'1':'0');save_setting($db,'card_description_lines',(string)max(1,min(4,(int)($_POST['card_description_lines']??3))));foreach(['site_logo','site_logo_dark','site_logo_light','favicon'] as $field){if(!empty($_FILES[$field]['tmp_name'])){$uploaded=upload_image($field,$field);if($uploaded){save_setting($db,$field,$uploaded);media_register($db,$uploaded,$_FILES[$field],(int)$user['id'],'شعار محراب');}}}audit($db,(int)$user['id'],'update','settings',0,'visual_identity');flash('success','تم حفظ الهوية والإعدادات.');redirect('/admin/settings');}catch(InvalidArgumentException|RuntimeException $e){flash('error',$e->getMessage());redirect('/admin/settings');}}
        admin_render('admin_settings',['title'=>'الهوية البصرية والإعدادات']);exit;
    }

    if($path==='/admin/backups' && can($user,'backups')){
        if(!final_v6_schema($db)){flash('error','طبّق migration 007_final_project.sql أولًا.');redirect('/admin');}
        if(isset($_GET['download'])){
            try{$name=(string)$_GET['download'];$backupPath=backup_safe_path($config,$name);backup_validate_manifest($backupPath);backup_assert_registered_checksum($db,$backupPath,$name);$zip=backup_zip_file($backupPath);audit($db,(int)$user['id'],'backup_download','backup',0,basename($name));header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.basename($name).'.zip"');header('Content-Length: '.filesize($zip));header('Cache-Control: no-store');readfile($zip);@unlink($zip);exit;}catch(Throwable $e){flash('error',$e->getMessage());redirect('/admin/backups');}
        }
        if(is_post()){
            verify_csrf();$action=(string)($_POST['action']??'create');
            try{
                if($action==='create'){$type=in_array($_POST['backup_type']??'full',['database','media','full'],true)?(string)$_POST['backup_type']:'full';$backup=backup_create_typed($db,$config,__DIR__,$type,'manual');backup_register($db,$backup,(int)$user['id']);$removed=backup_apply_retention_registered($db,$config,max(1,(int)setting($db,'backup_retention','10')));audit($db,(int)$user['id'],'backup_created','backup',0,$backup['name'].' type='.$type);flash('success','تم إنشاء النسخة الاحتياطية.'.($removed?' وتم حذف '.$removed.' نسخة قديمة حسب سياسة الاحتفاظ.':''));}
                elseif($action==='settings'){$keep=max(1,min(50,(int)($_POST['retention']??10)));$schedule=in_array($_POST['schedule']??'off',['off','daily','weekly','monthly'],true)?(string)$_POST['schedule']:'off';save_setting($db,'backup_retention',(string)$keep);save_setting($db,'backup_schedule',$schedule);audit($db,(int)$user['id'],'backup_settings','backup',0,'keep='.$keep.' schedule='.$schedule);flash('success','تم حفظ سياسة النسخ الاحتياطية.');}
                elseif($action==='delete'){
                    if(($user['role']??'')!=='super_admin')throw new RuntimeException('حذف النسخ متاح للمدير العام فقط.');$name=(string)($_POST['name']??'');backup_delete_named($db,$config,$name);audit($db,(int)$user['id'],'backup_deleted','backup',0,basename($name));flash('success','تم حذف النسخة الاحتياطية.');
                }elseif($action==='restore'){
                    if(($user['role']??'')!=='super_admin')throw new RuntimeException('الاستعادة متاحة للمدير العام فقط.');$name=(string)($_POST['name']??'');$backupPath=backup_safe_path($config,$name);$manifest=backup_validate_manifest($backupPath);backup_assert_registered_checksum($db,$backupPath,$name);$compat=backup_compatibility($backupPath,$manifest);if(empty($compat['compatible']))throw new RuntimeException('النسخة غير متوافقة: '.($compat['message']??'سبب غير معروف'));$type=(string)($manifest['backup_type']??'full');
                    $pre=backup_create_typed($db,$config,__DIR__,'full','pre-restore');backup_register($db,$pre,(int)$user['id']);save_setting($db,'maintenance_mode','1');audit($db,(int)$user['id'],'restore_started','backup',0,$name.' pre='.$pre['name']);
                    try{if($type!=='media')backup_restore_database($db,$backupPath);if($type!=='database')backup_restore_files($config,$backupPath);clear_project_cache($config);try{save_setting($db,'maintenance_mode','0');}catch(Throwable $ignored){}backup_register($db,$pre,(int)$user['id']);try{$db->prepare("UPDATE backup_registry SET status='restored' WHERE backup_name=?")->execute([basename($name)]);}catch(Throwable $ignored){}try{audit($db,(int)$user['id'],'restore_completed','backup',0,$name.' type='.$type);}catch(Throwable $ignored){}flash('success','تمت الاستعادة وفحوص السلامة الأساسية. النسخة السابقة للحالة الحالية: '.$pre['name']);}
                    catch(Throwable $restoreError){try{save_setting($db,'maintenance_mode','0');}catch(Throwable $ignored){}throw new RuntimeException('فشلت الاستعادة: '.$restoreError->getMessage().' — احتفظ بنسخة Pre-Restore: '.$pre['name']);}
                }else throw new InvalidArgumentException('إجراء غير معروف.');
            }catch(Throwable $e){flash('error',$e->getMessage());}
            redirect('/admin/backups');
        }
        $backups=list_backup_dirs($config);foreach($backups as &$backupItem)$backupItem['_compat']=backup_compatibility((string)$backupItem['path'],(array)$backupItem['manifest']);unset($backupItem);admin_render('admin_backups',['title'=>'النسخ الاحتياطية والاستعادة','backups'=>$backups,'retention'=>(int)setting($db,'backup_retention','10'),'schedule'=>setting($db,'backup_schedule','off')]);exit;
    }

    if($path==='/admin/email-templates' && can($user,'integrations')){
        if(!final_v6_schema($db)){flash('error','طبّق migration 007 أولًا.');redirect('/admin');}
        $catalog=[
            'new_review_admin'=>['label'=>'إشعار الإدارة — تقييم جديد','vars'=>['{{center}}','{{id}}'],'default_subject'=>'تقييم جديد — {{center}}','default_body'=>"تم استلام تقييم جديد مرتبط بالسجل: {{center}}\nرقم الطلب: {{id}}\nراجع لوحة الإدارة لاتخاذ الإجراء المناسب."],
            'new_complaint_admin'=>['label'=>'إشعار الإدارة — شكوى جديدة','vars'=>['{{center}}','{{id}}'],'default_subject'=>'شكوى جديدة — {{center}}','default_body'=>"تم استلام شكوى جديدة مرتبطة بالسجل: {{center}}\nرقم الطلب: {{id}}\nراجع لوحة الإدارة لاتخاذ الإجراء المناسب."],
            'data_report_admin'=>['label'=>'إشعار الإدارة — بلاغ تصحيح','vars'=>['{{center}}','{{id}}'],'default_subject'=>'بلاغ تصحيح بيانات — {{center}}','default_body'=>"تم استلام بلاغ تصحيح بيانات مرتبط بالسجل: {{center}}\nرقم الطلب: {{id}}\nراجع لوحة الإدارة لاتخاذ الإجراء المناسب."],
            'complaint_status_user'=>['label'=>'إشعار المستخدم — تحديث شكوى','vars'=>['{{id}}','{{status}}','{{note}}'],'default_subject'=>'تحديث شكوى محراب #{{id}}','default_body'=>"تم تحديث حالة الشكوى رقم #{{id}} إلى: {{status}}.\n{{note}}"],
        ];
        if(is_post()){
            verify_csrf();$key=(string)($_POST['template_key']??'');if(!isset($catalog[$key])){flash('error','القالب غير معروف.');redirect('/admin/email-templates');}
            $subject=mb_substr(trim((string)($_POST['subject']??'')),0,255,'UTF-8');$body=mb_substr(trim((string)($_POST['body_text']??'')),0,10000,'UTF-8');if($subject===''||$body===''){flash('error','الموضوع والنص مطلوبان.');redirect('/admin/email-templates');}
            $db->prepare("INSERT INTO email_templates(template_key,subject,body_text,is_active,updated_by,updated_at) VALUES(?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE subject=VALUES(subject),body_text=VALUES(body_text),is_active=VALUES(is_active),updated_by=VALUES(updated_by),updated_at=NOW()")->execute([$key,$subject,$body,!empty($_POST['is_active'])?1:0,(int)$user['id']]);audit($db,(int)$user['id'],'email_template_update','email_template',0,$key);flash('success','تم حفظ قالب البريد.');redirect('/admin/email-templates');
        }
        $stored=[];try{foreach($db->query('SELECT * FROM email_templates')->fetchAll() as $row)$stored[(string)$row['template_key']]=$row;}catch(Throwable $e){}
        $templates=[];foreach($catalog as $key=>$meta){$row=$stored[$key]??[];$templates[$key]=['label'=>$meta['label'],'vars'=>$meta['vars'],'subject'=>(string)($row['subject']??$meta['default_subject']),'body_text'=>(string)($row['body_text']??$meta['default_body']),'is_active'=>isset($row['is_active'])?(int)$row['is_active']:1];}
        admin_render('admin_email_templates',['title'=>'قوالب البريد','templates'=>$templates]);exit;
    }

    if($path==='/admin/system-status' && can($user,'maintenance')){
        if(is_post()){
            verify_csrf();$action=(string)($_POST['action']??'');
            if($action==='maintenance'){$enabled=!empty($_POST['enabled']);$message=mb_substr(trim((string)($_POST['message']??'')),0,500,'UTF-8');save_setting($db,'maintenance_mode',$enabled?'1':'0');save_setting($db,'maintenance_message',$message?:'الموقع تحت الصيانة، نعود قريبًا.');audit($db,(int)$user['id'],'maintenance_mode','system',0,$enabled?'enabled':'disabled');flash('success','تم تحديث وضع الصيانة.');}
            elseif($action==='clear_cache'){$count=clear_project_cache($config);audit($db,(int)$user['id'],'cache_cleared','system',0,'files='.$count);flash('success','تم مسح Cache الآمن: '.$count.' ملف.');}
            redirect('/admin/system-status');
        }
        $checks=project_system_checks($db,$config);$checks[]=['key'=>'PHP','status'=>version_compare(PHP_VERSION,'8.2','>=')?'healthy':'warning','detail'=>PHP_VERSION];$free=@disk_free_space(__DIR__);$total=@disk_total_space(__DIR__);$checks[]=['key'=>'المساحة المتاحة','status'=>($free!==false&&$free>1024*1024*500)?'healthy':'warning','detail'=>$free===false?'غير متاح':number_format($free/1073741824,2).' GB'];$checks[]=['key'=>'Upload limit','status'=>'healthy','detail'=>(string)ini_get('upload_max_filesize')];admin_render('admin_system_status',['title'=>'حالة النظام والصيانة','checks'=>$checks]);exit;
    }

    if($path==='/admin/system-logs' && can($user,'maintenance')){$logs=safe_log_tail($config,150);admin_render('admin_system_logs',['title'=>'سجلات النظام','logs'=>$logs]);exit;}

    if($path==='/admin/search-analytics' && can($user,'listings')){
        if(!final_v6_schema($db)){flash('error','طبّق migration 007 أولًا.');redirect('/admin');}
        if(is_post()){
            verify_csrf();$action=(string)($_POST['action']??'');try{if($action==='synonym_add'){$term=mb_substr(trim((string)($_POST['term']??'')),0,190,'UTF-8');$syn=mb_substr(trim((string)($_POST['synonym']??'')),0,190,'UTF-8');if($term===''||$syn==='')throw new InvalidArgumentException('اكتب المصطلح والمرادف.');$db->prepare('INSERT IGNORE INTO search_synonyms(term,synonym,is_active,created_at) VALUES(?,?,1,NOW())')->execute([$term,$syn]);flash('success','تمت إضافة المرادف.');}elseif($action==='synonym_delete'){$id=(int)($_POST['id']??0);$db->prepare('DELETE FROM search_synonyms WHERE id=?')->execute([$id]);flash('success','تم حذف المرادف.');}}catch(Throwable $e){flash('error',$e->getMessage());}redirect('/admin/search-analytics');
        }
        $noResults=$db->query("SELECT query_text,COUNT(*) total,MAX(created_at) last_at FROM search_queries WHERE result_count=0 GROUP BY normalized_query,query_text ORDER BY total DESC,last_at DESC LIMIT 100")->fetchAll();$popular=$db->query("SELECT query_text,COUNT(*) total,MAX(created_at) last_at FROM search_queries GROUP BY normalized_query,query_text ORDER BY total DESC,last_at DESC LIMIT 100")->fetchAll();$synonyms=$db->query('SELECT * FROM search_synonyms ORDER BY term,synonym')->fetchAll();admin_render('admin_search_analytics',['title'=>'تحليلات البحث والمرادفات','noResults'=>$noResults,'popular'=>$popular,'synonyms'=>$synonyms]);exit;
    }

    if($path==='/admin/seo' && can($user,'seo')){
        if(is_post()){
            verify_csrf();$action=(string)($_POST['action']??'save');
            if($action==='generate_indexnow'){$key=bin2hex(random_bytes(20));save_setting($db,'indexnow_key',$key);audit($db,(int)$user['id'],'indexnow_key_generate','settings',0,'');flash('success','تم إنشاء مفتاح IndexNow.');redirect('/admin/seo');}
            $meta=mb_substr(trim((string)($_POST['meta_description']??'')),0,500,'UTF-8');$suffix=mb_substr(trim((string)($_POST['title_suffix']??'محراب')),0,80,'UTF-8');save_setting($db,'meta_description',$meta);save_setting($db,'title_suffix',$suffix);save_setting($db,'seo_indexing',!empty($_POST['seo_indexing'])?'1':'0');$og=trim((string)($_POST['default_og_image']??''));if($og!==''&&!str_starts_with($og,'uploads/')&&!valid_http_url($og))$og='';save_setting($db,'default_og_image',$og);
            $ai=in_array($_POST['ai_crawler_policy']??'search_only',['allow_all','search_only','block_all'],true)?(string)$_POST['ai_crawler_policy']:'search_only';save_setting($db,'ai_crawler_policy',$ai);save_setting($db,'organization_description',mb_substr(trim((string)($_POST['organization_description']??'')),0,700,'UTF-8'));$sameAsLines=preg_split('/\R+/',trim((string)($_POST['organization_sameas']??'')))?:[];$validLinks=[];foreach($sameAsLines as $link){$link=trim($link);if($link!==''&&safe_external_url($link))$validLinks[]=$link;}save_setting($db,'organization_sameas',implode("\n",array_slice(array_unique($validLinks),0,20)));
            audit($db,(int)$user['id'],'update','settings',0,'seo+geo');flash('success','تم حفظ إعدادات SEO وGEO/AEO.');redirect('/admin/seo');
        }
        admin_render('admin_seo',['title'=>'إعدادات SEO وGEO']);exit;
    }

    if($path==='/admin/security' && can($user,'security')){
        if(is_post()){verify_csrf();$idle=max(15,min(1440,(int)($_POST['session_idle_minutes']??120)));$attempts=max(3,min(10,(int)($_POST['login_max_attempts']??5)));$lock=max(5,min(120,(int)($_POST['login_lock_minutes']??15)));save_setting($db,'session_idle_minutes',(string)$idle);save_setting($db,'login_max_attempts',(string)$attempts);save_setting($db,'login_lock_minutes',(string)$lock);audit($db,(int)$user['id'],'update','settings',0,'security');flash('success','تم حفظ إعدادات الحماية.');redirect('/admin/security');}
        $backupPath=configured_storage_path('backup');$backupParent=is_dir($backupPath)?$backupPath:dirname($backupPath);$latestBackup=null;if(is_dir($backupPath)){foreach(array_reverse(glob(rtrim($backupPath,'/\\').DIRECTORY_SEPARATOR.'mihrab-*')?:[]) as $candidate){if(is_dir($candidate)&&is_file($candidate.'/manifest.json')){$latestBackup=basename($candidate);break;}}}$checks=['https'=>$isHttps,'debug'=>(bool)($config['app']['debug']??false),'config_protected'=>is_file(__DIR__.'/private/.htaccess'),'storage_protected'=>is_file(__DIR__.'/storage/.htaccess'),'uploads_protected'=>is_file(__DIR__.'/uploads/.htaccess'),'php_version'=>PHP_VERSION,'upload_max'=>ini_get('upload_max_filesize'),'app_url'=>(string)($config['app']['url']??'')!=='','environment'=>(string)($config['app']['environment']??'production'),'base_path'=>(string)($config['app']['base_path']??''),'public_storage_writable'=>is_dir(configured_storage_path('public'))&&is_writable(configured_storage_path('public')),'private_storage_writable'=>is_dir(configured_storage_path('private'))&&is_writable(configured_storage_path('private')),'backup_ready'=>is_dir($backupParent)&&is_writable($backupParent),'mail_configured'=>(string)($config['mail']['host']??'')!==''&&(string)($config['mail']['from_address']??'')!=='','trust_proxy'=>(bool)($config['security']['trust_proxy']??false),'hsts'=>(bool)($config['security']['hsts']??false),'latest_backup'=>$latestBackup,'config_source'=>getenv('DB_NAME')!==false?'environment variables':(getenv('MIHRAB_CONFIG_FILE')!==false?'external config file':'private/config.php')];admin_render('admin_security',['title'=>'الحماية','checks'=>$checks]);exit;
    }

    if($path==='/admin/languages' && can($user,'languages')){
        if(is_post()){verify_csrf();$action=(string)($_POST['action']??'add');try{if($action==='toggle'){$id=(int)($_POST['id']??0);$db->exec('UPDATE languages SET is_active=IF(is_active=1,0,1) WHERE id='.$id.' AND is_default=0');}else{$code=strtolower(trim((string)($_POST['code']??'')));$name=mb_substr(trim((string)($_POST['name']??'')),0,80,'UTF-8');if(!preg_match('/^[a-z]{2,3}(?:-[a-z]{2})?$/',$code)||!$name)throw new InvalidArgumentException('رمز اللغة أو اسمها غير صحيح.');$db->prepare('INSERT INTO languages(code,name,is_default,is_active,sort_order) VALUES(?,?,0,0,?)')->execute([$code,$name,(int)($_POST['sort_order']??0)]);}flash('success','تم حفظ اللغة.');}catch(Throwable $e){flash('error',$e instanceof InvalidArgumentException?$e->getMessage():'اللغة موجودة مسبقًا.');}redirect('/admin/languages');}
        $languages=$db->query('SELECT * FROM languages ORDER BY is_default DESC,sort_order,id')->fetchAll();admin_render('admin_languages',['title'=>'اللغات','languages'=>$languages]);exit;
    }

    if($path==='/admin/users' && $user['role']==='super_admin'){
        if(is_post()){
            verify_csrf();$action=(string)($_POST['action']??'');
            if($action==='create'){
                $name=mb_substr(trim((string)($_POST['name']??'')),0,160,'UTF-8');$email=mb_strtolower(trim((string)($_POST['email']??'')),'UTF-8');$pass=(string)($_POST['password']??'');$role=(string)($_POST['role']??'admin');$valid=['super_admin','admin','editor','moderator'];$errors=auth_password_errors($pass);
                if(!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||$errors||!in_array($role,$valid,true)){flash('error','تحقق من بيانات المستخدم. كلمة المرور يجب أن تكون قوية.');redirect('/admin/users');}
                try{$db->prepare("INSERT INTO users(name,email,password_hash,role,status,created_at) VALUES(?,?,?,?, 'active',NOW())")->execute([$name,$email,password_hash($pass,PASSWORD_ARGON2ID),$role]);$uid=(int)$db->lastInsertId();$db->prepare('INSERT INTO user_security(user_id,email_verified_at,password_changed_at) VALUES(?,NOW(),NOW())')->execute([$uid]);audit($db,(int)$user['id'],'create','user',$uid,$email);flash('success','تم إنشاء المستخدم.');}catch(Throwable $e){flash('error','تعذر إنشاء المستخدم. تأكد من تطبيق migration 003 وأن البريد غير مستخدم.');}
            }elseif($action==='invite'){
                $email=mb_strtolower(trim((string)($_POST['email']??'')),'UTF-8');$role=(string)($_POST['role']??'editor');$valid=['admin','editor','moderator'];
                if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($role,$valid,true)){flash('error','بيانات الدعوة غير صحيحة.');redirect('/admin/users');}
                try{[$token,$hash]=auth_new_token();$db->prepare('UPDATE signup_invites SET used_at=NOW() WHERE email=? AND used_at IS NULL')->execute([$email]);$db->prepare('INSERT INTO signup_invites(email,token_hash,role,expires_at,created_by,created_at) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR),?,NOW())')->execute([$email,$hash,$role,(int)$user['id']]);$url=base_url('/admin/signup?token='.rawurlencode($token));[$html,$text]=auth_email_template('دعوة لإدارة محراب','تمت دعوتك لإنشاء حساب إداري. الرابط صالح لمدة 24 ساعة ويستخدم مرة واحدة.','إنشاء الحساب',$url);if(!mailer_send($email,'دعوة إدارة محراب',$html,$text)){throw new RuntimeException('mail failed');}audit($db,(int)$user['id'],'invite','user',0,$email);flash('success','تم إرسال الدعوة الآمنة.');}catch(Throwable $e){flash('error','تعذر إرسال الدعوة. تحقق من إعدادات البريد وتطبيق migration 003.');}
            }elseif($action==='status'){
                $id=(int)($_POST['id']??0);if($id&&$id!==(int)$user['id']){$status=($_POST['status']??'disabled')==='active'?'active':'disabled';$db->prepare('UPDATE users SET status=? WHERE id=?')->execute([$status,$id]);if($status==='disabled')auth_revoke_all_sessions($db,$id);audit($db,(int)$user['id'],'status','user',$id,$status);}
            }elseif($action==='permissions'){
                $id=(int)($_POST['id']??0);$targetSt=$db->prepare('SELECT id,role,email FROM users WHERE id=? LIMIT 1');$targetSt->execute([$id]);$target=$targetSt->fetch();if(!$target||$target['role']==='super_admin'){flash('error','لا يمكن تخصيص صلاحيات المدير العام من هنا.');redirect('/admin/users');}$catalog=permission_catalog();$submitted=is_array($_POST['permissions']??null)?$_POST['permissions']:[];$db->beginTransaction();try{$db->prepare('DELETE FROM user_permissions WHERE user_id=?')->execute([$id]);$permSt=$db->prepare('INSERT INTO user_permissions(user_id,permission,allowed) VALUES(?,?,?)');foreach($catalog as $permission=>$label)$permSt->execute([$id,$permission,!empty($submitted[$permission])?1:0]);$db->commit();audit($db,(int)$user['id'],'permissions','user',$id,(string)$target['email']);flash('success','تم حفظ الصلاحيات المخصصة.');}catch(Throwable $e){if($db->inTransaction())$db->rollBack();flash('error','تعذر حفظ الصلاحيات. تأكد من تطبيق تحديث قاعدة البيانات.');}
            }
            redirect('/admin/users');
        }
        $users=$db->query('SELECT id,name,email,role,status,created_at FROM users ORDER BY id')->fetchAll();$overrides=[];try{foreach($db->query('SELECT user_id,permission,allowed FROM user_permissions')->fetchAll() as $pr)$overrides[(int)$pr['user_id']][(string)$pr['permission']]=(int)$pr['allowed'];}catch(Throwable $e){}foreach($users as &$listedUser)$listedUser['_permission_overrides']=$overrides[(int)$listedUser['id']]??[];unset($listedUser);admin_render('admin_users',['title'=>'المستخدمون والصلاحيات','users'=>$users,'user'=>$user,'permissionCatalog'=>permission_catalog()]);exit;
    }
    if($path==='/admin/audit' && $user['role']==='super_admin'){$total=(int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();$pg=pagination_meta($total,page_number(),100);$logs=$db->query("SELECT a.*,u.name user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}")->fetchAll();admin_render('admin_audit',['title'=>'سجل النشاط','logs'=>$logs,'pagination'=>$pg]);exit;}

    if($path==='/admin/integrations' && can($user,'integrations')){
        try{ensure_integrations_schema($db);}catch(Throwable $e){flash('error','تعذر تجهيز جداول التكاملات. نفّذ ملفات migrations من لوحة قاعدة البيانات.');redirect('/admin');}
        if(is_post()){verify_csrf();$action=(string)($_POST['action']??'');try{if($action==='save_integration'){$serviceKey=trim((string)($_POST['service_key']??''));integration_save($db,$serviceKey,!empty($_POST['enabled']),$_POST,(int)$user['id']);audit($db,(int)$user['id'],'integration_update','integration',0,$serviceKey.' enabled='.(!empty($_POST['enabled'])?'1':'0'));flash('success','تم حفظ إعدادات التكامل بشكل مستقل.');}elseif($action==='save_ad_placement'){$placementKey=trim((string)($_POST['placement_key']??''));ad_placement_save($db,$placementKey,!empty($_POST['enabled']),trim((string)($_POST['slot_id']??'')),(int)$user['id']);audit($db,(int)$user['id'],'ad_placement_update','integration',0,$placementKey);flash('success','تم حفظ موضع الإعلان.');}else throw new InvalidArgumentException('طلب غير معروف.');}catch(InvalidArgumentException $e){flash('error',$e->getMessage());}catch(Throwable $e){flash('error','تعذر حفظ الإعداد. لم يتم تغيير الخدمات الأخرى.');}redirect('/admin/integrations');}
        $integrations=integrations_all($db);$placements=ad_placements_all($db);admin_render('admin_integrations',['title'=>'التكاملات والتتبع','integrations'=>$integrations,'placements'=>$placements]);exit;
    }

    http_response_code(404);admin_render('admin_not_found',['title'=>'الصفحة غير موجودة']);exit;
}

http_response_code(404);render('404',['title'=>'الصفحة غير موجودة | محراب','description'=>'تعذر العثور على الصفحة المطلوبة في محراب.','robots'=>'noindex,follow']);
