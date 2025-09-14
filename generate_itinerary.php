<?php
// ============================ 基本設定（CORS / Error / JSON） ============================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=UTF-8');

// ============================ 工具函式 ============================
function read_json_input() {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
function pick($arr, $keys, $default = null) {
    foreach ($keys as $k) if (array_key_exists($k, $arr)) return $arr[$k];
    return $default;
}
function ensure_array($v) {
    if (is_array($v)) return $v;
    if (is_string($v)) {
        $tmp = json_decode($v, true);
        if (is_array($tmp)) return $tmp;
        if (strpos($v, ',') !== false) return array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen'));
    }
    return [];
}
function haversine($lat1, $lng1, $lat2, $lng2) {
    $earth_radius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2)) * sin($dLng/2)**2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earth_radius * $c;
}
function sort_cafes_by_distance($cafes, $user_lat, $user_lng) {
    foreach ($cafes as &$c) {
        if (!empty($c['latitude']) && !empty($c['longitude'])) {
            $c['distance'] = haversine((float)$user_lat, (float)$user_lng, (float)$c['latitude'], (float)$c['longitude']);
        } else {
            $c['distance'] = 9999;
        }
    }
    unset($c);
    usort($cafes, fn($a,$b)=>($a['distance'] ?? 9999) <=> ($b['distance'] ?? 9999));
    return $cafes;
}

// ============================ 時間解析／開門時間處理 ============================
function hhmm_to_minutes($hhmm) {
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($hhmm), $m)) return null;
    return ((int)$m[1]) * 60 + (int)$m[2];
}
function earliest_open_minutes_from_string($openStr) {
    if (!$openStr) return null;
    preg_match_all('/([01]?\d|2[0-3]):([0-5]\d)/', $openStr, $m);
    if (empty($m[0])) return null;
    $mins = array_map('hhmm_to_minutes', $m[0]);
    $mins = array_values(array_filter($mins, fn($v)=>$v!==null));
    if (empty($mins)) return null;
    sort($mins);
    return $mins[0];
}
/** 嚴格版：不因 must_include 而放過晚開門店（給「第一時段可用咖啡廳」用） */
function filter_cafes_open_by_start_strict($cafes, $startHHmm) {
    $startMin = hhmm_to_minutes($startHHmm);
    if ($startMin === null) return $cafes;
    $out = [];
    foreach ($cafes as $c) {
        $earliest = earliest_open_minutes_from_string($c['open_time'] ?? ($c['Open_time'] ?? null));
        if ($earliest === null || $earliest <= $startMin) $out[] = $c;
    }
    return $out;
}

