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

// ============================ 正規化/比對工具 ============================
function norm_city($c) {
    if (!is_string($c) || $c === '') return $c;
    $lc = strtolower(trim($c));
    if (in_array($lc, ['taipei','taipei city'])) return '台北市';
    if (in_array($lc, ['xinbei','newtaipei','new_taipei','new taipei','new taipei city'])) return '新北市';
    return $c;
}
function is_no_time_limit($v) {
    if ($v === null) return true;
    $s = strtolower(trim((string)$v));
    return ($s === '0' || $s === 'no' || $s === '');
}
function is_no_min_charge($v) {
    if ($v === null) return true;
    $s = trim((string)$v);
    if ($s === '' || $s === '0') return true;
    return (mb_strpos($s, '無') !== false);
}

// ---- 捷運站名規範化與精確比對 ----
function mrt_normalize($s) {
    $s = (string)$s;
    // 移除空白（含全形）、"捷運"、括號內容
    $s = preg_replace('/\s+/u', '', $s);
    $s = str_replace(['　','捷運'], ['', ''], $s);
    $s = preg_replace('/（.*?）|\(.*?\)/u', '', $s);
    // 臺/台一致、去掉尾端「站」
    $s = str_replace('臺', '台', $s);
    $s = rtrim($s, "站");
    return $s;
}
/** 把一個欄位的捷運資訊拆成陣列（逗號/斜線/豎線/頓號/空白皆可） */
function mrt_field_to_list($mrtField) {
    if (is_array($mrtField)) {
        $s = implode(',', array_map('strval', $mrtField));
    } else {
        $s = (string)$mrtField;
    }
    $s = str_replace(['｜','|','、',';','；'], ',', $s);
    $parts = array_filter(array_map('trim', preg_split('/[,\s\/]+/u', $s)), 'strlen');
    return array_values($parts);
}
/** 精確站名命中：只接受「中山 / 中山站」=「中山」，不會吃到「中山國小」等 */
function mrt_exact_match($mrtField, $queryMrt) {
    if ($queryMrt === null || $queryMrt === '') return true; // 無查詢就不過濾
    $q = mrt_normalize($queryMrt);
    $list = mrt_field_to_list($mrtField);
    foreach ($list as $raw) {
        if (mrt_normalize($raw) === $q) return true;
    }
    return false;
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
        $ncafeCity = norm_city($cafeCity);
        $okCity = true;
        if ($ncafeCity) {
            $okCity = ($ncafeCity === $city);
        } else {
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

    // --- 捷運模式（精確比對） ---
    if ($searchMode === 'mrt') {
        if (!mrt_exact_match($cafe['mrt'] ?? '', (string)$mrt)) return false;
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
        // 其餘（wifi/quiet）本版本不過濾
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
