<?php
// ============================ CORS / Error ============================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================ 讀參數（GET / POST / JSON） ============================
function read_json_input() {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
$in = read_json_input();

$searchMode = $_POST['search_mode'] ?? $_GET['search_mode'] ?? ($in['search_mode'] ?? $in['searchMode'] ?? 'address');
$city       = $_POST['city']        ?? $_GET['city']        ?? ($in['city'] ?? null);
$district   = $_POST['district']    ?? $_GET['district']    ?? ($in['district'] ?? null);
$road       = $_POST['road']        ?? $_GET['road']        ?? ($in['road'] ?? null);
$mrt        = $_POST['mrt']         ?? $_GET['mrt']         ?? ($in['mrt'] ?? null);

// 可選：日期（原樣回傳，前端顯示或後續串天氣用）
$date       = $_POST['date']        ?? $_GET['date']        ?? ($in['date'] ?? null);

// 偏好（支援陣列、逗號字串、JSON字串）
$preferences = $_POST['preferences'] ?? $_GET['preferences'] ?? ($in['preferences'] ?? []);
if (is_string($preferences)) {
    $tmp = json_decode($preferences, true);
    if (is_array($tmp)) $preferences = $tmp;
    else $preferences = array_values(array_filter(array_map('trim', explode(',', $preferences)), 'strlen'));
}
if (!is_array($preferences)) $preferences = [];

// 正規化城市：允許英文簡碼
if (is_string($city)) {
    $lc = strtolower($city);
    if ($lc === 'taipei') $city = '台北市';
    if ($lc === 'xinbei' || $lc === 'newtaipei' || $lc === 'new_taipei') $city = '新北市';
}

// ============================ 讀資料（cafes.json） ============================
$jsonFile = __DIR__ . '/cafes.json';
$cafes = [];
if (file_exists($jsonFile)) {
    $jsonData = file_get_contents($jsonFile);
    $cafes = json_decode($jsonData, true);
    if (!is_array($cafes)) $cafes = [];
}

// ============================ 過濾 ============================
$cafes = array_filter($cafes, function($cafe) use ($searchMode, $city, $district, $road, $mrt, $preferences) {
    // 城市
    if ($city && isset($cafe['city']) && $cafe['city'] !== $city) return false;

    // 地址模式
    if ($searchMode === 'address') {
        if ($district && (!isset($cafe['address']) || stripos($cafe['address'], $district) === false)) return false;
        if ($road && (!isset($cafe['address']) || stripos($cafe['address'], $road) === false)) return false;
    }

    // 捷運模式
    if ($searchMode === 'mrt') {
        if ($mrt && (!isset($cafe['mrt']) || stripos($cafe['mrt'], $mrt) === false)) return false;
    }

    // 偏好（僅保留以下 key）
    foreach ($preferences as $pref) {
        $pref = trim($pref);
        if ($pref === 'socket'           && (($cafe['socket'] ?? '') !== "1")) return false;
        if ($pref === 'no_time_limit'    && (($cafe['limited_time'] ?? '') !== "0")) return false;   // 不限時 => limited_time = "0"
        if ($pref === 'minimum_charge'   && (($cafe['minimum_charge'] ?? '') !== "0")) return false; // 無低消 => minimum_charge = "0"
        if ($pref === 'outdoor_seating'  && (($cafe['outdoor_seating'] ?? '') !== "1")) return false;
        if ($pref === 'pet_friendly'     && (($cafe['pet_friendly'] ?? '') !== "1")) return false;
    }

    return true;
});

// 重新索引
$cafes = array_values($cafes);

// ============================ 回傳 ============================
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'cafes' => $cafes,
    'date'  => $date, // 回傳給前端帶去 generate_itinerary.php 或顯示
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