// ============================ 偏好過濾（此檔改為「可選」：search_mode 已經先過濾） ============================
function filter_cafes_by_preferences($cafes, $preferences) {
    if (empty($preferences)) return $cafes;
    $filtered = [];
    $weightMap = [
        'socket'           => 1,
        'no_time_limit'    => 1, // limited_time === "0"
        'minimum_charge'   => 1, // minimum_charge === "0"
        'outdoor_seating'  => 1,
        'pet_friendly'     => 1,
    ];
    foreach ($cafes as $cafe) {
        $score = 0;
        foreach ($preferences as $pref) {
            switch ($pref) {
                case 'no_time_limit':
                    if (($cafe['limited_time'] ?? '') === '0') $score += ($weightMap[$pref] ?? 0);
                    break;
                case 'minimum_charge':
                    if (($cafe['minimum_charge'] ?? '') === '0') $score += ($weightMap[$pref] ?? 0);
                    break;
                default:
                    if (($cafe[$pref] ?? '') === '1') $score += ($weightMap[$pref] ?? 0);
            }
        }
        if ($score >= max(1, ceil(count($preferences) * 0.3))) {
            $cafe['match_score'] = $score;
            $filtered[] = $cafe;
        }
    }
    usort($filtered, fn($a,$b)=>($b['match_score'] ?? 0) <=> ($a['match_score'] ?? 0));
    return $filtered;
}
function index_cafes_by_name($cafes) {
    $idx = [];
    foreach ($cafes as $c) if (!empty($c['name'])) $idx[$c['name']] = $c;
    return $idx;
}
function enrich_itinerary_with_cafe_fields($itinerary, $cafeIndex) {
    foreach ($itinerary as &$item) {
        if (!empty($item['place']) && isset($cafeIndex[$item['place']])) {
            $c = $cafeIndex[$item['place']];
            $item['address']          = $c['address']          ?? ($item['address'] ?? null);
            $item['mrt']              = $c['mrt']              ?? ($item['mrt'] ?? null);
            $item['limited_time']     = $c['limited_time']     ?? ($item['limited_time'] ?? null);
            $item['socket']           = $c['socket']           ?? ($item['socket'] ?? null);
            $item['pet_friendly']     = $c['pet_friendly']     ?? ($item['pet_friendly'] ?? null);
            $item['outdoor_seating']  = $c['outdoor_seating']  ?? ($item['outdoor_seating'] ?? null);
        }
    }
    unset($item);
    return $itinerary;
}
function build_tags_from_cafe($c) {
    $tags = [];
    if (isset($c['limited_time']))     $tags[] = ($c['limited_time'] === '0') ? '不限時' : '限時';
    if (isset($c['socket']))           $tags[] = ($c['socket'] === '1') ? '有插座' : '無插座';
    if (isset($c['pet_friendly']))     $tags[] = ($c['pet_friendly'] === '1') ? '寵物友善' : '非寵物友善';
    if (isset($c['outdoor_seating']))  $tags[] = ($c['outdoor_seating'] === '1') ? '戶外座位' : '無戶外座位';
    if (isset($c['minimum_charge']))   $tags[] = ($c['minimum_charge'] === '0') ? '無低消' : '有低消';
    return $tags;
}
function to_candidates($cafes, $limit = 5) {
    $out = [];
    $n = 0;
    foreach ($cafes as $c) {
        $out[] = [
            'name'    => $c['name']    ?? '',
            'address' => $c['address'] ?? null,
            'mrt'     => $c['mrt']     ?? null,
            'tags'    => build_tags_from_cafe($c),
        ];
        if (++$n >= max(3, min(5, $limit))) break;
    }
    return $out;
}

// ============================ 讀取輸入（支援 snake / camel） ============================
$input          = read_json_input();

$location       = pick($input, ['location'], '');
$mrt            = pick($input, ['mrt'], '');
$search_mode    = pick($input, ['search_mode', 'searchMode'], 'address');

$preferences    = ensure_array(pick($input, ['preferences'], [])); // ← search_mode 已先過濾，這裡僅作排序用（可保留）
$style_pref     = pick($input, ['style'], '文青');
$time_pref      = pick($input, ['time_preference', 'timePreference'], '標準');
$user_goals     = ensure_array(pick($input, ['user_goals', 'userGoals'], []));

$user_lat       = pick($input, ['latitude'], null);
$user_lng       = pick($input, ['longitude'], null);

$date           = pick($input, ['date'], null);

$mood           = pick($input, ['mood'], 'RELAX');
$weather        = pick($input, ['weather'], 'UNKNOWN');
$start_time     = pick($input, ['start_time', 'startTime'], null);
$duration_hours = (int) pick($input, ['duration_hours', 'durationHours'], 8);

// 候選清單（**來自 search_mode.php 的成品**）
$cafes          = ensure_array(pick($input, ['cafes'], []));

// 重新計算支援：只用這些店 / 排除這些店
$include_only   = ensure_array(pick($input, ['include_only', 'includeOnly'], []));
$exclude        = ensure_array(pick($input, ['exclude'], []));

// 使用者「必選店」（UI 勾選）
$must_include   = ensure_array(pick($input, ['must_include', 'mustInclude'], []));
if (empty($must_include) && !empty($include_only)) $must_include = $include_only;
if (count($must_include) > 3) $must_include = array_slice($must_include, 0, 3);

