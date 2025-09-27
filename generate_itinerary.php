<?php
// ============================ 基本設定 ============================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=UTF-8');

// ============================ 工具 ============================
function read_json_input(){ $raw=file_get_contents('php://input'); if($raw===false||$raw==='')return[]; $d=json_decode($raw,true); return is_array($d)?$d:[]; }
function pick($arr,$keys,$def=null){ foreach($keys as $k) if(array_key_exists($k,$arr)) return $arr[$k]; return $def; }
function ensure_array($v){ if(is_array($v)) return $v; if(is_string($v)){ $t=json_decode($v,true); if(is_array($t))return $t; if(strpos($v,',')!==false) return array_values(array_filter(array_map('trim',explode(',',$v)),'strlen')); } return[]; }
function hhmm_to_minutes($hhmm){ if(!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/',trim($hhmm),$m))return null; return ((int)$m[1])*60+(int)$m[2]; }
function minutes_to_hhmm($m){ $h=floor($m/60); $mm=$m%60; return sprintf('%02d:%02d',$h,$mm); }
function earliest_open_minutes_from_string($s){ if(!$s)return null; preg_match_all('/([01]?\d|2[0-3]):([0-5]\d)/',$s,$m); if(empty($m[0]))return null; $mins=array_map('hhmm_to_minutes',$m[0]); $mins=array_values(array_filter($mins,fn($v)=>$v!==null)); if(empty($mins))return null; sort($mins); return $mins[0]; }
function index_cafes_by_name($cafes){ $idx=[]; foreach($cafes as $c) if(!empty($c['name'])) $idx[$c['name']]=$c; return $idx; }
function enrich_itinerary_with_cafe_fields($it,$idx){ foreach($it as &$item){ if(!empty($item['place']) && isset($idx[$item['place']])){ $c=$idx[$item['place']]; foreach(['address','mrt','limited_time','socket','pet_friendly','outdoor_seating','minimum_charge'] as $k){ if(isset($c[$k]) && empty($item[$k])) $item[$k]=$c[$k]; } } } unset($item); return $it; }
function build_tags_from_cafe($c){ $t=[]; if(isset($c['limited_time']))$t[]=$c['limited_time']==='0'?'不限時':'限時'; if(isset($c['socket']))$t[]=$c['socket']==='1'?'有插座':'無插座'; if(isset($c['pet_friendly']))$t[]=$c['pet_friendly']==='1'?'寵物友善':'非寵物友善'; if(isset($c['outdoor_seating']))$t[]=$c['outdoor_seating']==='1'?'戶外座位':'無戶外座位'; if(isset($c['minimum_charge']))$t[]=$c['minimum_charge']==='0'?'無低消':'有低消'; return $t; }
function to_candidates($cafes,$limit=5,$selectedNames=[]){
  $sel = array_flip($selectedNames);
  $out=[]; $n=0;
  foreach($cafes as $c){
    $name=$c['name']??'';
    $out[]=[
      'name'=>$name,
      'address'=>$c['address']??null,
      'mrt'=>$c['mrt']??null,
      'tags'=>build_tags_from_cafe($c),
      'selected'=> isset($sel[$name]) // ✅ 行程已有 → 前端可顯示已勾選
    ];
    if(++$n>=max(3,min(5,$limit))) break;
  }
  return $out;
}
function filter_cafes_open_by_start_strict($cafes,$startHHmm){ $startMin=hhmm_to_minutes($startHHmm); if($startMin===null)return $cafes; $out=[]; foreach($cafes as $c){ $e=earliest_open_minutes_from_string($c['open_time']??($c['Open_time']??null)); if($e===null || $e <= $startMin) $out[]=$c; } return $out; }
function time_to_minutes_or_default($t,$def='12:00'){ $m=hhmm_to_minutes($t); return $m===null?hhmm_to_minutes($def):$m; }

// 新增：以 time 決定 period（不再相信 LLM 的 period）
function fix_period_by_time($itinerary){
  if(!is_array($itinerary)) return $itinerary;
  foreach($itinerary as &$item){
    $timeStr = $item['time'] ?? null;
    $m = time_to_minutes_or_default($timeStr,'12:00'); // 取分鐘數（若無效則預設中午）
    $hour = intval(floor($m / 60));
    if ($hour >= 6 && $hour < 12) {
      $item['period'] = 'morning';
    } elseif ($hour >= 12 && $hour < 18) {
      $item['period'] = 'afternoon';
    } else {
      $item['period'] = 'evening';
    }
  }
  unset($item);
  return $itinerary;
}

// 用來檢查 place 是否是通用詞
function looks_like_generic_place($p){
  if(!$p) return true;
  $t=trim($p);
  if(preg_match('/^(附近|某)/u',$t)) return true;
  if(preg_match('/附近/u',$t)) return true;
  $exact = ['自由活動','景點','具名景點','公園','市場','老街','河濱','步道','書店','展覽','美術館','商場','書店 / 展覽','公園 / 步道','河濱 / 老街'];
  if(in_array($t,$exact,true)) return true;
  if(preg_match('/書店 *\/ *展覽|公園 *\/ *步道|河濱 *\/ *老街/u',$t)) return true;
  return false;
}

// ============================ 讀入 ============================
$in            = read_json_input();
$location      = pick($in,['location'],'');
$mrt           = pick($in,['mrt'],'');
$search_mode   = pick($in,['search_mode','searchMode'],'address');
$preferences   = ensure_array(pick($in,['preferences'],[]));
$style_pref    = pick($in,['style'],'文青');
$time_pref     = pick($in,['time_preference','timePreference'],'標準');
$user_goals    = ensure_array(pick($in,['user_goals','userGoals'],[]));
$date          = pick($in,['date'],null);
$mood          = pick($in,['mood'],'RELAX');
$weather       = pick($in,['weather'],'UNKNOWN');
$start_time_in = pick($in,['start_time','startTime'],null);
$duration_hrs  = (int)pick($in,['duration_hours','durationHours'],8);
$cafes         = ensure_array(pick($in,['cafes'],[]));           // 搜尋結果（可能為空）
$include_only  = ensure_array(pick($in,['include_only','includeOnly'],[]));
$exclude       = ensure_array(pick($in,['exclude'],[]));
$must_include  = ensure_array(pick($in,['must_include','mustInclude'],[]));
if (empty($must_include) && !empty($include_only)) $must_include = $include_only;
if (count($must_include) > 3) $must_include = array_slice($must_include,0,3);

// ============================ 時段設定 ============================
$timeSettings = [
  '早鳥' => ['start'=>'09:00','end'=>'18:00'],
  '標準' => ['start'=>'10:00','end'=>'20:00'],
  '夜貓' => ['start'=>'13:00','end'=>'23:00'],
];
$startTime = $start_time_in ?: ($timeSettings[$time_pref]['start'] ?? '10:00');
$endTime   = $timeSettings[$time_pref]['end']   ?? '20:00';

// include/exclude
if (!empty($include_only)) {
  $set = array_flip($include_only);
  $cafes = array_values(array_filter($cafes, fn($c)=>isset($set[$c['name']??''])));
}
if (!empty($exclude)) {
  $ban = array_flip($exclude);
  $cafes = array_values(array_filter($cafes, fn($c)=>!isset($ban[$c['name']??''])));
}

$cafeIndexAll      = index_cafes_by_name($cafes);
$cafes_open_first  = filter_cafes_open_by_start_strict($cafes,$startTime);

// ============================ OpenAI ============================
$apiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?? '';

$mkCafeList = function($list){
  $txt=''; foreach($list as $c){
    $features=[];
    if(($c['socket']??'')==='1')          $features[]='有插座';
    if(($c['limited_time']??'')==='0')    $features[]='不限時';
    if(($c['minimum_charge']??'')==='0')  $features[]='無低消';
    if(($c['outdoor_seating']??'')==='1') $features[]='戶外座位';
    if(($c['pet_friendly']??'')==='1')    $features[]='寵物友善';
    $txt.=($c['name']??'（未命名）')."\n";
    $txt.="   地址: ".($c['address']??'未知')."\n";
    if(!empty($c['mrt'])) $txt.="   捷運: ".$c['mrt']."\n";
    if(!empty($features)) $txt.="   特色: ".implode('、',$features)."\n";
    if(!empty($c['open_time']??null)) $txt.="   營業: ".$c['open_time']."\n";
    $txt.="\n";
  } return $txt;
};
$cafe_list_text_all   = $mkCafeList($cafes);
$cafe_list_text_first = $mkCafeList($cafes_open_first);

$search_info = ($search_mode==='mrt') ? "以捷運站「{$mrt}」為中心" : "在「{$location}」地區";

$pref_map = ['socket'=>'有插座','no_time_limit'=>'不限時','minimum_charge'=>'無低消','outdoor_seating'=>'戶外座位','pet_friendly'=>'寵物友善'];
$pref_texts=[]; foreach($preferences as $p) if(isset($pref_map[$p])) $pref_texts[]=$pref_map[$p];
$preference_text = empty($pref_texts) ? "" : "用戶偏好（已過濾）: ".implode('、',$pref_texts)."\n";
$user_goal_text = empty($user_goals) ? "" : "旅遊目的: ".implode('、',$user_goals)."\n";

$schema_hint = <<<JSON
輸出 JSON 結構（鍵名必須完全一致）：
{
  "reason": "為什麼這樣安排（2~4 句）",
  "story": "2~4 句旁白",
  "mood": "RELAX | LOW | HAPPY | ROMANTIC",
  "weather": "SUNNY | RAINY | CLOUDY | WINDY | HOT | COLD | HUMID | UNKNOWN",
  "itinerary": [
    {"time":"10:00","place":"具名地點","activity":"做什麼","transport":"步行/大眾運輸/Ubike/公車","period":"morning|afternoon|evening","category":"cafe|attraction|free_activity","desc":"一句話"}
  ]
}
JSON;

$prompt = <<<PROMPT
你是專業旅遊行程規劃師。請為 {$search_info} 於 {$date} 規劃一日行程。
心情：{$mood}；天氣：{$weather}；風格：{$style_pref}
{$user_goal_text}{$preference_text}
時間偏好：{$time_pref}（{$startTime} - {$endTime}）

候選咖啡廳（只能從下列名單挑；若名單為空，今日可不安排咖啡廳）：
{$cafe_list_text_all}
第一時段可用咖啡廳（若第一時段是咖啡廳，必須從此清單挑；清單為空則第一時段改為具名景點）：
{$cafe_list_text_first}

硬性規則：
0) 第一個項目的 time **必須等於 {$startTime}**。
1) 若有候選咖啡廳可用：上午至少 1 間、下午至少 1 間（名稱精準取自候選清單；若使用者有必選名單則必需納入）。
2) **至少安排 2 個非咖啡時段**（attraction 或 free_activity）。
3) **place 一律要「具名地點」**，不得用「附近 / 某 / 公園 / 老街 / 書店 / 展覽 / 自由活動」等通用詞。
4) 由早到晚排序、盡量避免折返；中午正熱避免曝曬。
5) 僅輸出 JSON，**不要**多餘文字或 markdown。

