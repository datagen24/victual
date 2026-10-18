<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\Database\PostgresDialect;
use PHPUnit\Framework\Attributes\DataProvider;
use Victual\Helpers\CanonicalJson;
use Victual\Services\ApiKeyService;
use Victual\Services\StockBookingIdempotencyService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/** #775: each request has its own authenticated process and shares only PostgreSQL state. */
class StockBookingIdempotencyTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static array $keys = [];
	private static int $pantry;
	private static int $cellar;

	/** Two locations and two users who hold every booking permission, each with an API key. */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::$db = self::Pdo();
		self::$pantry = self::insert('locations', ['name' => 'Key pantry']);
		self::$cellar = self::insert('locations', ['name' => 'Key cellar']);
		foreach ([9801, 9802] as $user)
		{
			self::$db->exec("INSERT INTO users(id,username,password) VALUES ($user,'key-user-$user','fixture')");
			self::$db->exec("INSERT INTO user_permissions(user_id,permission_id) SELECT $user,id FROM permission_hierarchy WHERE name IN ('STOCK_VIEW','STOCK_PRICES_VIEW','STOCK_PURCHASE','STOCK_CONSUME','STOCK_TRANSFER','STOCK_OPEN','STOCK_INVENTORY','STOCK_UNDO','STOCK_EDIT')");
			$key = bin2hex(random_bytes(25));
			self::$keys[$user] = $key;
			$stmt = self::$db->prepare("INSERT INTO api_keys(api_key,key_hint,user_id,expires,key_type,read_only) VALUES (?,?,?,now()+interval '30 days',?,0)");
			$stmt->execute([ApiKeyService::HashKey($key), substr($key,-4), $user, ApiKeyService::API_KEY_TYPE_DEFAULT]);
		}
	}

	/** Inserts one row and returns its id. */
	private static function insert(string $table, array $values): int
	{
		$stmt = self::$db->prepare('INSERT INTO ' . $table . '(' . implode(',',array_keys($values)) . ') VALUES (' . implode(',',array_fill(0,count($values),'?')) . ') RETURNING id');
		$stmt->execute(array_values($values));
		return (int)$stmt->fetchColumn();
	}

	/** A product, with $amount purchased into the pantry unless it is 0. */
	private static function product(int $amount = 10): int
	{
		$product = self::insert('products', ['name' => 'Key product '.bin2hex(random_bytes(4)), 'location_id' => self::$pantry, 'qu_id_purchase' => 2, 'qu_id_stock' => 2, 'qu_id_consume' => 2, 'qu_id_price' => 2]);
		if ($amount > 0)
		{
			StockService::GetInstance()->AddProduct($product,$amount,'2035-06-30',StockService::TRANSACTION_TYPE_PURCHASE,'2026-04-01',3,self::$pantry);
		}
		return $product;
	}

	/** Starts one HTTP request in a subprocess, with the key as Idempotency-Key unless it is null, and returns the process handle for finish(). */
	private static function start(string $path, array $body, ?string $key, int $user = 9801, string $method = 'POST', array $headers = []): array
	{
		$headers['VICTUAL-API-KEY'] = self::$keys[$user];
		if ($key !== null) { $headers['Idempotency-Key'] = $key; }
		$spec = ['method'=>$method,'path'=>$path,'headers'=>$headers,'body'=>$body];
		$env = array_merge(array_filter(array_merge($_SERVER,$_ENV),'is_scalar'), ['RBAC_TEST_SCHEMA'=>self::Schema(),'VICTUAL_ROOT'=>VICTUAL_ROOT_PATH]);
		$process = proc_open([PHP_BINARY,__DIR__.'/request-subprocess-helper.php',base64_encode(json_encode($spec))],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
		return [$process,$pipes];
	}

	/** Waits for a request start() began and returns its status, decoded body and lower-cased headers. */
	private static function finish(array $started): array
	{
		[$process,$pipes] = $started;
		$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
		fclose($pipes[1]); fclose($pipes[2]);
		self::assertSame(0,proc_close($process),$out.$err);
		$result = json_decode($out,true);
		self::assertIsArray($result,$out.$err);
		$result['body'] = json_decode($result['body'],true);
		$result['headers'] = array_change_key_case($result['headers']);
		return $result;
	}

	/** Sends one request and waits for its answer. */
	private static function send(string $path, array $body, ?string $key, int $user = 9801, string $method = 'POST', array $headers = []): array
	{
		return self::finish(self::start($path,$body,$key,$user,$method,$headers));
	}

	/** The direct booking route for a product. */
	private static function path(int $product, string $operation): string { return "/api/stock/products/$product/$operation"; }
	/** A valid body for each booking operation. */
	private static function body(string $operation): array
	{
		return match ($operation) {
			'add' => ['amount'=>2,'best_before_date'=>'2035-06-30'],
			'inventory' => ['new_amount'=>12,'best_before_date'=>'2035-06-30'],
			'transfer' => ['amount'=>2,'location_id_from'=>self::$pantry,'location_id_to'=>self::$cellar],
			default => ['amount'=>2],
		};
	}
	/** The product's stock rows and ledger rows, to show a request booked nothing. */
	private static function digest(int $product): array
	{
		return [self::$db->query("SELECT row_to_json(s) FROM stock s WHERE product_id=$product ORDER BY id")->fetchAll(PDO::FETCH_COLUMN), self::$db->query("SELECT row_to_json(s) FROM stock_log s WHERE product_id=$product ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)];
	}
	/** Asserts the Idempotent-Replayed header, or its absence when $value is null. */
	private static function header(array $response, ?string $value): void
	{
		self::assertSame($value === null ? null : [$value],$response['headers']['idempotent-replayed'] ?? null);
	}
	/** Asserts a 200 answer. */
	private static function ok(array $response): void { self::assertSame(200,$response['status'],json_encode($response)); }

	/** The five bookings, by route name. */
	public static function operations(): array { return array_map(fn($op)=>[$op],['add','consume','transfer','open','inventory']); }

	/** Each booking books once, and the same key and body replays the same rows with Idempotent-Replayed: true, without extending the key or moving the changed time. */
	#[DataProvider('operations')]
	public function testEachOperationBooksOnceAndReplays(string $operation): void
	{
		$product=self::product(); $body=self::body($operation); $key=bin2hex(random_bytes(16));
		$first=self::send(self::path($product,$operation),$body,$key); self::ok($first); self::header($first,'false');
		$digest=self::digest($product);
		$expires=self::$db->query("SELECT expires_at FROM stock_booking_idempotency_keys WHERE idempotency_key='$key'")->fetchColumn();
		// ADR-0046 decision 5: a replay writes nothing, so it must not move the changed time clients poll
		$changedTime=static fn () => self::$db->query('SELECT changed_time FROM ' . PostgresDialect::CHANGED_TIME_TABLE . ' WHERE id = 1')->fetchColumn();
		$changedBefore=$changedTime();
		$retry=self::send(self::path($product,$operation),$body,$key); self::ok($retry); self::header($retry,'true');
		self::assertSame($changedBefore,$changedTime(),'a replay does not advance GET /api/system/db-changed-time');
		self::assertSame($first['body'],$retry['body']); self::assertSame($digest,self::digest($product));
		self::assertSame($expires,self::$db->query("SELECT expires_at FROM stock_booking_idempotency_keys WHERE idempotency_key='$key'")->fetchColumn());
		self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/',$first['body'][0]['transaction_id']);
	}

	/** The same key with another body, another product or another stock entry answers 409 idempotency_conflict and books nothing. */
	public function testConflictsBindBodyProductAndEntry(): void
	{
		$product=self::product(); $key=bin2hex(random_bytes(16)); $body=['amount'=>1];
		self::ok(self::send(self::path($product,'consume'),$body,$key));
		$other=self::product();
		foreach ([[$product,['amount'=>2]],[$other,$body],[$product,['amount'=>1,'stock_entry_id'=>'different']]] as [$id,$payload])
		{
			$before=self::digest($id); $response=self::send(self::path($id,'consume'),$payload,$key);
			self::assertSame(409,$response['status']); self::assertSame('idempotency_conflict',$response['body']['code']); self::header($response,null); self::assertSame($before,self::digest($id));
		}
	}

	/** Keys outside the 8-128 character [A-Za-z0-9._:-] grammar. */
	public static function malformedKeys(): array { return [[''],['short'],[str_repeat('x',129)],['bad key!'],['valid-key/slash']]; }
	/** A malformed key is refused with 400 before anything is booked. */
	#[DataProvider('malformedKeys')]
	public function testMalformedKeysNeverBook(string $key): void
	{
		$product=self::product(); $before=self::digest($product);
		$result=self::send(self::path($product,'add'),self::body('add'),$key);
		self::assertSame(400,$result['status']); self::header($result,null); self::assertSame($before,self::digest($product));
	}

	/** Without the header every request books, carries no Idempotent-Replayed header and writes no key row. */
	public function testUnkeyedRequestsBookNormally(): void
	{
		$product=self::product(); $before=(int)self::$db->query('SELECT count(*) FROM stock_booking_idempotency_keys')->fetchColumn();
		foreach ([1,2] as $_) { $response=self::send(self::path($product,'add'),self::body('add'),null); self::ok($response); self::header($response,null); }
		self::assertSame($before,(int)self::$db->query('SELECT count(*) FROM stock_booking_idempotency_keys')->fetchColumn());
	}

	/** Just inside the window the key replays; once expired it books again. A committed key row without a transaction id answers 409 idempotency_in_progress and books nothing. */
	public function testRetentionBoundaryAndInProgress(): void
	{
		$product=self::product(); $body=self::body('add'); $key=bin2hex(random_bytes(16)); $path=self::path($product,'add');
		$first=self::send($path,$body,$key); self::ok($first);
		self::$db->exec("UPDATE stock_booking_idempotency_keys SET expires_at=now()+interval '1 minute' WHERE idempotency_key='$key'");
		self::header(self::send($path,$body,$key),'true');
		self::$db->exec("UPDATE stock_booking_idempotency_keys SET expires_at=now()-interval '1 microsecond' WHERE idempotency_key='$key'");
		$new=self::send($path,$body,$key); self::ok($new); self::header($new,'false'); self::assertNotSame($first['body'][0]['transaction_id'],$new['body'][0]['transaction_id']);
		self::$db->exec("UPDATE stock_booking_idempotency_keys SET transaction_id=NULL WHERE idempotency_key='$key'");
		$before=self::digest($product); $response=self::send($path,$body,$key);
		self::assertSame(409,$response['status']); self::assertSame('idempotency_in_progress',$response['body']['code']); self::header($response,null); self::assertSame($before,self::digest($product));
	}

	/** A refused booking leaves no key row, so the same key books once the cause is gone. */
	public function testFailedBookingRollsBackKeyAndRetryCanSucceed(): void
	{
		$product=self::product(0); $key=bin2hex(random_bytes(16)); $path=self::path($product,'consume');
		self::assertSame(400,self::send($path,['amount'=>2],$key)['status']);
		self::assertSame(0,(int)self::$db->query("SELECT count(*) FROM stock_booking_idempotency_keys WHERE idempotency_key='$key'")->fetchColumn());
		self::ok(self::send(self::path($product,'add'),self::body('add'),null));
		$result=self::send($path,['amount'=>2],$key); self::ok($result); self::header($result,'false');
	}

	/** Two users' identical keys are separate, and a replay of a transaction id shared with another user (a legacy uniqid() collision) returns only the acting user's rows. */
	public function testScopeAndLegacyCollisionFiltering(): void
	{
		$product=self::product(); $key=bin2hex(random_bytes(16)); $body=self::body('add'); $path=self::path($product,'add');
		$a=self::send($path,$body,$key,9801); $b=self::send($path,$body,$key,9802); self::ok($a); self::ok($b);
		self::assertNotSame($a['body'][0]['transaction_id'],$b['body'][0]['transaction_id']);
		$legacy='123456789abcd';
		$stmt=self::$db->prepare('UPDATE stock_log SET transaction_id=? WHERE transaction_id IN (?,?)'); $stmt->execute([$legacy,$a['body'][0]['transaction_id'],$b['body'][0]['transaction_id']]);
		$stmt=self::$db->prepare('UPDATE stock_booking_idempotency_keys SET transaction_id=? WHERE idempotency_key=?'); $stmt->execute([$legacy,$key]);
		foreach ([9801,9802] as $user) { $result=self::send($path,$body,$key,$user); self::ok($result); self::assertCount(1,$result['body']); self::assertSame($user,(int)$result['body'][0]['user_id']); }
		$read=self::send('/api/stock/transactions/'.$legacy,[],null,9801,'GET'); self::ok($read); self::assertCount(2,$read['body']);
	}

	/**
	 * A replay re-reads the transaction as it is now (ADR-0046 decision 7): an undo since the
	 * original shows as undone, prices follow the caller's current permissions, and rows that
	 * no longer exist answer 400 without booking again. A consume is used because
	 * STOCK_PRICES_VIEW is a child of STOCK_PURCHASE, so a caller allowed to replay a purchase
	 * always sees its price.
	 */
	public function testReplayShowsCurrentUndoAndRedactionAndDeletedRowsNeverRebook(): void
	{
		$product=self::product(); $key=bin2hex(random_bytes(16)); $body=self::body('consume'); $path=self::path($product,'consume');
		$first=self::send($path,$body,$key); self::ok($first); $transaction=$first['body'][0]['transaction_id'];
		self::assertArrayHasKey('price',$first['body'][0],'the original response shows the price');
		$undo=self::send('/api/stock/transactions/'.$transaction.'/undo',[],null); self::assertContains($undo['status'],[200,204],json_encode($undo));
		self::$db->exec("DELETE FROM user_permissions WHERE user_id=9801 AND permission_id IN (SELECT id FROM permission_hierarchy WHERE name IN ('STOCK_PRICES_VIEW','STOCK_PURCHASE'))");
		try { $before=self::digest($product); $replay=self::send($path,$body,$key); self::ok($replay); self::header($replay,'true'); self::assertSame(1,$replay['body'][0]['undone'],'undone is the documented integer flag (issue #230)'); self::assertArrayNotHasKey('price',$replay['body'][0]); self::assertSame($before,self::digest($product)); }
		finally { self::$db->exec("INSERT INTO user_permissions(user_id,permission_id) SELECT 9801,id FROM permission_hierarchy WHERE name IN ('STOCK_PRICES_VIEW','STOCK_PURCHASE')"); }
		self::$db->prepare('DELETE FROM stock_log WHERE transaction_id=?')->execute([$transaction]);
		$before=self::digest($product); $gone=self::send($path,$body,$key); self::assertSame(400,$gone['status']); self::header($gone,null); self::assertSame($before,self::digest($product));
	}

	/** Without the booking permission or STOCK_VIEW, a keyed request, replay or new key, direct or by barcode, answers 403 and changes neither stock nor the key table. */
	#[DataProvider('operations')]
	public function testPermissionsAreRecheckedBeforeKeyLookupAndWrappers(string $operation): void
	{
		$product=self::product(); $key=bin2hex(random_bytes(16)); $body=self::body($operation); $path=self::path($product,$operation);
		self::ok(self::send($path,$body,$key));
		$permission=match($operation) { 'add'=>'STOCK_PURCHASE', default=>'STOCK_'.strtoupper($operation) };
		foreach ([$permission,'STOCK_VIEW'] as $right)
		{
			self::$db->exec("DELETE FROM user_permissions WHERE user_id=9801 AND permission_id=(SELECT id FROM permission_hierarchy WHERE name='$right')");
			try
			{
				$before=self::digest($product); $keys=self::$db->query('SELECT row_to_json(k) FROM stock_booking_idempotency_keys k ORDER BY user_id,operation,idempotency_key')->fetchAll();
				foreach ([$path,"/api/stock/products/by-barcode/unknown/$operation"] as $route)
				{
					foreach ([$key,bin2hex(random_bytes(16))] as $requestKey) { $response=self::send($route,$body,$requestKey); self::assertSame(403,$response['status'],json_encode($response)); self::header($response,null); }
				}
				self::assertSame($before,self::digest($product)); self::assertSame($keys,self::$db->query('SELECT row_to_json(k) FROM stock_booking_idempotency_keys k ORDER BY user_id,operation,idempotency_key')->fetchAll());
			}
			finally { self::$db->exec("INSERT INTO user_permissions(user_id,permission_id) SELECT 9801,id FROM permission_hierarchy WHERE name='$right'"); }
		}
	}

	/** The bookings a Grocycode can name a stock entry for. */
	public static function entryOperations(): array { return [['consume'],['open'],['transfer']]; }
	/** A Grocycode booking replays through the direct route naming the same entry, and answers 409 for another entry. */
	#[DataProvider('entryOperations')]
	public function testGrocycodeWrappersFingerprintResolvedEntry(string $operation): void
	{
		$product=self::product(); $stockId=self::$db->query("SELECT stock_id FROM stock WHERE product_id=$product ORDER BY id LIMIT 1")->fetchColumn();
		$key=bin2hex(random_bytes(16)); $body=self::body($operation);
		$barcode="grcy:p:$product:$stockId";
		$first=self::send('/api/stock/products/by-barcode/'.rawurlencode($barcode).'/'.$operation,$body,$key); self::ok($first);
		$directBody=$body+['stock_entry_id'=>$stockId];
		$second=self::send(self::path($product,$operation),$directBody,$key); self::ok($second); self::header($second,'true'); self::assertSame($first['body'],$second['body']);
		$directBody['stock_entry_id']='another-entry'; self::assertSame(409,self::send(self::path($product,$operation),$directBody,$key)['status']);
	}

	/**
	 * Every new transaction id is bin2hex(random_bytes(16)) (ADR-0046 decision 7, "Transaction id
	 * format"), and an id minted by uniqid() before this change can still be read and undone.
	 */
	public function testNewTransactionIdsAreRandomAndLegacyIdsStayUndoable(): void
	{
		$product=self::product(0);
		$first=self::send(self::path($product,'add'),self::body('add'),null); self::ok($first);
		$second=self::send(self::path($product,'add'),self::body('add'),null); self::ok($second);
		foreach ([$first,$second] as $response) { self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D',$response['body'][0]['transaction_id']); }
		self::assertNotSame($first['body'][0]['transaction_id'],$second['body'][0]['transaction_id']);

		$legacy=substr(uniqid(),0,13);
		self::$db->prepare('UPDATE stock_log SET transaction_id=? WHERE transaction_id=?')->execute([$legacy,$second['body'][0]['transaction_id']]);
		$read=self::send('/api/stock/transactions/'.$legacy,[],null,9801,'GET'); self::ok($read); self::assertSame($legacy,$read['body'][0]['transaction_id']);
		$undo=self::send('/api/stock/transactions/'.$legacy.'/undo',[],null); self::assertContains($undo['status'],[200,204],json_encode($undo));
		self::assertSame(2.0,(float)self::$db->query("SELECT sum(amount) FROM stock WHERE product_id=$product")->fetchColumn(),'the legacy booking was undone');
	}

	/**
	 * A keyed booking also deletes a bounded batch of other users' expired keys, so keys nobody
	 * retries do not outlive the retention window; unexpired keys are left alone.
	 */
	public function testExpiredKeysOfAnyUserArePurgedInBoundedBatches(): void
	{
		$insert=self::$db->prepare("INSERT INTO stock_booking_idempotency_keys(user_id,operation,idempotency_key,request_fingerprint,transaction_id,expires_at) VALUES (9802,'consume',?,?,'old',CURRENT_TIMESTAMP + make_interval(secs=>?))");
		foreach (['expired-a-'.bin2hex(random_bytes(4)) => -60, 'expired-b-'.bin2hex(random_bytes(4)) => -60, 'expired-c-'.bin2hex(random_bytes(4)) => -60, 'live-key-'.bin2hex(random_bytes(4)) => 3600] as $key=>$offset)
		{
			$insert->execute([$key,str_repeat('a',64),$offset]);
		}
		$expired=fn()=>(int)self::$db->query("SELECT count(*) FROM stock_booking_idempotency_keys WHERE expires_at <= CURRENT_TIMESTAMP")->fetchColumn();

		self::$db->beginTransaction();
		try { self::assertSame(2,(new StockBookingIdempotencyService(self::$db))->PurgeExpired(2),'at most the batch is deleted'); }
		finally { self::$db->commit(); }
		self::assertSame(1,$expired());

		$product=self::product();
		self::ok(self::send(self::path($product,'add'),self::body('add'),bin2hex(random_bytes(16))));
		self::assertSame(0,$expired(),'a keyed booking purged the remaining expired key');
		self::assertSame(1,(int)self::$db->query("SELECT count(*) FROM stock_booking_idempotency_keys WHERE idempotency_key LIKE 'live-key-%'")->fetchColumn(),'an unexpired key is kept');
	}

	/** GET /api/system/info publishes the capability from the retention constant, and the document describes the header, 409 and replay header on all ten routes. */
	public function testCapabilityAndOpenApiAndCors(): void
	{
		$response=self::send('/api/system/info',[],null,9801,'GET'); self::ok($response);
		self::assertSame(StockBookingIdempotencyService::Capability(),$response['body']['stock_booking_idempotency']);
		self::assertSame(604800,$response['body']['stock_booking_idempotency']['retention_seconds']);
		$spec=json_decode(file_get_contents(VICTUAL_ROOT_PATH.'/victual.openapi.json'),true);
		foreach (self::operations() as [$operation])
		{
			foreach (["/stock/products/{productId}/$operation","/stock/products/by-barcode/{barcode}/$operation"] as $path)
			{
				$op=$spec['paths'][$path]['post']; self::assertContains('Idempotency-Key',array_column($op['parameters'],'name')); self::assertArrayHasKey('409',$op['responses']); self::assertArrayHasKey('Idempotent-Replayed',$op['responses']['200']['headers']);
			}
		}
	}

	/** Begin() refuses to reserve a key outside the transaction that will book. */
	public function testReservationRequiresCallerTransaction(): void
	{
		$this->expectException(\LogicException::class);
		(new StockBookingIdempotencyService(self::$db))->Begin(9801,'purchase','example-key',[]);
	}

	/** Two requests with the same key at once: the second waits on the first reservation, then replays it, so stock changes once. */
	public function testDeterministicConcurrentDuplicateBooksOnce(): void
	{
		$product=self::product(); $key=bin2hex(random_bytes(16)); $body=self::body('add'); $path=self::path($product,'add');
		// Hold the same per-product lock StockService takes. The first request reserves
		// its key, then waits here; the second demonstrably waits on that reservation.
		$lock=new PDO('pgsql:host='.getenv('PGHOST').';port='.getenv('PGPORT').';dbname='.getenv('PHPUNIT_DB_NAME'),getenv('PGUSER'),getenv('PGPASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
		$lock->beginTransaction();
		$lock->query('SELECT pg_advisory_xact_lock('.PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS.','.$product.')');
		$first=self::start($path,$body,$key);
		try
		{
			self::waitForBlocked(1);
			$second=self::start($path,$body,$key);
			self::waitForBlocked(2);
		}
		finally { $lock->rollBack(); }
		$a=self::finish($first); $b=self::finish($second); self::ok($a); self::ok($b);
		self::assertSame($a['body'][0]['transaction_id'],$b['body'][0]['transaction_id']); self::header($a,'false'); self::header($b,'true');
		self::assertSame(12.0,(float)self::$db->query("SELECT sum(amount) FROM stock WHERE product_id=$product")->fetchColumn());
	}

	/** Waits until $count other connections are blocked on a lock, or fails after ten seconds. */
	private static function waitForBlocked(int $count): void
	{
		$until=microtime(true)+10;
		do
		{
			$blocked=(int)self::$db->query("SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid() AND wait_event_type='Lock'")->fetchColumn();
			if ($blocked >= $count) { return; }
			usleep(20000);
		} while (microtime(true)<$until);
		self::fail("Expected $count blocked booking connection(s), observed $blocked");
	}
}
