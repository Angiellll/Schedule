<?php
// ------------------- 基本設定 -------------------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ------------------- 讀取 JSON -------------------
$input = json_decode(file_get_contents('php://input'), true);

$location = $input['location'] ?? '';
$search_mode = $input['searchMode'] ?? 'address';
$preferences = $input['preferences'] ?? [];
$style_preference = $input['style'] ?? '文青';
$time_preference = $input['timePreference'] ?? '標準';
$user_goals = $input['userGoals'] ?? [];
$user_lat = $input['latitude'] ?? null;
$user_lng = $input['longitude'] ?? null;
$cafes = $input['cafes'] ?? [];

// 如果傳進來的是字串 JSON，再 decode
if (is_string($preferences)) $preferences = json_decode($preferences,true) ?? [];
if (is_string($user_goals)) $user_goals = json_decode($user_goals,true) ?? [];
if (is_string($cafes)) $cafes = json_decode($cafes,true) ?? [];

// ------------------- 篩選咖啡廳 -------------------
function filterCafesByPreferences($cafes, $preferences){
    if (empty($preferences)) return $cafes;
    $filtered = [];
    $weightMap = [
        'socket'=>1,
        'no_time_limit'=>1,
        'minimum_charge'=>1,
        'outdoor_seating'=>1,
        'pet_friendly'=>1
    ];
    foreach ($cafes as $cafe){
        $score = 0;
        foreach ($preferences as $pref){
            switch($pref){
                case 'no_time_limit':
                    if(isset($cafe['limited_time']) && $cafe['limited_time']==='0') $score += $weightMap[$pref];
                    break;
                default:
                    if(isset($cafe[$pref]) && $cafe[$pref]==='1') $score += $weightMap[$pref];
            }
        }
        if ($score >= ceil(count($preferences)*0.3)) {
            $cafe['match_score']=$score;
            $filtered[] = $cafe;
        }
    }
    usort($filtered,function($a,$b){ return ($b['match_score']??0) - ($a['match_score']??0); });
    return $filtered;
}

$cafes = filterCafesByPreferences($cafes, $preferences);

// ------------------- 按距離排序 -------------------
function haversine($lat1,$lng1,$lat2,$lng2){
    $earth_radius = 6371;
    $dLat = deg2rad($lat2-$lat1);
    $dLng = deg2rad($lng2-$lng1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2)) * sin($dLng/2)**2;
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

function sortCafesByDistance($cafes, $lat, $lng){
    foreach($cafes as &$cafe){
        if(isset($cafe['latitude']) && isset($cafe['longitude'])){
            $cafe['distance'] = haversine($lat, $lng, $cafe['latitude'], $cafe['longitude']);
        } else $cafe['distance']=9999;
    }
    unset($cafe);
    usort($cafes,function($a,$b){ return $a['distance'] <=> $b['distance']; });
    return $cafes;
}

if ($user_lat !== null && $user_lng !== null) {
    $cafes = sortCafesByDistance($cafes, $user_lat, $user_lng);
}

// ------------------- 時間設定 -------------------
$timeSettings = ["早鳥"=>["start"=>"09:00","end"=>"18:00"], "標準"=>["start"=>"10:00","end"=>"20:00"], "夜貓"=>["start"=>"13:00","end"=>"23:00"]];
$startTime = $timeSettings[$time_preference]["start"] ?? "10:00";
$endTime = $timeSettings[$time_preference]["end"] ?? "20:00";

// ------------------- 生成咖啡廳代號清單 -------------------
$cafe_map = [];
$cafe_list = "";
foreach ($cafes as $index => $cafe){
    $code = "CA".($index+1); // 代號簡化成 CA1, CA2
    $cafe_map[$code] = $cafe['name'];
    
    $features = [];
    if (isset($cafe['socket']) && $cafe['socket']==='1') $features[]='有插座';
    if (isset($cafe['limited_time']) && $cafe['limited_time']==='0') $features[]='不限時';
    if (isset($cafe['minimum_charge']) && $cafe['minimum_charge']==='0') $features[]='無低消';
    if (isset($cafe['outdoor_seating']) && $cafe['outdoor_seating']==='1') $features[]='戶外座位';
    if (isset($cafe['pet_friendly']) && $cafe['pet_friendly']==='1') $features[]='寵物友善';

    $cafe_list .= "$code: ".$cafe['name']."\n";
    $cafe_list .= "   地址: ".($cafe['address'] ?? '未知')."\n";
    if (!empty($cafe['mrt'])) $cafe_list .= "   捷運: ".$cafe['mrt']."\n";
    if (!empty($features)) $cafe_list .= "   特色: ".implode('、', $features)."\n";
    $cafe_list .= "\n";
}

// ------------------- 使用者偏好文字 -------------------
$preference_text = "";
if (!empty($preferences)) {
    $pref_map = [
        'socket' => '有插座',
        'no_time_limit' => '不限時',
        'minimum_charge' => '無低消',
        'outdoor_seating' => '戶外座位',
        'pet_friendly' => '寵物友善'
    ];
    $pref_texts = [];
    foreach ($preferences as $pref) if (isset($pref_map[$pref])) $pref_texts[] = $pref_map[$pref];
    if (!empty($pref_texts)) $preference_text = "用戶偏好: ".implode('、',$pref_texts)."\n";
}

// ------------------- 旅遊目的文字 -------------------
$user_goal_text = "";
if (!empty($user_goals)) $user_goal_text = "旅遊目的/偏好型: ".implode('、',$user_goals)."\n";