{$schema_hint}
PROMPT;

function call_openai_chat($key,$prompt,$temperature=0.5,$max_tokens=1500){
  if(!$key) return false;
  $payload=["model"=>"gpt-3.5-turbo","messages"=>[
    ["role"=>"system","content"=>"你是一個專業旅遊行程規劃師，只輸出 JSON。"],
    ["role"=>"user","content"=>$prompt]
  ],"temperature"=>$temperature,"max_tokens"=>$max_tokens];
  $ch=curl_init();
  curl_setopt_array($ch,[CURLOPT_URL=>"https://api.openai.com/v1/chat/completions",
    CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>40,
    CURLOPT_HTTPHEADER=>["Content-Type: application/json","Authorization: Bearer {$key}"],
    CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE)]);
  $resp=curl_exec($ch); if(curl_errno($ch)){ curl_close($ch); return false; } curl_close($ch);
  $data=json_decode($resp,true); return $data['choices'][0]['message']['content']??false;
}
function parse_llm_json($raw){
  if(!$raw) return false;
  $j=json_decode($raw,true);
  if(is_array($j)&&isset($j['itinerary'])) return $j;
  if(preg_match('/\{.*\}/s',$raw,$m)){ $j=json_decode($m[0],true); if(is_array($j)&&isset($j['itinerary'])) return $j; }
  return false;
}

