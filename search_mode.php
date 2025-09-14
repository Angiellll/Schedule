<?php
// ============================ CORS / Error ============================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

// ============================ 讀參數 ============================
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
$date       = $_POST['date']        ?? $_GET['date']        ?? ($in['date'] ?? null);

// 偏好（支援陣列 / 逗號字串 / JSON）
$preferences = $_POST['preferences'] ?? $_GET['preferences'] ?? ($in['preferences'] ?? []);
if (is_string($preferences)) {
    $tmp = json_decode($preferences, true);
    if (is_array($tmp)) $preferences = $tmp;
    else $preferences = array_values(array_filter(array_map('trim', explode(',', $preferences)), 'strlen'));
}
if (!is_array($preferences)) $preferences = [];

// ============================ 正規化工具 ============================
function norm_city_in($c) {
    if (!is_string($c) || $c === '') return null;
    $lc = mb_strtolower(trim($c));
    // 統一成資料庫用的小寫拼音
    if (in_array($lc, ['台北市','臺北市','taipei','taipei city'])) return 'taipei';
    if (in_array($lc, ['新北市','newtaipei','new taipei','new taipei city','xinbei'])) return 'xinbei';
    return $lc;
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
function norm_mrt($s) {
    if (!is_string($s)) return '';
    // 去掉括號內容
    $s = preg_replace('/（.*?）|\(.*?\)/u', '', $s);
    // 去掉「7號出口」這類尾綴
    $s = preg_replace('/\d+\s*號出口/u', '', $s);
    $s = str_replace(['出口','號'], '', $s);
    // 去常見字樣
    $s = str_replace(['台北捷運','捷運','車站','站'], '', $s);
    // 去分隔與空白
    $s = preg_replace('/\s|　|\/|｜|\||、|，|,|;|-|‧|·/u', '', $s);
    return mb_strtolower(trim($s));
}

$cityNorm = norm_city_in($city);
$mrtNormQ = norm_mrt($mrt);

// ============================ 讀資料 ============================
$jsonFile = __DIR__ . '/cafes.json';
$cafes = [];
if (file_exists($jsonFile)) {
    $jsonData = file_get_contents($jsonFile);
    $cafes = json_decode($jsonData, true);
    if (!is_array($cafes)) $cafes = [];
}

// ============================ 過濾 ============================
$cafes = array_values(array_filter($cafes, function($cafe) use ($searchMode, $cityNorm, $district, $road, $mrtNormQ, $preferences) {
    // 城市（用欄位 city 比對；若無再退回 address 包含）
    if ($cityNorm) {
        $cc = isset($cafe['city']) ? mb_strtolower($cafe['city']) : null;
        $okCity = true;
        if ($cc) $okCity = ($cc === $cityNorm);
        else {
            $addr = $cafe['address'] ?? '';
            $okCity = $addr && (
                ($cityNorm === 'taipei' && (mb_strpos($addr, '台北市') !== false || mb_strpos($addr, '臺北市') !== false)) ||
                ($cityNorm === 'xinbei' && (mb_strpos($addr, '新北市') !== false))
            );
        }
        if (!$okCity) return false;
    }

    if ($searchMode === 'address') {
        if ($district) {
            $addr = $cafe['address'] ?? '';
            if ($addr === '' || stripos($addr, $district) === false) return false;
        }
        if ($road) {
            $addr = $cafe['address'] ?? '';
            if ($addr === '' || stripos($addr, $road) === false) return false;
        }
    } else { // mrt 模式（嚴格比對，不降級）
        if (!$mrtNormQ) return false;
        $cv = $cafe['mrt'] ?? '';
        if ($cv === '') return false;
        $norm = norm_mrt($cv);
        if ($norm === '' || $norm !== $mrtNormQ) return false; // **等值比對**
    }

    // 偏好
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
    }
    return true;
}));

echo json_encode([
    'cafes' => $cafes,
    'date'  => $date,
    'debug' => [
        'search_mode' => $searchMode,
        'city_norm'   => $cityNorm,
        'mrt_query'   => $mrt,
        'mrt_norm'    => $mrtNormQ,
        'count'       => count($cafes),
        'strict_mrt'  => true,   // 一律嚴格，不做降級
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
