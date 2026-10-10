<?php
// Lists every non-GET /api operation with the PERMISSION_ constants its handler names.
// Usage: podman run --rm -v "$PWD":/app -v /app/packages -w /app localhost/victual:dev php .devtools/issue742/route-permissions.php
// Static: a handler that delegates through Dispatch() (labels, consumption events and refills,
// by-barcode wrappers) shows [], which means "not followed", not "unauthenticated".
define('VICTUAL_ROOT_PATH','/app');
require '/app/packages/autoload.php';
require '/app/tests/Support/RouteInventory.php';
use Victual\Tests\Support\RouteInventory;
$rows=[];
foreach (RouteInventory::Api() as $o) {
  if ($o->Method==='GET') continue;
  $perms=[]; 
  if ($o->ControllerClass) {
    $m=new ReflectionMethod($o->ControllerClass,$o->ControllerMethod);
    $src=implode('',array_slice(file($m->getFileName()),$m->getStartLine()-1,$m->getEndLine()-$m->getStartLine()+1));
    preg_match_all('/PERMISSION_[A-Z_]+/',$src,$mm); $perms=array_values(array_unique($mm[0]));
    if (preg_match('/CheckMayAdminister|CheckMayGrant/',$src)) $perms[]='+ADR14';
  }
  $rows[]=sprintf("%-6s %-70s %s::%s  [%s]",$o->Method,$o->Path,basename(str_replace('\\','/',$o->ControllerClass??'?')),$o->ControllerMethod,implode(' ',array_map(fn($p)=>str_replace('PERMISSION_','',$p),$perms)));
}
echo implode("\n",$rows),"\n";