// ============================ 產生行程（主要交給 GPT） ============================
$llm_json = null;
if ($apiKey) $llm_json = parse_llm_json(call_openai_chat($apiKey,$prompt,0.6,1600));

// 萬一沒有 API key 或 LLM 失敗，給極簡備援（仍然不寫死景點名）
if (!$llm_json) {
  $llm_json = [
    'reason'  => '以時間偏好為主，穿插咖啡與休閒活動。',
    'story'   => '準時出發，隨著步調探索城市。',
    'mood'    => $mood,
    'weather' => $weather,
    'itinerary'=>[
      ['time'=>$startTime,'place'=>'（請改為具名地點）','activity'=>'晨間漫步','transport'=>'步行','period'=>'morning','category'=>'attraction','desc'=>'以輕鬆步調展開。']
    ]
  ];
}

// ---------- 後處理（不寫死其他活動，只做「約束 + 修正」） ----------

// A) 首站時間對齊（早鳥09:00 / 標準10:00 / 夜貓13:00）
function snap_first_morning_to_start($it,$time_pref){
  $start=['標準'=>'10:00','早鳥'=>'09:00','夜貓'=>'13:00'][$time_pref] ?? '10:00';
  $startMin=hhmm_to_minutes($start);
  $idx=null; $best=PHP_INT_MAX;
  foreach($it as $i=>$item){ if(strtolower($item['period']??'')==='morning'){ $t=time_to_minutes_or_default($item['time']??'12:00'); if($t<$best){$best=$t;$idx=$i;} } }
  if($idx!==null) $it[$idx]['time']=$start;
  foreach($it as $i=>$item){ $t=time_to_minutes_or_default($item['time']??'12:00'); if($t<$startMin) $it[$i]['time']=$start; }
  usort($it, fn($a,$b)=>time_to_minutes_or_default($a['time']??'12:00') <=> time_to_minutes_or_default($b['time']??'12:00'));
  return $it;
}

