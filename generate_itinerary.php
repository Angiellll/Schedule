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
      'selected'=> isset($sel[$name])
    ];
    if(++$n>=max(3,min(5,$limit))) break;
  }
  return $out;
}
function filter_cafes_open_by_start_strict($cafes,$startHHmm){ $startMin=hhmm_to_minutes($startHHmm); if($startMin===null)return $cafes; $out=[]; foreach($cafes as $c){ $e=earliest_open_minutes_from_string($c['open_time']??($c['Open_time']??null)); if($e===null || $e <= $startMin) $out[]=$c; } return $out; }
function time_to_minutes_or_default($t,$def='12:00'){ $m=hhmm_to_minutes($t); return $m===null?hhmm_to_minutes($def):$m; }

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

// ============================ Prompt（若有 API Key） ============================
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
心情：{$mood}（LOW→安撫與甜點；RELAX→放鬆步調；HAPPY→活力體驗；ROMANTIC→氛圍與景觀）
天氣：{$weather}（RAINY/HUMID/COLD→室內為主；SUNNY/HOT/WINDY→戶外或通風良好）
風格：{$style_pref}
{$user_goal_text}{$preference_text}
時間偏好：{$time_pref}（{$startTime} - {$endTime}）

候選咖啡廳（只能從下列名單挑；若名單為空，今日可不安排咖啡廳）：
{$cafe_list_text_all}
第一時段可用咖啡廳（若第一時段是咖啡廳，必須從此清單挑；清單為空則第一時段改為具名景點）：
{$cafe_list_text_first}

硬性規則：
0) 第一個項目的 time **必須等於 {$startTime}**。第一個若為 cafe，place 必須出自「第一時段可用」清單；若清單為空或不用咖啡廳，請改安排具名景點（例：某公園/市場/書店/展館），位於 {$search_info} 周邊。
1) 若有候選咖啡廳可用：上午至少 1 間、下午至少 1 間（名稱精準取自候選清單；若使用者有必選名單則必需納入）。若候選清單為空，整天可只安排非咖啡類別。
2) **至少安排 2 個非咖啡時段**（attraction 或 free_activity），並呼應「旅遊目的」「風格」「心情」「天氣」。
3) 由早到晚排序、盡量避免折返；中午正熱避免曝曬。
4) 每個項目都要填 time/place/activity/transport/period/category 與簡短 desc。
5) 僅輸出 JSON，**不要**多餘文字或 markdown。

{$schema_hint}
PROMPT;

