<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require dirname(__DIR__).'/app/Helpers/url.php';
use App\Services\QuizService;
use App\Services\QuizDbService;
use App\Services\QuizAdminAuthService;

final class ContinuousFixture extends QuizService
{
    public int $clock=100000;
    protected function now(): string { return gmdate('Y-m-d H:i:s',$this->clock); }
}
$tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'ple-continuous-'.bin2hex(random_bytes(5));mkdir($tmp,0700,true);
try {
    $_SESSION=[];$database=new QuizDbService($tmp);$db=$database->pdo();$auth=new QuizAdminAuthService($database,false);
    $owner=$auth->createAdmin('owner@example.test','Owner','owner-password','quiz_admin',false);
    $quiz=new ContinuousFixture($database,['branding'=>['quiz_hmac_secret'=>str_repeat('p',64)]],$tmp);$quiz->setAdminContext($owner);
    $rules=['title'=>'Continuous','google_form_url'=>'https://docs.google.com/forms/d/e/PRIVATE_FORM/viewform','attempt_entry_id'=>'123456','duration_minutes'=>15,'max_incidents'=>2,'min_away_seconds'=>10];
    $sid=(int)$quiz->createSession($rules,"Alice Alpha alice@example.test\nBob Beta bob@example.test")['id'];$quiz->openLobby($sid);$students=$quiz->listStudents($sid);$a=$quiz->joinAttempt($quiz->getSession($sid),$students[0]['code']);$aid=(int)$a['id'];$student=(int)$a['student_id'];$_SESSION['quiz_attempt_id']=$aid;
    $session=static fn():array=>$quiz->getSession($sid);$generation=static fn():string=>$quiz->getSession($sid)['tracking_generation'];
    assertSameValue('off',$session()['tracking_mode'],'5b never autoactivates');$quiz->setTrackingMode($sid,'continuous',0,'Explicit fictitious pilot');$quiz->launch($sid);$epoch=$quiz->openTrackingRoomDocument($sid,$aid);
    $row=static function()use($db,$aid,&$epoch):array{$stmt=$db->prepare('SELECT * FROM quiz_tracking_contexts WHERE attempt_id=:aid AND document_epoch_hash=:epoch AND status=\'active\' ORDER BY id DESC LIMIT 1');$stmt->execute(['aid'=>$aid,'epoch'=>hash('sha256',$epoch)]);return $stmt->fetch();};
    $reject=static function(callable $call,string|array $expected)use($db):void{try{$call();throw new LogicException('Expected refusal');}catch(RuntimeException $e){assertSameValue(true,in_array($e->getMessage(),(array)$expected,true),'Expected private refusal '.$e->getMessage());}assertSameValue(false,$db->inTransaction(),'No leaked transaction');};
    $seven=['listener_roundtrip'=>true,'trusted_enter_received'=>true,'hidden_received'=>true,'visible_after_hidden_received'=>true,'focus_after_hidden_received'=>true,'fullscreen_change_received'=>true,'fullscreen_active'=>true];$two=['listener_roundtrip'=>true,'fullscreen_active'=>false];
    $pref=static function()use($quiz,$sid,$aid,$generation,&$epoch,$seven):array{$nonce=$quiz->createTrackingChallenge($sid,$aid,$generation(),$epoch);return $quiz->submitTrackingPreflight($sid,$aid,$generation(),$epoch,$nonce['challenge'],$seven);};
    $issue=static function()use($quiz,$sid,$aid,$generation,&$epoch):array{return $quiz->createTrackingPulseChallenge($sid,$aid,$generation(),$epoch);};
    $pulse=static function(string $nonce,array $checks)use($quiz,$sid,$aid,$generation,&$epoch):array{return $quiz->submitTrackingPulse($sid,$aid,$generation(),$epoch,$nonce,$checks);};
    $state=$pref();$initial=$row();$incarnation=$initial['proof_incarnation'];$quiz->clock=100020;$nonce=$issue();
    assertSameValue(100060,(int)$row()['proof_until'],'Challenge emission never renews proof');
    $state=$pulse($nonce['challenge'],$two);$renewed=$row();assertSameValue(100080,$state['access_until'],'Healthy renewing pulse adds60 from locked server time');assertSameValue($incarnation,$renewed['proof_incarnation'],'Healthy pulse keeps incarnation');assertSameValue($initial['proof_issued_at'],$renewed['proof_issued_at'],'Pulse keeps original full preflight reception');assertSameValue($initial['checks_json'],$renewed['checks_json'],'Pulse retains full gesture coverage');
    assertSameValue('healthy',$renewed['last_pulse_outcome'],'Targeted last pulse');assertSameValue(0,(int)$quiz->getAttempt($aid)['incident_count'],'Probe never becomes an incident');
    $results=static fn():int=>(int)$db->query("SELECT COUNT(*) FROM quiz_tracking_diagnostics WHERE code LIKE 'pulse_%'")->fetchColumn();$before=$results();
    $reject(static fn()=>$pulse($nonce['challenge'],$two),'challenge_replayed');assertSameValue($before,$results(),'Replay has no second result or renewal');
    $grant=$quiz->grantTechnicalOverride($sid,$student,$generation(),['tracking'],'PRIVATE_TRACKING_REASON');$quiz->clock=100039;$nonce=$issue();$false=$two;$false['listener_roundtrip']=false;
    assertSameValue(true,$pulse($nonce['challenge'],$false)['access_allowed'],'Override admits despite failed raw pulse');assertSameValue('failed',$row()['proof_status'],'False pulse revokes healthy even under override');assertSameValue(null,$row()['proof_until'],'Failure clears old healthy deadline');
    $nonce=$issue();$purpose=$db->query('SELECT purpose FROM quiz_tracking_challenges ORDER BY id DESC LIMIT 1')->fetchColumn();assertSameValue('diagnostic_only',$purpose,'Purpose chosen privately from raw failed proof');
    assertSameValue(true,$pulse($nonce['challenge'],$two)['access_allowed'],'Diagnostic can keep an exempted permission');assertSameValue('failed',$row()['proof_status'],'True diagnostic cannot restore healthy');assertSameValue($incarnation,$row()['proof_incarnation'],'Diagnostic does not create incarnation');
    $private=$quiz->getTrackingContexts($sid,$aid)['rows'][0];assertSameValue(true,in_array('listener_probe_failed',$private['causes'],true),'Original invalidating cause preserved after diagnostictrue');assertSameValue(true,$private['last_pulse_checks']['listener_roundtrip'],'Latest observation is separate from failure cause');
    $quiz->revokeTechnicalOverride($sid,$student,$grant,$generation(),'Return to actual failure');assertSameValue(false,$quiz->prepareTrackingState($sid,$aid)['access_allowed'],'Revoke returns to actual failed proof');
    $diagnosticNonce=$issue();$pref();assertSameValue(false,$row()['proof_incarnation']===$incarnation,'Full preflight creates fresh J');assertSameValue(null,$row()['last_pulse_at'],'J does not inherit last pulseI');assertSameValue(null,$row()['pulse_failure_checks_json'],'J does not inherit failureI');
    $reject(static fn()=>$pulse($diagnosticNonce['challenge'],$two),['challenge_terminated','proof_incarnation_mismatch']);
    $fullNonce=$quiz->createTrackingChallenge($sid,$aid,$generation(),$epoch);$reject(static fn()=>$pulse($fullNonce['challenge'],$two),'challenge_kind_mismatch');$quiz->submitTrackingPreflight($sid,$aid,$generation(),$epoch,$fullNonce['challenge'],$seven);
    $pulseNonce=$issue();$reject(static fn()=>$quiz->submitTrackingPreflight($sid,$aid,$generation(),$epoch,$pulseNonce['challenge'],$seven),'challenge_kind_mismatch');$pulse($pulseNonce['challenge'],$two);
    foreach([['listener_roundtrip'=>1,'fullscreen_active'=>false],['listener_roundtrip'=>true],$two+['extra'=>true]]as$bad){$reject(static fn()=>$pulse($issue()['challenge'],$bad),'schema_invalid');}

    // Effective expiry precedes nonce120, and survives an expected late refusal without consume.
    $quiz->clock=200000;$pref();$quiz->clock=200050;$nonce=$issue();$quiz->clock=200059;$pulse($nonce['challenge'],$two);assertSameValue(200119,(int)$row()['proof_until'],'Renewing valid at59');
    $quiz->clock=210000;$pref();$quiz->clock=210050;$nonce=$issue();$quiz->clock=210060;$before=$results();
    $reject(static fn()=>$pulse($nonce['challenge'],$two),'challenge_terminated');assertSameValue('expired',$row()['proof_status'],'Late trusted request commits expiry');assertSameValue($before,$results(),'Late nonce consumes no result');assertSameValue(null,$db->query('SELECT consumed_at FROM quiz_tracking_challenges ORDER BY id DESC LIMIT 1')->fetchColumn(),'Late nonce never consumed');
    $expiryCount=(int)$db->query("SELECT COUNT(*) FROM quiz_tracking_diagnostics WHERE code='proof_expired'")->fetchColumn();$reject(static fn()=>$pulse($nonce['challenge'],$two),'challenge_terminated');assertSameValue($expiryCount,(int)$db->query("SELECT COUNT(*) FROM quiz_tracking_diagnostics WHERE code='proof_expired'")->fetchColumn(),'Expiry transition occurs only once');
    $nonce=$issue();$quiz->clock+=119;$pulse($nonce['challenge'],$two);assertSameValue('expired',$row()['proof_status'],'Diagnostic before nonce119 cannot revive proof');
    $nonce=$issue();$quiz->clock+=120;$reject(static fn()=>$pulse($nonce['challenge'],$two),'challenge_expired');$quiz->clock+=1;$reject(static fn()=>$pulse($nonce['challenge'],$two),'challenge_expired');
    // Independent full preparation remains usable after an older I expires.
    $quiz->clock=220000;$pref();$quiz->clock=220050;$fullNonce=$quiz->createTrackingChallenge($sid,$aid,$generation(),$epoch);$quiz->clock=220060;$quiz->prepareTrackingState($sid,$aid);$quiz->clock=220095;
    assertSameValue(true,$quiz->submitTrackingPreflight($sid,$aid,$generation(),$epoch,$fullNonce['challenge'],$seven)['access_allowed'],'Full challenge t50 survives old proof expiry t60 and succeeds t95');

    // Fresh finished guard at BOTH emission and consumption; full preflight remains permitted.
    $quiz->clock=300000;$pref();$nonce=$issue();$metadata=static fn():array=>['attempt_id'=>$aid,'tracking_generation'=>$generation(),'event_uid'=>bin2hex(random_bytes(16)),'source'=>'page'];
    $quiz->recordEvent($session(),$quiz->getAttempt($aid),'finish',0,$metadata());$finishAt=$quiz->getAttempt($aid)['finished_at'];
    $reject(static fn()=>$pulse($nonce['challenge'],$two),'invalid_tracking_state');$reject($issue,'invalid_tracking_state');assertSameValue($finishAt,$quiz->getAttempt($aid)['finished_at'],'Pulse never resumes');$pref();$quiz->recordEvent($session(),$quiz->getAttempt($aid),'resume',0,$metadata());
    $nonce=$issue();$rules['require_fullscreen']='on';$quiz->updateSession($sid,$rules);$reject(static fn()=>$pulse($nonce['challenge'],$two),['challenge_terminated','settings_revision_mismatch']);$pref();$nonce=$issue();$pulse($nonce['challenge'],$two);assertSameValue('failed',$row()['proof_status'],'Current required fullscreenfalse invalidates proof');
    assertSameValue(true,in_array('fullscreen_inactive',$quiz->getTrackingContexts($sid,$aid)['rows'][0]['causes'],true),'Private failure source uses pulse requirement');unset($rules['require_fullscreen']);$quiz->updateSession($sid,$rules);assertSameValue('failed',$row()['proof_status'],'RemovingFS never invents healthy');

    // SQL rollback after consume/renew and during expiry's pending fence.
    $quiz->clock=400000;$pref();$quiz->clock=400010;$nonce=$issue();$tables=static function()use($db):array{$out=[];foreach(['quiz_tracking_contexts','quiz_tracking_challenges','quiz_tracking_diagnostics']as$table){$out[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();}return $out;};$beforeTables=$tables();
    $db->exec("CREATE TRIGGER fail_pulse BEFORE INSERT ON quiz_tracking_diagnostics WHEN NEW.code LIKE 'pulse_%' BEGIN SELECT RAISE(ABORT,'pulse_failure'); END");try{$pulse($nonce['challenge'],$two);throw new LogicException('Expected SQL failure');}catch(PDOException $e){assertSameValue(true,str_contains($e->getMessage(),'pulse_failure'),'Injected failure after mutation');}finally{$db->exec('DROP TRIGGER fail_pulse');}
    assertSameValue($beforeTables,$tables(),'Consume/proof/result all roll back');$pulse($nonce['challenge'],$two);
    $quiz->clock=410000;$pref();$quiz->clock=410050;$nonce=$issue();$quiz->clock=410060;$beforeTables=$tables();
    $db->exec("CREATE TRIGGER fail_fence BEFORE UPDATE ON quiz_tracking_challenges WHEN NEW.terminated_at IS NOT NULL AND OLD.terminated_at IS NULL BEGIN SELECT RAISE(ABORT,'fence_failure'); END");try{$pulse($nonce['challenge'],$two);throw new LogicException('Expected SQL failure');}catch(PDOException $e){assertSameValue(true,str_contains($e->getMessage(),'fence_failure'),'Injected pending fence failure');}finally{$db->exec('DROP TRIGGER fail_fence');}
    assertSameValue($beforeTables,$tables(),'Expiry SQL failure rolls back status/pending/diagnostic');
    $reject(static fn()=>$quiz->submitTrackingPulse($sid,$aid,str_repeat('d',32),$epoch,$nonce['challenge'],$two),'generation_mismatch');
    assertSameValue($beforeTables,$tables(),'Untrusted generation cannot commit expiration');
    $reject(static fn()=>$quiz->submitTrackingPulse($sid,$aid,$generation(),str_repeat('d',32),$nonce['challenge'],$two),'document_epoch_mismatch');
    assertSameValue($beforeTables,$tables(),'Untrusted document cannot commit expiration');
    $db->exec("CREATE TRIGGER fail_expiry BEFORE INSERT ON quiz_tracking_diagnostics WHEN NEW.code='proof_expired' BEGIN SELECT RAISE(ABORT,'expiry_failure'); END");try{$pulse($nonce['challenge'],$two);throw new LogicException('Expected SQL failure');}catch(PDOException $e){assertSameValue(true,str_contains($e->getMessage(),'expiry_failure'),'Injected expiry diagnostic failure after pending fence');}finally{$db->exec('DROP TRIGGER fail_expiry');}
    assertSameValue($beforeTables,$tables(),'Expiry diagnostic SQL failure rolls back status and pending fence');
    $reject(static fn()=>$pulse($nonce['challenge'],$two),'challenge_terminated');

    $quiz->clock=500000;$pref();$quiz->clock=500020;$pulse($issue()['challenge'],$two);$cookieA=$_SESSION;$epochA=$epoch;$contextA=$row();
    $_SESSION=['quiz_attempt_id'=>$aid];$epoch=$quiz->openTrackingRoomDocument($sid,$aid);assertSameValue(false,$quiz->prepareTrackingState($sid,$aid)['access_allowed'],'CookieB does not share healthyA');$nonce=$quiz->createTrackingPulseChallenge($sid,$aid,$generation(),$epoch);assertSameValue(false,$pulse($nonce['challenge'],$two)['access_allowed'],'B diagnostictrue cannot borrow proofA');
    $_SESSION=$cookieA;$epoch=$epochA;$quiz->setTrackingRequestEpoch($epoch);assertSameValue(true,$quiz->prepareTrackingState($sid,$aid)['access_allowed'],'B cannot contaminate healthyA');
    $nonce=$quiz->createTrackingPulseChallenge($sid,$aid,$generation(),$epoch);$oldEpoch=$epoch;$epoch=$quiz->openTrackingRoomDocument($sid,$aid);$reject(static fn()=>$quiz->submitTrackingPulse($sid,$aid,$generation(),$oldEpoch,$nonce['challenge'],$two),'document_epoch_mismatch');
    $pref();$oldGeneration=$generation();$nonce=$quiz->createTrackingPulseChallenge($sid,$aid,$generation(),$epoch);$quiz->stop($sid);$quiz->launch($sid);$quiz->prepareTrackingState($sid,$aid);$reject(static fn()=>$quiz->submitTrackingPulse($sid,$aid,$oldGeneration,$epoch,$nonce['challenge'],$two),'generation_mismatch');$pref();
    $quiz->clock+=20;$nonce=$quiz->createTrackingPulseChallenge($sid,$aid,$generation(),$epoch);$pulse($nonce['challenge'],$two);$latest=$row();$quiz->resetAndRelaunch($sid);$archive=$quiz->getArchive((int)$quiz->listArchives($sid)['rows'][0]['id']);$frozen=end($archive['snapshot']['tracking_contexts']);
    assertSameValue('continuous',$archive['snapshot']['tracking_policy']['mode'],'Snapshot freezes continuous policy');assertSameValue($latest['last_pulse_at'],$frozen['last_pulse_at'],'Snapshot preserves targeted last reception');assertSameValue($two,$frozen['last_pulse_checks'],'Snapshot preserves two normalized checks');assertSameValue($latest['last_pulse_until'],$frozen['last_pulse_until'],'Snapshot preserves renewed bound');
    $public=$quiz->prepareTrackingState($sid,$aid);foreach(['purpose','proof_incarnation','proof_status','last_pulse','PRIVATE_TRACKING_REASON','context_ref']as$marker){assertSameValue(false,str_contains(json_encode($public),$marker),'Public state has no private pulse marker');}
    $csv=$quiz->exportCsv($sid);assertSameValue(true,str_contains($csv,'pulse_failed')&&str_contains($csv,'pulse_diagnostic_only'),'Complete global result journal remains in CSV');foreach(['secret_hash','cookie_binding_hash','document_epoch_hash','proof_incarnation',$nonce['challenge'],$epoch]as$secret){assertSameValue(false,str_contains($csv,$secret),'No secret in export');}
    $legacy=$archive['snapshot'];foreach($legacy['tracking_contexts']as&$context){foreach(array_keys($context)as$key){if(str_starts_with($key,'last_pulse')||str_starts_with($key,'pulse_failure')){unset($context[$key]);}}}unset($context);$stmt=$db->prepare('UPDATE quiz_attempt_archives SET snapshot_json=:json WHERE id=:id');$stmt->execute(['json'=>json_encode($legacy,JSON_THROW_ON_ERROR),'id'=>$archive['id']]);assertSameValue(false,array_key_exists('last_pulse_at',end($quiz->getArchive((int)$archive['id'])['snapshot']['tracking_contexts'])),'Old archive pulse metadata is not backfilled');
    echo "QuizTrackingContinuousTest: OK (renewal/incarnation/purpose/expiry-first/finished/SQL/privacy/snapshots/cookies)\n";
} finally {
    unset($quiz,$database,$db,$auth,$row,$session,$generation,$reject,$pref,$issue,$pulse,$results,$metadata,$tables,$stmt,$e);gc_collect_cycles();foreach(glob($tmp.DIRECTORY_SEPARATOR.'*')?:[]as$file){if(is_file($file)){unlink($file);}}rmdir($tmp);
}
