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

// ============================ 正規化工具 ============================
function norm_city($c) {
    if (!is_string($c) || $c === '') return $c;
    $lc = strtolower(trim($c));
    // 英/拼音 → 中文
    if (in_array($lc, ['taipei','taipei city'])) return '台北市';
    if (in_array($lc, ['xinbei','newtaipei','new_taipei','new taipei','new taipei city'])) return '新北市';
    // 已是中文就回傳原值
    return $c;
}
function is_no_time_limit($v) {
    // 不限時：limited_time = "0" | "no" | "" | null
    if ($v === null) return true;
    $s = strtolower(trim((string)$v));
    return ($s === '0' || $s === 'no' || $s === '');
}
function is_no_min_charge($v) {
    // 無低消：minimum_charge = "0" | "" | null | "無"
    if ($v === null) return true;
    $s = trim((string)$v);
    if ($s === '' || $s === '0') return true;
    return (mb_strpos($s, '無') !== false);
}

$city = norm_city($city);

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
    // --- 城市比對（寬鬆）---
    if ($city) {
        $cafeCity = isset($cafe['city']) ? $cafe['city'] : null;
        // 將資料內 city 也正規化後比對
        $ncafeCity = norm_city($cafeCity);
        $okCity = true;
        if ($ncafeCity) {
            $okCity = ($ncafeCity === $city);
        } else {
            // 若資料沒 city，就用 address 內是否包含城市字眼來判定
            $addr = $cafe['address'] ?? '';
            $okCity = ($addr && mb_strpos($addr, $city) !== false);
        }
        if (!$okCity) return false;
    }

    // --- 地址模式 ---
    if ($searchMode === 'address') {
        if ($district) {
            $addr = $cafe['address'] ?? '';
            if ($addr === '' || stripos($addr, $district) === false) return false;
        }
        if ($road) {
            $addr = $cafe['address'] ?? '';
            if ($addr === '' || stripos($addr, $road) === false) return false;
        }
    }

    // --- 捷運模式 ---
    if ($searchMode === 'mrt') {
        if ($mrt) {
            $cv = $cafe['mrt'] ?? '';
            if ($cv === '' || stripos($cv, $mrt) === false) return false;
        }
    }

    // --- 偏好（僅保留以下 key）---
    foreach ($preferences as $pref) {
        $pref = trim($pref);
        if ($pref === 'socket') {
            if (($cafe['socket'] ?? '') !== "1") return false;
        } elseif ($pref === 'no_time_limit') {
            if (!is_no_time_limit($cafe['limited_time'] ?? null)) return false;
        } elseif ($pref === 'minimum_charge') {
            if (!is_no_min_charge($cafe['minimum_charge'] ?? null)) return false;
        } elseif ($pref === 'outdoor_seating') {
            if (($cafe['outdoor_seating'] ?? '') !== "1") return false;
        } elseif ($pref === 'pet_friendly') {
            if (($cafe['pet_friendly'] ?? '') !== "1") return false;
        }
        // 其餘（wifi/quiet）不在此版本過濾
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