function call_openai_chat($key,$prompt){
  if(!$key) return false;
  $payload=["model"=>"gpt-3.5-turbo","messages"=>[
    ["role"=>"system","content"=>"你是一個專業旅遊行程規劃師，只輸出 JSON。"],
    ["role"=>"user","content"=>$prompt]
  ],"temperature"=>0.5,"max_tokens"=>1500];
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

// ============================ Fallback（可零咖啡廳） ============================
function fallback_itinerary($cafes,$cafes_open_first,$time_pref,$mood,$weather,$startTime){
  $slots_std=['10:00','11:30','13:30','15:30','17:30'];
  $slots_early=['09:00','11:00','13:00','15:00','17:00'];
  $slots_late=['13:00','14:30','16:30','18:30','20:00'];
  $slots=$slots_std; if($time_pref==='早鳥')$slots=$slots_early; if($time_pref==='夜貓')$slots=$slots_late;
  $preferIndoor=in_array($weather,['RAINY','HUMID','COLD','UNKNOWN'],true);
  $it=[];
  $c1=$cafes_open_first[0]??null;
  if($c1){
    $it[]=['time'=>$startTime,'place'=>$c1['name'],'activity'=>'享用咖啡與輕食','transport'=>'步行','period'=>'morning','category'=>'cafe','desc'=>($mood==='LOW' || $mood==='RELAX')?'挑個安靜角落。':'坐在窗邊吸收晨間活力。'];
  } else {
    $it[]=['time'=>$startTime,'place'=>$preferIndoor?'附近書店/展場':'附近公園/市場','activity'=>$preferIndoor?'逛逛展覽或書店':'清晨散步拍照','transport'=>'步行','period'=>'morning','category'=>'attraction','desc'=>$preferIndoor?'雨天也愜意。':'空氣清新喚醒一天。'];
  }
  $it[]=['time'=>$slots[1],'place'=>$preferIndoor?'書店 / 展覽':'公園 / 老街散步','activity'=>$preferIndoor?'看展或翻翻新書':'在綠意或街景間拍照','transport'=>'步行或大眾運輸','period'=>'morning','category'=>'attraction','desc'=>$preferIndoor?'換個空間轉換心情。':'把步調放慢，留點空白。'];
  $c2=$cafes[1]??($cafes[0]??null);
  if($c2){
    $it[]=['time'=>$slots[2],'place'=>$c2['name'],'activity'=>'午後咖啡與甜點','transport'=>'步行','period'=>'afternoon','category'=>'cafe','desc'=>'午後光影配甜點最剛好。'];
  } else {
    $it[]=['time'=>$slots[2],'place'=>$preferIndoor?'美術館 / 商場':'河濱 / 步道','activity'=>$preferIndoor?'逛逛展區或窗逛放空':'沿著水岸或樹蔭慢行','transport'=>'步行或大眾運輸','period'=>'afternoon','category'=>'attraction','desc'=>$preferIndoor?'遇雨也能優雅漫遊。':'傍晚風起最舒服的時刻。'];
  }
  $it[]=['time'=>$slots[4],'place'=>'自由活動','activity'=>'找家喜歡的小店作結','transport'=>'步行','period'=>'evening','category'=>'free_activity','desc'=>'用輕鬆步調收尾今天。'];
  return ['reason'=>'依時間偏好與天氣安排，穿插活動並兼顧移動效率；若無咖啡廳可用則改走景點路線。','story'=>'第一刻準時出發，順著天氣與心情在城市裡漫遊。','mood'=>$mood,'weather'=>$weather,'itinerary'=>$it];
}

// ============================ 產生行程 ============================
$llm_json = null;
if ($apiKey) $llm_json = parse_llm_json(call_openai_chat($apiKey,$prompt));
if (!$llm_json) $llm_json = fallback_itinerary($cafes,$cafes_open_first,$time_pref,$mood,$weather,$startTime);

// ---------- 行程後處理（關鍵邏輯） ----------
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

// 勾選時：把原來行程中的「非勾選咖啡廳」拿掉（非咖啡活動不動）
function prune_unselected_cafes($plan,$mustInclude){
  if (empty($mustInclude)) return $plan;
  $allow = array_flip($mustInclude);
  $out=[];
  foreach(($plan['itinerary']??[]) as $item){
    $isCafe = strtolower($item['category']??'')==='cafe';
    if(!$isCafe || ($isCafe && isset($allow[$item['place']??'']))){
      $out[]=$item;
    }
  }
  $plan['itinerary']=$out;
  return $plan;
}

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
      else $it[$firstIdx]=['time'=>$startTime,'place'=>'附近書店/展覽或公園','activity'=>'清晨散步或看書展','transport'=>'步行','period'=>'morning','category'=>'attraction','desc'=>'以輕鬆步調展開今天。'];
    }
  }
  $plan['itinerary']=$it; return $plan;
}

// 上午/下午至少各 1 間咖啡（優先塞 must_include），其餘保留給非咖啡
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

  // 先安插必選（最多 2 個時段：AM/PM）
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

  // 再補到「至少 1 AM / 1 PM」
  if (!$hasAM) {
    foreach ($idx as $n => $_) {
      if (empty($used[$n])) { $it[] = ['time'=>$slots[0],'place'=>$n,'activity'=>'晨間咖啡','transport'=>'步行','period'=>'morning','category'=>'cafe','desc'=>'從香氣開始。']; $used[$n]=true; $hasAM=true; break; }
    }
  }
  if (!$hasPM) {
    foreach ($idx as $n => $_) {
      if (empty($used[$n])) { $it[] = ['time'=>$slots[2],'place'=>$n,'activity'=>'午後咖啡與甜點','transport'=>'步行','period'=>'afternoon','category'=>'cafe','desc'=>'午後時光慢下來。']; $used[$n]=true; $hasPM=true; break; }
    }
  }

  $plan['itinerary'] = $it;
  return $plan;
}