// B) 若第一站是咖啡，盡量用「可在起始時間就開門」的那幾家；否則保留非咖啡
function ensure_first_slot_if_cafe_open($plan,$cafes_open_first,$startTime,$must_include=[]){
  $it=$plan['itinerary']??[]; if(!is_array($it)||empty($it)) return $plan;
  $firstIdx=null; $firstT=PHP_INT_MAX;
  foreach($it as $i=>$item){ if(strtolower($item['period']??'')==='morning'){ $t=time_to_minutes_or_default($item['time']??$startTime); if($t<$firstT){$firstT=$t;$firstIdx=$i;} } }
  if($firstIdx===null) return $plan;
  $it[$firstIdx]['time']=$startTime;

  $openNames = array_flip(array_map(fn($c)=>$c['name']??'', $cafes_open_first));
  $mustOpen  = array_values(array_filter($must_include, fn($n)=>isset($openNames[$n])));

  if(strtolower($it[$firstIdx]['category']??'')==='cafe'){
    $cur=$it[$firstIdx]['place']??'';
    if(!isset($openNames[$cur])){
      if(!empty($mustOpen))       $it[$firstIdx]['place']=$mustOpen[0];
      elseif(!empty($cafes_open_first)) $it[$firstIdx]['place']=$cafes_open_first[0]['name'];
      else $it[$firstIdx]=['time'=>$startTime,'place'=>'（請改為具名地點）','activity'=>'清晨散步或看書展','transport'=>'步行','period'=>'morning','category'=>'attraction','desc'=>'以輕鬆步調展開今天。'];
    }
  }
  $plan['itinerary']=$it; return $plan;
}

