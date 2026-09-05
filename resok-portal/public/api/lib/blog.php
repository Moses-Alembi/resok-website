<?php
declare(strict_types=1);

/**
 * Blog CMS: article storage, editorial workflow, and the public reading endpoints.
 *
 * Kept out of index.php so the API stays navigable - index.php dispatches, this owns
 * everything about articles. Analytics lives in its own module for the same reason.
 *
 * Editorial roles (see schema-blog.sql) are enforced here rather than at the route, so a
 * new endpoint cannot forget to check: blogCanPublish() gates anything that puts content
 * in front of the public, blogCanEdit() gates changes to it, and authors are additionally
 * confined to their own drafts.
 */

const BLOG_ROLES_EDIT = ['author', 'editor', 'content_manager', 'admin'];
const BLOG_ROLES_PUBLISH = ['editor', 'content_manager', 'admin'];
const BLOG_ROLES_ANALYTICS = ['analytics_manager', 'content_manager', 'admin'];

function blogRole(array $user): string
{
    return (string)($user['role'] ?? 'member');
}

function blogCanEdit(array $user): bool
{
    return in_array(blogRole($user), BLOG_ROLES_EDIT, true);
}

function blogCanPublish(array $user): bool
{
    return in_array(blogRole($user), BLOG_ROLES_PUBLISH, true);
}

function blogCanViewAnalytics(array $user): bool
{
    return in_array(blogRole($user), BLOG_ROLES_ANALYTICS, true);
}

function blogRequireEdit(array $user): void
{
    if (!blogCanEdit($user)) respond(403, ['error' => 'You do not have permission to manage articles.']);
}

function blogRequirePublish(array $user): void
{
    if (!blogCanPublish($user)) respond(403, ['error' => 'You do not have permission to publish.']);
}

/**
 * Whether the blog tables exist yet. schema-blog.sql is imported by hand, so until someone
 * does that every blog route would otherwise throw a raw SQL error and surface as a generic
 * 500 - which says nothing about the one thing that would fix it.
 */
