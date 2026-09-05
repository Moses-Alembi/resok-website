<?php
declare(strict_types=1);

/**
 * Pulls posts from ReSoK's own social channels into a review queue.
 *
 * Nothing here publishes anything. Ingested items land in blog_social_items with status
 * 'new'; an editor turns one into an article, which is when it becomes public. That is a
 * deliberate choice: social copy is short, hashtag-heavy and written for a feed, and
 * publishing it verbatim would read badly and dilute the site. The queue also means a bad
 * fetch can never deface the blog.
 *
 * Each platform is an adapter returning a normalised list. What differs between them is
 * almost entirely what access the platform grants:
 *
 *   youtube   - a public RSS feed per channel. No credentials, no app, no quota. Works now.
 *   facebook  - Graph API /{page-id}/posts with a Page access token (pages_read_engagement).
 *   instagram - Graph API /{ig-user-id}/media, business account linked to that Page.
 *   linkedin  - organization posts need Community Management API access, which is granted
 *               by application and is frequently refused; implemented, but expect friction.
 *   x         - read access is a paid tier now, so there is no free path. Not implemented,
 *               rather than shipped as something that quietly never works.
 *   tiktok    - Display API requires app review; not implemented for the same reason.
 *
 * Credentials live in blog_social_sources.credentials, not in code, so tokens can be
 * rotated from the admin panel without a deploy.
 */

function socialHttpGet(string $url, array $headers = [], int $timeout = 20): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'ReSoK-site/1.0 (+https://www.resok.org)',
        CURLOPT_HTTPHEADER => $headers,
        // These calls carry API tokens and follow redirects, so the redirect is the risk:
        // a redirect to file:// or gopher:// would read local files, and one to 169.254.x
        // or localhost would reach services behind the firewall. Restricting the protocol
        // on both the initial request and any redirect, and capping the chain, closes that
        // without needing to resolve and vet every hop.
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false || $status >= 400) {
        throw new RuntimeException($error !== '' ? $error : "HTTP $status from " . parse_url($url, PHP_URL_HOST));
    }
    return (string)$body;
}

