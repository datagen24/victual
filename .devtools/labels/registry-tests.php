<?php
require __DIR__.'/test-support.php';
use Victual\Services\Labels\{DriverRegistryService,PrinterConfigurationService,SettingsSchemaValidator};
use Victual\Services\ApiKeyService;
runLabelTests(function(PDO $db){
 [$worker,$id]=seed($db);$registry=new DriverRegistryService($db);$config=new PrinterConfigurationService($db);
 $snapshot=fn()=>$db->query('SELECT row_to_json(p) FROM label_printers p ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
 $before=$snapshot();
 $bad=printer($worker);$bad['settings']['resolution_x']=600;
 refused(fn()=>tx($db,fn()=>$config->Save($bad,$id)),'unsupported_combination');check($snapshot()===$before,'Impossible combination writes nothing');
 $bad=printer($worker);$bad['settings']['extra']=true;
 refused(fn()=>tx($db,fn()=>$config->Save($bad,$id)),'unknown_property');check($snapshot()===$before,'Unknown property writes nothing');
 $bad=printer($worker);$bad['settings']['resolution_x']=900;
 refused(fn()=>tx($db,fn()=>$config->Save($bad,$id)),'value_out_of_range');check($snapshot()===$before,'Out of range writes nothing');
 $bad=printer($worker);$bad['driver_schema_version']='missing';
 refused(fn()=>tx($db,fn()=>$config->Save($bad)),'unknown_driver_version');
 $different=driver();$different['capability_document']['models'][]='Another';
 refused(fn()=>tx($db,fn()=>$registry->Register($worker,[$different])),'contradicting_registration');
 $mutations=[
  function(&$d){$d['capability_document']['completion_evidence']='transport';},
  function(&$d){unset($d['capability_document']['artifact_forms'][0]['applies_to']);},
  function(&$d){unset($d['capability_document']['combinations'][0]['provenance']);},
  function(&$d){unset($d['capability_document']['combinations'][0]['printable_width_um']);},
  function(&$d){unset($d['capability_document']['combinations'][0]['connection_type']);},
  function(&$d){$d['settings_schemas'][]=$d['settings_schemas'][0];},
 ];
 foreach($mutations as $mutate){$bad=driver();$mutate($bad);refused(fn()=>tx($db,fn()=>$registry->Register($worker,[$bad])),'invalid_definition');}
 foreach(['allOf','if','$ref'] as $key){$bad=driver();$bad['settings_schemas'][0]['schema'][$key]=[];refused(fn()=>tx($db,fn()=>$registry->Register($worker,[$bad])),'schema_unevaluable');}
 $bad=driver();$bad['settings_schemas'][0]['schema']['properties']['//']='comment';
 refused(fn()=>tx($db,fn()=>$registry->Register($worker,[$bad])),'schema_unevaluable');
 // Corrupt stored schema, emulating the gate-4 defect, without a registration write path.
 $q=$db->prepare('UPDATE label_drivers SET settings_schemas=?::jsonb');$q->execute([json_encode($bad['settings_schemas'])]);
 refused(fn()=>tx($db,fn()=>$config->Save(printer($worker),$id)),'schema_unevaluable');check($snapshot()===$before,'Unevaluable stored schema writes nothing');
 check(ApiKeyService::StoredValueOf('secret',ApiKeyService::API_KEY_TYPE_LABEL_WORKER)===hash('sha256','secret'),'Worker credential hashed');
 check(ApiKeyService::StoredValueOf('secret','future-type')===hash('sha256','secret'),'Unknown future type hashed');
 check(ApiKeyService::StoredValueOf('secret',ApiKeyService::API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL)==='secret','Calendar credential remains recoverable');
});