// C) 上午/下午至少各 1 間咖啡（優先塞 must_include）；其餘完全不動（交給 GPT）
function ensure_am_pm_cafes($plan, $cafes_all, $time_pref, $must_include = []) {
  if (empty($cafes_all)) return $plan;
  $slots_std  = ['10:00','11:30','13:30','15:30','17:30'];
  $slots_early= ['09:00','11:00','13:00','15:00','17:00'];
  $slots_late = ['13:00','14:30','16:30','18:30','20:00'];
  $slots = $slots_std;
  if ($time_pref==='早鳥') $slots=$slots_early;
  if ($time_pref==='夜貓') $slots=$slots_late;

  $amCut = hhmm_to_minutes('12:00');
  $it = $plan['itinerary'] ?? [];
  if (!is_array($it)) $it = [];

  $used = []; $hasAM=false; $hasPM=false;
  foreach ($it as $item) {
    if (strtolower($item['category'] ?? '') === 'cafe') {
      $name = $item['place'] ?? '';
      if ($name) $used[$name] = true;
      $t = time_to_minutes_or_default($item['time'] ?? '12:00');
      if ($t < $amCut) $hasAM = true; else $hasPM = true;
    }
  }

  $idx = index_cafes_by_name($cafes_all);
  $must = array_values(array_filter($must_include, fn($n) => isset($idx[$n])));

  $prefTimes = [$slots[0], $slots[2]];
  $prefPeriod = function($t) use ($amCut) { return (hhmm_to_minutes($t) < $amCut) ? 'morning' : 'afternoon'; };

  $ti = 0;
  foreach ($must as $name) {
    if ($ti>=count($prefTimes)) break;
    if (!empty($used[$name])) { $ti++; continue; }
    $time = $prefTimes[$ti++];
    $it[] = [
      'time' => $time,
      'place' => $name,
      'activity' => ($prefPeriod($time)==='morning') ? '晨間咖啡' : '午後咖啡與甜點',
      'transport' => '步行',
      'period' => $prefPeriod($time),
      'category' => 'cafe',
      'desc' => '（必選）'
    ];
    $used[$name] = true;
    if ($prefPeriod($time)==='morning') $hasAM = true; else $hasPM = true;
  }

  if (!$hasAM) { foreach ($idx as $n => $_) { if (empty($used[$n])) { $it[] = ['time'=>$slots[0],'place'=>$n,'activity'=>'晨間咖啡','transport'=>'步行','period'=>'morning','category'=>'cafe','desc'=>'從香氣開始。']; $used[$n]=true; $hasAM=true; break; } } }
  if (!$hasPM) { foreach ($idx as $n => $_) { if (empty($used[$n])) { $it[] = ['time'=>$slots[2],'place'=>$n,'activity'=>'午後咖啡與甜點','transport'=>'步行','period'=>'afternoon','category'=>'cafe','desc'=>'午後時光慢下來。']; $used[$n]=true; $hasPM=true; break; } } }

  usort($it, fn($a, $b) => time_to_minutes_or_default($a['time'] ?? '12:00') <=> time_to_minutes_or_default($b['time'] ?? '12:00'));
  $plan['itinerary'] = $it;
  return $plan;
}

