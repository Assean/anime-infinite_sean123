<?php
/**
 * ANIME INFINITE — News / Articles API
 * api/news.php
 */

require_once __DIR__ . '/../config/config.php';

$body   = getJsonBody();
$action = sanitize($body['action'] ?? $_GET['action'] ?? 'list');

// Public endpoints (no auth required)
$publicActions = ['list', 'get', 'get_hot', 'get_tags'];

if (!in_array($action, $publicActions)) {
    $auth = getAuthUser(true);
    $uid  = (int)$auth['uid'];
    $role = (int)($auth['role_level'] ?? 1);
} else {
    $auth = getAuthUser(false);
    $uid  = (int)($auth['uid'] ?? 0);
    $role = (int)($auth['role_level'] ?? 0);
}

switch ($action) {
    // ── Public ──────────────────────────────────────────────
    case 'list':     listArticles();            break;
    case 'get':      getArticle();              break;
    case 'get_hot':  getHotArticles();          break;
    case 'get_tags': getTags();                 break;

    // ── Auth required ────────────────────────────────────────
    case 'like':     toggleLike($uid);          break;
    case 'comment':  postComment($uid);         break;
    case 'share':    recordShare($uid);         break;

    // ── Contributor+ ────────────────────────────────────────
    case 'create':   createArticle($uid, $role, $body);  break;
    case 'update':   updateArticle($uid, $role, $body);  break;
    case 'delete':   deleteArticle($uid, $role, $body);  break;
    case 'stats':    getCreatorStats($uid, $role);       break;

    default: jsonError('未知操作', 400);
}