// ------------------- 搜尋資訊文字 -------------------
$search_info = $search_mode==='mrt' ? "以捷運站「{$location}」為中心" : "在「{$location}」地區";

// ------------------- GPT Prompt -------------------
$apiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?? "sk-xxxxxx...";

$prompt = "你是一個專業旅遊行程規劃師，請生成一日行程 JSON。
上午安排1間咖啡廳，下午安排1間咖啡廳，其他時段安排景點或自由活動。
規劃地點：{$search_info}
{$preference_text}
{$user_goal_text}
使用者風格：{$style_preference}
時間偏好：{$time_preference}（{$startTime} - {$endTime}）
可用咖啡廳（請只使用以下代號，不可自行生成其他咖啡廳名稱）：
{$cafe_list}

要求：
1. 嚴格使用上述咖啡廳代號，禁止列表外咖啡廳
2. 其他時段安排景點或自由活動，符合使用者地點、旅遊目的、風格與偏好
3. 優化路線避免來回跑
4. 每個行程需說明為何選擇這些咖啡廳或景點，以及如何符合使用者風格、時間偏好與偏好條件
5. 回傳 JSON，格式如下：
{
  \"reason\": \"說明推薦理由\",
  \"itinerary\": [
    {
      \"time\": \"09:00\",
      \"place\": \"咖啡廳或景點代號\",
      \"activity\": \"活動內容\",
      \"transport\": \"步行/交通方式\",
      \"period\": \"morning/afternoon/evening\",
      \"category\": \"cafe/attraction/free_activity\"
    }
  ]
}";

// ------------------- 呼叫 OpenAI -------------------
function callOpenAI($apiKey, $prompt){
    if(empty($apiKey) || $apiKey==="sk-xxxxxx...") return false;
    $ch = curl_init();
    curl_setopt_array($ch,[
        CURLOPT_URL => "https://api.openai.com/v1/chat/completions",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json","Authorization: Bearer {$apiKey}"],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            "model"=>"gpt-3.5-turbo",
            "messages"=>[["role"=>"system","content"=>"你是一個專業旅遊行程規劃師"],["role"=>"user","content"=>$prompt]],
            "temperature"=>0,
            "max_tokens"=>1500
        ])
    ]);
    $response = curl_exec($ch);
    if(curl_errno($ch)){ curl_close($ch); return false; }
    curl_close($ch);
    $data=json_decode($response,true);
    if(!$data || !isset($data['choices'][0]['message']['content'])) return false;
    return $data['choices'][0]['message']['content'];
}

// ------------------- 解析 GPT 回應 -------------------
function parseGPTResponse($raw){
    $json = json_decode($raw,true);
    if($json && isset($json['itinerary'])) return $json;
    return false;
}

// ------------------- Fallback 行程 -------------------
function fallbackItinerary($cafes, $cafe_map){
    $itinerary = [];
    $timeSlots = ['09:00','11:00','13:00','15:00','17:00'];

    if(count($cafes)>0){
        $itinerary[] = [
            'time'=>$timeSlots[0],
            'place'=>$cafes[0]['name'],
            'activity'=>'享用咖啡與輕食，放鬆休息',
            'transport'=>'步行',
            'period'=>'morning',
            'category'=>'cafe'
        ];
    }

    $itinerary[] = [
        'time'=>$timeSlots[1],
        'place'=>'附近景點或自由活動',
        'activity'=>'參觀或探索附近景點，符合使用者偏好與風格',
        'transport'=>'步行/大眾運輸',
        'period'=>'morning',
        'category'=>'free_activity'
    ];

    if(count($cafes)>1){
        $itinerary[] = [
            'time'=>$timeSlots[2],
            'place'=>$cafes[1]['name'],
            'activity'=>'下午茶時間，體驗特色咖啡廳',
            'transport'=>'步行/交通工具',
            'period'=>'afternoon',
            'category'=>'cafe'
        ];
    }

    $itinerary[] = [
        'time'=>$timeSlots[3],
        'place'=>'商場/文創園區/藝文活動',
        'activity'=>'參觀符合旅遊目的或偏好型的景點',
        'transport'=>'步行/大眾運輸',
        'period'=>'afternoon',
        'category'=>'attraction'
    ];

    $itinerary[] = [
        'time'=>$timeSlots[4],
        'place'=>'自由探索夜間活動',
        'activity'=>'夜間散步或小型活動，體驗當地文化',
        'transport'=>'步行',
        'period'=>'evening',
        'category'=>'free_activity'
    ];

    return [
        'reason'=>"使用 fallback 行程，依據提供的咖啡廳列表及使用者地點、偏好自動排程",
        'itinerary'=>$itinerary
    ];
}
// ------------------- 代號強制轉真名 -------------------
function forceCafeName($itinerary, $cafe_map){
    foreach($itinerary as &$item){
        if(!isset($item['place'])) continue;

        // 去除空白、冒號等，抓到代號
        $place = trim($item['place']);
        foreach($cafe_map as $code => $name){
            if(strpos($place, $code) !== false){
                $item['place'] = $name; // 強制轉成真名
                break;
            }
        }
    }
    unset($item);
    return $itinerary;
}

// ------------------- 執行 -------------------
// 呼叫 GPT
$gpt_response = callOpenAI($apiKey, $prompt);
$result = parseGPTResponse($gpt_response);

// 強制把 place 轉成真名
if($result && isset($result['itinerary'])){
    $result['itinerary'] = forceCafeName($result['itinerary'], $cafe_map);
}

// 若 GPT 回傳失敗，使用 fallback
if(!$result){
    $result = fallbackItinerary($cafes, $cafe_map);
}

header('Content-Type: application/json');
echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
?>
