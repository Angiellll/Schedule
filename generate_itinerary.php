<?php
// ------------------- 基本設定 -------------------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ------------------- 讀取參數 -------------------
$location = $_POST['location'] ?? $_GET['location'] ?? '';
$search_mode = $_POST['search_mode'] ?? $_GET['search_mode'] ?? 'address';
$preferences = $_POST['preferences'] ?? $_GET['preferences'] ?? [];
if (is_string($preferences)) $preferences = json_decode($preferences,true) ?? explode(',', $preferences);

$style_preference = $_POST['style'] ?? $_GET['style'] ?? '文青';
$time_preference = $_POST['time_preference'] ?? $_GET['time_preference'] ?? '標準';
$user_goals = $_POST['user_goals'] ?? $_GET['user_goals'] ?? [];
if (is_string($user_goals)) $user_goals = json_decode($user_goals,true) ?? explode(',', $user_goals);

$user_lat = $_POST['latitude'] ?? $_GET['latitude'] ?? null;  
$user_lng = $_POST['longitude'] ?? $_GET['longitude'] ?? null;

// ------------------- include search_mode.php -------------------
$searchModeParam = ($search_mode==='mrt') ? 'mrt' : 'address';
$searchParams = [
    'search_mode' => $searchModeParam,
    'city' => $location,
    'district' => $location,
    'mrt' => $location,
    'preferences' => implode(',', $preferences)
];

$_GET = $_GET + $searchParams;
$_POST = $_POST + $searchParams;

$searchModePath = __DIR__ . '/search_mode.php';
if(!file_exists($searchModePath)){
    echo json_encode(["reason"=>"search_mode.php 不存在","itinerary"=>[]], JSON_UNESCAPED_UNICODE);
    exit;
}

$cafes = include($searchModePath);
if (!is_array($cafes)) $cafes = [];

// ------------------- 篩選咖啡廳 -------------------
$cafes = filterCafesByPreferences($cafes, $preferences);

// ------------------- 時間設定 -------------------
$timeSettings = ["早鳥"=>["start"=>"09:00","end"=>"18:00"], "標準"=>["start"=>"10:00","end"=>"20:00"], "夜貓"=>["start"=>"13:00","end"=>"23:00"]];
$startTime = $timeSettings[$time_preference]["start"] ?? "10:00";
$endTime = $timeSettings[$time_preference]["end"] ?? "20:00";

// ------------------- 按距離排序 -------------------
if ($user_lat !== null && $user_lng !== null) {
    $cafes = sortCafesByDistance($cafes, $user_lat, $user_lng);
}

// ------------------- 準備咖啡廳文字清單 -------------------
$cafe_list = "";
foreach ($cafes as $index => $cafe) {
    $features = [];
    if (isset($cafe['wifi']) && $cafe['wifi']==='1') $features[]='WiFi';
    if (isset($cafe['socket']) && $cafe['socket']==='1') $features[]='插座';
    if (isset($cafe['quiet']) && $cafe['quiet']==='1') $features[]='安靜';
    if (isset($cafe['limited_time']) && $cafe['limited_time']==='0') $features[]='不限時';
    if (isset($cafe['pet_friendly']) && $cafe['pet_friendly']==='1') $features[]='寵物友善';
    $cafe_list .= ($index+1).". ".$cafe['name']."\n";
    $cafe_list .= "   地址: ".($cafe['address'] ?? '未知')."\n";
    if (!empty($cafe['mrt'])) $cafe_list .= "   捷運: ".$cafe['mrt']."\n";
    if (!empty($features)) $cafe_list .= "   特色: ".implode('、', $features)."\n";
    $cafe_list .= "\n";
}

// ------------------- 使用者偏好文字 -------------------
$preference_text = "";
if (!empty($preferences)) {
    $pref_map = ['quiet'=>'安靜環境','socket'=>'有插座','no_time_limit'=>'不限時','wifi'=>'WiFi','pet_friendly'=>'寵物友善','outdoor_seating'=>'戶外座位','minimum_charge'=>'有低消'];
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

$prompt = "你是一個專業旅遊行程規劃師，請生成一日行程 JSON，上午安排1間咖啡廳，下午1間咖啡廳，其他時間安排自由活動。
規劃地點：{$search_info}
{$preference_text}
{$user_goal_text}
使用者風格：{$style_preference}
時間偏好：{$time_preference}（{$startTime} - {$endTime}）
可用咖啡廳：
{$cafe_list}

要求：
1. 從上述提供的咖啡廳列表中挑選，不可使用列表外的咖啡廳
2. 優化路線或距離，避免來回跑
3. 每個行程需說明為何選擇這些咖啡廳或地點，以及如何符合使用者風格、時間偏好與偏好條件
4. 回傳 JSON，格式如下：
{
  \"reason\": \"請說明推薦的理由，需解釋為何選擇這些咖啡廳或地點，以及這些地點如何符合使用者的風格、時間偏好與偏好條件。若是地址搜尋請包含『{$location}』，若是捷運搜尋請包含『{$location}站』。\",
  \"itinerary\": [
    {
      \"time\": \"09:00\",
      \"place\": \"咖啡廳名稱\",
      \"activity\": \"活動內容\",
      \"transport\": \"步行/交通方式\",
      \"period\": \"morning\",
      \"category\": \"cafe\"
    }
  ]
}";

