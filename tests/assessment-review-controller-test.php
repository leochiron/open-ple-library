<?php
declare(strict_types=1);
namespace App\Services { class I18nService {} }
namespace {
require __DIR__.'/bootstrap.php';
require __DIR__.'/fixtures/AssessmentDefinitions.php';
require dirname(__DIR__).'/app/Controllers/AssessmentReviewController.php';
function assetBase():string{return '/assets';}
function render(string $view,array $data,$i18n,array $config):void{extract($data);include dirname(__DIR__).'/app/Views/quiz/admin/submissions.php';}
$root=sys_get_temp_dir().'/ple-controller-tests/'.bin2hex(random_bytes(4));mkdir($root.'/remises-cc/DEMO-1',0700,true);
assessmentFixture($root);
$id=str_repeat('e',32);
$r=['assessment'=>'DEMO-1','reference'=>'DEMO-1-'.strtoupper(substr($id,0,12)),'received_at'=>'2026-10-14T19:00:01+02:00','nom'=>'Exemple','prenom'=>'Élise','github_url'=>'https://github.com/exemple/musee'];
file_put_contents($root.'/remises-cc/DEMO-1/'.$id.'.json',json_encode($r));
$service=new \App\Services\AssessmentReviewService($root);
$controller=new \App\Controllers\AssessmentReviewController($service);
$passed=0;
function request(string $path,string $role='super_admin',string $method='GET',array $get=[],array $post=[]):array{
    global $controller;$_GET=$get;$_POST=$post;$_SESSION=[];$_SERVER['REQUEST_METHOD']=$method;http_response_code(200);ob_start();
    $controller->handle($path,['role'=>$role,'display_name'=>'Formateur Test'],'valid-token',new \App\Services\I18nService(),[]);
    return ['code'=>http_response_code(),'body'=>ob_get_clean()];
}
function test(bool $ok,string $label):void{global $passed;if(!$ok)throw new \RuntimeException('FAIL '.$label);$passed++;}
$out=request('/remises','quiz_admin');test($out['code']===404&&!str_contains($out['body'],'Élise'),'enseignant sans droit exclu');
$out=request('/remises','','GET');test($out['code']===404,'absence rôle exclue');
$out=request('/remises/save','super_admin','POST',[],['id'=>$id,'status'=>'verified']);test($out['code']===403,'CSRF obligatoire');
test($service->review('DEMO-1',$id)['status']==='pending','requête CSRF ne modifie rien');
$out=request('/remises','super_admin','GET');test(str_contains($out['body'],'14/10/2026 19:00:01')&&str_contains($out['body'],'En retard'),'liste avec date et délai');
$out=request('/remises','super_admin','GET',['q'=>'introuvable']);test(str_contains($out['body'],'Aucune remise ne correspond aux filtres.'),'écran filtre vide');
$service->saveReview('DEMO-1',$id,'incomplete','<script>alert(1)</script>',0,'Test');
$out=request('/remises','super_admin','GET',['id'=>$id]);test(str_contains($out['body'],'&lt;script&gt;')&&!str_contains($out['body'],'<script>alert(1)</script>'),'commentaire HTML échappé');
$out=request('/remises','super_admin','GET',['course'=>'../../secret']);test($out['code']===404,'épreuve arbitraire exclue');
$out=request('/remises/save','super_admin','GET');test($out['code']===405,'modification GET interdite');
$out=request('/remises/save','super_admin','POST',[],['_csrf'=>'valid-token','id'=>$id,'status'=>'verified','comment'=>'Validation','version'=>'1']);test($out['code']===303&&$service->review('DEMO-1',$id)['status']==='verified','POST valide persisté et redirigé');
$out=request('/remises/export','super_admin','GET');test(str_contains($out['body'],'Élise')&&str_contains($out['body'],'2026-10-14T19:00:01+02:00'),'export daté');
$parent=file_get_contents(dirname(__DIR__).'/app/Controllers/QuizAdminController.php');
test(strpos($parent,'$this->currentAdmin === null')<strpos($parent,"$"."subPath === '/remises'"),'route placée après connexion existante');
$navigation=file_get_contents(dirname(__DIR__).'/app/Views/quiz/admin/index.php');test(strpos($navigation,"$"."admin['role'] === 'super_admin'")<strpos($navigation,'/quiz-admin/remises'),'bouton réservé au superadmin');

echo $passed.' tests contrôleur réussis.' . "\n";
}
