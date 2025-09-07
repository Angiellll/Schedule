<?php
// 允許跨域
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ------------------- 讀取參數 -------------------
$searchMode = $_POST['search_mode'] ?? $_GET['search_mode'] ?? 'address';
$city = $_POST['city'] ?? $_GET['city'] ?? null;
$district = $_POST['district'] ?? $_GET['district'] ?? null;
$road = $_POST['road'] ?? $_GET['road'] ?? null;
$mrt = $_POST['mrt'] ?? $_GET['mrt'] ?? null;
$preferences = $_POST['preferences'] ?? $_GET['preferences'] ?? [];
if (is_string($preferences)) $preferences = explode(',', $preferences);

// ------------------- 讀取 JSON -------------------
$jsonFile = __DIR__ . '/cafes.json';
if (!file_exists($jsonFile)) {
    $cafes = [];
    if (basename($_SERVER['PHP_SELF']) === 'search_mode.php') echo json_encode(['error' => 'cafes.json not found'], JSON_UNESCAPED_UNICODE);
    return;
}

$jsonData = file_get_contents($jsonFile);
$cafes = json_decode($jsonData, true);
if ($cafes === null) {
    $cafes = [];
    if (basename($_SERVER['PHP_SELF']) === 'search_mode.php') echo json_encode(['error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    return;
}

// ------------------- 過濾咖啡廳 -------------------
$cafes = array_filter($cafes, function($cafe) use ($searchMode, $city, $district, $road, $mrt, $preferences) {
    if ($city && isset($cafe['city']) && $cafe['city'] !== $city) return false;

    if ($searchMode === 'address') {
        if ($district && stripos($cafe['address'], $district) === false) return false;
        if ($road && stripos($cafe['address'], $road) === false) return false;
    }

    if ($searchMode === 'mrt') {
        if ($mrt && (!isset($cafe['mrt']) || stripos($cafe['mrt'], $mrt) === false)) return false;
    }

    foreach ($preferences as $pref) {
        $pref = trim($pref);
        if ($pref === 'wifi' && (!isset($cafe['wifi']) || $cafe['wifi'] != "1")) return false;
        if ($pref === 'socket' && (!isset($cafe['socket']) || $cafe['socket'] != "1")) return false;
        if ($pref === 'quiet' && (!isset($cafe['quiet']) || $cafe['quiet'] != "1")) return false;
        if ($pref === 'no_time_limit' && (!isset($cafe['limited_time']) || $cafe['limited_time'] != "0")) return false;
        if ($pref === 'pet_friendly' && (!isset($cafe['pet_friendly']) || $cafe['pet_friendly'] != "1")) return false;
        if ($pref === 'outdoor_seating' && (!isset($cafe['outdoor_seating']) || $cafe['outdoor_seating'] != "1")) return false;
        if ($pref === 'minimum_charge' && (!isset($cafe['minimum_charge']) || $cafe['minimum_charge'] != "1")) return false;
    }

    return true;
});

// 重新索引
$cafes = array_values($cafes);

// ------------------- 直接訪問 echo JSON -------------------
if (basename($_SERVER['PHP_SELF']) === 'search_mode.php') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['cafes' => $cafes], JSON_UNESCAPED_UNICODE);
    exit;
}
