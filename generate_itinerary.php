<?php
header("Content-Type: application/json; charset=UTF-8");
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

error_reporting(E_ALL);
ini_set('display_errors', 1);
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') exit(0);

// ------------------- 讀取參數 -------------------
$location = $_POST['location'] ?? $_REQUEST['location'] ?? '';
$search_mode = $_POST['search_mode'] ?? $_REQUEST['search_mode'] ?? 'address'; // 'address' or 'mrt'
$preferences = $_POST['preferences'] ?? $_REQUEST['preferences'] ?? [];
if (is_string($preferences)) $preferences = json_decode($preferences,true) ?? explode(',', $preferences);

$style_preference = $_POST['style'] ?? $_REQUEST['style'] ?? '文青';
$time_preference = $_POST['time_preference'] ?? $_REQUEST['time_preference'] ?? '標準';
$user_goals = $_POST['user_goals'] ?? $_REQUEST['user_goals'] ?? [];
if (is_string($user_goals)) $user_goals = json_decode($user_goals,true) ?? explode(',', $user_goals);

$user_lat = $_POST['latitude'] ?? $_REQUEST['latitude'] ?? null;  
$user_lng = $_POST['longitude'] ?? $_REQUEST['longitude'] ?? null;

// ------------------- 從 search_mode.php 取得咖啡廳 -------------------
$searchModeParam = ($search_mode==='mrt') ? 'mrt' : 'address';
$searchQuery = http_build_query([
    'search_mode' => $searchModeParam,
    'city' => $location,
    'district' => $location,
    'mrt' => $location,
    'preferences' => implode(',', $preferences)
]);

// ✅ 用 HTTP URL，而不是 __DIR__
$baseUrl = "https://schedule-5axo.onrender.com/search_mode.php";
$searchUrl = $baseUrl . '?' . $searchQuery;
$searchResponse = file_get_contents($searchUrl);

// 檢查是否成功，避免回傳 HTML
if ($searchResponse === false) {
    echo json_encode([
        "reason" => "search_mode.php 無法呼叫",
        "itinerary" => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$cafesData = json_decode($searchResponse,true);
$cafes = $cafesData['cafes'] ?? [];

// ------------------- 篩選咖啡廳 -------------------
$cafes = filterCafesByPreferences($cafes, $preferences);

// 若沒有符合偏好的，保留原始列表
if (empty($cafes)) $cafes = $cafesData['cafes'] ?? [];

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
    $pref_map = ['quiet'=>'安靜環境','socket'=>'有插座','no_time_limit'=>'不限時','wifi'=>'WiFi','pet_friendly'=>'寵物友善'];
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
$prompt = "你是一個專業旅遊行程規劃師，請生成一日行程 JSON，上午安排1間咖啡廳，下午1間咖啡廳，其他時間安排與使用者偏好/活動風格相關的場所。
規劃地點：{$search_info}
{$preference_text}
{$user_goal_text}
使用者風格：{$style_preference}
時間偏好：{$time_preference}（{$startTime} - {$endTime}）

可用咖啡廳：
{$cafe_list}

請生成 JSON：
{
  \"reason\": \"請詳細說明推薦的理由，需解釋為何選擇這些咖啡廳或地點，以及這些地點如何符合使用者的風格、時間偏好與偏好條件。若是地址搜尋請包含『{$location}』，若是捷運搜尋請包含『{$location}站』。\",
  \"itinerary\": []
}";

// ------------------- 呼叫 OpenAI -------------------
$ai_response = callOpenAI($apiKey, $prompt);
if ($ai_response === false) {
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

// 篩選偏好咖啡廳
function filterCafesByPreferences($cafes, $preferences){
    if (empty($preferences)) return $cafes;
    $filtered = [];
    $weightMap = ['quiet'=>2,'socket'=>1,'wifi'=>1,'no_time_limit'=>1,'pet_friendly'=>1];
    foreach ($cafes as $cafe){
        $score = 0;
        foreach ($preferences as $pref){
            switch($pref){
                case 'quiet': if(isset($cafe['quiet']) && $cafe['quiet']==='1') $score+=$weightMap['quiet']; break;
                case 'socket': if(isset($cafe['socket']) && $cafe['socket']==='1') $score+=$weightMap['socket']; break;
                case 'wifi': if(isset($cafe['wifi']) && $cafe['wifi']==='1') $score+=$weightMap['wifi']; break;
                case 'no_time_limit': if(isset($cafe['limited_time']) && $cafe['limited_time']==='0') $score+=$weightMap['no_time_limit']; break;
                case 'pet_friendly': if(isset($cafe['pet_friendly']) && $cafe['pet_friendly']==='1') $score+=$weightMap['pet_friendly']; break;
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

// 按距離排序
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

// 計算兩點距離
function haversine($lat1,$lng1,$lat2,$lng2){
    $earth_radius = 6371;
    $dLat = deg2rad($lat2-$lat1);
    $dLng = deg2rad($lng2-$lng1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2)) * sin($dLng/2)**2;
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

// 呼叫 OpenAI
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
            "messages"=>[["role"=>"system","content"=>"你是一個專業旅遊行程規劃師，能依據使用者偏好與場所資訊推薦行程。"],["role"=>"user","content"=>$prompt]],
            "temperature"=>0.8,
            "max_tokens"=>1500
        ])
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
    if(curl_errno($ch)){curl_close($ch); return false;}
    curl_close($ch);
    if($http_code!==200) return false;
    $data=json_decode($response,true);
    return $data['choices'][0]['message']['content'] ?? false;
}

// 解析 AI 回應
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

// fallback 行程
function generateFallbackItinerarySegmented($cafes,$search_mode,$location,$start,$end){
    $itinerary=[];
    $cafes_count=count($cafes);
    if($cafes_count>0){
        $cafe1=$cafes[rand(0,$cafes_count-1)];
        $itinerary[]=['time'=>$start,'place'=>$cafe1['name'],'activity'=>'享用早餐咖啡','transport'=>'步行 5 分鐘','period'=>'morning','category'=>'cafe'];
    }
    if($cafes_count>1){
        $cafe2=$cafes[rand(0,$cafes_count-1)];
        while($cafe2['name']===$cafe1['name']) $cafe2=$cafes[rand(0,$cafes_count-1)];
        $itinerary[]=['time'=>date('H:i',strtotime($start.' +4 hours')),'place'=>$cafe2['name'],'activity'=>'享用午後咖啡','transport'=>'步行 5 分鐘','period'=>'afternoon','category'=>'cafe'];
    }
    $itinerary[]=['time'=>date('H:i',strtotime($start.' +2 hours')),'place'=>'自由活動','activity'=>'探索周邊景點','transport'=>'步行或大眾運輸','period'=>'morning','category'=>'sightseeing'];
    return $itinerary;
}

// 分段上午/下午/晚間
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