// ══════════════════════════════════════════════════════════════════
function listArticles(): void {
    $cat    = sanitize($_GET['cat'] ?? 'all', 20);
    $q      = sanitize($_GET['q'] ?? '', 100);
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 12;
    $offset = ($page - 1) * $limit;

    $where  = ["a.status = 'published'"];
    $params = [];

    if ($cat !== 'all') {
        $where[]  = "a.category = ?";
        $params[] = $cat;
    }
    if ($q) {
        $where[]  = "(a.title LIKE ? OR a.content LIKE ?)";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $params   = array_merge($params, [$limit, $offset]);

    $articles = Database::query(
        "SELECT a.id, a.category, a.title, a.cover_emoji, a.views, a.likes, a.shares,
                a.published_at, u.nickname as author, u.role_level as author_role
         FROM articles a
         JOIN users u ON u.id = a.author_id
         {$whereSql}
         ORDER BY a.published_at DESC
         LIMIT ? OFFSET ?",
        $params
    );

    $countParams = array_slice($params, 0, -2);
    $total = Database::queryOne(
        "SELECT COUNT(*) as cnt FROM articles a JOIN users u ON u.id = a.author_id {$whereSql}",
        $countParams
    )['cnt'] ?? 0;

    jsonSuccess(['articles' => $articles, 'total' => (int)$total, 'page' => $page]);
}

// ══════════════════════════════════════════════════════════════════
function getArticle(): void {
    global $uid;
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('缺少文章 ID');

    $article = Database::queryOne(
        "SELECT a.*, u.nickname as author, u.avatar as author_avatar, u.role_level as author_role
         FROM articles a JOIN users u ON u.id = a.author_id
         WHERE a.id = ? AND a.status = 'published' LIMIT 1",
        [$id]
    );
    if (!$article) jsonError('找不到文章', 404);

    // Increment view
    Database::execute("UPDATE articles SET views = views + 1 WHERE id = ?", [$id]);

    // Check if current user liked it
    $liked = false;
    if ($uid) {
        $liked = (bool)Database::queryOne(
            "SELECT id FROM article_likes WHERE article_id = ? AND user_id = ? LIMIT 1",
            [$id, $uid]
        );
    }

    // Get comments (latest 20)
    $comments = Database::query(
        "SELECT c.id, c.content, c.created_at, u.nickname, u.avatar, u.role_level
         FROM article_comments c JOIN users u ON u.id = c.user_id
         WHERE c.article_id = ? ORDER BY c.created_at DESC LIMIT 20",
        [$id]
    );

    jsonSuccess([
        'article'  => $article,
        'liked'    => $liked,
        'comments' => $comments,
    ]);
}

// ══════════════════════════════════════════════════════════════════
function getHotArticles(): void {
    $limit = min(10, (int)($_GET['limit'] ?? 5));
    $rows  = Database::query(
        "SELECT id, title, cover_emoji, views, likes, category, published_at
         FROM articles WHERE status = 'published'
         ORDER BY (views * 0.3 + likes * 0.7) DESC LIMIT ?",
        [$limit]
    );
    jsonSuccess(['articles' => $rows]);
}

// ══════════════════════════════════════════════════════════════════
function getTags(): void {
    // Returns most-used categories as a tag cloud
    $rows = Database::query(
        "SELECT category, COUNT(*) as cnt
         FROM articles WHERE status = 'published'
         GROUP BY category ORDER BY cnt DESC"
    );
    jsonSuccess(['tags' => $rows]);
}

// ══════════════════════════════════════════════════════════════════
function toggleLike(int $uid): void {
    $id = (int)(getJsonBody()['article_id'] ?? 0);
    if (!$id) jsonError('缺少 article_id');

    $existing = Database::queryOne(
        "SELECT id FROM article_likes WHERE article_id = ? AND user_id = ? LIMIT 1",
        [$id, $uid]
    );

    if ($existing) {
        Database::execute("DELETE FROM article_likes WHERE article_id = ? AND user_id = ?", [$id, $uid]);
        Database::execute("UPDATE articles SET likes = GREATEST(0, likes - 1) WHERE id = ?", [$id]);
        jsonSuccess(['liked' => false, 'action' => 'unliked']);
    } else {
        Database::execute(
            "INSERT IGNORE INTO article_likes (article_id, user_id, created_at) VALUES (?, ?, NOW())",
            [$id, $uid]
        );
        Database::execute("UPDATE articles SET likes = likes + 1 WHERE id = ?", [$id]);
        jsonSuccess(['liked' => true, 'action' => 'liked']);
    }
}

// ══════════════════════════════════════════════════════════════════
function postComment(int $uid): void {
    $body    = getJsonBody();
    $id      = (int)($body['article_id'] ?? 0);
    $content = sanitize($body['content'] ?? '', 1000);

    if (!$id || !$content) jsonError('請填寫留言內容');
    if (mb_strlen($content) < 2) jsonError('留言至少需要 2 個字元');

    // Rate limit: 5 comments per minute
    if (!checkRateLimit("comment_{$uid}", 5, 60)) {
        jsonError('留言過於頻繁，請稍後再試', 429);
    }

    $article = Database::queryOne("SELECT id FROM articles WHERE id = ? AND status = 'published' LIMIT 1", [$id]);
    if (!$article) jsonError('找不到文章');

    $commentId = Database::insert(
        "INSERT INTO article_comments (article_id, user_id, content, created_at) VALUES (?, ?, ?, NOW())",
        [$id, $uid, $content]
    );

    $user = Database::queryOne("SELECT nickname, avatar, role_level FROM users WHERE id = ?", [$uid]);

    jsonSuccess([
        'comment' => [
            'id'         => $commentId,
            'content'    => $content,
            'created_at' => date('Y-m-d H:i:s'),
            'nickname'   => $user['nickname'],
            'avatar'     => $user['avatar'],
            'role_level' => $user['role_level'],
        ],
    ], '留言成功！');
}

// ══════════════════════════════════════════════════════════════════
function recordShare(int $uid): void {
    $id = (int)(getJsonBody()['article_id'] ?? 0);
    if (!$id) jsonError('缺少 article_id');
    Database::execute("UPDATE articles SET shares = shares + 1 WHERE id = ?", [$id]);
    jsonSuccess([], '分享紀錄已記錄');
}

// ══════════════════════════════════════════════════════════════════
function createArticle(int $uid, int $role, array $body): void {
    if ($role < 3) jsonError('需要貢獻層（Lv.3）以上才能發表文章', 403);

    $category = sanitize($body['cat'] ?? 'guide', 20);
    $title    = sanitize($body['title'] ?? '', 200);
    $content  = $body['content'] ?? '';

    if (!in_array($category, ['update','event','guide','patch'])) jsonError('無效的文章類別');
    if (!$title || mb_strlen($title) < 5) jsonError('標題至少需要 5 個字元');
    if (!$content || mb_strlen(strip_tags($content)) < 50) jsonError('內容至少需要 50 個字元');

    // Auto-publish for admin; draft for contributors
    $status = $role >= 8 ? 'published' : 'draft';
    $pubAt  = $status === 'published' ? date('Y-m-d H:i:s') : null;

    $id = Database::insert(
        "INSERT INTO articles (author_id, category, title, content, status, published_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())",
        [$uid, $category, $title, $content, $status, $pubAt]
    );

    writeAuditLog('create_article', ['id' => $id, 'title' => $title, 'status' => $status], $uid);
    jsonSuccess(['id' => $id, 'status' => $status],
        $status === 'published' ? '文章已發布！' : '文章已提交審核，發布後即可顯示');
}

// ══════════════════════════════════════════════════════════════════
function updateArticle(int $uid, int $role, array $body): void {
    $id = (int)($body['id'] ?? 0);
    if (!$id) jsonError('缺少文章 ID');

    $article = Database::queryOne("SELECT * FROM articles WHERE id = ? LIMIT 1", [$id]);
    if (!$article) jsonError('找不到文章', 404);

    // Only author or admin can edit
    if ($article['author_id'] !== $uid && $role < 8) {
        jsonError('您沒有權限編輯此文章', 403);
    }

    $title   = sanitize($body['title'] ?? $article['title'], 200);
    $content = $body['content'] ?? $article['content'];
    $status  = $role >= 8 ? sanitize($body['status'] ?? $article['status'], 20) : $article['status'];

    Database::execute(
        "UPDATE articles SET title = ?, content = ?, status = ?, updated_at = NOW() WHERE id = ?",
        [$title, $content, $status, $id]
    );

    writeAuditLog('update_article', ['id' => $id], $uid);
    jsonSuccess([], '文章已更新');
}

// ══════════════════════════════════════════════════════════════════
function deleteArticle(int $uid, int $role, array $body): void {
    $id = (int)($body['id'] ?? 0);
    if (!$id) jsonError('缺少文章 ID');

    $article = Database::queryOne("SELECT author_id FROM articles WHERE id = ? LIMIT 1", [$id]);
    if (!$article) jsonError('找不到文章', 404);

    if ($article['author_id'] !== $uid && $role < 10) {
        jsonError('您沒有權限刪除此文章', 403);
    }

    Database::execute("UPDATE articles SET status = 'archived' WHERE id = ?", [$id]);
    writeAuditLog('delete_article', ['id' => $id], $uid);
    jsonSuccess([], '文章已下架');
}

// ══════════════════════════════════════════════════════════════════
function getCreatorStats(int $uid, int $role): void {
    if ($role < 3) jsonError('需要貢獻層（Lv.3）以上', 403);

    $stats = Database::queryOne(
        "SELECT
            COUNT(*) as article_count,
            COALESCE(SUM(views), 0)  as total_views,
            COALESCE(SUM(likes), 0)  as total_likes,
            COALESCE(SUM(shares), 0) as total_shares
         FROM articles WHERE author_id = ? AND status = 'published'",
        [$uid]
    );

    $recentArticles = Database::query(
        "SELECT id, title, views, likes, shares, published_at
         FROM articles WHERE author_id = ? AND status = 'published'
         ORDER BY published_at DESC LIMIT 5",
        [$uid]
    );

    jsonSuccess([
        'stats'           => $stats,
        'recent_articles' => $recentArticles,
    ]);
}
