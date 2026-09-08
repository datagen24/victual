<?php
require __DIR__.'/test-support.php';
use Victual\Services\Labels\{LabelPrintJobService,PrintAttemptService};
if(($argv[1]??'')==='child')
{
 $db=labelDb($argv[2]);$db->exec("SET application_name='".$argv[2]."_child'");
 $db->beginTransaction();$service=$argv[3]==='unlocked'?new class($db) extends ReadyAttempts {protected function LockClause():string{return '';}}:new ReadyAttempts($db);
 echo json_encode($service->Claim((int)$argv[4]));$db->commit();exit;
}
runLabelTests(function(PDO $db,string $schema){
 [$worker,$printer]=seed($db);$jobs=new LabelPrintJobService($db);$attempts=new ReadyAttempts($db,1,2);
 $db->beginTransaction();$jobs->Enqueue(1,0,$printer);$db->rollBack();
 check((int)$db->query('SELECT COUNT(*) FROM labels')->fetchColumn()===0,'Rollback removes uid');
 check((int)$db->query('SELECT COUNT(*) FROM outbox')->fetchColumn()===0,'Rollback removes event');
 check((int)$db->query('SELECT COUNT(*) FROM print_jobs')->fetchColumn()===0,'Rollback removes job');
 $bad=printer($worker);$bad['settings']['resolution_x']=600;
 $q=$db->prepare('UPDATE label_printers SET settings=?::jsonb WHERE id=?');$q->execute([json_encode($bad['settings']),$printer]);
 refused(fn()=>tx($db,fn()=>$jobs->Enqueue(1,0,$printer)),'unsupported_combination');
 check((int)$db->query('SELECT COUNT(*) FROM labels')->fetchColumn()===0,'Rejected enqueue rolls back minted label');
 $q->execute([json_encode(printer($worker)['settings']),$printer]);
 // The artifact dependency itself is exercised in artifact-tests.php, against the real
 // readiness condition plan 27 replaced group B's closed seam with. Here it is a
 // precondition rather than the subject: a job is enqueued, rendered and attached, and what
 // this suite is about is what happens to it afterwards.
 $unrendered=tx($db,fn()=>$jobs->Enqueue(1,0,$printer));
 check(tx($db,fn()=>(new PrintAttemptService($db))->Claim($worker))===[],'A job with no artifact is not claimable');
 check($jobs->Monitor()[0]['state']==='awaiting_artifact','Artifact dependency visible');
 renderAndAttach($db,$unrendered);
 $first=issued($db,$jobs,$printer);
 $drainFirst=tx($db,fn()=>$attempts->Claim($worker));
 check((int)$drainFirst[0]['attempt']['job_id']===$unrendered,'The rendered job dispatches in queue order');
 tx($db,fn()=>$attempts->Result($worker,(int)$drainFirst[0]['attempt']['id'],'printed',[]));
 $a=tx($db,fn()=>$attempts->Claim($worker))[0]['attempt'];
 check($jobs->Monitor()[0]['state']==='claimed','Claim state');
 refused(fn()=>tx($db,fn()=>$jobs->AuthorizeAnotherAttempt($first,(int)$a['id'])),'attempt_running');
 tx($db,fn()=>$attempts->Sent($worker,(int)$a['id']));check($jobs->Monitor()[0]['state']==='sent','Bytes sent is not a device report');
 tx($db,fn()=>$attempts->Result($worker,(int)$a['id'],'failed',['error'=>'Offline']));
 check($jobs->Monitor()[0]['state']==='failed','Failed state retains error');
 check($jobs->Monitor()[0]['authorization_state']==='awaiting_authorization','Exhausted failure awaits human authorization');
 $second=issued($db,$jobs,$printer);
 // Negative control: selecting an exhausted head before filtering starves the eligible row.
 $naive=$db->query('SELECT id FROM print_jobs WHERE outcome IS NULL ORDER BY outbox_id LIMIT 1')->fetchColumn();
 check((int)$naive===$first,'Negative control selects exhausted head');
 $eligible=tx($db,fn()=>$attempts->Claim($worker));check((int)$eligible[0]['attempt']['job_id']===$second,'Exhausted job cannot starve eligible job');
 tx($db,fn()=>$jobs->AuthorizeAnotherAttempt($first,(int)$a['id']));tx($db,fn()=>$jobs->AuthorizeAnotherAttempt($first,(int)$a['id']));
 check((int)$db->query('SELECT attempts_authorized FROM print_jobs WHERE id='.$first)->fetchColumn()===2,'Double authorization is idempotent');
 $replacement=tx($db,fn()=>$attempts->Claim($worker))[0]['attempt'];
 refused(fn()=>tx($db,fn()=>$attempts->Heartbeat($worker,(int)$a['id'])),'lease_ended');
 refused(fn()=>tx($db,fn()=>$jobs->AuthorizeAnotherAttempt($first,(int)$a['id'])),'not_current');
 $db->exec("UPDATE print_attempts SET lease_expires_at=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE ended_at IS NULL");
 check(tx($db,fn()=>$attempts->Claim($worker))===[],'Expired attempt is never automatically redispatched');
 check(in_array('uncertain',array_column($jobs->Monitor(),'state'),true),'Expired delivery is visibly uncertain');
 tx($db,fn()=>$attempts->Result($worker,(int)$replacement['id'],'printed',['device'=>'complete']));
 check($jobs->Monitor()[0]['state']==='uncertain_but_reported','Late report preserves uncertainty and sorts first');
 $third=issued($db,$jobs,$printer);$fourth=issued($db,$jobs,$printer);
 $db->exec("UPDATE outbox SET payload='{\"payload_version\":999}' WHERE id=(SELECT outbox_id FROM print_jobs WHERE id=$third)");
 $next=tx($db,fn()=>$attempts->Claim($worker));check((int)$next[0]['attempt']['job_id']===$fourth,'Unreadable payload does not starve valid successor');
 check($db->query('SELECT outcome FROM print_jobs WHERE id='.$third)->fetchColumn()==='dead_lettered','Unreadable payload dead-lettered');
 tx($db,fn()=>$attempts->Result($worker,(int)$next[0]['attempt']['id'],'printed',['device'=>'complete']));
 check($db->query('SELECT outcome FROM print_jobs WHERE id='.$fourth)->fetchColumn()==='printed','Current result completes job');
 $fifth=issued($db,$jobs,$printer);$db->exec('DELETE FROM label_worker_capabilities');
 check(tx($db,fn()=>$attempts->Claim($worker))===[],'Missing advertisement returns no dispatch');
 check($db->query('SELECT outcome FROM print_attempts WHERE job_id='.$fifth)->fetchColumn()==='blocked','Missing advertisement creates visible blocked attempt');
 tx($db,fn()=>(new Victual\Services\Labels\DriverRegistryService($db))->Register($worker,[driver()]));
 // Two claims with and without SKIP LOCKED: unique attempt number is the independent fence.
 foreach(['locked','unlocked'] as $mode)
 {
  $job=issued($db,$jobs,$printer);$db->beginTransaction();
  $held=(new ReadyAttempts($db))->Claim($worker);check(count($held)===1,'Parent claims fixture');
  $process=proc_open([PHP_BINARY,__FILE__,'child',$schema,$mode,(string)$worker],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  fclose($pipes[0]);
  if($mode==='unlocked')
  {
   $observer=labelDb($schema);$blocked=false;$deadline=microtime(true)+5;
   while(microtime(true)<$deadline){$q=$observer->query("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name='".$schema."_child' AND wait_event_type='Lock'");if((int)$q->fetchColumn()>0){$blocked=true;break;}usleep(10000);}
   check($blocked,'Unfenced SELECT reaches unique-index lock');
  }
  else
  {
   $deadline=microtime(true)+5;while(proc_get_status($process)['running']&&microtime(true)<$deadline)usleep(10000);
   check(!proc_get_status($process)['running'],'SKIP LOCKED returns without waiting');
  }
  $db->commit();$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
  check($error===''&&json_decode($output,true)===[],'Second claim produces no duplicate: '.$error);
  check((int)$db->query('SELECT COUNT(*) FROM print_attempts WHERE job_id='.$job)->fetchColumn()===1,'Unique fence permits one attempt');
 }

 $cap=driver()['capability_document'];$cap['completion_evidence'][0]['evidence']='transport';
 $q=$db->prepare('UPDATE label_drivers SET capability_document=?::jsonb');$q->execute([json_encode($cap)]);
 $sendJob=issued($db,$jobs,$printer);$sendAttempt=tx($db,fn()=>$attempts->Claim($worker))[0]['attempt'];
 tx($db,fn()=>$attempts->Sent($worker,(int)$sendAttempt['id']));
 check($db->query('SELECT outcome FROM print_jobs WHERE id='.$sendJob)->fetchColumn()==='sent','Transport-only configuration acknowledges on send');
 tx($db,fn()=>$attempts->Result($worker,(int)$sendAttempt['id'],'printed',['device'=>'late']));
 check($db->query('SELECT outcome FROM print_jobs WHERE id='.$sendJob)->fetchColumn()==='sent','Late result annotates without rewriting concluded send');
});