/** @return array<int,array{externalId:string,title:?string,body:?string,permalink:?string,media:?string,mediaType:string,postedAt:?string}> */
function socialFetchYoutube(array $source): array
{
    $channelId = (string)($source['handle'] ?? '');
    if ($channelId === '') throw new RuntimeException('YouTube source has no channel id.');

    $xml = socialHttpGet('https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode($channelId));
    $prev = libxml_use_internal_errors(true);
    $feed = simplexml_load_string($xml);
    libxml_use_internal_errors($prev);
    if (!$feed) throw new RuntimeException('YouTube feed could not be parsed.');

    $items = [];
    foreach ($feed->entry as $entry) {
        $media = $entry->children('http://search.yahoo.com/mrss/');
        $yt = $entry->children('http://www.youtube.com/xml/schemas/2015');
        $videoId = (string)($yt->videoId ?? '');
        if ($videoId === '') continue;
        $items[] = [
            'externalId' => $videoId,
            'title' => trim((string)$entry->title),
            'body' => trim((string)($media->group->description ?? '')),
            'permalink' => 'https://www.youtube.com/watch?v=' . $videoId,
            // maxresdefault is not generated for every upload; hqdefault always is.
            'media' => 'https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg',
            'mediaType' => 'video',
            'postedAt' => date('Y-m-d H:i:s', strtotime((string)$entry->published)),
        ];
    }
    return $items;
}

function socialFetchFacebook(array $source): array
{
    $creds = json_decode((string)($source['credentials'] ?? ''), true) ?: [];
    $token = (string)($creds['page_access_token'] ?? '');
    $pageId = (string)($source['handle'] ?? '');
    if ($token === '' || $pageId === '') {
        throw new RuntimeException('Facebook source needs a page id and a page access token.');
    }
    $url = 'https://graph.facebook.com/v21.0/' . rawurlencode($pageId) . '/posts'
        . '?fields=id,message,created_time,permalink_url,full_picture&limit=25'
        . '&access_token=' . rawurlencode($token);
    $data = json_decode(socialHttpGet($url), true);
    if (!is_array($data) || !isset($data['data'])) throw new RuntimeException('Unexpected Facebook response.');

    $items = [];
    foreach ($data['data'] as $post) {
        $message = trim((string)($post['message'] ?? ''));
        if ($message === '') continue;   // photo-only posts carry no text worth reviewing
        $items[] = [
            'externalId' => (string)$post['id'],
            'title' => socialTitleFromBody($message),
            'body' => $message,
            'permalink' => $post['permalink_url'] ?? null,
            'media' => $post['full_picture'] ?? null,
            'mediaType' => !empty($post['full_picture']) ? 'image' : 'none',
            'postedAt' => isset($post['created_time']) ? date('Y-m-d H:i:s', strtotime($post['created_time'])) : null,
        ];
    }
    return $items;
}

function socialFetchInstagram(array $source): array
{
    $creds = json_decode((string)($source['credentials'] ?? ''), true) ?: [];
    $token = (string)($creds['access_token'] ?? '');
    $userId = (string)($source['handle'] ?? '');
    if ($token === '' || $userId === '') {
        throw new RuntimeException('Instagram source needs an IG user id and an access token.');
    }
    $url = 'https://graph.facebook.com/v21.0/' . rawurlencode($userId) . '/media'
        . '?fields=id,caption,media_type,media_url,thumbnail_url,permalink,timestamp&limit=25'
        . '&access_token=' . rawurlencode($token);
    $data = json_decode(socialHttpGet($url), true);
    if (!is_array($data) || !isset($data['data'])) throw new RuntimeException('Unexpected Instagram response.');

    $items = [];
    foreach ($data['data'] as $post) {
        $caption = trim((string)($post['caption'] ?? ''));
        $items[] = [
            'externalId' => (string)$post['id'],
            'title' => socialTitleFromBody($caption),
            'body' => $caption,
            'permalink' => $post['permalink'] ?? null,
            'media' => $post['thumbnail_url'] ?? $post['media_url'] ?? null,
            'mediaType' => ($post['media_type'] ?? '') === 'VIDEO' ? 'video' : 'image',
            'postedAt' => isset($post['timestamp']) ? date('Y-m-d H:i:s', strtotime($post['timestamp'])) : null,
        ];
    }
    return $items;
}

function socialFetchLinkedin(array $source): array
{
    $creds = json_decode((string)($source['credentials'] ?? ''), true) ?: [];
    $token = (string)($creds['access_token'] ?? '');
    $orgId = (string)($source['handle'] ?? '');
    if ($token === '' || $orgId === '') {
        throw new RuntimeException('LinkedIn source needs an organization id and an access token.');
    }
    $url = 'https://api.linkedin.com/rest/posts?author=' . rawurlencode('urn:li:organization:' . $orgId)
        . '&q=author&count=25&sortBy=LAST_MODIFIED';
    $body = socialHttpGet($url, [
        'Authorization: Bearer ' . $token,
        'LinkedIn-Version: 202409',
        'X-Restli-Protocol-Version: 2.0.0',
    ]);
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['elements'])) throw new RuntimeException('Unexpected LinkedIn response.');

    $items = [];
    foreach ($data['elements'] as $post) {
        $text = trim((string)($post['commentary'] ?? ''));
        if ($text === '') continue;
        $urn = (string)($post['id'] ?? '');
        $items[] = [
            'externalId' => $urn,
            'title' => socialTitleFromBody($text),
            'body' => $text,
            'permalink' => $urn !== '' ? 'https://www.linkedin.com/feed/update/' . $urn : null,
            'media' => null,
            'mediaType' => 'none',
            'postedAt' => isset($post['publishedAt']) ? date('Y-m-d H:i:s', (int)($post['publishedAt'] / 1000)) : null,
        ];
    }
    return $items;
}

/** First sentence or so of a social post, for a queue that has to be skimmable. */
function socialTitleFromBody(string $body): string
{
    $clean = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');
    if ($clean === '') return 'Untitled post';
    $cut = preg_split('/(?<=[.!?])\s/', $clean)[0] ?? $clean;
    if (mb_strlen($cut) > 120) $cut = mb_substr($cut, 0, 117) . '...';
    return $cut;
}