// 至少保證 2 個非咖啡活動（若不足就補）
function ensure_min_non_cafe($plan,$time_pref,$weather){
  $slots_std=['10:00','11:30','13:30','15:30','17:30'];
  $slots_early=['09:00','11:00','13:00','15:00','17:00'];
  $slots_late=['13:00','14:30','16:30','18:30','20:00'];
  $slots=$slots_std; if($time_pref==='早鳥')$slots=$slots_early; if($time_pref==='夜貓')$slots=$slots_late;

  $preferIndoor=in_array($weather,['RAINY','HUMID','COLD','UNKNOWN'],true);
  $ideasIndoor=[['書店 / 展覽','看展或翻翻新書','morning'],['美術館 / 商場','逛逛展區或窗逛放空','afternoon']];
  $ideasOutdoor=[['公園 / 步道','散步拍照','morning'],['河濱 / 老街','慢遊放鬆','afternoon']];
  $ideas=$preferIndoor?$ideasIndoor:$ideasOutdoor;

  $it=$plan['itinerary']??[];
  $non=0; foreach($it as $x){ if(strtolower($x['category']??'')!=='cafe') $non++; }
  $need=max(0,2-$non);
  if($need<=0){ $plan['itinerary']=$it; return $plan; }

  // 可放的時間：優先 11:30 / 15:30 兩個空檔
  $wantTimes=[];
  foreach(['11:30','15:30'] as $w){ $wantTimes[]=$w; }
  if($time_pref==='早鳥') $wantTimes=['11:00','15:00'];
  if($time_pref==='夜貓') $wantTimes=['14:30','18:30'];

  $usedTimes=array_flip(array_map(fn($x)=>$x['time']??'', $it));
  $i=0;
  foreach($wantTimes as $t){
    if($need<=0) break;
    if(isset($usedTimes[$t])) continue;
    $idea=$ideas[$i%count($ideas)];
    $it[]=['time'=>$t,'place'=>$idea[0],'activity'=>$idea[1],'transport'=>'步行或大眾運輸','period'=>$idea[2],'category'=>'attraction','desc'=>'穿插非咖啡時段，保持節奏。'];
    $usedTimes[$t]=true; $need--; $i++;
  }
  $plan['itinerary']=$it; return $plan;
}

// 時間去重：若同時間撞到，就往後推 30 分鐘（不越過 21:30）
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
$llm_json = prune_unselected_cafes($llm_json, $must_include);
$llm_json['itinerary'] = snap_first_morning_to_start($llm_json['itinerary'] ?? [], $time_pref);
$llm_json = ensure_first_slot_if_cafe_open($llm_json, $cafes_open_first, $startTime, $must_include);
$llm_json = ensure_am_pm_cafes($llm_json, $cafes, $time_pref, $must_include);
$llm_json = ensure_min_non_cafe($llm_json, $time_pref, $weather);
$llm_json = normalize_times_unique($llm_json);

// 補欄位/候選 + 回傳
$llm_json['itinerary'] = enrich_itinerary_with_cafe_fields($llm_json['itinerary'] ?? [], $cafeIndexAll);
if (empty($llm_json['story']))    $llm_json['story']   = '第一刻準時出發，依心情與天氣在城市輕鬆漫遊。';
if (empty($llm_json['mood']))     $llm_json['mood']    = $mood;
if (empty($llm_json['weather']))  $llm_json['weather'] = $weather;
$llm_json['date'] = $date;

$selectedCafeNames=[];
foreach(($llm_json['itinerary']??[]) as $i){
  if(strtolower($i['category']??'')==='cafe' && !empty($i['place'])) $selectedCafeNames[]=$i['place'];
}
$llm_json['candidates'] = to_candidates($cafes, 5, $selectedCafeNames);

echo json_encode($llm_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
