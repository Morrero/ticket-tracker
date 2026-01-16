<?php

declare(strict_types=1);

use App\Core\Router;
use App\Core\Database;
use App\Utils\Response;

require __DIR__ . '/../vendor/autoload.php';

session_start();

if (isset($_GET['route'])) {
    $_SERVER['REQUEST_URI'] = $_GET['route'];
}

$envPath = __DIR__ . '/../config/.env';
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $row) {
    if (strpos($row, '=') !== false) {
        [$key, $value] = explode('=', $row, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

function inputJson(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function needClient(): void
{
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'client') {
        Response::json(['ok' => false, 'error' => 'Unauthorized'], 401);
        exit;
    }
}

function needAdmin(): void
{
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
        Response::json(['ok' => false, 'error' => 'Forbidden'], 403);
        exit;
    }
}

$router = new Router();

$router->get('/api/ping', fn () => Response::json(['pong' => true]));

$router->post('/api/register', function () {
    $data = inputJson();

    $db = Database::pdo();
    $q = $db->prepare(
        'INSERT INTO users (username, password_hash, role)
         VALUES (?, ?, ?)'
    );

    $q->execute([
        $data['username'],
        password_hash($data['password'], PASSWORD_DEFAULT),
        $data['role'] ?? 'client'
    ]);

    Response::json(['ok' => true]);
});

$router->post('/api/login', function () {
    $data = inputJson();

    $db = Database::pdo();
    $q = $db->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $q->execute([$data['username']]);
    $user = $q->fetch();

    if (!$user || !password_verify($data['password'], $user['password_hash'])) {
        Response::json(['ok' => false, 'error' => 'Invalid credentials'], 401);
        return;
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];

    Response::json([
        'ok' => true,
        'user' => [
            'id' => $user['id'],
            'role' => $user['role']
        ]
    ]);
});

$router->post('/api/logout', function () {
    session_destroy();
    Response::json(['ok' => true]);
});

$router->post('/api/tickets/create', function () {
    needClient();

    $data = inputJson();
    $db = Database::pdo();

    $q = $db->prepare(
        'INSERT INTO tickets (title, description, status_id, client_id, created_at, updated_at)
         VALUES (?, ?, 1, ?, NOW(), NOW())'
    );

    $q->execute([
        $data['title'],
        $data['description'],
        $_SESSION['user_id']
    ]);

    Response::json(['ok' => true]);
});

$router->get('/api/tickets/list', function () {
    needClient();

    $db = Database::pdo();

    $status = $_GET['status'] ?? null;
    $sort = $_GET['sort'] ?? 'created_at';
    $dir = ($_GET['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';

    if (!in_array($sort, ['created_at', 'updated_at'], true)) {
        $sort = 'created_at';
    }

    $sql = '
        SELECT id, title, description, status_id, created_at, updated_at
        FROM tickets
        WHERE client_id = ?
    ';

    $params = [$_SESSION['user_id']];

    if ($status) {
        $sql .= ' AND status_id = ?';
        $params[] = (int)$status;
    }

    $sql .= " ORDER BY $sort $dir";

    $q = $db->prepare($sql);
    $q->execute($params);

    Response::json([
        'ok' => true,
        'tickets' => $q->fetchAll()
    ]);
});

$router->get('/api/tickets/view/(\d+)', function ($id) {
    needClient();

    $db = Database::pdo();
    $q = $db->prepare(
        'SELECT * FROM tickets WHERE id = ? AND client_id = ?'
    );
    $q->execute([$id, $_SESSION['user_id']]);
    $ticket = $q->fetch();

    if (!$ticket) {
        Response::json(['ok' => false, 'error' => 'Not found'], 404);
        return;
    }

    $c = $db->prepare(
        'SELECT author_id, body, created_at
         FROM ticket_comments
         WHERE ticket_id = ?
         ORDER BY created_at'
    );
    $c->execute([$id]);

    Response::json([
        'ok' => true,
        'ticket' => $ticket,
        'comments' => $c->fetchAll()
    ]);
});

$router->get('/api/admin/tickets', function () {
    needAdmin();

    $db = Database::pdo();

    $status = $_GET['status'] ?? null;
    $sort = $_GET['sort'] ?? 'created_at';
    $dir = ($_GET['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';

    if (!in_array($sort, ['created_at', 'updated_at'], true)) {
        $sort = 'created_at';
    }

    $sql = '
        SELECT
            t.id,
            t.title,
            t.description,
            t.status_id,
            t.created_at,
            t.updated_at,
            u.username AS client_name,
            GROUP_CONCAT(tags.name SEPARATOR ", ") AS tags
        FROM tickets t
        LEFT JOIN users u ON u.id = t.client_id
        LEFT JOIN ticket_tags tt ON tt.ticket_id = t.id
        LEFT JOIN tags ON tags.id = tt.tag_id
        WHERE 1=1
    ';

    $args = [];

    if ($status) {
        $sql .= ' AND t.status_id = ?';
        $args[] = (int)$status;
    }

    $sql .= " GROUP BY t.id ORDER BY t.$sort $dir";

    $q = $db->prepare($sql);
    $q->execute($args);

    Response::json([
        'ok' => true,
        'tickets' => $q->fetchAll()
    ]);
});

$router->post('/api/admin/tickets/status', function () {
    needAdmin();

    $data = inputJson();
    $db = Database::pdo();

    $db->prepare(
        'UPDATE tickets SET status_id = ?, admin_id = ?, updated_at = NOW()
         WHERE id = ?'
    )->execute([
        $data['status_id'],
        $_SESSION['user_id'],
        $data['ticket_id']
    ]);

    Response::json(['ok' => true]);
});

$router->post('/api/admin/tickets/comment', function () {
    needAdmin();

    $data = inputJson();
    $db = Database::pdo();

    $db->prepare(
        'INSERT INTO ticket_comments (ticket_id, author_id, body, created_at)
         VALUES (?, ?, ?, NOW())'
    )->execute([
        $data['ticket_id'],
        $_SESSION['user_id'],
        $data['comment']
    ]);

    Response::json(['ok' => true]);
});

$router->post('/api/admin/tickets/tags', function () {
    needAdmin();

    $data = inputJson();

    if (empty($data['ticket_id']) || !is_array($data['tags'])) {
        Response::json(['ok' => false, 'error' => 'Bad request']);
        return;
    }

    $ticketId = (int)$data['ticket_id'];
    $tagIds = array_map('intval', $data['tags']);

    $db = Database::pdo();

    $in = implode(',', array_fill(0, count($tagIds), '?'));
    $q = $db->prepare("SELECT id FROM tags WHERE id IN ($in)");
    $q->execute($tagIds);

    $valid = array_column($q->fetchAll(), 'id');

    if (!$valid) {
        Response::json(['ok' => false, 'error' => 'Теги не найдены']);
        return;
    }

    $db->prepare('DELETE FROM ticket_tags WHERE ticket_id = ?')
        ->execute([$ticketId]);

    $ins = $db->prepare(
        'INSERT INTO ticket_tags (ticket_id, tag_id) VALUES (?, ?)'
    );

    foreach ($valid as $tagId) {
        $ins->execute([$ticketId, $tagId]);
    }

    Response::json(['ok' => true, 'attached' => $valid]);
});

$router->get('/api/admin/tags', function () {
    needAdmin();

    $db = Database::pdo();
    $q = $db->query('SELECT id, name, created_at FROM tags ORDER BY id DESC');

    Response::json(['ok' => true, 'tags' => $q->fetchAll()]);
});

$router->post('/api/admin/tags/create', function () {
    needAdmin();

    $data = inputJson();
    if (empty($data['name'])) {
        Response::json(['ok' => false, 'error' => 'Name required']);
        return;
    }

    $db = Database::pdo();
    $db->prepare(
        'INSERT INTO tags (name, created_at) VALUES (?, NOW())'
    )->execute([$data['name']]);

    Response::json(['ok' => true]);
});

$router->post('/api/admin/tags/update', function () {
    needAdmin();

    $data = inputJson();
    if (empty($data['id']) || empty($data['name'])) {
        Response::json(['ok' => false, 'error' => 'Bad request']);
        return;
    }

    $db = Database::pdo();
    $db->prepare(
        'UPDATE tags SET name = ? WHERE id = ?'
    )->execute([$data['name'], $data['id']]);

    Response::json(['ok' => true]);
});

$router->post('/api/admin/tags/delete', function () {
    needAdmin();

    $data = inputJson();
    if (empty($data['id'])) {
        Response::json(['ok' => false, 'error' => 'ID required']);
        return;
    }

    $db = Database::pdo();
    $db->prepare('DELETE FROM tags WHERE id = ?')
        ->execute([$data['id']]);

    Response::json(['ok' => true]);
});

$router->run();
