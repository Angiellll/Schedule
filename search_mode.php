<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);
error_reporting(E_ALL);
ini_set('display_errors', 1);

mb_internal_encoding('UTF-8');

function norm_city($city) {
    if (!$city) return null;
    $c = trim(mb_strtolower($city));
    // 前端可能丟 taipei/xinbei/中文，這裡一律對應回中文城市
    if ($c === 'taipei' || $c === '臺北市' || $c === '台北市') return '台北市';
    if ($c === 'xinbei' || $c === '新北市') return '新北市';
    // 其他城市就原樣回傳（例如你未來擴張）
    return $city;
}
function mb_contains($haystack, $needle) {
    if ($needle === null || $needle === '') return true;
    return mb_stripos($haystack ?? '', $needle) !== false;
}

$searchMode = $_POST['search_mode'] ?? $_GET['search_mode'] ?? 'address';
$city       = norm_city($_POST['city'] ?? $_GET['city'] ?? null);
$district   = $_POST['district'] ?? $_GET['district'] ?? null;
$road       = $_POST['road'] ?? $_GET['road'] ?? null;
$mrt        = $_POST['mrt'] ?? $_GET['mrt'] ?? null;

// 只保留：socket/no_time_limit/minimum_charge/outdoor_seating/pet_friendly
$preferences = $_POST['preferences'] ?? $_GET['preferences'] ?? [];
if (is_string($preferences)) {
    $preferences = array_filter(array_map('trim', explode(',', $preferences)));
}

$jsonFile = __DIR__ . '/cafes.json';
$cafes = [];
if (file_exists($jsonFile)) {
    $cafes = json_decode(file_get_contents($jsonFile), true) ?: [];
}

$cafes = array_values(array_filter($cafes, function($cafe) use ($searchMode,$city,$district,$road,$mrt,$preferences){
    // 城市（容錯：若 cafe 沒 city 欄就放過）
    if ($city && isset($cafe['city']) && $cafe['city'] !== $city) return false;

    if ($searchMode === 'address') {
        if ($district && !mb_contains($cafe['address'] ?? '', $district)) return false;
        if ($road && !mb_contains($cafe['address'] ?? '', $road)) return false;
    } else { // mrt
        if ($mrt && !mb_contains($cafe['mrt'] ?? '', $mrt)) return false;
    }

    // 偏好（用你的 DB 規則：1=有/是、0=無/不限）
    foreach ($preferences as $pref) {
        switch ($pref) {
            case 'socket':
                if (($cafe['socket'] ?? '0') !== "1") return false; break;
            case 'no_time_limit':
                if (($cafe['limited_time'] ?? '1') !== "0") return false; break;
            case 'minimum_charge':
                // 你說的是「有低消=1、無低消=0」，而 chips 是「無低消」
                if (($cafe['minimum_charge'] ?? '1') !== "0") return false; break;
            case 'outdoor_seating':
                if (($cafe['outdoor_seating'] ?? '0') !== "1") return false; break;
            case 'pet_friendly':
                if (($cafe['pet_friendly'] ?? '0') !== "1") return false; break;
            default:
                // 忽略未支援的鍵
                break;
        }
    }
    return true;
}));

if (basename($_SERVER['PHP_SELF']) === 'search_mode.php') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['cafes' => $cafes], JSON_UNESCAPED_UNICODE);
    exit;
}
return $cafes;
