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
function filter_cafes_by_opening_cutoff($cafes, $startHHmm, $mustIncludeNames = []) {
    $startMin = hhmm_to_minutes($startHHmm);
    if ($startMin === null) return $cafes;

    $must = array_flip($mustIncludeNames ?: []);
    $out = [];
    foreach ($cafes as $c) {
        $name = $c['name'] ?? '';
        $openStr = $c['open_time'] ?? ($c['Open_time'] ?? null);
        $earliest = earliest_open_minutes_from_string($openStr);

        // 規則：一般店家需「最早開門時間 <= 起始時間」；但使用者「必選店」一律保留（即便開晚些，下午可排）
        if (isset($must[$name])) {
            $out[] = $c;
            continue;
        }
        if ($earliest === null || $earliest <= $startMin) {
            $out[] = $c;
        }
    }
    return $out;
}

// ============================ 偏好過濾（去除 wifi/quiet） ============================
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
            // wifi / quiet 已移除
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

$preferences    = ensure_array(pick($input, ['preferences'], []));
$style_pref     = pick($input, ['style'], '文青');
$time_pref      = pick($input, ['time_preference', 'timePreference'], '標準');
$user_goals     = ensure_array(pick($input, ['user_goals', 'userGoals'], []));

$user_lat       = pick($input, ['latitude'], null);
$user_lng       = pick($input, ['longitude'], null);

// 新增：可選日期（yyyy-mm-dd）
$date           = pick($input, ['date'], null);

$mood           = pick($input, ['mood'], 'RELAX');
$weather        = pick($input, ['weather'], 'UNKNOWN');
$start_time     = pick($input, ['start_time', 'startTime'], null);
$duration_hours = (int) pick($input, ['duration_hours', 'durationHours'], 8);

// 候選清單（前端傳來的店）
$cafes          = ensure_array(pick($input, ['cafes'], []));

// 重新計算支援：只用這些店 / 排除這些店
$include_only   = ensure_array(pick($input, ['include_only', 'includeOnly'], [])); // [name, ...]
$exclude        = ensure_array(pick($input, ['exclude'], []));                      // [name, ...]

// 使用者「必選店」（若前端有提供 include_only 或者用戶在 UI 勾選的 cafes）
$must_include   = ensure_array(pick($input, ['must_include', 'mustInclude'], []));
if (empty($must_include) && !empty($include_only)) $must_include = $include_only;

// 限制「必選店」最多 3 間（與 UI 規則一致）
if (count($must_include) > 3) $must_include = array_slice($must_include, 0, 3);

// ============================ 時段設定（起始/結束） ============================
$timeSettings = [
    '早鳥' => ['start' => '09:00', 'end' => '18:00'],
    '標準' => ['start' => '10:00', 'end' => '20:00'],
    '夜貓' => ['start' => '13:00', 'end' => '23:00'],
];
$startTime = $start_time ?: ($timeSettings[$time_pref]['start'] ?? '10:00');
$endTime   = $timeSettings[$time_pref]['end']   ?? '20:00';

// ============================ 候選清單預處理（include/exclude / 偏好 / 開門 / 距離） ============================
// 先做 include/exclude
if (!empty($include_only)) {
    $set = array_flip($include_only);
    $cafes = array_values(array_filter($cafes, fn($c)=> isset($set[$c['name'] ?? ''])));
}
if (!empty($exclude)) {
    $ban = array_flip($exclude);
    $cafes = array_values(array_filter($cafes, fn($c)=> !isset($ban[$c['name'] ?? ''])));
}

// 偏好過濾
$cafes = filter_cafes_by_preferences($cafes, $preferences);

// 依起始時間過濾：營業最早時間 <= 起始時間；但「必選店」一律保留
$cafes_filtered_by_open = filter_cafes_by_opening_cutoff($cafes, $startTime, $must_include);

// 距離排序（若有座標）
if ($user_lat !== null && $user_lng !== null) {
    $cafes_filtered_by_open = sort_cafes_by_distance($cafes_filtered_by_open, $user_lat, $user_lng);
}

