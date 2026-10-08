<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use App\Services\QuizBrowserEvaluator as Browser;
$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures/BrowserPolicyFixtures.json'),true,512,JSON_THROW_ON_ERROR);
$results=[];$count=0;
foreach($fixtures['groups']as$group){foreach($group['cases']as$case){
    $input=$case['input'];$policy=$case['policy']??$fixtures['defaults']['policy'];
    $browser=['user_agent'=>$input['client_ua'],'brands'=>$input['brands'],'capabilities'=>array_replace($fixtures['defaults']['capabilities'],$input['capabilities'])];
    $actual=Browser::evaluate($input['http_ua'],$browser,$policy);$expected=$case['expected'];
    assertSameValue(['family'=>$expected['http_family'],'major'=>$expected['http_product_major']],$actual['canonical']['http'],$case['id'].' HTTP product');
    assertSameValue(['family'=>$expected['client_family'],'major'=>$expected['client_product_major']],$actual['canonical']['javascript'],$case['id'].' JS product');
    assertSameValue($expected['browser_allowed'],$actual['allowed'],$case['id'].' policy');assertSameValue($expected['private_causes'],$actual['causes'],$case['id'].' private causes');
    $results[$case['id']]=$actual;++$count;
}}
assertSameValue(58,$count,'All independent fixtures used');
foreach($fixtures['canonical_equivalence_pairs']as$pair){assertSameValue($pair['same_canonical_environment'],$results[$pair['a']]['environment_fingerprint']===$results[$pair['b']]['environment_fingerprint'],'Canonical pair '.$pair['a'].'/'.$pair['b']);}
$input=$fixtures['groups'][0]['cases'][0]['input'];$base=['user_agent'=>$input['client_ua'],'brands'=>$input['brands'],'capabilities'=>$fixtures['defaults']['capabilities']];$policy=$fixtures['defaults']['policy'];
foreach(Browser::CAPABILITIES as$cap){$false=$base;$false['capabilities'][$cap]=false;$actual=Browser::evaluate($input['http_ua'],$false,$policy);assertSameValue($cap==='fullscreen',$actual['allowed'],'Required API '.$cap);}
$duplicate=$base;$duplicate['brands'][]=['brand'=>'Google Chrome','version'=>'151.9'];assertSameValue(true,Browser::evaluate($input['http_ua'],$duplicate,$policy)['allowed'],'Duplicate same major harmless');$duplicate['brands'][]=['brand'=>'Google Chrome','version'=>'150'];assertSameValue(['browser_info_inconsistent'],Browser::evaluate($input['http_ua'],$duplicate,$policy)['causes'],'Contradictory recognized duplicate');
$malformed=$base;$malformed['brands']=[['brand'=>'Google Chrome','version'=>'bad']];assertSameValue(['browser_info_inconsistent'],Browser::evaluate($input['http_ua'],$malformed,$policy)['causes'],'Malformed recognized brand not ignored');
$grease=$base;$grease['brands'][]=['brand'=>'Not.A/Brand;=?','version'=>'99'];assertSameValue(true,Browser::evaluate($input['http_ua'],$grease,$policy)['allowed'],'Punctuated GREASE accepted');
$c1ua=array_replace($base,['user_agent'=>"bad\u{0085}UA"]);$c1brand=$base;$c1brand['brands']=[['brand'=>"bad\u{0085}brand",'version'=>'1']];
foreach([' Firefox/144',' Edg/151 Firefox/144',' Chrome/150']as$extra){$ua=$input['http_ua'].$extra;$contradiction=$base;$contradiction['user_agent']=$ua;$contradiction['brands']=null;assertSameValue(['browser_info_inconsistent'],Browser::evaluate($ua,$contradiction,$policy)['causes'],'Concordant HTTP/JS incompatible tokens remain inconsistent');}
$safari='Mozilla/5.0 Version/18 Safari/605 Version/19';$contradiction=$base;$contradiction['user_agent']=$safari;$contradiction['brands']=null;assertSameValue(['browser_info_inconsistent'],Browser::evaluate($safari,$contradiction,$policy)['causes'],'Safari repeated product Version majors conflict');
$bad=[$base+['extra'=>true],array_replace($base,['user_agent'=>'']),array_replace($base,['user_agent'=>str_repeat('x',1025)]),array_replace($base,['user_agent'=>"bad\nUA"]),$c1ua,$c1brand,array_replace($base,['brands'=>['x'=>['brand'=>'Google Chrome','version'=>'151']]]),array_replace($base,['brands'=>array_fill(0,13,['brand'=>'Unknown','version'=>'1'])])];$caps=$base;$caps['capabilities']['fetch_api']=1;$bad[]=$caps;
foreach($bad as$browser){try{Browser::normalizeEnvelope($browser);throw new LogicException('Expected schema rejection');}catch(RuntimeException $e){assertSameValue('schema_invalid',$e->getMessage(),'Closed/bounded envelope');}}
echo "QuizBrowserEvaluatorTest: OK (58 independent fixtures/11 canonical pairs/capabilities/closed transport)\n";
