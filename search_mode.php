<?php
header('Content-Type: application/json; charset=utf-8');

// cafes.json 路徑
$jsonFile = __DIR__ . '/cafes.json';

// 讀取 JSON
if (!file_exists($jsonFile)) {
    echo json_encode(['error' => 'cafes.json not found']);
    exit;
}

$jsonData = file_get_contents($jsonFile);
$cafes = json_decode($jsonData, true);
if ($cafes === null) {
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// 取得搜尋條件
$searchMode = isset($_GET['search_mode']) ? $_GET['search_mode'] : 'address';
$city = isset($_GET['city']) ? $_GET['city'] : null;       // taipei / xinbei
$district = isset($_GET['district']) ? $_GET['district'] : null; // 中文區域，例如 "士林區"
$road = isset($_GET['road']) ? $_GET['road'] : null;
$mrt = isset($_GET['mrt']) ? $_GET['mrt'] : null;
$preferences = isset($_GET['preferences']) ? explode(',', $_GET['preferences']) : [];

// 過濾 JSON 資料
$filtered = array_filter($cafes, function($cafe) use ($searchMode, $city, $district, $road, $mrt, $preferences) {

    // 城市篩選
    if ($city && isset($cafe['city']) && $cafe['city'] !== $city) return false;

    // 搜尋模式：地址
    if ($searchMode === 'address') {
        if ($district && stripos($cafe['address'], $district) === false) return false;
        if ($road && stripos($cafe['address'], $road) === false) return false;
    }

    // 搜尋模式：捷運
    if ($searchMode === 'mrt') {
        if ($mrt && (!isset($cafe['mrt']) || stripos($cafe['mrt'], $mrt) === false)) return false;
    }

    // 偏好篩選 (英文 key 對應 generate_itinerary.php)
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

// 回傳結果
echo json_encode(['cafes' => array_values($filtered)], JSON_UNESCAPED_UNICODE);
