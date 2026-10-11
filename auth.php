<?php
require __DIR__ . '/config.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('FISHLURESESSID');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function authReply($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function authUser() {
    if (empty($_SESSION['user_id'])) return null;
    return [
        'id' => (int)$_SESSION['user_id'],
        'name' => (string)$_SESSION['user_name'],
        'email' => (string)$_SESSION['user_email'],
    ];
}
function newCsrfToken() {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

$action = $_GET['action'] ?? '';
if ($action === 'me' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = $_SESSION['csrf_token'] ?? newCsrfToken();
    authReply(200, ['user' => authUser(), 'csrfToken' => $token]);
}

if (!in_array($action, ['register', 'login', 'logout'], true) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    authReply(404, ['error' => 'Endpoint not found.']);
}
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) authReply(400, ['error' => 'Send account details as JSON.']);
$submittedToken = (string)($input['csrfToken'] ?? '');
$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    authReply(419, ['error' => 'Your session expired. Refresh the page and try again.']);
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
    authReply(200, ['user' => null, 'message' => 'You are logged out.']);
}

$email = strtolower(trim((string)($input['email'] ?? '')));
$password = (string)($input['password'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    authReply(422, ['error' => 'Enter a valid email address.']);
}
if ($password === '' || strlen($password) > 72) {
    authReply(422, ['error' => 'Password must be 1 to 72 characters.']);
}

if ($action === 'register') {
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) authReply(422, ['error' => 'Enter your name (up to 100 characters).']);
    if (strlen($password) < 8) authReply(422, ['error' => 'Choose a password with at least 8 characters.']);
    try {
        $stmt = $pdo->prepare('INSERT INTO users (display_name, email, password_hash) VALUES (:name, :email, :hash)');
        $stmt->execute(['name' => $name, 'email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int)$pdo->lastInsertId();
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') authReply(409, ['error' => 'An account with this email already exists. Try logging in.']);
        authReply(500, ['error' => 'Could not create the account. Please try again.']);
    }
} else {
    $stmt = $pdo->prepare('SELECT user_id, display_name, email, password_hash FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        authReply(401, ['error' => 'Email or password is incorrect.']);
    }
    $userId = (int)$row['user_id'];
    $name = (string)$row['display_name'];
    $email = (string)$row['email'];
    $update = $pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE user_id = :id');
    $update->execute(['id' => $userId]);
}

session_regenerate_id(true);
$_SESSION['user_id'] = $userId;
$_SESSION['user_name'] = $name;
$_SESSION['user_email'] = $email;
newCsrfToken();
authReply(200, ['user' => authUser(), 'csrfToken' => $_SESSION['csrf_token']]);
