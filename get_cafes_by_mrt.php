<?php
// get_cafes_by_mrt.php

header("Content-Type: application/json; charset=UTF-8");
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

// 共用函數抽出
function loadCafes(): array {
    $raw = @file_get_contents(__DIR__ . '/cafes.json');
    if ($raw === false) throw new Exception('讀取 cafes.json 失敗');
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new Exception('cafes.json 格式錯誤');

    // 0/1 -> boolean
    foreach ($data as &$cafe) {
        $cafe['limited_time'] = isset($cafe['limited_time']) ? ($cafe['limited_time'] == 0) : false;
        $cafe['socket'] = isset($cafe['socket']) ? ($cafe['socket'] == 1) : false;
        $cafe['minimum_charge'] = isset($cafe['minimum_charge']) ? ($cafe['minimum_charge'] == 0) : false;
        $cafe['pet_friendly'] = isset($cafe['pet_friendly']) ? ($cafe['pet_friendly'] == 1) : false;
        $cafe['outdoor_seating'] = isset($cafe['outdoor_seating']) ? ($cafe['outdoor_seating'] == 1) : false;
    }
    unset($cafe);

    return $data;
}

function filterCafes(array $data, string $mrt, array $preferences): array {
    return array_values(array_filter($data, function($cafe) use ($mrt, $preferences) {
        $in_mrt = stripos($cafe['mrt'] ?? '', $mrt) !== false;
        $in_addr = stripos($cafe['address'] ?? '', $mrt) !== false;
        if (!$in_mrt && !$in_addr) return false;

        foreach ($preferences as $pref) {
            switch ($pref) {
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
    }));
}

// 取得參數
$mrt = $_GET['mrt'] ?? '';
$preferences = isset($_GET['preferences']) && $_GET['preferences'] !== ''
    ? explode(',', $_GET['preferences'])
    : [];

if ($mrt === '') {
    http_response_code(400);
    echo json_encode(['error' => '缺少 mrt 參數'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $cafes = loadCafes();
    $results = filterCafes($cafes, $mrt, $preferences);
    echo json_encode($results, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
