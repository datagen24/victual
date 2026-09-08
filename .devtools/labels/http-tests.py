# Run only against a disposable, authenticated app; creates workers, printers and locations.
# LABEL_HTTP_URL=http://127.0.0.1:18293 LABEL_HTTP_ADMIN_KEY=... python3 .devtools/labels/http-tests.py
import os
from pathlib import Path
import json,urllib.request,urllib.error,uuid
suffix=uuid.uuid4().hex
BASE=os.environ['LABEL_HTTP_URL'].rstrip('/')+'/api/'
ADMIN_KEY=os.environ['LABEL_HTTP_ADMIN_KEY']
def call(method,path,body=None,key=ADMIN_KEY,expected=200):
 req=urllib.request.Request(BASE+path,data=None if body is None else json.dumps(body).encode(),method=method,headers={'Content-Type':'application/json','VICTUAL-API-KEY':key})
 try:
  with urllib.request.urlopen(req) as r:status=r.status;raw=r.read()
 except urllib.error.HTTPError as e:status=e.code;raw=e.read()
 assert status==expected,(method,path,status,raw[:600])
 return json.loads(raw) if raw else None
worker=call('POST','labels/workers',{'name':'HTTP worker '+suffix,'configuration_mode':'declared'})['id']
key=call('POST',f'labels/workers/{worker}/credentials',{})['credential']
call('POST','labels/register',{'drivers':[json.loads((Path(__file__).parent/'fixtures/brother-ql.json').read_text())]},key)
p={'name':'HTTP printer','worker_id':worker,'driver_id':'brother.ql','driver_schema_version':'1.0','connection':'127.0.0.1:9100','connection_type':'tcp','model':'QL-820NWBc','settings':{'media':'62red','resolution_x':300,'resolution_y':300,'color_mode':'black_red'}}
printer=call('POST','labels/printers',p)['id']
location=call('POST','objects/locations',{'name':'HTTP shelf '+suffix})['created_object_id']
context=call('GET',f'labels/locations/{location}/context')
job=call('POST',f'labels/locations/{location}/print',{'printer_id':printer,'import_epoch':context['import_epoch']},expected=202)
assert job['state']=='awaiting_artifact'
assert call('POST','labels/jobs/claim',{'limit':1},key)==[]
call('POST',f'labels/printers/{printer}/status',{'device_state':'idle'},key)
assert call('GET','labels/jobs')[0]['state']=='awaiting_artifact'
call('GET','objects/locations',key=key,expected=401)
call('POST','labels/jobs/claim',{},expected=401)
p['settings']['resolution_x']=600
err=call('PUT',f'labels/printers/{printer}',p,expected=422);assert err['code']=='unsupported_combination'
call('DELETE',f'labels/workers/{worker}/credentials')
call('POST','labels/jobs/claim',{},key,expected=401)
print('PASS authenticated HTTP worker creation, registration, printer validation, atomic enqueue, artifact blocking, status, monitor, key scope and revocation')
# Pairing is public, but ownership comes only from the administrator-issued session.
paired=call('POST','labels/workers',{'name':'HTTP paired '+suffix,'configuration_mode':'paired'})['id']
material=call('POST',f'labels/workers/{paired}/pairing-material',{})['material']
pair=call('POST','labels/pair',{'material':material},key='')
import secrets
request_id=secrets.token_hex(32); material=secrets.token_hex(32)
rotation={'rotation_request_id':request_id,'material':material}
next_key=call('POST','labels/credentials/rotate',rotation,key=pair['credential'])
assert next_key==call('POST','labels/credentials/rotate',rotation,key=pair['credential'])
call('POST','labels/jobs/claim',{},key=pair['credential'],expected=401)
assert call('POST','labels/jobs/claim',{},key=next_key['credential'])==[]
call('POST','labels/credentials/rotate',{'rotation_request_id':secrets.token_hex(32),'material':secrets.token_hex(32)},key=pair['credential'],expected=401)
call('POST','labels/jobs/claim',{},key=next_key['credential'],expected=401)
print('PASS public pairing, rotation replay, ordinary stale-key refusal and durable reuse revocation through HTTP')
for path,body in [('register',{'drivers':[]}),('jobs/claim',{}),('attempts/1/heartbeat',{}),('attempts/1/sent',{}),('attempts/1/result',{'outcome':'printed','detail':{}}),('attempts/1/evidence',{}),('printers/1/status',{}),('credentials/rotate',{'rotation_request_id':secrets.token_hex(32),'material':secrets.token_hex(32)}),('pair',{'material':secrets.token_hex(32)})]:
 call('POST','labels/'+path,body,key=key,expected=401)
print('PASS revoked credential rejected across all nine worker routes (pairing still requires valid material)')
