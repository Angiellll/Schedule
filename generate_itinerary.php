<?php
// ============================ 基本設定 ============================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=UTF-8');

// ============================ 工具函式 ============================
function read_json_input(){ $raw=file_get_contents('php://input'); if(!$raw) return[]; $d=json_decode($raw,true); return is_array($d)?$d:[]; }
function pick($arr,$keys,$def=null){ foreach($keys as $k) if(array_key_exists($k,$arr)) return $arr[$k]; return $def; }
function ensure_array($v){ if(is_array($v)) return $v; if(is_string($v)){ $t=json_decode($v,true); if(is_array($t))return $t; if(strpos($v,',')!==false) return array_values(array_filter(array_map('trim',explode(',',$v)),'strlen')); } return[]; }
function hhmm_to_minutes($hhmm){ if(!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/',trim($hhmm),$m))return null; return ((int)$m[1])*60+(int)$m[2]; }
function minutes_to_hhmm($m){ $h=floor($m/60); $mm=$m%60; return sprintf('%02d:%02d',$h,$mm); }
function earliest_open_minutes_from_string($s){ if(!$s)return null; preg_match_all('/([01]?\d|2[0-3]):([0-5]\d)/',$s,$m); if(empty($m[0]))return null; $mins=array_map('hhmm_to_minutes',$m[0]); sort($mins); return $mins[0]; }
function index_cafes_by_name($cafes){ $idx=[]; foreach($cafes as $c) if(!empty($c['name'])) $idx[$c['name']]=$c; return $idx; }
function enrich_itinerary_with_cafe_fields($it,$idx){ foreach($it as &$item){ if(!empty($item['place']) && isset($idx[$item['place']])){ $c=$idx[$item['place']]; foreach(['address','mrt','limited_time','socket','pet_friendly','outdoor_seating','minimum_charge'] as $k){ if(isset($c[$k]) && empty($item[$k])) $item[$k]=$c[$k]; } } } unset($item); return $it; }
function build_tags_from_cafe($c){ $t=[]; if(isset($c['limited_time']))$t[]=$c['limited_time']==='0'?'不限時':'限時'; if(isset($c['socket']))$t[]=$c['socket']==='1'?'有插座':'無插座'; if(isset($c['pet_friendly']))$t[]=$c['pet_friendly']==='1'?'寵物友善':'非寵物友善'; if(isset($c['outdoor_seating']))$t[]=$c['outdoor_seating']==='1'?'戶外座位':'無戶外座位'; if(isset($c['minimum_charge']))$t[]=$c['minimum_charge']==='0'?'無低消':'有低消'; return $t; }
function to_candidates($cafes,$limit=5,$selectedNames=[]){ $sel=array_flip($selectedNames); $out=[]; $n=0; foreach($cafes as $c){ $name=$c['name']??''; $out[]=['name'=>$name,'address'=>$c['address']??null,'mrt'=>$c['mrt']??null,'tags'=>build_tags_from_cafe($c),'selected'=>isset($sel[$name])]; if(++$n>=max(3,min(5,$limit))) break; } return $out; }
function filter_cafes_open_by_start_strict($cafes,$startHHmm){ $startMin=hhmm_to_minutes($startHHmm); if($startMin===null)return $cafes; $out=[]; foreach($cafes as $c){ $e=earliest_open_minutes_from_string($c['open_time']??($c['Open_time']??null)); if($e===null || $e <= $startMin) $out[]=$c; } return $out; }
function time_to_minutes_or_default($t,$def='12:00'){ $m=hhmm_to_minutes($t); return $m===null?hhmm_to_minutes($def):$m; }

// 修正 period
function fix_period_by_time($itinerary){ foreach($itinerary as &$item){ $m = time_to_minutes_or_default($item['time']??'12:00','12:00'); $h=intval(floor($m/60)); $item['period']=$h<12?'morning':($h<18?'afternoon':'evening'); } unset($item); return $itinerary; }

// 檢查是否為通用地點
function looks_like_generic_place($p){ if(!$p) return true; $t=trim($p); if(preg_match('/^(附近|某)/u',$t)) return true; $exact=['自由活動','景點','具名景點','公園','市場','老街','河濱','步道','書店','展覽','美術館','商場','書店 / 展覽','公園 / 步道','河濱 / 老街']; if(in_array($t,$exact,true)) return true; if(preg_match('/書店 *\/ *展覽|公園 *\/ *步道|河濱 *\/ *老街/u',$t)) return true; return false; }

// ============================ 讀入前端 ============================
$in=read_json_input();
$location=pick($in,['location'],'');
$mrt=pick($in,['mrt'],'');
$search_mode=pick($in,['search_mode','searchMode'],'address');
$preferences=ensure_array(pick($in,['preferences'],[]));
$style_pref=pick($in,['style'],'文青');
$time_pref=pick($in,['time_preference','timePreference'],'標準');
$user_goals=ensure_array(pick($in,['user_goals','userGoals'],[]));
$date=pick($in,['date'],null);
$mood=pick($in,['mood'],'RELAX');
$weather=pick($in,['weather'],'UNKNOWN');
$start_time_in=pick($in,['start_time','startTime'],null);
$duration_hrs=(int)pick($in,['duration_hours','durationHours'],8);
$cafes=ensure_array(pick($in,['cafes'],[]));
$include_only=ensure_array(pick($in,['include_only','includeOnly'],[]));
$exclude=ensure_array(pick($in,['exclude'],[]));
$must_include=ensure_array(pick($in,['must_include','mustInclude'],[]));
if(empty($must_include) && !empty($include_only)) $must_include=$include_only;
if(count($must_include)>3) $must_include=array_slice($must_include,0,3);

// ============================ 時間設定 ============================
$timeSettings=['早鳥'=>['start'=>'09:00','end'=>'18:00'],'標準'=>['start'=>'10:00','end'=>'20:00'],'夜貓'=>['start'=>'13:00','end'=>'23:00']];
$startTime=$start_time_in?:($timeSettings[$time_pref]['start']??'10:00');
$endTime=$timeSettings[$time_pref]['end']??'20:00';

// include/exclude
if(!empty($include_only)){$set=array_flip($include_only); $cafes=array_values(array_filter($cafes,fn($c)=>isset($set[$c['name']??''])));}
if(!empty($exclude)){$ban=array_flip($exclude); $cafes=array_values(array_filter($cafes,fn($c)=>!isset($ban[$c['name']??''])));}

$cafeIndexAll=index_cafes_by_name($cafes);
$cafes_open_first=filter_cafes_open_by_start_strict($cafes,$startTime);

// ============================ OpenAI ============================
$apiKey=$_ENV['OPENAI_API_KEY']??getenv('OPENAI_API_KEY')??'';
$mkCafeList=function($list){$txt=''; foreach($list as $c){ $features=[]; if(($c['socket']??'')==='1') $features[]='有插座'; if(($c['limited_time']??'')==='0') $features[]='不限時'; if(($c['minimum_charge']??'')==='0') $features[]='無低消'; if(($c['outdoor_seating']??'')==='1') $features[]='戶外座位'; if(($c['pet_friendly']??'')==='1') $features[]='寵物友善'; $txt.=($c['name']??'（未命名）')."\n"; $txt.="   地址: ".($c['address']??'未知')."\n"; if(!empty($c['mrt'])) $txt.="   捷運: ".$c['mrt']."\n"; if(!empty($features)) $txt.="   特色: ".implode('、',$features)."\n"; if(!empty($c['open_time']??null)) $txt.="   營業: ".$c['open_time']."\n"; $txt.="\n"; } return $txt;};
$cafe_list_text_all=$mkCafeList($cafes);
$cafe_list_text_include=$mkCafeList(array_values(array_filter($cafes,function($c) use($must_include){return in_array($c['name']??'', $must_include);})));

// ============================ GPT 生成範例 ============================
$examplePlan=[
    ['time'=>'09:00','place'=>$must_include[0]??($cafes[0]['name']??'咖啡館 A'),'note'=>'享用早餐，順便喝咖啡'],
    ['time'=>'11:00','place'=>$must_include[1]??($cafes[1]['name']??'咖啡館 B'),'note'=>'休息、拍照'],
    ['time'=>'14:00','place'=>$must_include[2]??($cafes[2]['name']??'咖啡館 C'),'note'=>'午後茶、文青時光'],
    ['time'=>'16:30','place'=>$must_include[0]??($cafes[0]['name']??'咖啡館 A'),'note'=>'回訪，喝咖啡'],
];

// ============================ 後處理 ============================
$plan=fix_period_by_time($examplePlan);
$plan=enrich_itinerary_with_cafe_fields($plan,$cafeIndexAll);

// 去除同地點重複（不同時段）版
$seen_places=[];
$plan_unique=[];
foreach($plan as $item){
    $place=$item['place']??'';
    if(empty($place) || !isset($seen_places[$place])){
        $plan_unique[]=$item;
        if(!empty($place)) $seen_places[$place]=true;
    } else {
        // 如果重複，可以合併 note
        foreach($plan_unique as &$p){
            if(($p['place']??'')==$place){
                $p['note'].='；'.$item['note'];
                break;
            }
        }
    }
}
$plan=$plan_unique;

// ============================ 候選咖啡廳 ============================
$candidates=to_candidates($cafes,5,$must_include);

// ============================ 輸出 ============================
echo json_encode([
    'success'=>true,
    'date'=>$date,
    'mood'=>$mood,
    'weather'=>$weather,
    'candidates'=>$candidates,
    'plan'=>$plan
], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
