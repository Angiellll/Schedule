<?php
header("Content-Type: application/json; charset=UTF-8");
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

// 取得參數
$city_input = $_GET['city'] ?? '';
$district_input = $_GET['district'] ?? '';
$road_input = $_GET['road'] ?? '';
$preferences = isset($_GET['preferences']) && $_GET['preferences'] !== ''
  ? explode(',', $_GET['preferences'])
  : [];

// 基本參數檢查（city / district 至少要有一個）
if ($city_input === '' && $district_input === '') {
    http_response_code(400);
    echo json_encode(['error' => '缺少 city 或 district 參數'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 城市中文 -> JSON 拼音對照表
$city_map = [
    "台北市" => "taipei",
    "新北市" => "xinbei"
];

// 區域中文 -> JSON address 對照表（只做包含比對用）
$district_map = [
    // 台北市
    "中正區"=>"中正區","大同區"=>"大同區","中山區"=>"中山區","松山區"=>"松山區",
    "大安區"=>"大安區","萬華區"=>"萬華區","信義區"=>"信義區","士林區"=>"士林區",
    "北投區"=>"北投區","內湖區"=>"內湖區","南港區"=>"南港區","文山區"=>"文山區",
    // 新北市
    "板橋區"=>"板橋區","新莊區"=>"新莊區","中和區"=>"中和區","永和區"=>"永和區",
    "土城區"=>"土城區","樹林區"=>"樹林區","三峽區"=>"三峽區","鶯歌區"=>"鶯歌區",
    "三重區"=>"三重區","新店區"=>"新店區","深坑區"=>"深坑區","石碇區"=>"石碇區",
    "瑞芳區"=>"瑞芳區","平溪區"=>"平溪區","雙溪區"=>"雙溪區","貢寮區"=>"貢寮區",
    "淡水區"=>"淡水區","汐止區"=>"汐止區","金山區"=>"金山區","八里區"=>"八里區",
    "萬里區"=>"萬里區","三芝區"=>"三芝區","石門區"=>"石門區"
];

$city = $city_map[$city_input] ?? '';
$district = $district_map[$district_input] ?? '';

// 讀取資料
$raw = @file_get_contents(__DIR__ . '/cafes.json');
if ($raw === false) {
    http_response_code(500);
    echo json_encode(['error' => '讀取 cafes.json 失敗'], JSON_UNESCAPED_UNICODE);
    exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(500);
    echo json_encode(['error' => 'cafes.json 格式錯誤'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 0/1 -> boolean
foreach ($data as &$cafe) {
    $cafe['limited_time'] = isset($cafe['limited_time']) ? ($cafe['limited_time'] == 0) : false; // 0=不限時
    $cafe['socket'] = isset($cafe['socket']) ? ($cafe['socket'] == 1) : false;
    $cafe['minimum_charge'] = isset($cafe['minimum_charge']) ? ($cafe['minimum_charge'] == 0) : false; // 0=無低消
    $cafe['pet_friendly'] = isset($cafe['pet_friendly']) ? ($cafe['pet_friendly'] == 1) : false;
    $cafe['outdoor_seating'] = isset($cafe['outdoor_seating']) ? ($cafe['outdoor_seating'] == 1) : false;
}
unset($cafe);

// 篩選
$results = array_filter($data, function($cafe) use ($city, $district, $road_input, $preferences){
    // 城市（cafes.json 的 city 是拼音）
    if ($city && stripos($cafe['city'] ?? '', $city) === false) return false;

    // 區域（在 address 內找中文字）
    if ($district && stripos($cafe['address'] ?? '', $district) === false) return false;

    // 路名（可選）
    if ($road_input && stripos($cafe['address'] ?? '', $road_input) === false) return false;

    // 偏好
    foreach ($preferences as $pref) {
        switch($pref) {
            case "不限時":
                if (empty($cafe['limited_time'])) return false;
                break;
            case "有插座":
                if (empty($cafe['socket'])) return false;
                break;
            case "無低消":
                if (empty($cafe['minimum_charge'])) return false;
                break;
            case "寵物友善":
                if (empty($cafe['pet_friendly'])) return false;
                break;
            case "戶外座位":
                if (empty($cafe['outdoor_seating'])) return false;
                break;
        }
    }

    return true;
});

// 只回傳清單（與前端 Retrofit 對齊）
echo json_encode(array_values($results), JSON_UNESCAPED_UNICODE);
