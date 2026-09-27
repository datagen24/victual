<?php
require '/app/tests/bootstrap.php';
use Victual\Services\StockService as S;
use Victual\Services\RecipesService as R;
use Victual\Tests\Support\PgsqlSchemaTestCase;

// A standalone evidence runner. Each case has a fresh product in a disposable migrated schema.
class Adr32Probe extends PgsqlSchemaTestCase
{
    private static PDO $db;
    private static S $stock;
    private static int $loc;
    private static int $to;
    private static array $results = [];
    private static int $serial = 0;
    public static function product(): int {
        $n = ++self::$serial;
        return (int)self::$db->query("INSERT INTO products(name,location_id,qu_id_stock,qu_id_purchase,qu_id_consume,qu_id_price) VALUES ('spike-$n',".self::$loc.",2,2,2,2) RETURNING id")->fetchColumn();
    }
    private static function row(int $p,float $amount,int $open=0): array {
        $n=++self::$serial;
        $v=sprintf('%.17g',$amount);
        $id=(int)self::$db->query("INSERT INTO stock(product_id,amount,best_before_date,purchased_date,stock_id,location_id,open) VALUES ($p,$v,'2030-01-01','2026-09-27','spike-$n',".self::$loc.",$open) RETURNING id")->fetchColumn();
        return self::$db->query("SELECT * FROM stock WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    }
    private static function snapshot(int $p): array {
        return [self::$db->query("SELECT * FROM stock WHERE product_id=$p ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),self::$db->query("SELECT * FROM stock_log WHERE product_id=$p ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)];
    }
    private static function total(int $p): float {return (float)self::$db->query("SELECT coalesce(sum(amount),0) FROM stock WHERE product_id=$p")->fetchColumn();}
    private static function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
    private static function near(float $a,float $b,float $delta=1e-12): void {self::check(abs($a-$b)<=$delta,sprintf('expected %.17g got %.17g (delta %.17g)',$b,$a,$a-$b));}
    private static function refuse(callable $call,int $p): string {
        $before=self::snapshot($p);$caught=null;
        try {$call();} catch(Throwable $e){$caught=$e;}
        self::check($caught!==null,'accepted invalid/no-change input');
        self::check(!($caught instanceof PDOException),'SQL error instead of service refusal: '.$caught?->getMessage());
        self::check(self::snapshot($p)===$before,'refusal mutated stock or ledger');
        return $caught->getMessage();
    }
    private static function scenario(string $name,callable $call): void {
        try { $details=$call(); self::$results[]=['case'=>$name,'kind'=>(str_starts_with($name,'bulk ') || str_starts_with($name,'drift ')) ? 'observation' : 'check','pass'=>true,'details'=>$details]; }
        catch(Throwable $e){self::$results[]=['case'=>$name,'kind'=>(str_starts_with($name,'bulk ') || str_starts_with($name,'drift ')) ? 'observation' : 'check','pass'=>false,'error'=>$e->getMessage()];}
    }
    private static function consume(int $p,float $n): string {return self::$stock->ConsumeProduct($p,$n,false,S::TRANSACTION_TYPE_CONSUME);}
    private static function edit(array $row,float $n): mixed {return self::$stock->EditStockEntry((int)$row['id'],$n,$row['best_before_date'],(int)$row['location_id'],null,null,(int)$row['open'],$row['purchased_date']);}
    private static function undoAll(int $p): void {
        $ids=self::$db->query("SELECT id FROM stock_log WHERE product_id=$p AND undone=0 ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);
        foreach($ids as $id) {if(self::$db->query("SELECT undone FROM stock_log WHERE id=$id")->fetchColumn()==0)self::$stock->UndoBooking((int)$id);}
    }
    public static function executeProbe(): void {
        parent::setUpBeforeClass();
        try {
            self::$db=self::Pdo();self::$stock=S::GetInstance();
            self::$db->exec("INSERT INTO users(id,username,password) VALUES(9000,'adr32','fixture')");
            self::$db->exec("INSERT INTO user_permissions(user_id,permission_id) SELECT 9000,id FROM permission_hierarchy WHERE name='ADMIN'");
            self::$loc=(int)self::$db->query("INSERT INTO locations(name) VALUES('adr32 source') RETURNING id")->fetchColumn();
            self::$to=(int)self::$db->query("INSERT INTO locations(name) VALUES('adr32 destination') RETURNING id")->fetchColumn();
            $metadata=['php'=>PHP_VERSION,'postgres'=>self::$db->query('SHOW server_version')->fetchColumn(),'precision'=>ini_get('precision'),'serialize_precision'=>ini_get('serialize_precision'),'mode'=>getenv('ADR32_MODE')];
            foreach(['consume','open','transfer'] as $operation) {
                self::scenario("$operation: 0.1+0.2 and undo",function()use($operation){
                    $p=self::product();self::row($p,.1);self::row($p,.2);
                    if($operation==='consume')self::consume($p,.3);
                    elseif($operation==='open')self::$stock->OpenProduct($p,.3);
                    else self::$stock->TransferProduct($p,.3,self::$loc,self::$to);
                    $rows=self::snapshot($p)[0];
                    if($operation==='consume')self::check(count($rows)===0,'residue rows: '.json_encode($rows));
                    elseif($operation==='open'){foreach($rows as $r)self::check((int)$r['open']===1,'unopened residue');}
                    else {foreach($rows as $r)self::check((int)$r['location_id']===self::$to,'source residue');}
                    $logs=self::snapshot($p)[1];self::check(count($logs)===($operation==='transfer'?4:2),'unexpected booking count');
                    foreach($logs as $log)self::check(abs(abs((float)$log['amount'])-.1)<1e-12 || abs(abs((float)$log['amount'])-.2)<1e-12,'incorrect ledger amount');
                    self::undoAll($p);self::near(self::total($p),.3);
                    foreach(self::snapshot($p)[0] as $r){self::check((int)$r['location_id']===self::$loc,'undo location');self::check((int)$r['open']===0,'undo open');}
                    return ['bookings'=>count($logs),'restored'=>self::total($p)];
                });
                foreach([.5e-9,2e-9] as $extra)self::scenario("$operation shortage $extra",function()use($operation,$extra){
                    $p=self::product();self::row($p,1);
                    $call=fn()=>match($operation){'consume'=>self::consume($p,1+$extra),'open'=>self::$stock->OpenProduct($p,1+$extra),'transfer'=>self::$stock->TransferProduct($p,1+$extra,self::$loc,self::$to)};
                    if($extra>1e-9)return self::refuse($call,$p);
                    $call();return ['stock'=>self::total($p),'bookings'=>count(self::snapshot($p)[1])];
                });
            }
            self::scenario('transfer undo removes arithmetic residue',function(){
                $p=self::product();self::row($p,1);self::$stock->TransferProduct($p,.3,self::$loc,self::$to);
                self::$db->exec("UPDATE stock SET amount=0.1::double precision + 0.2::double precision WHERE product_id=$p AND location_id=".self::$to);
                self::undoAll($p);self::check(count(self::snapshot($p)[0])===1,'destination phantom row');self::near(self::total($p),1);
            });
            self::scenario('consume preserves 0.001',function(){ $p=self::product();self::row($p,1.001);self::consume($p,1);self::near(self::total($p),.001);return self::total($p);});
            self::scenario('no extra booking after near-zero remainder',function(){ $p=self::product();self::row($p,.3);self::row($p,.4);self::consume($p,.3+.5e-9);self::check(count(self::snapshot($p)[1])===1,'extra booking');self::near(self::total($p),.4); });
            foreach([S::TRANSACTION_TYPE_PURCHASE,S::TRANSACTION_TYPE_SELF_PRODUCTION,S::TRANSACTION_TYPE_INVENTORY_CORRECTION] as $type)self::scenario("$type undo",function()use($type){$p=self::product();self::$stock->AddProduct($p,.3,'2030-01-01',$type,'2026-09-27',null,self::$loc);self::near(self::total($p),.3);self::check(count(self::snapshot($p)[1])===1,'purchase booking count');self::near(abs((float)self::snapshot($p)[1][0]['amount']),.3);self::undoAll($p);self::check(count(self::snapshot($p)[0])===0,'undo residue');foreach(self::snapshot($p)[1] as $log)self::check((int)$log['undone']===1,'booking not undone');});
            foreach([.2,.5] as $count)self::scenario("inventory $count and undo",function()use($count){$p=self::product();self::row($p,.3);self::$stock->InventoryProduct($p,$count,'2030-01-01',self::$loc);self::near(self::total($p),$count);self::check(count(self::snapshot($p)[1])===1,'inventory booking count');self::near(abs((float)self::snapshot($p)[1][0]['amount']),abs($count-.3));self::undoAll($p);self::near(self::total($p),.3);foreach(self::snapshot($p)[1] as $log)self::check((int)$log['undone']===1,'inventory booking not undone');});
            self::scenario('inventory within tolerance refused',function(){$p=self::product();self::row($p,1);return self::refuse(fn()=>self::$stock->InventoryProduct($p,1+.5e-9,'2030-01-01',self::$loc),$p);});
            foreach(['edit','inventory','open','transfer','consume','add'] as $operation)foreach([-5e-10,NAN,INF,-INF] as $value)self::scenario("$operation rejects ".(string)$value,function()use($operation,$value){$p=self::product();$row=self::row($p,1);return self::refuse(fn()=>match($operation){'edit'=>self::edit($row,$value),'inventory'=>self::$stock->InventoryProduct($p,$value,'2030-01-01',self::$loc),'open'=>self::$stock->OpenProduct($p,$value),'transfer'=>self::$stock->TransferProduct($p,$value,self::$loc,self::$to),'consume'=>self::consume($p,$value),'add'=>self::$stock->AddProduct($p,$value,'2030-01-01',S::TRANSACTION_TYPE_PURCHASE,'2026-09-27',null,self::$loc)},$p);});
            self::scenario('edit accepts zero',function(){$p=self::product();$r=self::row($p,1);self::edit($r,0);self::check(count(self::snapshot($p)[0])===1,'zero row deleted');self::near(self::total($p),0);});
            foreach([1.0,1-.5e-9,1+.5e-9,.995,.998,1.002,1.005] as $value)foreach(['measure','edit','measured-open'] as $op)self::scenario("$op coherence ".sprintf('%.17g',$value),function()use($op,$value){
                $p=self::product();$m=['amount'=>.5,'qu_id'=>2];
                if($op==='edit'){$r=self::row($p,1,1);self::$stock->MeasureStockEntry((int)$r['id'],$m);self::edit($r,$value);$after=self::snapshot($p)[0][0];self::check(($after['opened_amount']!==null)===($value==1.0),'metadata coherence');}
                elseif($op==='measure'){$r=self::row($p,$value,1);$call=fn()=>self::$stock->MeasureStockEntry((int)$r['id'],$m);if($value!=1.0)return self::refuse($call,$p);$call();}
                else {$r=self::row($p,$value);$call=function()use($p,$r,$m){$tx=null;return self::$stock->OpenProduct($p,1,$r['stock_id'],$tx,false,$m);};if($value<1)return self::refuse($call,$p);$call();$rows=self::snapshot($p)[0];$measured=array_values(array_filter($rows,fn($x)=>$x['opened_amount']!==null));self::check(count($measured)===1,'missing measured row');self::near((float)$measured[0]['amount'],1,0);if($value>1)self::check(count($rows)===2,'positive remainder lost');}
            });
            foreach([.5e-9,2e-9] as $short)self::scenario("recipe clamp $short",function()use($short){$p=self::product();self::row($p,1);$r=(int)self::$db->query("INSERT INTO recipes(name) VALUES('spike recipe') RETURNING id")->fetchColumn();$v=sprintf('%.17g',1+$short);self::$db->exec("INSERT INTO recipes_pos(recipe_id,product_id,amount,qu_id) VALUES($r,$p,$v,2)");R::GetInstance()->ConsumeRecipe($r);self::near(self::total($p),0);return count(self::snapshot($p)[1]);});
            foreach(['consume','open'] as $op)foreach([4.0,.001] as $factor)self::scenario("mixed units $op factor $factor",function()use($op,$factor){
                $parent=self::product();$child=self::product();$qu=(int)self::$db->query("INSERT INTO quantity_units(name,name_plural) VALUES('spike converted $child','spike converted $child') RETURNING id")->fetchColumn();
                self::$db->exec("UPDATE products SET parent_product_id=$parent,qu_id_stock=$qu,qu_id_purchase=$qu,qu_id_consume=$qu,qu_id_price=$qu WHERE id=$child");
                self::$db->exec("INSERT INTO quantity_unit_conversions(product_id,from_qu_id,to_qu_id,factor) VALUES($child,2,$qu,$factor)");
                self::row($child,.1*$factor);self::row($child,.2*$factor);$tx=null;
                if($op==='consume')self::$stock->ConsumeProduct($parent,.3,false,S::TRANSACTION_TYPE_CONSUME,'default',null,null,$tx,true);
                else self::$stock->OpenProduct($parent,.3,'default',$tx,true);
                $rows=self::snapshot($child)[0];if($op==='consume')self::check(count($rows)===0,'converted residue');
                else foreach($rows as $r)self::check((int)$r['open']===1,'converted unopened residue');
                self::undoAll($child);self::near(self::total($child),.3*$factor);
            });
            self::scenario('factor 10 opens ten separate rows and undoes',function(){
                $parent=self::product();$child=self::product();
                $qu=(int)self::$db->query("INSERT INTO quantity_units(name,name_plural) VALUES('ten cans','ten cans') RETURNING id")->fetchColumn();
                self::$db->exec("UPDATE products SET parent_product_id=$parent,qu_id_stock=$qu,qu_id_purchase=$qu,qu_id_consume=$qu,qu_id_price=$qu WHERE id=$child");
                self::$db->exec("INSERT INTO quantity_unit_conversions(product_id,from_qu_id,to_qu_id,factor) VALUES($child,2,$qu,10)");
                for($i=0;$i<10;$i++)self::row($child,1);
                $tx=null;self::$stock->OpenProduct($parent,1,'default',$tx,true);
                [$rows,$logs]=self::snapshot($child);
                self::check(count($rows)===10 && count($logs)===10,'expected ten rows and bookings');
                foreach($rows as $r){self::check((int)$r['open']===1,'unopened row');self::near((float)$r['amount'],1);}
                foreach($logs as $log)self::near(abs((float)$log['amount']),1);
                self::undoAll($child);self::near(self::total($child),10);
                foreach(self::snapshot($child)[0] as $r)self::check((int)$r['open']===0,'undo failed to close row');
            });
            foreach([NAN,INF,-INF] as $v)self::scenario('measurement rejects '.(string)$v,function()use($v){$p=self::product();$r=self::row($p,1,1);return self::refuse(fn()=>self::$stock->MeasureStockEntry((int)$r['id'],['amount'=>$v,'qu_id'=>2]),$p);});
            foreach([1-.5e-9,1+.5e-9] as $v)self::scenario('measured request exact '.sprintf('%.17g',$v),function()use($v){$p=self::product();$r=self::row($p,2);return self::refuse(function()use($p,$r,$v){$tx=null;return self::$stock->OpenProduct($p,$v,$r['stock_id'],$tx,false,['amount'=>.5,'qu_id'=>2]);},$p);});
            foreach((getenv('ADR32_FAST') ? [] : [1e5,1e6,1e7]) as $start)self::scenario("drift $start 1000 bookings",function()use($start){$p=self::product();self::row($p,$start);for($i=0;$i<1000;$i++)self::consume($p,.1);$before=self::total($p);$expected=$start-100;self::consume($p,$expected);return ['before'=>$before,'error'=>$before-$expected,'residue'=>self::total($p),'rows'=>count(self::snapshot($p)[0])];});
            self::scenario('bulk carried residue',function(){$p=self::product();self::row($p,999999999.9+.1);self::consume($p,999999999.9);$small=self::total($p);self::consume($p,.1);return ['small'=>$small,'residue'=>self::total($p),'rows'=>count(self::snapshot($p)[0])];});
            self::scenario('bulk legitimate 0.0005 remainder',function(){$p=self::product();self::row($p,1e9);self::consume($p,1e9-.0005);return ['residue'=>self::total($p),'rows'=>count(self::snapshot($p)[0])];});
            foreach([.0005,.002] as $extra)self::scenario("large availability shortage $extra",function()use($extra){
                $p=self::product();self::row($p,1e9);$call=fn()=>self::consume($p,1e9+$extra);
                if(getenv('ADR32_MODE')==='relative' && $extra===.0005){$call();self::check(count(self::snapshot($p)[0])===0,'tolerated whole row not consumed');self::check(count(self::snapshot($p)[1])===1,'unexpected bookings');}
                else return self::refuse($call,$p);
            });
            if(method_exists(S::class,'SpikeCompare'))self::scenario('large operand predicate boundaries',function(){
                $expected=getenv('ADR32_MODE')==='relative'?0:-1;
                self::check(S::SpikeCompare(1e9,1e9+.0005)===$expected,'inside relative window');
                self::check(S::SpikeCompare(1e9,1e9+.002)===-1,'outside relative window');
                self::check(S::SpikeCompare(1e9+.002,1e9)===1,'opposite direction');
            });
            if(method_exists(S::class,'SpikeCompare')) self::scenario('inclusive boundary predicates',function(){foreach([0,.5e-9,1e-9,2e-9,-1e-9,-2e-9] as $v)self::check(S::SpikeCompare($v,0)===($v>1e-9?1:($v< -1e-9?-1:0)),'boundary '.$v);});
            echo json_encode(['environment'=>$metadata,'results'=>self::$results],JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR),"\n";
            if(getenv('ADR32_MODE')!=='baseline' && array_filter(self::$results,fn($r)=>$r['kind']==='check' && !$r['pass']))throw new RuntimeException('Acceptance spike checks failed');
        } finally {parent::tearDownAfterClass();}
    }
}
Adr32Probe::executeProbe();