// D) 若有「通用詞」或非咖啡 < 2，請 GPT 以**具名地點**重寫（保留時段與已選咖啡）
function refine_named_places_via_llm($key,$original,$search_info,$date){
  if(!$key) return $original;
  $raw = json_encode($original,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $repair = <<<TXT
以下是目前的行程 JSON。請「就地修改 JSON」，遵守：
- place 一律換成**具名地點**（真實存在的景點/書店/公園/夜市/博物館等），不要出現「附近/某/公園/老街/書店/展覽/自由活動」這種通用詞。
- 所有地點必須位於 {$search_info} 附近（步行或捷運可到達，建議 1~2 公里範圍內）。
- 保留每個 item 的 time / period / category / transport 結構；若時間相同可微調 ±30 分鐘避免撞時段。
- 至少保有 2 個非咖啡項目（category=attraction 或 free_activity）。
- 地點需合理位於或鄰近 {$search_info}，日期：{$date}。
- 僅輸出 JSON。

JSON：
{$raw}
TXT;
  $fixed = parse_llm_json(call_openai_chat($key,$repair,0.2,1200));
  return $fixed ?: $original;
}

// E) 時間去重：若同時間撞到，就往後推 30 分鐘（不越過 21:30）
function normalize_times_unique($plan){
  $it=$plan['itinerary']??[]; if(empty($it)) return $plan;
  usort($it, fn($a,$b)=>time_to_minutes_or_default($a['time']??'12:00')<=>time_to_minutes_or_default($b['time']??'12:00'));
  $seen=[];
  foreach($it as &$x){
    $m=time_to_minutes_or_default($x['time']??'12:00');
    while(isset($seen[$m]) && $m<=hhmm_to_minutes('21:30')){ $m+=30; }
    $x['time']=minutes_to_hhmm($m);
    $seen[$m]=true;
  } unset($x);
  $plan['itinerary']=$it; return $plan;
}

// ---------- 呼叫順序 ----------
$llm_json['itinerary'] = snap_first_morning_to_start($llm_json['itinerary'] ?? [], $time_pref);
$llm_json = ensure_first_slot_if_cafe_open($llm_json, $cafes_open_first, $startTime, $must_include);
$llm_json = ensure_am_pm_cafes($llm_json, $cafes, $time_pref, $must_include);

// 若存在通用詞或非咖啡不足 → 交給 LLM 做具名地點修正
$needRefine = false; $nonCafe=0;
foreach(($llm_json['itinerary']??[]) as $x){
  if(strtolower($x['category']??'')!=='cafe') $nonCafe++;
  if(looks_like_generic_place($x['place']??'')) $needRefine=true;
}
if($nonCafe<2) $needRefine=true;
if($needRefine){
  $llm_json = refine_named_places_via_llm($apiKey,$llm_json,$search_info,$date);
}

$llm_json = normalize_times_unique($llm_json);

// **在此呼叫：以 time 修正 period（避免 LLM 回傳錯誤 period）**
$llm_json['itinerary'] = fix_period_by_time($llm_json['itinerary'] ?? []);

// 補欄位/候選 + 回傳
$llm_json['itinerary'] = enrich_itinerary_with_cafe_fields($llm_json['itinerary'] ?? [], $cafeIndexAll);
if (empty($llm_json['story']))    $llm_json['story']   = '第一刻準時出發，依心情與天氣在城市輕鬆漫遊。';
if (empty($llm_json['mood']))     $llm_json['mood']    = $mood;
if (empty($llm_json['weather']))  $llm_json['weather'] = $weather;
$llm_json['date'] = $date;

// 讓候選清單預選「行程上出現的咖啡廳」
$selectedCafeNames=[];
foreach(($llm_json['itinerary']??[]) as $i){
  if(strtolower($i['category']??'')==='cafe' && !empty($i['place'])) $selectedCafeNames[]=$i['place'];
}
$llm_json['candidates'] = to_candidates($cafes, 5, $selectedCafeNames);

echo json_encode($llm_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