function blogTablesReady(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->query('SELECT 1 FROM blog_articles LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function blogRequireTables(PDO $pdo): void
{
    if (blogTablesReady($pdo)) return;
    respond(503, [
        'error' => 'The blog is not set up on this server yet.',
        'missing' => 'resok-portal/server/schema-blog.sql has not been imported into the database.',
    ]);
}

/** URL-safe slug. Collisions get a numeric suffix rather than overwriting someone's article. */
function blogSlugify(string $text): string
{
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? substr($slug, 0, 180) : 'article';
}

function blogUniqueSlug(PDO $pdo, string $base, ?int $ignoreId = null): string
{
    $slug = blogSlugify($base);
    $candidate = $slug;
    $n = 2;
    while (true) {
        $sql = 'SELECT id FROM blog_articles WHERE slug = ?' . ($ignoreId ? ' AND id <> ?' : '') . ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ignoreId ? [$candidate, $ignoreId] : [$candidate]);
        if (!$stmt->fetch()) return $candidate;
        $candidate = $slug . '-' . $n;
        $n++;
    }
}

/**
 * Strips anything that could execute, keeping the formatting an editor legitimately needs.
 *
 * Sanitising on write rather than on read is deliberate: the stored article is then safe by
 * construction, and every page that renders it - public page, RSS, preview, email - gets
 * that safety without having to remember. The cost is that fixing this list does not
 * retroactively clean old rows, so it is written to be strict from the start.
 */
function blogSanitizeHtml(string $html): string
{
    if (trim($html) === '') return '';

    // Whole elements whose content must go with them, not just their tags.
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button)\b[^>]*/?>#i', '', $html) ?? $html;

    $allowed = '<p><br><strong><b><em><i><u><s><h2><h3><h4><ul><ol><li><blockquote>'
        . '<a><img><figure><figcaption><table><thead><tbody><tr><th><td><hr><sup><sub><code><pre><span><div>';
    $html = strip_tags($html, $allowed);

    // Event handlers survive strip_tags, so they are removed by hand - quoted, single
    // quoted, and bare, in that order.
    $html = preg_replace('/\son[a-z]+\s*=\s*"[^"]*"/i', '', $html) ?? $html;
    $html = preg_replace("/\son[a-z]+\s*=\s*'[^']*'/i", '', $html) ?? $html;
    $html = preg_replace('/\son[a-z]+\s*=\s*[^\s>]+/i', '', $html) ?? $html;

    // style= is dropped entirely. It is not needed for editorial formatting, and it is a
    // route to overlaying or hiding page furniture (clickjacking) that no allowlist of
    // tags protects against.
    $html = preg_replace('/\sstyle\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;

    // URLs are checked against an allowlist of schemes rather than a blocklist. Blocking
    // "javascript:" by name is bypassable in several ways browsers still honour -
    // "jav&#97;script:", a tab or newline inside the scheme, or leading control bytes -
    // whereas requiring the URL to *begin* as http/https/mailto/tel, a site-relative path,
    // or a fragment leaves nothing to smuggle.
    $html = preg_replace_callback(
        '/\b(href|src)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i',
        static function (array $m): string {
            $attr = strtolower($m[1]);
            // The three alternatives in the pattern are double-quoted, single-quoted and
            // bare; exactly one of them holds the URL. Written as a loop rather than nested
            // ternaries, which PHP 8 rejects outright unless parenthesised.
            $url = '';
            foreach ([2, 3, 4] as $group) {
                if (isset($m[$group]) && $m[$group] !== '') {
                    $url = $m[$group];
                    break;
                }
            }
            // Decode entities and strip control characters before judging the scheme, so
            // the check sees what the browser will eventually see.
            $probe = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $probe = preg_replace('/[\x00-\x20]/', '', $probe) ?? $probe;

            // A URL carrying a scheme must use one of ours. A URL with no scheme is
            // relative - a path, a query, or a fragment - and cannot execute anything.
            $hasScheme = (bool)preg_match('#^[a-z][a-z0-9+.\-]*:#i', $probe);
            $safe = $hasScheme ? (bool)preg_match('#^(https?|mailto|tel):#i', $probe) : true;

            return $attr . '="' . htmlspecialchars($safe ? $url : '#', ENT_QUOTES) . '"';
        },
        $html
    ) ?? $html;

    return trim($html);
}

/** ~200 words per minute over the rendered text, floored at one. */
function blogReadingMinutes(string $html, string $excerpt = ''): int
{
    $text = trim(strip_tags($html) . ' ' . strip_tags($excerpt));
    $words = $text === '' ? 0 : count(preg_split('/\s+/', $text) ?: []);
    return max(1, (int)round($words / 200));
}

/** Shapes a row for the public site. Nothing internal leaks - no created_by, no draft data. */
function blogPublicArticle(array $row, bool $withBody = false): array
{
    $out = [
        'id' => (int)$row['id'],
        'slug' => $row['slug'],
        'title' => $row['title'],
        'subtitle' => $row['subtitle'],
        'excerpt' => $row['excerpt'],
        'image' => $row['featured_image'],
        'imageAlt' => $row['featured_image_alt'],
        'category' => $row['category_name'] ?? null,
        'categorySlug' => $row['category_slug'] ?? null,
        'author' => $row['author_name'] ?? null,
        'authorTitle' => $row['author_job_title'] ?? null,
        'authorOrg' => $row['author_organization'] ?? null,
        'authorPhoto' => $row['author_photo'] ?? null,
        'publishedAt' => $row['published_at'],
        'updatedAt' => $row['updated_content_at'],
        'readingMinutes' => (int)$row['reading_minutes'],
        'readCount' => (int)$row['read_count'],
        'isFeatured' => (bool)(int)$row['is_featured'],
        'commentsEnabled' => (bool)(int)$row['comments_enabled'],
    ];
    if ($withBody) {
        $out['body'] = $row['body_html'];
        $out['seo'] = [
            'title' => $row['seo_title'] ?: $row['title'],
            'description' => $row['seo_description'] ?: $row['excerpt'],
            'canonical' => $row['canonical_url'],
            'ogTitle' => $row['og_title'] ?: $row['seo_title'] ?: $row['title'],
            'ogDescription' => $row['og_description'] ?: $row['seo_description'] ?: $row['excerpt'],
            'socialImage' => $row['social_image'] ?: $row['featured_image'],
        ];
    }
    return $out;
}

/** Adds everything an editor needs and the public must not see. */
function blogAdminArticle(array $row): array
{
    $out = blogPublicArticle($row, true);
    return array_merge($out, [
        'status' => $row['status'],
        'categoryId' => $row['category_id'] !== null ? (int)$row['category_id'] : null,
        'authorId' => $row['author_id'] !== null ? (int)$row['author_id'] : null,
        'focusKeywords' => $row['focus_keywords'],
        'uniqueReaders' => (int)$row['unique_reader_count'],
        'reactions' => (int)$row['reaction_count'],
        'shares' => (int)$row['share_count'],
        'comments' => (int)$row['comment_count'],
        'createdBy' => $row['created_by'] !== null ? (int)$row['created_by'] : null,
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at'],
    ]);
}

const BLOG_ARTICLE_SELECT = '
    SELECT a.*, c.name AS category_name, c.slug AS category_slug,
           au.name AS author_name, au.job_title AS author_job_title,
           au.organization AS author_organization, au.photo_path AS author_photo
      FROM blog_articles a
      LEFT JOIN blog_categories c ON c.id = a.category_id
      LEFT JOIN blog_authors au ON au.id = a.author_id
';

/**
 * "Published" is a moving target: a scheduled article becomes public the moment its time
 * passes. Expressing that in the query means no cron is needed to flip statuses, and an
 * article can never be visible on one page and hidden on another.
 */
const BLOG_PUBLIC_WHERE = " (a.status = 'published' OR (a.status = 'scheduled' AND a.published_at <= NOW()))
                            AND a.published_at IS NOT NULL AND a.published_at <= NOW() ";

function blogListPublic(PDO $pdo, array $query): array
{
    $where = [BLOG_PUBLIC_WHERE];
    $args = [];

    if (!empty($query['category'])) {
        $where[] = 'c.slug = ?';
        $args[] = (string)$query['category'];
    }
    if (!empty($query['tag'])) {
        $where[] = 'EXISTS (SELECT 1 FROM blog_article_tags at JOIN blog_tags t ON t.id = at.tag_id
                            WHERE at.article_id = a.id AND t.slug = ?)';
        $args[] = (string)$query['tag'];
    }
    if (!empty($query['q'])) {
        // LIKE rather than the FULLTEXT index: at this article count it is quicker than
        // maintaining relevance ranking, and it matches partial words, which readers expect.
        $where[] = '(a.title LIKE ? OR a.excerpt LIKE ? OR a.body_html LIKE ?)';
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], (string)$query['q']) . '%';
        array_push($args, $like, $like, $like);
    }

    $sort = (string)($query['sort'] ?? 'latest');
    $order = [
        'latest' => 'a.published_at DESC',
        'updated' => 'COALESCE(a.updated_content_at, a.published_at) DESC',
        'popular' => 'a.read_count DESC, a.published_at DESC',
    ][$sort] ?? 'a.published_at DESC';

    $perPage = min(50, max(1, (int)($query['perPage'] ?? 9)));
    $page = max(1, (int)($query['page'] ?? 1));
    $offset = ($page - 1) * $perPage;
    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM blog_articles a
        LEFT JOIN blog_categories c ON c.id = a.category_id WHERE ' . $whereSql);
    $countStmt->execute($args);
    $total = (int)$countStmt->fetch()['c'];

    $stmt = $pdo->prepare(BLOG_ARTICLE_SELECT . ' WHERE ' . $whereSql .
        ' ORDER BY ' . $order . ' LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $stmt->execute($args);

    return [
        'articles' => array_map(fn($r) => blogPublicArticle($r), $stmt->fetchAll()),
        'page' => $page,
        'perPage' => $perPage,
        'total' => $total,
        'pages' => (int)ceil($total / $perPage),
    ];
}

