<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

function fishLureDecodeJsonArray($value) {
    $decoded = json_decode((string)$value, true);
    // Accept old rows where valid JSON was accidentally stored as a JSON string.
    if (is_string($decoded)) {
        // Legacy imports wrapped the JSON array/object in extra quote characters.
        $legacyJson = trim($decoded);
        $legacyJson = trim($legacyJson, "'\"");
        $decoded = json_decode($legacyJson, true);
    }
    return is_array($decoded) ? $decoded : [];
}

function fishLureReply($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'species' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $query = $pdo->query(
        'SELECT species_id, common_name, habitat_group, habitat_info, preferred_depth_zones
         FROM fish_species
         ORDER BY habitat_group, common_name'
    );
    $species = $query->fetchAll();
    foreach ($species as &$fish) {
        $fish['preferred_depth_zones'] = json_decode($fish['preferred_depth_zones'], true) ?: [];
    }
    unset($fish);
    fishLureReply(200, ['species' => $species]);
}

if ($action !== 'recommend' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    fishLureReply(404, ['error' => 'Endpoint not found.']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    fishLureReply(400, ['error' => 'Send the fishing conditions as JSON.']);
}

$fish = trim((string)($input['fish'] ?? ''));
$water = (string)($input['water'] ?? '');
$weather = (string)($input['weather'] ?? '');
$location = (string)($input['location'] ?? '');
$allowedWater = ['clear', 'stained', 'murky'];
$allowedWeather = ['sunny', 'cloudy', 'lowlight'];
$allowedLocations = ['estuary', 'lake', 'river', 'coast', 'offshore'];
if ($fish === '' || !in_array($water, $allowedWater, true) || !in_array($weather, $allowedWeather, true) || !in_array($location, $allowedLocations, true)) {
    fishLureReply(422, ['error' => 'Choose a fish, water clarity, weather, and fishing environment.']);
}

$sql = 'SELECT s.common_name, r.display_rank, r.recommended_depth, r.recommendation_reason,
               l.lure_name, l.lure_type, l.size_guide, l.suitable_depth_zones,
               l.suitable_environments, l.colour_by_water_clarity,
               l.suggested_technique, l.retrieve_method, l.lure_id
        FROM species_lure_rules r
        JOIN fish_species s ON s.species_id = r.species_id
        JOIN lure_styles l ON l.lure_id = r.lure_id
        WHERE r.species_id = :species_id
        ORDER BY r.display_rank';
$stmt = $pdo->prepare($sql);
$stmt->execute(['species_id' => $fish]);
$rows = $stmt->fetchAll();
if (!$rows) {
    fishLureReply(404, ['error' => 'No lure rules are available for this fish yet.']);
}

$recommendations = [];
foreach ($rows as $row) {
    $environments = fishLureDecodeJsonArray($row['suitable_environments']);
    $depthZones = fishLureDecodeJsonArray($row['suitable_depth_zones']);
    $colors = fishLureDecodeJsonArray($row['colour_by_water_clarity']);
    $score = 20 - (int)$row['display_rank'];
    if (in_array($location, $environments, true)) $score += 4;
    if ($water === 'murky' && in_array($row['lure_id'], ['paddle', 'vibe', 'spinner', 'spoon', 'metaljig'], true)) $score += 2;
    if ($water === 'clear' && in_array($row['lure_id'], ['minnow', 'crank', 'walker'], true)) $score += 2;
    if ($weather === 'sunny' && (in_array('deep', $depthZones, true) || in_array('bottom', $depthZones, true))) $score += 1;
    if ($weather === 'lowlight' && in_array($row['lure_id'], ['walker', 'frog', 'vibe'], true)) $score += 1;
    $row['score'] = $score;
    $row['name'] = $row['lure_name'];
    $row['type'] = $row['lure_type'];
    $row['size'] = $row['size_guide'];
    $row['color'] = $colors[$water] ?? 'Natural / high-contrast';
    $row['suggestedDepth'] = $row['recommended_depth'];
    $row['technique'] = $row['suggested_technique'];
    $row['retrieve'] = $row['retrieve_method'];
    $row['why'] = $row['recommendation_reason'];
    $row['fit'] = $score >= 23 ? 'Strong fit' : ($score >= 18 ? 'Good fit' : 'Worth trying');
    unset($row['suitable_depth_zones'], $row['suitable_environments'], $row['colour_by_water_clarity'], $row['lure_id'], $row['lure_name'], $row['lure_type'], $row['size_guide'], $row['suggested_technique'], $row['retrieve_method'], $row['recommended_depth'], $row['recommendation_reason'], $row['display_rank']);
    $recommendations[] = $row;
}
usort($recommendations, function ($a, $b) { return $b['score'] <=> $a['score']; });
foreach ($recommendations as &$recommendation) unset($recommendation['score']);
unset($recommendation);

fishLureReply(200, [
    'speciesName' => $rows[0]['common_name'],
    'recommendations' => $recommendations,
]);