// ------------------- 呼叫 OpenAI -------------------
$ai_response = callOpenAI($apiKey, $prompt);
if ($ai_response === false) {
    // fallback：依距離選擇前兩間咖啡廳
    $fallback_itinerary = generateFallbackItinerarySegmented($cafes, $search_mode, $location, $startTime, $endTime);
    $result = [
        'reason' => "AI 服務無法取得，使用 fallback 行程",
        'itinerary' => segmentItineraryByTime($fallback_itinerary, $startTime, $endTime),
        'raw_text' => null
    ];
} else {
    $result = parseAIResponseSegmented($ai_response, $startTime, $endTime);
}

// ------------------- 輸出 JSON -------------------
echo json_encode($result, JSON_UNESCAPED_UNICODE);

/* ------------------- 函數區 ------------------- */
function filterCafesByPreferences($cafes, $preferences){
    if (empty($preferences)) return $cafes;
    $filtered = [];
    $weightMap = ['quiet'=>2,'socket'=>1,'wifi'=>1,'no_time_limit'=>1,'pet_friendly'=>1,'outdoor_seating'=>1,'minimum_charge'=>1];
    foreach ($cafes as $cafe){
        $score = 0;
        foreach ($preferences as $pref){
            if(isset($cafe[$pref]) && $cafe[$pref]==='1') $score += $weightMap[$pref] ?? 1;
            if($pref==='no_time_limit' && isset($cafe['limited_time']) && $cafe['limited_time']==='0') $score += $weightMap[$pref] ?? 1;
        }
        if ($score >= ceil(count($preferences)*0.3)) {
            $cafe['match_score']=$score;
            $filtered[] = $cafe;
        }
    }
    usort($filtered,function($a,$b){ return ($b['match_score']??0) - ($a['match_score']??0); });
    return $filtered;
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

function haversine($lat1,$lng1,$lat2,$lng2){
    $earth_radius = 6371;
    $dLat = deg2rad($lat2-$lat1);
    $dLng = deg2rad($lng2-$lng1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2)) * sin($dLng/2)**2;
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

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
            "temperature"=>0.8,
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

function parseAIResponseSegmented($ai_response,$startTime,$endTime){
    $matches=[]; 
    preg_match('/\{.*"itinerary".*\}/s',$ai_response,$matches);
    $result=['reason'=>null,'itinerary'=>[],'raw_text'=>$ai_response];
    if(!empty($matches[0])){
        $parsed=json_decode($matches[0],true);
        if($parsed && isset($parsed['itinerary'])){
            $result['reason']=$parsed['reason']??"建議行程以附近咖啡廳與景點安排";
            $result['itinerary'] = segmentItineraryByTime($parsed['itinerary'],$startTime,$endTime);
        }
    }
    return $result;
}

function generateFallbackItinerarySegmented($cafes,$search_mode,$location,$start,$end){
    $itinerary=[];
    $cafes_count=count($cafes);
    if($cafes_count>0){
        $cafe1=$cafes[0];
        $itinerary[]=['time'=>$start,'place'=>$cafe1['name'],'activity'=>'享用早餐咖啡','transport'=>'步行或交通','period'=>'morning','category'=>'cafe'];
    }
    if($cafes_count>1){
        $cafe2=$cafes[1];
        $itinerary[]=['time'=>date('H:i',strtotime($start.' +4 hours')),'place'=>$cafe2['name'],'activity'=>'享用午後咖啡','transport'=>'步行或交通','period'=>'afternoon','category'=>'cafe'];
    }
    $itinerary[]=['time'=>date('H:i',strtotime($start.' +2 hours')),'place'=>'自由活動','activity'=>'探索周邊景點','transport'=>'步行或大眾運輸','period'=>'morning','category'=>'sightseeing'];
    return $itinerary;
}

function segmentItineraryByTime($itinerary,$startTime,$endTime){
    foreach($itinerary as &$item){
        $hour=(int)substr($item['time'],0,2);
        if($hour<12) $item['period']='morning';
        elseif($hour<18) $item['period']='afternoon';
        else $item['period']='evening';
    }
    return $itinerary;
}
?>