function blogFeatured(PDO $pdo): ?array
{
    $stmt = $pdo->query(BLOG_ARTICLE_SELECT . ' WHERE ' . BLOG_PUBLIC_WHERE .
        ' ORDER BY a.is_featured DESC, a.published_at DESC LIMIT 1');
    $row = $stmt->fetch();
    return $row ? blogPublicArticle($row) : null;
}

function blogBySlug(PDO $pdo, string $slug, bool $publicOnly = true): ?array
{
    $sql = BLOG_ARTICLE_SELECT . ' WHERE a.slug = ?' . ($publicOnly ? ' AND ' . BLOG_PUBLIC_WHERE : '');
    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Related articles (§14): same category first, then anything sharing a tag, newest first.
 * Deliberately simple - topic-similarity scoring is not worth its complexity until there
 * are far more articles than a category can usefully narrow.
 */
function blogRelated(PDO $pdo, int $articleId, ?int $categoryId, int $limit = 3): array
{
    $stmt = $pdo->prepare(BLOG_ARTICLE_SELECT . ' WHERE ' . BLOG_PUBLIC_WHERE . ' AND a.id <> ?
        ORDER BY (a.category_id <=> ?) DESC,
                 EXISTS (SELECT 1 FROM blog_article_tags x
                         JOIN blog_article_tags y ON y.tag_id = x.tag_id AND y.article_id = ?
                         WHERE x.article_id = a.id) DESC,
                 a.published_at DESC
        LIMIT ' . (int)$limit);
    $stmt->execute([$articleId, $categoryId, $articleId]);
    return array_map(fn($r) => blogPublicArticle($r), $stmt->fetchAll());
}

function blogCategories(PDO $pdo, bool $withCounts = true): array
{
    $sql = $withCounts
        ? 'SELECT c.id, c.name, c.slug, c.description, c.colour,
                  (SELECT COUNT(*) FROM blog_articles a WHERE a.category_id = c.id AND ' . BLOG_PUBLIC_WHERE . ') AS article_count
             FROM blog_categories c WHERE c.is_active = 1 ORDER BY c.sort_order, c.name'
        : 'SELECT id, name, slug, description, colour FROM blog_categories WHERE is_active = 1 ORDER BY sort_order, name';
    return array_map(fn($r) => [
        'id' => (int)$r['id'],
        'name' => $r['name'],
        'slug' => $r['slug'],
        'description' => $r['description'],
        'colour' => $r['colour'],
        'count' => isset($r['article_count']) ? (int)$r['article_count'] : null,
    ], $pdo->query($sql)->fetchAll());
}

/** Create or update. Returns the saved article as the admin view. */
function blogSaveArticle(PDO $pdo, array $user, array $data, ?int $articleId = null): array
{
    blogRequireEdit($user);

    $existing = null;
    if ($articleId) {
        $stmt = $pdo->prepare('SELECT * FROM blog_articles WHERE id = ? LIMIT 1');
        $stmt->execute([$articleId]);
        $existing = $stmt->fetch();
        if (!$existing) respond(404, ['error' => 'Article not found']);
        // An author may only touch their own work, and only while it is still a draft.
        if (blogRole($user) === 'author') {
            if ((int)$existing['created_by'] !== (int)$user['userId'] || $existing['status'] !== 'draft') {
                respond(403, ['error' => 'Authors can only edit their own drafts.']);
            }
        }
    }

    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') respond(400, ['error' => 'A title is required.']);

    $status = (string)($data['status'] ?? ($existing['status'] ?? 'draft'));
    if (!in_array($status, ['draft', 'scheduled', 'published', 'archived'], true)) $status = 'draft';
    if (in_array($status, ['published', 'scheduled'], true)) blogRequirePublish($user);

    $body = blogSanitizeHtml((string)($data['body'] ?? $existing['body_html'] ?? ''));
    $excerpt = trim((string)($data['excerpt'] ?? $existing['excerpt'] ?? ''));
    $slug = !empty($data['slug'])
        ? blogUniqueSlug($pdo, (string)$data['slug'], $articleId)
        : ($existing['slug'] ?? blogUniqueSlug($pdo, $title));

    $publishedAt = $data['publishedAt'] ?? $existing['published_at'] ?? null;
    if ($status === 'published' && !$publishedAt) $publishedAt = gmdate('Y-m-d H:i:s');
    if ($status === 'scheduled' && !$publishedAt) respond(400, ['error' => 'A scheduled article needs a publish date.']);

    $fields = [
        'slug' => $slug,
        'title' => $title,
        'subtitle' => $data['subtitle'] ?? $existing['subtitle'] ?? null,
        'excerpt' => $excerpt ?: null,
        'body_html' => $body ?: null,
        'featured_image' => $data['image'] ?? $existing['featured_image'] ?? null,
        'featured_image_alt' => $data['imageAlt'] ?? $existing['featured_image_alt'] ?? null,
        'category_id' => isset($data['categoryId']) ? ((int)$data['categoryId'] ?: null) : ($existing['category_id'] ?? null),
        'author_id' => isset($data['authorId']) ? ((int)$data['authorId'] ?: null) : ($existing['author_id'] ?? null),
        'status' => $status,
        'is_featured' => (int)(bool)($data['isFeatured'] ?? $existing['is_featured'] ?? 0),
        'comments_enabled' => (int)(bool)($data['commentsEnabled'] ?? $existing['comments_enabled'] ?? 1),
        'published_at' => $publishedAt,
        'updated_content_at' => $existing ? gmdate('Y-m-d H:i:s') : null,
        'reading_minutes' => blogReadingMinutes($body, $excerpt),
        'seo_title' => $data['seoTitle'] ?? $existing['seo_title'] ?? null,
        'seo_description' => $data['seoDescription'] ?? $existing['seo_description'] ?? null,
        'canonical_url' => $data['canonicalUrl'] ?? $existing['canonical_url'] ?? null,
        'focus_keywords' => $data['focusKeywords'] ?? $existing['focus_keywords'] ?? null,
        'og_title' => $data['ogTitle'] ?? $existing['og_title'] ?? null,
        'og_description' => $data['ogDescription'] ?? $existing['og_description'] ?? null,
        'social_image' => $data['socialImage'] ?? $existing['social_image'] ?? null,
    ];

    // Only one article is the lead story; setting a new one clears the previous.
    if ($fields['is_featured']) {
        $pdo->prepare('UPDATE blog_articles SET is_featured = 0 WHERE is_featured = 1'
            . ($articleId ? ' AND id <> ' . (int)$articleId : ''))->execute();
    }

    if ($articleId) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
        $stmt = $pdo->prepare("UPDATE blog_articles SET $sets WHERE id = ?");
        $stmt->execute([...array_values($fields), $articleId]);
    } else {
        $fields['created_by'] = (int)$user['userId'];
        $cols = implode(', ', array_keys($fields));
        $marks = implode(', ', array_fill(0, count($fields), '?'));
        $stmt = $pdo->prepare("INSERT INTO blog_articles ($cols) VALUES ($marks)");
        $stmt->execute(array_values($fields));
        $articleId = (int)$pdo->lastInsertId();
    }

    blogSyncTags($pdo, $articleId, $data['tags'] ?? null);

    $stmt = $pdo->prepare(BLOG_ARTICLE_SELECT . ' WHERE a.id = ? LIMIT 1');
    $stmt->execute([$articleId]);
    return blogAdminArticle($stmt->fetch());
}

function blogSyncTags(PDO $pdo, int $articleId, $tags): void
{
    if (!is_array($tags)) return;
    $pdo->prepare('DELETE FROM blog_article_tags WHERE article_id = ?')->execute([$articleId]);
    foreach ($tags as $name) {
        $name = trim((string)$name);
        if ($name === '') continue;
        $slug = blogSlugify($name);
        $pdo->prepare('INSERT IGNORE INTO blog_tags (name, slug) VALUES (?, ?)')->execute([$name, $slug]);
        $stmt = $pdo->prepare('SELECT id FROM blog_tags WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if ($row) {
            $pdo->prepare('INSERT IGNORE INTO blog_article_tags (article_id, tag_id) VALUES (?, ?)')
                ->execute([$articleId, (int)$row['id']]);
        }
    }
}

function blogListAdmin(PDO $pdo, array $user, array $query): array
{
    blogRequireEdit($user);
    $where = ['1=1'];
    $args = [];
    if (!empty($query['status'])) {
        $where[] = 'a.status = ?';
        $args[] = (string)$query['status'];
    }
    if (blogRole($user) === 'author') {
        $where[] = 'a.created_by = ?';
        $args[] = (int)$user['userId'];
    }
    $stmt = $pdo->prepare(BLOG_ARTICLE_SELECT . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY COALESCE(a.published_at, a.updated_at) DESC LIMIT 200');
    $stmt->execute($args);
    return array_map(fn($r) => blogAdminArticle($r), $stmt->fetchAll());
}

function blogDeleteArticle(PDO $pdo, array $user, int $articleId): void
{
    blogRequirePublish($user);
    $pdo->prepare('DELETE FROM blog_articles WHERE id = ?')->execute([$articleId]);
}
