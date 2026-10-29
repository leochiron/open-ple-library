<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/fixtures/AssessmentDefinitions.php';
use App\Services\AssessmentReviewService as Review;
$root=sys_get_temp_dir().'/ple-assessment-tests/'.bin2hex(random_bytes(4));
if(!is_dir($root.'/remises-cc/DEMO-1'))mkdir($root.'/remises-cc/DEMO-1',0700,true);
assessmentFixture($root);
$passed=0;
function check(bool $condition,string $name):void{global $passed;if(!$condition)throw new RuntimeException('FAIL: '.$name);$passed++;echo 'PASS '.$name."\n";}
function rejects(callable $f,string $name):void{try{$f();}catch(RuntimeException $e){check(true,$name);return;}check(false,$name);}
function record(string $id,string $date,string $nom='Exemple'):array{return ['assessment'=>'DEMO-1','reference'=>'DEMO-1-'.strtoupper(substr($id,0,12)),'received_at'=>$date,'nom'=>$nom,'prenom'=>'Élise','github_url'=>'https://github.com/exemple/musee'];}
$a=str_repeat('a',32);$b=str_repeat('b',32);$c=str_repeat('c',32);
$s=new Review($root);
check($s->all('DEMO-1')['records']===[],'aucune remise');
foreach([$a=>'2026-10-14T19:00:00+02:00',$b=>'2026-10-14T19:00:01+02:00',$c=>'2026-10-14T18:59:00+02:00'] as $id=>$date)file_put_contents($root.'/remises-cc/DEMO-1/'.$id.'.json',json_encode(record($id,$date)));
file_put_contents($root.'/remises-cc/DEMO-1/invalid.json','{broken');
$all=$s->all('DEMO-1');check(count($all['records'])===3 && count($all['warnings'])===1,'document corrompu signalé sans masquer les autres');
check(!$s->get('DEMO-1',$a)['late'] && $s->get('DEMO-1',$b)['late'],'borne exacte échéance et seconde de retard');
check($s->get('DEMO-1',$a)['received_label']==='14/10/2026 19:00:00','heure de Paris');
check($all['records'][0]['id']===$b,'tri réception décroissant');
check(count($s->filtered($all['records'],'Élise','','late'))===1,'recherche et filtre retard');
rejects(fn()=>$s->get('DEMO-1','../secret'),'refus traversée chemins');
rejects(fn()=>$s->all('../DEMO-1'),'épreuve hors périmètre');
rejects(fn()=>Review::repository('https://github.com.evil.test/exemple/depot'),'hôte GitHub exact');
rejects(fn()=>Review::repository('http://127.0.0.1/secret'),'URL arbitraire interdite');
check(Review::csvSafe(' =HYPERLINK("evil")')[0]==="'",'export neutralise les formules');
$sourceHash=hash_file('sha256',$root.'/remises-cc/DEMO-1/'.$a.'.json');
$s->saveReview('DEMO-1',$a,'verified','Commentaire <script>privé</script>',0,'Formateur Test');
$again=new Review($root);check($again->review('DEMO-1',$a)['status']==='verified','statut et commentaire persistants');
check(count($s->filtered($s->all('DEMO-1')['records'],'','verified',''))===1,'filtre état');
rejects(fn()=>$s->saveReview('DEMO-1',$a,'incomplete','ancien onglet',0,'Test'),'version périmée ne remplace pas le suivi');
rejects(fn()=>$s->saveReview('DEMO-1',$a,'bad','',1,'Test'),'statut inconnu refusé');
$sha=str_repeat('1',40);$requests=[];
$http=function(string $path)use($sha,&$requests):array{$requests[]=$path;if(str_contains($path,'/branches?'))return ['status'=>200,'data'=>[['name'=>'main'],['name'=>'feature/theme'],['name'=>'feature/confirmation'],['name'=>'hotfix/lisibilite'],['name'=>'feature/atelier']]];if(str_contains($path,'/commits/main'))return ['status'=>200,'data'=>['sha'=>$sha]];if(str_contains($path,'/contents/'))return ['status'=>200,'data'=>['type'=>'file','size'=>20]];return ['status'=>200,'data'=>['private'=>false]];};
$network=new Review($root,$http);$result=$network->runCheck('DEMO-1',$a,'Test');
check($result['state']==='observed' && $result['main_sha']===$sha && count($result['branches'])===5,'contrôle GitHub public complet');
check(in_array('/repos/exemple/musee/contents/EXPLICATIONS.md?ref='.$sha,$requests,true),'fichier contrôlé au commit observé');
$stored=$s->review('DEMO-1',$a);check($stored['status']==='verified'&&$stored['comment']==='Commentaire <script>privé</script>','contrôle conserve validation et commentaire');
$network->runCheck('DEMO-1',$a,'Test');check(count($s->review('DEMO-1',$a)['check_history'])===2,'contrôles datés conservés');
check(hash_file('sha256',$root.'/remises-cc/DEMO-1/'.$a.'.json')===$sourceHash,'remise originale inchangée');
$notfound=new Review($root,fn($p)=>['status'=>404,'data'=>['message'=>'Not Found']]);check($notfound->checkRepository('https://github.com/exemple/musee')['state']==='inaccessible','404 distingué du contrôle indisponible');
$missing=new Review($root,function($p)use($http):array{if(str_contains($p,'/contents/'))return ['status'=>404,'data'=>[]];return $http($p);});check($missing->checkRepository('https://github.com/exemple/musee')['state']==='missing','fichier manquant signalé');
$timeout=new Review($root,fn($p)=>throw new RuntimeException('GitHub indisponible'));check($timeout->runCheck('DEMO-1',$b,'Test')['state']==='unavailable','incident réseau sans fausse absence');
$s->saveDeadline('DEMO-1','2026-10-14T19:01');check(!$s->get('DEMO-1',$b)['late'],'échéance modifiable et délai recalculé');
rejects(fn()=>$s->saveDeadline('DEMO-1','2026-02-30T19:00'),'date impossible refusée');
check(count($s->all('DEMO-1')['records'])===3,'différents envois conservés');

echo $passed.' tests réussis.' . "\n";