// ============================ 時段設定（起始/結束） ============================
$timeSettings = [
    '早鳥' => ['start' => '09:00', 'end' => '18:00'],
    '標準' => ['start' => '10:00', 'end' => '20:00'],
    '夜貓' => ['start' => '13:00', 'end' => '23:00'],
];
$startTime = $start_time ?: ($timeSettings[$time_pref]['start'] ?? '10:00');
$endTime   = $timeSettings[$time_pref]['end']   ?? '20:00';

// ============================ 候選清單預處理（include/exclude / 距離） ============================
// （偏好已在 search_mode 做過，不再二次刪減，最多只拿來排序）
if (!empty($include_only)) {
    $set = array_flip($include_only);
    $cafes = array_values(array_filter($cafes, fn($c)=> isset($set[$c['name'] ?? ''])));
}
if (!empty($exclude)) {
    $ban = array_flip($exclude);
    $cafes = array_values(array_filter($cafes, fn($c)=> !isset($ban[$c['name'] ?? ''])));
}

if ($user_lat !== null && $user_lng !== null) {
    $cafes = sort_cafes_by_distance($cafes, $user_lat, $user_lng);
}
$cafeIndexAll = index_cafes_by_name($cafes);

// ======「第一時段可用咖啡廳」：嚴格要求起始時間前（含）開門（不因 must_include 破例）======
$cafes_open_first = filter_cafes_open_by_start_strict($cafes, $startTime);
$cafeIndexOpenFirst = index_cafes_by_name($cafes_open_first);

