<?php
require __DIR__ . '/config.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params(['lifetime'=>0, 'path'=>'/', 'secure'=>$https, 'httponly'=>true, 'samesite'=>'Lax']);
session_name('FISHLURESESSID');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function accountReply($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId) accountReply(401, ['error'=>'Please log in to use your personal fishing log.']);
$action = $_GET['action'] ?? '';

if ($action === 'dashboard' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $saved = $pdo->prepare('SELECT saved_id, species_id, species_name, water_clarity, weather, environment, lure_name, lure_type, lure_size, lure_colour, recommended_depth, technique, retrieve_method, reason, created_at FROM saved_recommendations WHERE user_id = :uid ORDER BY created_at DESC');
    $saved->execute(['uid'=>$userId]);
    $catches = $pdo->prepare('SELECT c.catch_id, c.species_id, s.common_name AS species_name, c.catch_date, c.location, c.size_note, c.lure_used, c.depth_note, c.notes, c.created_at FROM catch_logs c JOIN fish_species s ON s.species_id = c.species_id WHERE c.user_id = :uid ORDER BY c.catch_date DESC, c.catch_id DESC');
    $catches->execute(['uid'=>$userId]);
    accountReply(200, ['saved'=>$saved->fetchAll(), 'catches'=>$catches->fetchAll()]);
}

if (!in_array($action, ['save-recommendation','add-catch','delete-saved','delete-catch'], true) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    accountReply(404, ['error'=>'Endpoint not found.']);
}
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) accountReply(400, ['error'=>'Send your entry as JSON.']);
$csrf = (string)($_SESSION['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals($csrf, (string)($input['csrfToken'] ?? ''))) accountReply(419, ['error'=>'Your session expired. Refresh the page and try again.']);

if ($action === 'save-recommendation') {
    $required = ['speciesId','speciesName','water','weather','environment','name','type','size','color','suggestedDepth','technique','retrieve','why'];
    foreach ($required as $key) if (!isset($input[$key]) || !is_string($input[$key])) accountReply(422, ['error'=>'The recommendation data is incomplete.']);
    $speciesCheck = $pdo->prepare('SELECT 1 FROM fish_species WHERE species_id = :id');
    $speciesCheck->execute(['id'=>$input['speciesId']]);
    if (!$speciesCheck->fetchColumn()) accountReply(422, ['error'=>'Choose a fish from the FishLure catalog.']);
    $insert = $pdo->prepare('INSERT INTO saved_recommendations (user_id,species_id,species_name,water_clarity,weather,environment,lure_name,lure_type,lure_size,lure_colour,recommended_depth,technique,retrieve_method,reason) VALUES (:uid,:sid,:species,:water,:weather,:environment,:lure,:type,:size,:colour,:depth,:technique,:retrieve,:reason)');
    $insert->execute(['uid'=>$userId,'sid'=>$input['speciesId'],'species'=>$input['speciesName'],'water'=>$input['water'],'weather'=>$input['weather'],'environment'=>$input['environment'],'lure'=>$input['name'],'type'=>$input['type'],'size'=>$input['size'],'colour'=>$input['color'],'depth'=>$input['suggestedDepth'],'technique'=>$input['technique'],'retrieve'=>$input['retrieve'],'reason'=>$input['why']]);
    accountReply(201, ['message'=>'Recommendation saved to your account.']);
}

if ($action === 'add-catch') {
    $speciesId = trim((string)($input['speciesId'] ?? ''));
    $date = trim((string)($input['date'] ?? ''));
    $location = trim((string)($input['location'] ?? ''));
    if ($speciesId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $location === '' || mb_strlen($location) > 160) accountReply(422, ['error'=>'Enter a fish, valid date and location.']);
    $speciesCheck = $pdo->prepare('SELECT 1 FROM fish_species WHERE species_id = :id');
    $speciesCheck->execute(['id'=>$speciesId]);
    if (!$speciesCheck->fetchColumn()) accountReply(422, ['error'=>'Choose a fish from the FishLure catalog.']);
    $insert = $pdo->prepare('INSERT INTO catch_logs (user_id,species_id,catch_date,location,size_note,lure_used,depth_note,notes) VALUES (:uid,:sid,:date,:location,:size,:lure,:depth,:notes)');
    $insert->execute(['uid'=>$userId,'sid'=>$speciesId,'date'=>$date,'location'=>$location,'size'=>mb_substr(trim((string)($input['size'] ?? '')),0,80),'lure'=>mb_substr(trim((string)($input['lure'] ?? '')),0,160),'depth'=>mb_substr(trim((string)($input['depth'] ?? '')),0,120),'notes'=>mb_substr(trim((string)($input['notes'] ?? '')),0,3000)]);
    accountReply(201, ['message'=>'Catch added to your log.']);
}

$table = $action === 'delete-saved' ? 'saved_recommendations' : 'catch_logs';
$idColumn = $action === 'delete-saved' ? 'saved_id' : 'catch_id';
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id < 1) accountReply(422, ['error'=>'Choose a valid entry to delete.']);
$delete = $pdo->prepare("DELETE FROM {$table} WHERE {$idColumn} = :id AND user_id = :uid");
$delete->execute(['id'=>$id,'uid'=>$userId]);
if (!$delete->rowCount()) accountReply(404, ['error'=>'That entry was not found.']);
accountReply(200, ['message'=>'Entry deleted.']);