if (empty($cafes_filtered_by_open)) {
    echo json_encode([
        'reason'     => '沒有符合條件的咖啡廳（可能開門太晚），請調整時間或偏好後再試。',
        'story'      => '今天也可以先隨意走走，等遇見喜歡的咖啡香再坐下來。',
        'mood'       => $mood,
        'weather'    => $weather,
        'itinerary'  => [],
        'candidates' => [],
        'date'       => $date,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$cafeIndexAll = index_cafes_by_name($cafes);                 // 原始全集索引
$cafeIndex    = index_cafes_by_name($cafes_filtered_by_open); // 開門過濾後索引

// ============================ 準備 LLM Prompt（把「必選店」與硬規則寫死） ============================
$cafe_list_text = '';
foreach ($cafes_filtered_by_open as $cafe) {
    $features = [];
    if (($cafe['socket'] ?? '') === '1')          $features[] = '有插座';
    if (($cafe['limited_time'] ?? '') === '0')    $features[] = '不限時';
    if (($cafe['minimum_charge'] ?? '') === '0')  $features[] = '無低消';
    if (($cafe['outdoor_seating'] ?? '') === '1') $features[] = '戶外座位';
    if (($cafe['pet_friendly'] ?? '') === '1')    $features[] = '寵物友善';

    $cafe_list_text .= ($cafe['name'] ?? '（未命名）') . "\n";
    $cafe_list_text .= "   地址: " . ($cafe['address'] ?? '未知') . "\n";
    if (!empty($cafe['mrt'])) $cafe_list_text .= "   捷運: " . $cafe['mrt'] . "\n";
    if (!empty($features))    $cafe_list_text .= "   特色: " . implode('、', $features) . "\n";
    $cafe_list_text .= "\n";
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
$preference_text = empty($pref_texts) ? "" : "用戶偏好: " . implode('、', $pref_texts) . "\n";

$user_goal_text = empty($user_goals) ? "" : "旅遊目的/偏好型: " . implode('、', $user_goals) . "\n";
$search_info = ($search_mode === 'mrt')
    ? "以捷運站「{$mrt}」為中心"
    : "在「{$location}」地區";

// 必選店文字（最多三間）
$must_include_text = '';
if (!empty($must_include)) {
    $must_include_text = "（以下咖啡廳為使用者指定，**必須全部納入行程**）\n- " . implode("\n- ", $must_include) . "\n";
}

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
      "place": "咖啡廳或景點名稱（咖啡廳必須選自候選清單）",
      "activity": "做什麼",
      "transport": "步行/大眾運輸/Ubike/公車",
      "period": "morning | afternoon | evening",
      "category": "cafe | attraction | free_activity",
      "desc": "可選，1 句小故事或特色"
    }
  ]
}
JSON;

$prompt = <<<PROMPT
你是專業旅遊行程規劃師。請規劃一日行程，需同時考量「心情」「天氣」「使用者風格」「旅遊目的」「時間偏好」，並最小化來回移動。

條件：
- 日期：{$date}
- 規劃地點：{$search_info}
- 心情：{$mood}（LOW/RELAX → 安靜/療癒；HAPPY → 活力/體驗；ROMANTIC → 氛圍/景觀）
- 天氣：{$weather}（RAINY/HUMID/COLD → 優先室內；SUNNY/HOT/WINDY → 優先戶外或通風良好）
- 使用者風格：{$style_pref}
- 時間偏好：{$time_pref}（{$startTime} - {$endTime}）
{$preference_text}{$user_goal_text}
- 咖啡廳（只能從下列名單挑選，嚴禁自行創造名單外的咖啡廳名稱，也不得替換為相似名稱）：
{$cafe_list_text}
{$must_include_text}
硬性規則（必須全部滿足）：
1) **咖啡廳**：上午至少 1 間、下午至少 1 間，且名稱必須精確取自候選清單；若使用者有指定必選店，必須全部納入。
2) **非咖啡廳**：至少安排 2 個時段（category = attraction 或 free_activity）。
3) 依時間窗格由早到晚排序，盡量避免折返；中午正熱（SUNNY/HOT）避免曝曬戶外。
4) 每個項目都要填寫 time/place/activity/transport/period/category，並加入 1 句簡短的 desc。
5) 回覆只輸出 JSON，**不要**任何多餘文字或註解或 markdown。

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

// 嚴格解析 JSON（容錯處理 code fence）
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