// 若整體候選為空，直接回覆
if (empty($cafes)) {
    echo json_encode([
        'reason'     => '目前沒有可用的咖啡廳，請調整地址/捷運或偏好後再試。',
        'story'      => '今天也可以先隨意走走，等遇見喜歡的咖啡香再坐下來。',
        'mood'       => $mood,
        'weather'    => $weather,
        'itinerary'  => [],
        'candidates' => [],
        'date'       => $date,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================ 準備 LLM Prompt ============================
$search_info = ($search_mode === 'mrt')
    ? "以捷運站「{$mrt}」為中心"
    : "在「{$location}」地區";

$mkCafeList = function($list) {
    $txt = '';
    foreach ($list as $cafe) {
        $features = [];
        if (($cafe['socket'] ?? '') === '1')          $features[] = '有插座';
        if (($cafe['limited_time'] ?? '') === '0')    $features[] = '不限時';
        if (($cafe['minimum_charge'] ?? '') === '0')  $features[] = '無低消';
        if (($cafe['outdoor_seating'] ?? '') === '1') $features[] = '戶外座位';
        if (($cafe['pet_friendly'] ?? '') === '1')    $features[] = '寵物友善';
        $txt .= ($cafe['name'] ?? '（未命名）') . "\n";
        $txt .= "   地址: " . ($cafe['address'] ?? '未知') . "\n";
        if (!empty($cafe['mrt'])) $txt .= "   捷運: " . $cafe['mrt'] . "\n";
        if (!empty($features))    $txt .= "   特色: " . implode('、', $features) . "\n";
        if (!empty($cafe['open_time'] ?? null)) $txt .= "   營業: " . $cafe['open_time'] . "\n";
        $txt .= "\n";
    }
    return $txt;
};
$cafe_list_text_all   = $mkCafeList($cafes);
$cafe_list_text_first = $mkCafeList($cafes_open_first);

// 必選店文字（最多三間）
$must_include_text = '';
if (!empty($must_include)) {
    $must_include_text = "（以下咖啡廳由使用者指定，**必須全部納入行程**）\n- " . implode("\n- ", $must_include) . "\n";
}

$pref_map = [
    'socket' => '有插座',
    'no_time_limit' => '不限時',
    'minimum_charge' => '無低消',
    'outdoor_seating' => '戶外座位',
    'pet_friendly' => '寵物友善',
];
$pref_texts = [];
foreach ($preferences as $p) if (isset($pref_map[$p])) $pref_texts[] = $pref_map[$p];
$preference_text = empty($pref_texts) ? "" : "用戶偏好（已過濾）: " . implode('、', $pref_texts) . "\n";

// 強結構提示
$schema_hint = <<<JSON
輸出 JSON 結構（鍵名必須完全一致）：
{
  "reason": "為什麼這樣安排（2~4 句）",
  "story": "2~4 句，像旁白一樣描繪今天的步調與氛圍",
  "mood": "RELAX | LOW | HAPPY | ROMANTIC",
  "weather": "SUNNY | RAINY | CLOUDY | WINDY | HOT | COLD | HUMID | UNKNOWN",
  "itinerary": [
    {
      "time": "10:00",
      "place": "咖啡廳或景點名稱（咖啡廳只能選自候選清單；其他景點請填具名地點）",
      "activity": "做什麼",
      "transport": "步行/大眾運輸/Ubike/公車",
      "period": "morning | afternoon | evening",
      "category": "cafe | attraction | free_activity",
      "desc": "可選，1 句小故事或特色"
    }
  ]
}
JSON;

// 旅遊目的/心情/天氣 指引詞
$user_goal_text = empty($user_goals) ? "" : "旅遊目的: " . implode('、', $user_goals) . "\n";
$mood_hint = "心情：{$mood}（LOW → 安撫、甜點；RELAX → 放鬆步調；HAPPY → 活力體驗；ROMANTIC → 氛圍景觀）";
$weather_hint = "天氣：{$weather}（RAINY/HUMID/COLD → 室內比重高；SUNNY/HOT/WINDY → 戶外或通風良好）";

$prompt = <<<PROMPT
你是專業旅遊行程規劃師。請用下列「候選咖啡廳」與條件規劃一日行程，並最小化來回移動。

地點：{$search_info}
日期：{$date}
{$mood_hint}
{$weather_hint}
風格：{$style_pref}
{$user_goal_text}{$preference_text}
時間偏好：{$time_pref}（{$startTime} - {$endTime}）

候選咖啡廳（全部清單；咖啡廳只能從這裡挑）： 
{$cafe_list_text_all}

第一時段可用咖啡廳（**若第一時段是咖啡廳，必須從下列店家挑**；皆為 {$startTime} 前或等於開門）：
{$cafe_list_text_first}
{$must_include_text}
硬性規則（全部必須滿足）：
0) **第一個項目 time 必須等於 {$startTime}。**
   - 若第一個項目是「cafe」，其 place 必須出自「第一時段可用咖啡廳」清單。
   - 若不是「cafe」，請給出具名的景點/場所（如某公園、某市場、某展館、某書店），且位於 {$search_info} 周邊。
1) **咖啡廳**：上午至少 1 間、下午至少 1 間（名稱必須精確取自候選清單；使用者指定的必選店需納入）。
2) **非咖啡廳**：至少 2 個時段（category = attraction 或 free_activity），並呼應「旅遊目的」「風格」；雨天偏室內，心情 LOW 可安排甜點。
3) 由早到晚排序，盡量避免折返；中午正熱（SUNNY/HOT）避免曝曬戶外。
4) 每個項目都要填 time/place/activity/transport/period/category，並加入 1 句簡短 desc。
5) 回覆只輸出 JSON，**不要**多餘文字或 markdown。

{$schema_hint}
PROMPT;

// ============================ 呼叫 OpenAI（可選） ============================
$apiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?? '';

