<?php
// 允許跨域
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ------------------- 讀取參數 -------------------
$searchMode  = $_POST['search_mode'] ?? $_GET['search_mode'] ?? 'address'; // address | mrt
$city        = $_POST['city']        ?? $_GET['city']        ?? null;       // e.g. taipei / xinbei / ...
$district    = $_POST['district']    ?? $_GET['district']    ?? null;       // 中文區名（模糊比對 address）
$road        = $_POST['road']        ?? $_GET['road']        ?? null;       // 路名（模糊比對 address）
$mrt         = $_POST['mrt']         ?? $_GET['mrt']         ?? null;       // 站名（模糊比對 cafe.mrt）
$preferences = $_POST['preferences'] ?? $_GET['preferences'] ?? [];         // CSV: socket,no_time_limit,...

// 將 preferences 統一成陣列（英文 key）
if (is_string($preferences)) {
    $preferences = array_filter(array_map('trim', explode(',', $preferences)), fn($v) => $v !== '');
}

// ------------------- 讀取 JSON -------------------
$jsonFile = __DIR__ . '/cafes.json';
$cafes = [];
if (file_exists($jsonFile)) {
    $jsonData = file_get_contents($jsonFile);
    $cafes = json_decode($jsonData, true);
    if (!is_array($cafes)) $cafes = [];
}

// 安全的字串判斷（null-safe stripos）
function contains_ci(?string $haystack, ?string $needle): bool {
    if ($haystack === null || $needle === null || $needle === '') return false;
    return (stripos($haystack, $needle) !== false);
}

// ------------------- 過濾咖啡廳 -------------------
$cafes = array_values(array_filter($cafes, function($cafe) use ($searchMode, $city, $district, $road, $mrt, $preferences) {
    // 1) 城市（完全相等）
    if ($city && isset($cafe['city']) && $cafe['city'] !== $city) return false;

    // 2) 地址 or 捷運
    if ($searchMode === 'address') {
        if ($district && !contains_ci($cafe['address'] ?? '', $district)) return false;
        if ($road && !contains_ci($cafe['address'] ?? '', $road)) return false;
    } else if ($searchMode === 'mrt') {
        if ($mrt && !contains_ci($cafe['mrt'] ?? '', $mrt)) return false;
    }

    // 3) 偏好（只保留你指定的五個）
    //    limited_time：1=有限時、0=不限時（若用戶選 no_time_limit，則 limited_time 必須為 "0"）
    //    minimum_charge：1=有低消、0=無低消（若用戶選 minimum_charge，代表要「無低消」，所以必須為 "0"）
    $prefSet = array_flip($preferences); // O(1) 查詢
    // 有插座
    if (isset($prefSet['socket']) && (($cafe['socket'] ?? '0') !== "1")) return false;
    // 不限時
    if (isset($prefSet['no_time_limit']) && (($cafe['limited_time'] ?? '1') !== "0")) return false;
    // 無低消
    if (isset($prefSet['minimum_charge']) && (($cafe['minimum_charge'] ?? '1') !== "0")) return false;
    // 戶外座位
    if (isset($prefSet['outdoor_seating']) && (($cafe['outdoor_seating'] ?? '0') !== "1")) return false;
    // 寵物友善
    if (isset($prefSet['pet_friendly']) && (($cafe['pet_friendly'] ?? '0') !== "1")) return false;

    return true;
}));

// ------------------- 回傳 JSON -------------------
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['cafes' => $cafes], JSON_UNESCAPED_UNICODE);