/**
 * Runs one source and stores anything new. Returns a small summary for the cron log.
 * An item already seen is skipped by the unique (source_id, external_id) key, so a source
 * can be polled as often as you like without creating duplicates or resurrecting something
 * an editor has already ignored.
 */
function socialIngestSource(PDO $pdo, array $source): array
{
    $platform = (string)$source['platform'];
    $fetchers = [
        'youtube' => 'socialFetchYoutube',
        'facebook' => 'socialFetchFacebook',
        'instagram' => 'socialFetchInstagram',
        'linkedin' => 'socialFetchLinkedin',
    ];
    if (!isset($fetchers[$platform])) {
        throw new RuntimeException("No adapter for '$platform' - its API has no free read access.");
    }

    $items = $fetchers[$platform]($source);
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO blog_social_items
         (source_id, external_id, permalink, title, body, media_url, media_type, posted_at, fetched_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );

    $added = 0;
    foreach ($items as $item) {
        $insert->execute([
            (int)$source['id'], $item['externalId'], $item['permalink'], $item['title'],
            $item['body'], $item['media'], $item['mediaType'], $item['postedAt'],
        ]);
        $added += $insert->rowCount();
    }

    $pdo->prepare('UPDATE blog_social_sources SET last_checked_at = NOW(), last_error = NULL WHERE id = ?')
        ->execute([(int)$source['id']]);

    return ['source' => $source['label'], 'platform' => $platform, 'seen' => count($items), 'added' => $added];
}

/** Runs every enabled source. One failing source must not stop the others. */
function socialIngestAll(PDO $pdo): array
{
    $sources = $pdo->query('SELECT * FROM blog_social_sources WHERE is_enabled = 1')->fetchAll();
    $results = [];
    foreach ($sources as $source) {
        try {
            $results[] = socialIngestSource($pdo, $source);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE blog_social_sources SET last_checked_at = NOW(), last_error = ? WHERE id = ?')
                ->execute([mb_substr($e->getMessage(), 0, 400), (int)$source['id']]);
            $results[] = ['source' => $source['label'], 'platform' => $source['platform'], 'error' => $e->getMessage()];
            error_log('Social ingest failed for ' . $source['label'] . ': ' . $e->getMessage());
        }
    }
    return $results;
}

/**
 * Turns a queued item into a draft article for an editor to finish. Never publishes: the
 * article is created as a draft with the social text as its starting point, and the item is
 * marked imported so it cannot be turned into a second article later.
 */
function socialImportItem(PDO $pdo, array $user, int $itemId, array $overrides = []): array
{
    blogRequireEdit($user);

    $stmt = $pdo->prepare('SELECT i.*, s.platform, s.default_category_id
                             FROM blog_social_items i
                             JOIN blog_social_sources s ON s.id = i.source_id
                            WHERE i.id = ? LIMIT 1');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) respond(404, ['error' => 'Queued post not found']);
    if ($item['status'] === 'imported') respond(409, ['error' => 'That post has already been imported.']);

    $body = (string)($item['body'] ?? '');
    $paragraphs = array_filter(array_map('trim', preg_split('/\n{2,}/', $body) ?: []));
    $html = implode('', array_map(fn($p) => '<p>' . htmlspecialchars($p, ENT_QUOTES) . '</p>', $paragraphs));
    if ($item['permalink']) {
        $html .= '<p><a href="' . htmlspecialchars($item['permalink'], ENT_QUOTES) . '"'
            . ' target="_blank" rel="noopener noreferrer">Originally posted on '
            . htmlspecialchars(ucfirst((string)$item['platform']), ENT_QUOTES) . '</a></p>';
    }

    $article = blogSaveArticle($pdo, $user, array_merge([
        'title' => $item['title'] ?: 'Imported post',
        'excerpt' => mb_substr(trim(preg_replace('/\s+/', ' ', $body) ?? ''), 0, 280),
        'body' => $html,
        'image' => $item['media_url'],
        'categoryId' => $item['default_category_id'],
        'status' => 'draft',
    ], $overrides));

    $pdo->prepare('UPDATE blog_social_items SET status = "imported", article_id = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
        ->execute([(int)$article['id'], (int)$user['userId'], $itemId]);

    return $article;
}
