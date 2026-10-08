<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require dirname(__DIR__).'/app/Helpers/url.php';
use App\Services\QuizService;
use App\Services\QuizDbService;
use App\Services\QuizAdminAuthService;
use App\Services\I18nService;
use App\Controllers\QuizController;
use App\Controllers\QuizAdminController;

final class PreflightFixture extends QuizService
{
    public int $clock=100000; public bool $advancing=false;
    protected function now(): string { $value=$this->clock;if($this->advancing){++$this->clock;}return gmdate('Y-m-d H:i:s',$value); }
}
$tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'ple-preflight-'.bin2hex(random_bytes(5));mkdir($tmp,0700,true);
try{
    $_SESSION=[];$database=new QuizDbService($tmp);$db=$database->pdo();$auth=new QuizAdminAuthService($database,false);
    $owner=$auth->createAdmin('owner@example.test','Owner','owner-password','quiz_admin',false);
    $foreign=$auth->createAdmin('foreign@example.test','Foreign','foreign-password','quiz_admin',false);
    $config=['branding'=>['quiz_hmac_secret'=>str_repeat('p',64)]];$quiz=new PreflightFixture($database,$config,$tmp);$quiz->setAdminContext($owner);
    $rules=['title'=>'Preflight','google_form_url'=>'https://docs.google.com/forms/d/e/PRIVATE_FORM/viewform','attempt_entry_id'=>'123456','duration_minutes'=>15,'max_incidents'=>2,'min_away_seconds'=>10];
    $sid=(int)$quiz->createSession($rules,"Alice Alpha alice@example.test\nBob Beta bob@example.test")['id'];$quiz->openLobby($sid);$students=$quiz->listStudents($sid);$a=$quiz->joinAttempt($quiz->getSession($sid),$students[0]['code']);$aid=(int)$a['id'];$student=(int)$a['student_id'];$_SESSION['quiz_attempt_id']=$aid;
    $gen=static fn():string=>$quiz->getSession($sid)['tracking_generation'];$session=static fn():array=>$quiz->getSession($sid);
    $reject=static function(callable $action,string $code)use($db):void{try{$action();throw new LogicException('Expected rejection');}catch(RuntimeException $e){assertSameValue($code,$e->getMessage(),'Strict private rejection');}assertSameValue(false,$db->inTransaction(),'No transaction leaks');};
    assertSameValue('off',$session()['tracking_mode'],'Existing/new sessions start historical off');
    $epoch=$quiz->openTrackingRoomDocument($sid,$aid);$cookieA=$_SESSION;
    $quiz->setTrackingMode($sid,'preflight',0,'Fictitious pilot only');$before=$db->query('SELECT * FROM quiz_session_audit ORDER BY id')->fetchAll();
    $reject(static fn()=>$quiz->setTrackingMode($sid,'preflight',1,'No-op'),'tracking_mode_unchanged');
    assertSameValue(1,(int)$session()['settings_revision'],'No-op preserves revision');assertSameValue($before,$db->query('SELECT * FROM quiz_session_audit ORDER BY id')->fetchAll(),'No-op no success audit');
    $quiz->launch($sid);$state=$quiz->prepareTrackingState($sid,$aid);
    assertSameValue(false,$state['access_allowed'],'Lobby document adopts launch generation as pending');assertSameValue(false,isset($state['form_url']),'Pending response no Forms URL');
    $checks=['listener_roundtrip'=>true,'trusted_enter_received'=>true,'hidden_received'=>true,'visible_after_hidden_received'=>true,'focus_after_hidden_received'=>true,'fullscreen_change_received'=>false,'fullscreen_active'=>false];
    $challenge=$quiz->createTrackingChallenge($sid,$aid,$gen(),$epoch);
    $state=$quiz->submitTrackingPreflight($sid,$aid,$gen(),$epoch,$challenge['challenge'],$checks);
    assertSameValue(true,$state['access_allowed'],'Fresh valid preflight admits');assertSameValue(100060,$state['access_until'],'Permission bounded to proof60');
    $reject(static fn()=>$quiz->submitTrackingPreflight($sid,$aid,$gen(),$epoch,$challenge['challenge'],$checks),'challenge_replayed');
    $eventsBefore=$quiz->listEventsForAttempt($aid);$quiz->clock=100059;assertSameValue(true,$quiz->prepareTrackingState($sid,$aid)['access_allowed'],'Proof valid at59');
    $quiz->clock=100060;assertSameValue(false,$quiz->prepareTrackingState($sid,$aid)['access_allowed'],'Proof expired at60');
    $quiz->clock=100061;assertSameValue(false,$quiz->prepareTrackingState($sid,$aid)['access_allowed'],'Still expired61');
    assertSameValue($eventsBefore,$quiz->listEventsForAttempt($aid),'Diagnostic and expiry never create ordinary events');
    assertSameValue(1,(int)$db->query("SELECT COUNT(*) FROM quiz_tracking_diagnostics WHERE code='proof_expired'")->fetchColumn(),'Expiry transition logged only once');
    $challenge=$quiz->createTrackingChallenge($sid,$aid,$gen(),$epoch);$quiz->clock+=119;
    assertSameValue(true,$quiz->submitTrackingPreflight($sid,$aid,$gen(),$epoch,$challenge['challenge'],$checks)['access_allowed'],'Challenge valid119');
    $challenge=$quiz->createTrackingChallenge($sid,$aid,$gen(),$epoch);$quiz->clock+=120;
    $reject(static fn()=>$quiz->submitTrackingPreflight($sid,$aid,$gen(),$epoch,$challenge['challenge'],$checks),'challenge_expired');
    $challenge=$quiz->createTrackingChallenge($sid,$aid,$gen(),$epoch);$bad=$checks;$bad['listener_roundtrip']=1;
    $reject(static fn()=>$quiz->submitTrackingPreflight($sid,$aid,$gen(),$epoch,$challenge['challenge'],$bad),'schema_invalid');
    $quiz->submitTrackingPreflight($sid,$aid,$gen(),$epoch,$challenge['challenge'],$checks);
    $failure=$checks;$failure['trusted_enter_received']=false;$challenge=$quiz->createTrackingChallenge($sid,$aid,$gen(),$epoch);
    assertSameValue(false,$quiz->submitTrackingPreflight($sid,$aid,$gen(),$epoch,$challenge['challenge'],$failure)['access_allowed'],'Fresh failed revokes healthy before expiry');
    $override=$quiz->grantTechnicalOverride($sid,$student,$gen(),['browser'],'Browser only');assertSameValue(false,$quiz->prepareTrackingState($sid,$aid)['access_allowed'],'Browser override does not bypass tracking');$quiz->revokeTechnicalOverride($sid,$student,$override,$gen(),'Wrong scope');
    $override=$quiz->grantTechnicalOverride($sid,$student,$gen(),['tracking'],'PRIVATE_OVERRIDE_REASON');$state=$quiz->prepareTrackingState($sid,$aid);
    assertSameValue(true,$state['access_allowed'],'Tracking override admits failed diagnostic');assertSameValue($quiz->clock+60,$state['access_until'],'Override permission bounded independently');
    assertSameValue('failed',$quiz->getTrackingContexts($sid,$aid)['rows'][0]['proof_status'],'Override never fabricates healthy');
    $quiz->setManualAccess($sid,$student,true,'Manual absolute');assertSameValue(false,$quiz->prepareTrackingState($sid,$aid)['access_allowed'],'Manual absolute even under tracking override');$quiz->setManualAccess($sid,$student,false,'Lift explicitly');
    $quiz->revokeTechnicalOverride($sid,$student,$override,$gen(),'Return to actual causes');assertSameValue(false,$quiz->prepareTrackingState($sid,$aid)['access_allowed'],'Revoke restores failed cause');

    // Same attempt, another cookie: no shared healthy proof, no shared failed cause.
    $challenge=$quiz->createTrackingChallenge($sid,$aid,$gen(),$epoch);$quiz->submitTrackingPreflight($sid,$aid,$gen(),$epoch,$challenge['challenge'],$checks);$cookieA=$_SESSION;
    $_SESSION=['quiz_attempt_id'=>$aid];$epochB=$quiz->openTrackingRoomDocument($sid,$aid);assertSameValue(false,$quiz->prepareTrackingState($sid,$aid)['access_allowed'],'CookieB gets no proofA');$cookieB=$_SESSION;
    $_SESSION=$cookieA;$quiz->setTrackingRequestEpoch($epoch);assertSameValue(true,$quiz->prepareTrackingState($sid,$aid)['access_allowed'],'B missing proof does not block healthyA');
    $oldEpoch=$epoch;$oldChallenge=$quiz->createTrackingChallenge($sid,$aid,$gen(),$epoch);$epoch=$quiz->openTrackingRoomDocument($sid,$aid);
    $reject(static fn()=>$quiz->createTrackingChallenge($sid,$aid,$gen(),$oldEpoch),'document_epoch_mismatch');
    $quiz->setTrackingRequestEpoch($epoch);assertSameValue(false,$quiz->prepareTrackingState($sid,$aid)['access_allowed'],'Reload ends old healthy even before60');
    $challenge=$quiz->createTrackingChallenge($sid,$aid,$gen(),$epoch);$quiz->updateSession($sid,$rules);
    $reject(static fn()=>$quiz->submitTrackingPreflight($sid,$aid,$gen(),$epoch,$challenge['challenge'],$checks),'settings_revision_mismatch');
    assertSameValue('preflight',$session()['tracking_mode'],'Legacy settings form preserves tracking mode');

    $challenge=$quiz->createTrackingChallenge($sid,$aid,$gen(),$epoch);$quiz->submitTrackingPreflight($sid,$aid,$gen(),$epoch,$challenge['challenge'],$checks);
    $rules['title']='Changed title';$quiz->updateSession($sid,$rules);assertSameValue(true,$quiz->prepareTrackingState($sid,$aid)['access_allowed'],'Title/quota updates preserve existing proof');
    $rules['require_fullscreen']='on';$quiz->updateSession($sid,$rules);assertSameValue(false,$quiz->prepareTrackingState($sid,$aid)['access_allowed'],'Added fullscreen requires fresh coverage');
    $challenge=$quiz->createTrackingChallenge($sid,$aid,$gen(),$epoch);$quiz->submitTrackingPreflight($sid,$aid,$gen(),$epoch,$challenge['challenge'],$checks);
    unset($rules['require_fullscreen']);$quiz->updateSession($sid,$rules);assertSameValue(false,$quiz->prepareTrackingState($sid,$aid)['access_allowed'],'RemovingFS after failed does not invent healthy/TTL');
    $challenge=$quiz->createTrackingChallenge($sid,$aid,$gen(),$epoch);$quiz->submitTrackingPreflight($sid,$aid,$gen(),$epoch,$challenge['challenge'],$checks);
    $oldGeneration=$gen();$quiz->stop($sid);$quiz->launch($sid);$state=$quiz->prepareTrackingState($sid,$aid);assertSameValue(false,$state['access_allowed'],'Stop/launch same document requires new proof');
    $reject(static fn()=>$quiz->createTrackingChallenge($sid,$aid,$oldGeneration,$epoch),'generation_mismatch');
    $challenge=$quiz->createTrackingChallenge($sid,$aid,$gen(),$epoch);$quiz->submitTrackingPreflight($sid,$aid,$gen(),$epoch,$challenge['challenge'],$checks);
    $reject(static fn()=>$quiz->recordEvent($session(),$quiz->getAttempt($aid),'finish',0,[]),'generation_mismatch');
    $meta=static fn():array=>['attempt_id'=>$aid,'event_uid'=>bin2hex(random_bytes(16)),'tracking_generation'=>$gen(),'source'=>'page'];
    $finish=$meta();$quiz->recordEvent($session(),$quiz->getAttempt($aid),'finish',0,$finish);$finishedAt=$quiz->getAttempt($aid)['finished_at'];
    $epoch=$quiz->openTrackingRoomDocument($sid,$aid);$quiz->prepareTrackingState($sid,$aid);assertSameValue($finishedAt,$quiz->getAttempt($aid)['finished_at'],'Reload/preflight does not clear finish');
    $challenge=$quiz->createTrackingChallenge($sid,$aid,$gen(),$epoch);$quiz->submitTrackingPreflight($sid,$aid,$gen(),$epoch,$challenge['challenge'],$checks);$quiz->recordEvent($session(),$quiz->getAttempt($aid),'resume',0,$meta());assertSameValue(null,$quiz->getAttempt($aid)['finished_at'],'Resume works after fresh proof even finished journal stopped');
    $oldGeneration=$gen();$quiz->resetAndRelaunch($sid);$state=$quiz->prepareTrackingState($sid,$aid);assertSameValue(false,$state['access_allowed'],'Reset preserves attempt/doc but requires proof');assertSameValue($aid,(int)$quiz->getAttempt($aid)['id'],'Reset keeps attempt identity');
    $archive=$quiz->getArchive((int)$quiz->listArchives($sid)['rows'][0]['id']);assertSameValue('preflight',$archive['snapshot']['tracking_policy']['mode'],'Snapshot freezes mode');assertSameValue(true,isset($archive['snapshot']['tracking_contexts']),'Snapshot keeps targeted contexts before termination');
    $reject(static fn()=>$quiz->recordEvent($session(),$quiz->getAttempt($aid),'finish',0,$finish),'stale_generation');
    $quiz->setTrackingRequestEpoch($oldEpoch);$ack=$quiz->recordEvent($session(),$quiz->getAttempt($aid),'hidden',12,[]);assertSameValue(false,isset($ack['access_allowed'])||isset($ack['form_url']),'Ordinary legacy ACK does not authorize old document');assertSameValue(1,(int)$quiz->getAttempt($aid)['incident_count'],'Old ordinary observation before admission stays incident');
    $quiz->setTrackingRequestEpoch($epoch);

    // Failure after consuming challenge must roll back every proof/diagnostic/nonce.
    $challenge=$quiz->createTrackingChallenge($sid,$aid,$gen(),$epoch);
    $tables=static function()use($db):array{$out=[];foreach(['quiz_tracking_contexts','quiz_tracking_challenges','quiz_tracking_diagnostics']as$table){$out[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();}return $out;};$before=$tables();
    $db->exec("CREATE TRIGGER fail_preflight BEFORE INSERT ON quiz_tracking_diagnostics BEGIN SELECT RAISE(ABORT,'preflight_failure'); END");
    try{$quiz->submitTrackingPreflight($sid,$aid,$gen(),$epoch,$challenge['challenge'],$checks);throw new LogicException('Expected SQL failure');}catch(PDOException $e){assertSameValue(true,str_contains($e->getMessage(),'preflight_failure'),'Injected diagnostic failure');}finally{$db->exec('DROP TRIGGER fail_preflight');}
    assertSameValue($before,$tables(),'SQL failure rolls back consumption/proof/diagnostic atomically');assertSameValue(false,$db->inTransaction(),'Rollback complete');
    $state=$quiz->submitTrackingPreflight($sid,$aid,$gen(),$epoch,$challenge['challenge'],$checks);$proofUntil=$state['access_until'];$quiz->clock=$proofUntil-1;$quiz->advancing=true;$state=$quiz->prepareTrackingState($sid,$aid);$quiz->advancing=false;
    assertSameValue($proofUntil,$state['access_until'],'Single locked decision instant cannot extend proof across60');
    $quiz->clock=$proofUntil+1;$quiz->refreshTrackingProofs($sid);assertSameValue('expired',$quiz->getTrackingContexts($sid,$aid)['rows'][0]['proof_status'],'Teacher poll detects expiry with no student traffic');
    for($i=0;$i<28;$i++){$epoch=$quiz->openTrackingRoomDocument($sid,$aid);}
    $first=$quiz->getTrackingContexts($sid,$aid);$next=$quiz->getTrackingContexts($sid,$aid,$first['next_before']);assertSameValue(25,count($first['rows']),'Contexts default bounded25');assertSameValue([],array_intersect(array_column($first['rows'],'id'),array_column($next['rows'],'id')),'Context cursor gives disjoint next page');
    $public=$quiz->prepareTrackingState($sid,$aid);foreach(['proof_status','checks','context_ref','scope','PRIVATE_OVERRIDE_REASON','cookie_binding_hash']as$private){assertSameValue(false,str_contains(json_encode($public),$private),'No private marker public');}

    // Reset snapshots observe expiry even with no intervening state/poll and contain no secrets.
    $challenge=$quiz->createTrackingChallenge($sid,$aid,$gen(),$epoch);
    $healthy=$quiz->submitTrackingPreflight($sid,$aid,$gen(),$epoch,$challenge['challenge'],$checks);
    $quiz->clock=$healthy['access_until'];$quiz->resetAndRelaunch($sid);
    $archive=$quiz->getArchive((int)$quiz->listArchives($sid)['rows'][0]['id']);
    $archivedContexts=$archive['snapshot']['tracking_contexts'];$currentArchived=end($archivedContexts);
    assertSameValue('expired',$currentArchived['proof_status'],'Reset snapshots effective expiry before termination');
    $frozen=json_encode($archive['snapshot'],JSON_THROW_ON_ERROR);
    foreach(['cookie_binding_hash','document_epoch_hash','proof_incarnation','secret_hash','quiz_student_csrf','_csrf',$epoch,$challenge['challenge']]as$secret){assertSameValue(false,str_contains($frozen,$secret),'No tracking secret in archive');}
    $csv=$quiz->exportCsv($sid);$archiveCsv=$quiz->exportArchiveCsv((int)$archive['id']);
    assertSameValue(true,str_contains($csv,'diagnostic_suivi'),'CSV includes complete private diagnostics');
    assertSameValue(true,str_contains($archiveCsv,'archive_contexte_suivi'),'Archive CSV includes frozen targeted contexts');
    assertSameValue(true,str_contains($archiveCsv,'expired'),'Archive CSV agrees with snapshot proof state');
    foreach(['cookie_binding_hash','document_epoch_hash','proof_incarnation','secret_hash',$epoch,$challenge['challenge']]as$secret){assertSameValue(false,str_contains($csv.$archiveCsv,$secret),'CSV contains no protocol secret');}

    // Policy/audit failure rolls back context/challenge invalidation and the policy revision.
    $quiz->prepareTrackingState($sid,$aid);$challenge=$quiz->createTrackingChallenge($sid,$aid,$gen(),$epoch);
    $quiz->submitTrackingPreflight($sid,$aid,$gen(),$epoch,$challenge['challenge'],$checks);
    $before=$tables();$oldSession=$session();$auditBefore=$quiz->listAudit($sid);
    $db->exec("CREATE TRIGGER fail_tracking_mode BEFORE INSERT ON quiz_session_audit WHEN NEW.action='tracking_mode_changed' BEGIN SELECT RAISE(ABORT,'tracking_mode_failure'); END");
    try{$quiz->setTrackingMode($sid,'off',(int)$session()['settings_revision'],'Injected rollback');throw new LogicException('Expected SQL failure');}catch(PDOException $e){assertSameValue(true,str_contains($e->getMessage(),'tracking_mode_failure'),'Mode failure after invalidation');}finally{$db->exec('DROP TRIGGER fail_tracking_mode');}
    assertSameValue($before,$tables(),'Mode SQL failure rolls back every context and diagnostic');assertSameValue($oldSession,$session(),'Mode/revision rolled back');assertSameValue($auditBefore,$quiz->listAudit($sid),'No successful audit on failure');
    $before=$tables();$oldSession=$session();$archivesBefore=$quiz->listArchives($sid);
    $db->exec("CREATE TRIGGER fail_tracking_reset BEFORE INSERT ON quiz_session_audit WHEN NEW.action='reset' BEGIN SELECT RAISE(ABORT,'tracking_reset_failure'); END");
    try{$quiz->resetAndRelaunch($sid);throw new LogicException('Expected SQL failure');}catch(PDOException $e){assertSameValue(true,str_contains($e->getMessage(),'tracking_reset_failure'),'Reset failure after archiving and invalidation');}finally{$db->exec('DROP TRIGGER fail_tracking_reset');}
    assertSameValue($before,$tables(),'Reset SQL failure rolls back contexts/challenges/diagnostics');assertSameValue($oldSession,$session(),'Reset generation/revision rolled back');assertSameValue($archivesBefore,$quiz->listArchives($sid),'Reset archive rolled back');

    // Refusals are precise privately, without any refused values; owner transfer controls reads.
    $quiz->recordTrackingRefusal('preflight','challenge_replayed');
    $private=$quiz->listTrackingDiagnostics($sid);assertSameValue('challenge_replayed',$private['rows'][0]['code'],'Private refusal is analysable');
    $quiz->setAdminContext($foreign);$reject(static fn()=>$quiz->listTrackingDiagnostics($sid),'resource_not_found');$quiz->setAdminContext($owner);
    $quiz->setAdminContext($foreign);$reject(static fn()=>$quiz->getTrackingContexts($sid,$aid),'resource_not_found');$quiz->setAdminContext($owner);

    // Empty context list is known and explicit; old snapshots remain unknown, never backfilled.
    $emptySid=(int)$quiz->createSession($rules,"Carol Gamma carol@example.test")['id'];$quiz->openLobby($emptySid);$emptyStudent=$quiz->listStudents($emptySid)[0];
    $emptyAttempt=$quiz->joinAttempt($quiz->getSession($emptySid),$emptyStudent['code']);$quiz->resetAndRelaunch($emptySid);
    $emptyArchive=$quiz->getArchive((int)$quiz->listArchives($emptySid)['rows'][0]['id']);
    assertSameValue('off',$emptyArchive['snapshot']['tracking_policy']['mode'],'Empty/off snapshot records explicit policy');assertSameValue([],$emptyArchive['snapshot']['tracking_contexts'],'Empty snapshot context is known empty');
    unset($emptyArchive['snapshot']['tracking_contexts'],$emptyArchive['snapshot']['tracking_policy']);
    $stmt=$db->prepare('UPDATE quiz_attempt_archives SET snapshot_json=:json WHERE id=:id');$stmt->execute(['json'=>json_encode($emptyArchive['snapshot'],JSON_THROW_ON_ERROR),'id'=>$emptyArchive['id']]);
    assertSameValue(false,array_key_exists('tracking_contexts',$quiz->getArchive((int)$emptyArchive['id'])['snapshot']),'Legacy absent context remains absent');
    assertSameValue(true,str_contains($quiz->exportArchiveCsv((int)$emptyArchive['id']),'non conservé'),'Legacy CSV explicitly reports unknown tracking policy');

    // Actual rendered preflight HTML strips URL even when an override admits the new document.
    $i18n=new I18nService(['default_language'=>'fr'],require dirname(__DIR__).'/app/Config/i18n.php');
    $studentController=new QuizController($quiz,$i18n,$config);$adminController=new QuizAdminController($quiz,$auth,$i18n,$config);
    $capture=static function(object $controller,string $method):string{$reflection=new ReflectionMethod($controller,$method);ob_start();try{$reflection->invoke($controller);return (string)ob_get_contents();}finally{ob_end_clean();}};
    $override=$quiz->grantTechnicalOverride($sid,$student,$gen(),['tracking'],'PRIVATE_ACCESS_REASON');
    $_SESSION['quiz_attempt_id']=$aid;$_SESSION['quiz_session_id']=$sid;
    $html=$capture($studentController,'showRoom');
    foreach(['PRIVATE_FORM','PRIVATE_ACCESS_REASON','cookie_binding_hash','proof_status','listener_probe_failed','context_ref']as$marker){assertSameValue(false,str_contains($html,$marker),'Preflight HTML is safe even under override');}
    assertSameValue(true,str_contains($html,'roomEpoch'),'Current document receives its opaque transport epoch');
    $_GET=['id'=>$sid];$board=$capture($adminController,'apiBoard');assertSameValue(false,str_contains($board,'preflight_failed')||str_contains($board,'context_ref')||str_contains($board,'proof_status'),'Projected board has no tracking diagnosis');
    $renderHistory=static function()use($quiz,$sid,$i18n):string{
        $session=$quiz->getSession($sid);$attemptFilter=0;$archives=$quiz->listArchives($sid);$audit=$quiz->listAudit($sid);$technicalOverrides=$quiz->listTechnicalOverrides($sid);$trackingDiagnostics=$quiz->listTrackingDiagnostics($sid);
        ob_start();try{include dirname(__DIR__).'/app/Views/quiz/admin/history.php';return (string)ob_get_contents();}finally{ob_end_clean();}
    };
    $privateHtml=$renderHistory();assertSameValue(true,str_contains($privateHtml,'challenge_replayed'),'Owner history shows precise private refusal');
    $auth->authenticate('owner@example.test','owner-password');$_GET=[];$_POST=['id'=>$sid,'mode'=>'off','settings_revision'=>(int)$session()['settings_revision'],'reason'=>'Method/CSRF test'];
    $_SERVER['REQUEST_METHOD']='GET';ob_start();$adminController->handle('/tracking/mode');ob_end_clean();assertSameValue(404,http_response_code(),'GET never changes tracking mode');
    $_SERVER['REQUEST_METHOD']='POST';ob_start();$adminController->handle('/tracking/mode');ob_end_clean();assertSameValue(403,http_response_code(),'Missing admin CSRF refuses mode mutation');
    $_POST['_csrf']='wrong';ob_start();$adminController->handle('/tracking/mode');ob_end_clean();assertSameValue(403,http_response_code(),'Wrong admin CSRF refuses mode mutation');
    $_POST['_csrf']=$_SESSION['quiz_admin_csrf'];$_POST['id']=[$sid];ob_start();$adminController->handle('/tracking/mode');ob_end_clean();assertSameValue(400,http_response_code(),'Non-scalar mode subject cannot cast to quiz1');$_POST=[];

    // Explicit volume failure refuses the entire reset, never truncating contexts.
    $sourceContext=$db->query('SELECT id FROM quiz_tracking_contexts WHERE attempt_id='.$aid.' ORDER BY id LIMIT 1')->fetchColumn();
    $currentCount=(int)$db->query('SELECT COUNT(*) FROM quiz_tracking_contexts WHERE attempt_id='.$aid)->fetchColumn();
    $fill=$db->prepare("INSERT INTO quiz_tracking_contexts(context_ref,cookie_binding_hash,document_epoch_hash,session_id,student_id,attempt_id,tracking_generation,first_name,last_name,status,created_at) SELECT lower(hex(randomblob(16))),lower(hex(randomblob(32))),lower(hex(randomblob(32))),session_id,student_id,attempt_id,tracking_generation,first_name,last_name,'terminated',created_at FROM quiz_tracking_contexts WHERE id=:id");
    for($i=$currentCount;$i<257;++$i){$fill->execute(['id'=>$sourceContext]);}
    $before=$tables();$oldSession=$session();$archivesBefore=$quiz->listArchives($sid);$reject(static fn()=>$quiz->resetAndRelaunch($sid),'archive_tracking_context_limit');
    assertSameValue($before,$tables(),'Overcapacity refuses reset without proof/diagnostic changes');assertSameValue($oldSession,$session(),'Overcapacity preserves generation/current state');assertSameValue($archivesBefore,$quiz->listArchives($sid),'No partial snapshot on overcapacity');

    // Transfer/deletion does not rewrite the historical subject or give the old owner access.
    $super=$auth->createAdmin('super@example.test','Super','super-password','super_admin',false);$quiz->setAdminContext($super);$quiz->transferSession($sid,(int)$foreign['id']);$quiz->setAdminContext($owner);
    $reject(static fn()=>$quiz->listTrackingDiagnostics($sid),'resource_not_found');$quiz->setAdminContext($foreign);
    $retained=$quiz->getTrackingContexts($sid,$aid)['rows'];$quiz->deleteStudent($student,$sid);assertSameValue($retained,$quiz->getTrackingContexts($sid,$aid)['rows'],'Deleted student keeps frozen private tracking identity');
    assertSameValue(true,str_contains($quiz->exportCsv($sid),'PRIVATE_ACCESS_REASON'),'New owner export retains historical private access and diagnostics');
    echo "QuizTrackingPreflightTest: OK (TTL/challenges/cookies/documents/generations/overrides/ordinary queue/rollback/privacy/pagination)\n";
}finally{unset($quiz,$auth,$database,$db,$stmt,$fill,$studentController,$adminController,$capture,$renderHistory,$reject,$session,$gen,$meta,$tables,$e);gc_collect_cycles();foreach(glob($tmp.DIRECTORY_SEPARATOR.'*')?:[]as$file){if(is_file($file)){unlink($file);}}rmdir($tmp);}