// ============================ Fallback 行程（也保證 AM/PM 各一間咖啡） ============================
function fallback_itinerary($cafes_open_ok, $cafes_backup, $time_pref, $mood, $weather) {
    $slots_std    = ['10:00','11:30','13:30','15:30','17:30'];
    $slots_early  = ['09:00','11:00','13:00','15:00','17:00'];
    $slots_late   = ['13:00','14:30','16:30','18:30','20:00'];
    $slots = $slots_std;
    if ($time_pref === '早鳥') $slots = $slots_early;
    if ($time_pref === '夜貓') $slots = $slots_late;

    $preferIndoor = in_array($weather, ['RAINY','HUMID','COLD','UNKNOWN'], true);

    // 選 cafe1（上午優先由開門合格名單）
    $cafe1 = $cafes_open_ok[0] ?? null;

    // 選 cafe2（下午優先：開門合格第二間；若沒有，用備援名單）
    $cafe2 = $cafes_open_ok[1] ?? ($cafes_backup[0] ?? null);

    $it = [];
    if ($cafe1) {
        $it[] = [
            'time' => $slots[0],
            'place' => $cafe1['name'],
            'activity' => '享用咖啡與輕食，放鬆啟動今天',
            'transport' => '步行',
            'period' => 'morning',
            'category' => 'cafe',
            'desc' => ($mood === 'LOW' || $mood === 'RELAX') ? '挑個安靜角落，讓心慢慢沉澱。' : '坐在窗邊，吸收晨間的活力。'
        ];
    }

    $it[] = [
        'time' => $slots[1],
        'place' => $preferIndoor ? '書店 / 展覽' : '公園 / 老街散步',
        'activity' => $preferIndoor ? '逛逛藝文空間，雨天也愜意' : '在綠意或街景間散步拍照',
        'transport' => '步行或大眾運輸',
        'period' => 'morning',
        'category' => 'attraction',
        'desc' => $preferIndoor ? '室內行程，風雨無阻。' : '放慢腳步，感受城市流動。'
    ];

    if ($cafe2) {
        $it[] = [
            'time' => $slots[2],
            'place' => $cafe2['name'],
            'activity' => '午後咖啡與甜點，補充能量',
            'transport' => '步行',
            'period' => 'afternoon',
            'category' => 'cafe',
            'desc' => ($mood === 'ROMANTIC') ? '午後光影最適合一份甜點。' : '來杯手沖，換個心情。'
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
        'activity' => '逛逛周邊或找家喜歡的小店作結',
        'transport' => '步行',
        'period' => 'evening',
        'category' => 'free_activity',
        'desc' => '用輕鬆的步調收尾今天。'
    ];

    return [
        'reason'  => '依時間偏好與天氣安排，上午/下午穿插咖啡廳與活動，兼顧風格與移動效率。',
        'story'   => '從一杯咖啡出發，順著天氣與心情在城市裡漫遊。',
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
    // 備援名單（不符合「起始時間前開門」但仍可作下午用）
    $name_set_ok = array_flip(array_map(fn($c)=>$c['name'] ?? '', $cafes_filtered_by_open));
    $cafes_backup = array_values(array_filter($cafes, fn($c)=>!isset($name_set_ok[$c['name'] ?? ''])));

    $llm_json = fallback_itinerary($cafes_filtered_by_open, $cafes_backup, $time_pref, $mood, $weather);
}

// ============================ 強制後處理：必選店必加入、AM/PM 各一間咖啡 ============================
function time_to_minutes_or_default($t, $def = '12:00') {
    $m = hhmm_to_minutes($t);
    return $m === null ? hhmm_to_minutes($def) : $m;
}
function ensure_am_pm_cafes($plan, $must_include, $cafes_ok, $cafes_all, $time_pref) {
    $slots_std    = ['10:00','11:30','13:30','15:30','17:30'];
    $slots_early  = ['09:00','11:00','13:00','15:00','17:00'];
    $slots_late   = ['13:00','14:30','16:30','18:30','20:00'];
    $slots = $slots_std;
    if ($time_pref === '早鳥') $slots = $slots_early;
    if ($time_pref === '夜貓') $slots = $slots_late;

    $amCut = hhmm_to_minutes('12:00');

    $it = $plan['itinerary'] ?? [];
    if (!is_array($it)) $it = [];

    // 已出現的咖啡館（避免重複）
    $used = [];
    $hasAM = false; $hasPM = false;

    foreach ($it as $idx => $item) {
        $cat = strtolower($item['category'] ?? '');
        $t   = time_to_minutes_or_default($item['time'] ?? '12:00');
        if ($cat === 'cafe') {
            $name = $item['place'] ?? '';
            if ($name) $used[$name] = true;
            if ($t < $amCut) $hasAM = true; else $hasPM = true;
        }
    }

    // 建索引
    $idxOK   = index_cafes_by_name($cafes_ok);
    $idxAll  = index_cafes_by_name($cafes_all);

    // 先放「必選店」
    $queueMust = [];
    foreach ($must_include as $n) {
        if (isset($used[$n])) continue;
        if (isset($idxOK[$n]) || isset($idxAll[$n])) $queueMust[] = $n;
    }

    // 挑選助手
    $pick_from = function($poolIdx, &$used) {
        foreach ($poolIdx as $name => $c) {
            if (!isset($used[$name])) return $name;
        }
        return null;
    };

    // 若缺 AM
    if (!$hasAM) {
        $name = null;
        // 必選店優先
        foreach ($queueMust as $i => $mn) {
            $name = $mn; array_splice($queueMust, $i, 1); break;
        }
        if ($name === null) {
            // 其次開門合格名單
            $name = $pick_from($idxOK, $used);
        }
        if ($name === null) {
            // 最後用全集
            $name = $pick_from($idxAll, $used);
        }
        if ($name !== null) {
            $used[$name] = true;
            $it[] = [
                'time' => $slots[0],
                'place' => $name,
                'activity' => '晨間咖啡，開啟溫柔的一天',
                'transport' => '步行',
                'period' => 'morning',
                'category' => 'cafe',
                'desc' => '挑個靠窗位置，讓陽光與咖啡香喚醒自己。'
            ];
        }
    }

    // 若缺 PM
    if (!$hasPM) {
        $name = null;
        foreach ($queueMust as $i => $mn) {
            if (!isset($used[$mn])) { $name = $mn; array_splice($queueMust, $i, 1); break; }
        }
        if ($name === null) {
            $name = $pick_from($idxOK, $used);
        }
        if ($name === null) {
            $name = $pick_from($idxAll, $used);
        }
        if ($name !== null) {
            $used[$name] = true;
            $it[] = [
                'time' => $slots[2],
                'place' => $name,
                'activity' => '午後咖啡與甜點補充能量',
                'transport' => '步行',
                'period' => 'afternoon',
                'category' => 'cafe',
                'desc' => '午後時光慢下來，讓味蕾與心情一起放鬆。'
            ];
        }
    }

    // 若非咖啡項目不足 2，補上
    $nonCafe = array_values(array_filter($it, fn($x)=>strtolower($x['category'] ?? '')!=='cafe'));
    while (count($nonCafe) < 2) {
        $it[] = [
            'time' => $slots[count($it)%count($slots)],
            'place' => '散步 / 小景點',
            'activity' => '走走看看，拍幾張喜歡的照片',
            'transport' => '步行或大眾運輸',
            'period' => (count($it)%2===0) ? 'morning' : 'afternoon',
            'category' => 'attraction',
            'desc' => '把步調放慢，留點空白給今天。'
        ];
        $nonCafe[] = 1;
    }

    // 依時間排序
    usort($it, function($a,$b){
        $am = time_to_minutes_or_default($a['time'] ?? '12:00');
        $bm = time_to_minutes_or_default($b['time'] ?? '12:00');
        return $am <=> $bm;
    });

    $plan['itinerary'] = $it;
    return $plan;
}

// 執行「必選店納入 + AM/PM 各一間」保證
$llm_json = ensure_am_pm_cafes($llm_json, $must_include, $cafes_filtered_by_open, $cafes, $time_pref);

// 把咖啡廳欄位補進 itinerary（命中候選名時）
$llm_json['itinerary'] = enrich_itinerary_with_cafe_fields($llm_json['itinerary'] ?? [], $cafeIndexAll);

// 補欄位：故事 / 心情 / 天氣 / 日期
if (empty($llm_json['story']))    $llm_json['story']   = '今天就從一杯咖啡開始，保持彈性，跟著天氣與心情走。';
if (empty($llm_json['mood']))     $llm_json['mood']    = $mood;
if (empty($llm_json['weather']))  $llm_json['weather'] = $weather;
$llm_json['date'] = $date;

// 候選清單（3~5 間）
$llm_json['candidates'] = to_candidates($cafes_filtered_by_open, 5);

// ============================ 回傳 ============================
echo json_encode($llm_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