function call_openai_chat($apiKey, $prompt) {
    $payload = [
        "model" => "gpt-3.5-turbo",
        "messages" => [
            ["role" => "system", "content" => "你是一個專業旅遊行程規劃師，只輸出 JSON。"],
            ["role" => "user", "content" => $prompt]
        ],
        "temperature" => 0,
        "max_tokens" => 1500
    ];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://api.openai.com/v1/chat/completions",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer {$apiKey}"
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    $resp = curl_exec($ch);
    if (curl_errno($ch)) { curl_close($ch); return false; }
    curl_close($ch);
    $data = json_decode($resp, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    return $content ?: false;
}
function parse_llm_json($raw) {
    if (!$raw) return false;
    $j = json_decode($raw, true);
    if (is_array($j) && isset($j['itinerary'])) return $j;
    if (preg_match('/\{.*\}/s', $raw, $m)) {
        $j = json_decode($m[0], true);
        if (is_array($j) && isset($j['itinerary'])) return $j;
    }
    return false;
}

// ============================ Fallback（也遵守開場時間與 AM/PM 兩間咖啡） ============================
function fallback_itinerary($cafes, $cafes_open_first, $time_pref, $mood, $weather, $startTime) {
    $slots_std    = ['10:00','11:30','13:30','15:30','17:30'];
    $slots_early  = ['09:00','11:00','13:00','15:00','17:00'];
    $slots_late   = ['13:00','14:30','16:30','18:30','20:00'];
    $slots = $slots_std;
    if ($time_pref === '早鳥') $slots = $slots_early;
    if ($time_pref === '夜貓') $slots = $slots_late;

    $preferIndoor = in_array($weather, ['RAINY','HUMID','COLD','UNKNOWN'], true);

    // 第一筆：先用「第一時段可用咖啡廳」；若沒有就放具名景點（通用）
    $cafe1 = $cafes_open_first[0] ?? null;
    $it = [];
    if ($cafe1) {
        $it[] = [
            'time' => $startTime,
            'place' => $cafe1['name'],
            'activity' => '享用咖啡與輕食，溫柔開場',
            'transport' => '步行',
            'period' => 'morning',
            'category' => 'cafe',
            'desc' => ($mood === 'LOW' || $mood === 'RELAX') ? '挑個安靜角落，讓心慢慢沉澱。' : '坐在窗邊，吸收晨間的活力。'
        ];
    } else {
        $it[] = [
            'time' => $startTime,
            'place' => $preferIndoor ? '附近書店/展場' : '附近公園/市場',
            'activity' => $preferIndoor ? '逛逛書店或展覽，雨天也愜意' : '清晨散步，感受城市甦醒',
            'transport' => '步行',
            'period' => 'morning',
            'category' => 'attraction',
            'desc' => $preferIndoor ? '室內行程，風雨無阻。' : '清新空氣喚醒一天。'
        ];
    }

    // 其餘安排（確保 PM 有一間咖啡）
    $secondCafe = $cafes[1] ?? ($cafes[0] ?? null);
    $it[] = [
        'time' => $slots[1],
        'place' => $preferIndoor ? '書店 / 展覽' : '公園 / 老街散步',
        'activity' => $preferIndoor ? '看展或翻翻新書' : '在綠意或街景間拍照',
        'transport' => '步行或大眾運輸',
        'period' => 'morning',
        'category' => 'attraction',
        'desc' => $preferIndoor ? '換個空間轉換心情。' : '把步調放慢，留點空白。'
    ];
    if ($secondCafe) {
        $it[] = [
            'time' => $slots[2],
            'place' => $secondCafe['name'],
            'activity' => '午後咖啡與甜點，補充能量',
            'transport' => '步行',
            'period' => 'afternoon',
            'category' => 'cafe',
            'desc' => '午後光影最適合一份甜點。'
        ];
    }
    $it[] = [
        'time' => $slots[3],
        'place' => $preferIndoor ? '美術館 / 商場' : '河濱 / 步道',
        'activity' => $preferIndoor ? '逛逛展區或窗逛放空' : '沿著水岸或樹蔭慢行',
        'transport' => '步行或大眾運輸',
        'period' => 'afternoon',
        'category' => 'attraction',
        'desc' => $preferIndoor ? '遇雨也能優雅漫遊。' : '傍晚風起，最舒服的時刻。'
    ];
    $it[] = [
        'time' => $slots[4],
        'place' => '自由活動',
        'activity' => '找家喜歡的小店作結',
        'transport' => '步行',
        'period' => 'evening',
        'category' => 'free_activity',
        'desc' => '用輕鬆的步調收尾今天。'
    ];

    return [
        'reason'  => '依時間偏好與天氣安排，上午/下午穿插咖啡廳與活動，兼顧風格與移動效率。',
        'story'   => '從第一刻準時開場，順著天氣與心情在城市裡漫遊。',
        'mood'    => $mood,
        'weather' => $weather,
        'itinerary' => $it
    ];
}

// ============================ 產生行程：試 LLM → 失敗用 fallback ============================
$useLLM = !empty($apiKey);
$llm_json = null;

if ($useLLM) {
    $raw = call_openai_chat($apiKey, $prompt);
    $llm_json = parse_llm_json($raw);
}
if (!$llm_json) {
    $llm_json = fallback_itinerary($cafes, $cafes_open_first, $time_pref, $mood, $weather, $startTime);
}

// ============================ 後處理：對齊起始時間 / 第一筆若為咖啡廳必須早開 / AM/PM 各一間 ============================
function time_to_minutes_or_default($t, $def = '12:00') {
    $m = hhmm_to_minutes($t);
    return $m === null ? hhmm_to_minutes($def) : $m;
}
function snap_first_morning_to_start(array $it, string $time_pref): array {
    $start = ['標準'=>'10:00','早鳥'=>'09:00','夜貓'=>'13:00'][$time_pref] ?? '10:00';
    $startMin = hhmm_to_minutes($start);
    // 找最早的「morning」項目
    $idx = null; $best = PHP_INT_MAX;
    foreach ($it as $i => $item) {
        if (strtolower($item['period'] ?? '') === 'morning') {
            $t = time_to_minutes_or_default($item['time'] ?? '12:00');
            if ($t < $best) { $best = $t; $idx = $i; }
        }
    }
    if ($idx !== null) $it[$idx]['time'] = $start;

    // 若有更早於起始的時間，夾到起始
    foreach ($it as $i => $item) {
        $t = time_to_minutes_or_default($item['time'] ?? '12:00');
        if ($t < $startMin) $it[$i]['time'] = $start;
    }
    usort($it, fn($a,$b)=> time_to_minutes_or_default($a['time'] ?? '12:00') <=> time_to_minutes_or_default($b['time'] ?? '12:00'));
    return $it;
}
function ensure_first_slot_if_cafe_open(array $plan, array $cafes_open_first, string $time_pref, string $startTime): array {
    $it = $plan['itinerary'] ?? [];
    if (!is_array($it) || empty($it)) return $plan;

    // 取得第一個 morning 項目
    $firstIdx = null; $firstT = PHP_INT_MAX;
    foreach ($it as $i => $item) {
        if (strtolower($item['period'] ?? '') === 'morning') {
            $t = time_to_minutes_or_default($item['time'] ?? $startTime);
            if ($t < $firstT) { $firstT = $t; $firstIdx = $i; }
        }
    }
    if ($firstIdx === null) return $plan;

    // 對齊時間
    $it[$firstIdx]['time'] = $startTime;

    // 若是 cafe：必須從「第一時段可用咖啡廳」挑
    if (strtolower($it[$firstIdx]['category'] ?? '') === 'cafe') {
        $okNames = array_flip(array_map(fn($c)=>$c['name'] ?? '', $cafes_open_first));
        $cur = $it[$firstIdx]['place'] ?? '';
        if (!$cur || !isset($okNames[$cur])) {
            // 換成第一個可用的
            foreach ($cafes_open_first as $c) {
                if (!empty($c['name'])) {
                    $it[$firstIdx]['place'] = $c['name'];
                    break;
                }
            }
        }
        // 如果連可用清單都沒有，改成具名 attraction（保底）
        if (empty($it[$firstIdx]['place'])) {
            $it[$firstIdx]['category'] = 'attraction';
            $it[$firstIdx]['place'] = '附近書店/展覽或公園';
            $it[$firstIdx]['activity'] = '清晨散步或看書展';
            $it[$firstIdx]['transport'] = '步行';
            $it[$firstIdx]['desc'] = '以輕鬆步調展開今天。';
        }
    }
    $plan['itinerary'] = $it;
    return $plan;
}
function ensure_am_pm_cafes($plan, $must_include, $cafes_open_first, $cafes_all, $time_pref) {
    $slots_std    = ['10:00','11:30','13:30','15:30','17:30'];
    $slots_early  = ['09:00','11:00','13:00','15:00','17:00'];
    $slots_late   = ['13:00','14:30','16:30','18:30','20:00'];
    $slots = $slots_std;
    if ($time_pref === '早鳥') $slots = $slots_early;
    if ($time_pref === '夜貓') $slots = $slots_late;

    $amCut = hhmm_to_minutes('12:00');
    $it = $plan['itinerary'] ?? [];
    if (!is_array($it)) $it = [];

    $used = [];
    $hasAM = false; $hasPM = false;
    foreach ($it as $item) {
        $cat = strtolower($item['category'] ?? '');
        $t   = time_to_minutes_or_default($item['time'] ?? '12:00');
        if ($cat === 'cafe') {
            $name = $item['place'] ?? '';
            if ($name) $used[$name] = true;
            if ($t < $amCut) $hasAM = true; else $hasPM = true;
        }
    }

    $idxOpen = index_cafes_by_name($cafes_open_first);
    $idxAll  = index_cafes_by_name($cafes_all);

    // AM：優先用「第一時段可用」→ 再來必選 → 最後全集
    if (!$hasAM) {
        $name = null;
        foreach ($idxOpen as $n => $_) { if (empty($used[$n])) { $name = $n; break; } }
        if ($name === null) foreach ($must_include as $n) { if (isset($idxAll[$n]) && empty($used[$n])) { $name = $n; break; } }
        if ($name === null) foreach ($idxAll as $n => $_) { if (empty($used[$n])) { $name = $n; break; } }
        if ($name !== null) {
            $it[] = [
                'time' => $slots[0],
                'place' => $name,
                'activity' => '晨間咖啡',
                'transport' => '步行',
                'period' => 'morning',
                'category' => 'cafe',
                'desc' => '從香氣開始。'
            ];
            $used[$name] = true;
        }
    }

    // PM：先必選 → 再 open/全集
    if (!$hasPM) {
        $name = null;
        foreach ($must_include as $n) { if (isset($idxAll[$n]) && empty($used[$n])) { $name = $n; break; } }
        if ($name === null) foreach ($idxAll as $n => $_) { if (empty($used[$n])) { $name = $n; break; } }
        if ($name !== null) {
            $it[] = [
                'time' => $slots[2],
                'place' => $name,
                'activity' => '午後咖啡與甜點',
                'transport' => '步行',
                'period' => 'afternoon',
                'category' => 'cafe',
                'desc' => '午後時光慢下來。'
            ];
            $used[$name] = true;
        }
    }

    // 依時間排序
    usort($it, fn($a,$b)=> time_to_minutes_or_default($a['time'] ?? '12:00') <=> time_to_minutes_or_default($b['time'] ?? '12:00'));
    $plan['itinerary'] = $it;
    return $plan;
}

// 對齊起始時間
$llm_json['itinerary'] = snap_first_morning_to_start($llm_json['itinerary'] ?? [], $time_pref);
// 第一筆若是咖啡廳，必須用早開清單
$llm_json = ensure_first_slot_if_cafe_open($llm_json, $cafes_open_first, $time_pref, $startTime);
// AM/PM 各一間咖啡
$llm_json = ensure_am_pm_cafes($llm_json, $must_include, $cafes_open_first, $cafes, $time_pref);

// 補欄位/候選
$llm_json['itinerary'] = enrich_itinerary_with_cafe_fields($llm_json['itinerary'] ?? [], $cafeIndexAll);
if (empty($llm_json['story']))    $llm_json['story']   = '第一刻準時出發，依心情與天氣在城市輕鬆漫遊。';
if (empty($llm_json['mood']))     $llm_json['mood']    = $mood;
if (empty($llm_json['weather']))  $llm_json['weather'] = $weather;
$llm_json['date'] = $date;

// 候選清單（給前端顯示/再調整）
$llm_json['candidates'] = to_candidates($cafes, 5);

// ============================ 回傳 ============================
echo json_encode($llm_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
