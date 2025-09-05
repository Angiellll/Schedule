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
$city = isset($_GET['city']) ? $_GET['city'] : null;
$district = isset($_GET['district']) ? $_GET['district'] : null;
$road = isset($_GET['road']) ? $_GET['road'] : null;
$mrt = isset($_GET['mrt']) ? $_GET['mrt'] : null;
$preferences = isset($_GET['preferences']) ? explode(',', $_GET['preferences']) : [];

// 過濾 JSON 資料
$filtered = array_filter($cafes, function($cafe) use ($searchMode, $city, $district, $road, $mrt, $preferences) {

    // 搜尋模式：地址
    if ($searchMode === 'address') {
        if ($city && $cafe['city'] !== $city) return false;
        if ($district && (!isset($cafe['district']) || $cafe['district'] !== $district)) return false;
        if ($road && stripos($cafe['address'], $road) === false) return false;
    }

    // 搜尋模式：捷運
    if ($searchMode === 'mrt') {
        if ($mrt && (!isset($cafe['mrt']) || $cafe['mrt'] !== $mrt)) return false;
    }

    // 偏好篩選
    foreach ($preferences as $pref) {
        $pref = trim($pref);

        if ($pref === '限時' && (!isset($cafe['limited_time']) || $cafe['limited_time'] != "1")) return false;
        if ($pref === '不限時' && (!isset($cafe['limited_time']) || $cafe['limited_time'] != "0")) return false;

        if ($pref === '有插座' && (!isset($cafe['socket']) || $cafe['socket'] != "1")) return false;
        if ($pref === '無插座' && (!isset($cafe['socket']) || $cafe['socket'] != "0")) return false;

        if ($pref === '寵物友善' && (!isset($cafe['pet_friendly']) || $cafe['pet_friendly'] != "1")) return false;
        if ($pref === '非寵物友善' && (!isset($cafe['pet_friendly']) || $cafe['pet_friendly'] != "0")) return false;

        if ($pref === '戶外座位' && (!isset($cafe['outdoor_seating']) || $cafe['outdoor_seating'] != "1")) return false;
        if ($pref === '無戶外座位' && (!isset($cafe['outdoor_seating']) || $cafe['outdoor_seating'] != "0")) return false;

        if ($pref === '無低消' && (!isset($cafe['minimum_charge']) || $cafe['minimum_charge'] != "0")) return false;
        if ($pref === '有低消' && (!isset($cafe['minimum_charge']) || $cafe['minimum_charge'] == "0")) return false;
    }

    return true;
});

// 回傳結果
echo json_encode(['cafes' => array_values($filtered)]);
