<?php
// SPIKE ONLY. Shared bootstrap for the ADR-0040 probes: a disposable, fully migrated schema
// created by the repository's own PHPUnit base class, and a way for child processes to attach
// to that schema through the real DatabaseService singleton.
require '/app/tests/bootstrap.php';

use Victual\Services\DatabaseService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

class A40 extends PgsqlSchemaTestCase
{
    public static function create(): PDO
    {
        parent::setUpBeforeClass();
        return self::Pdo();
    }

    public static function drop(): void
    {
        parent::tearDownAfterClass();
    }

    public static function schemaName(): string
    {
        return self::Schema();
    }

    /** Child side: attach DatabaseService to an existing schema, as setUpBeforeClass() does. */
    public static function attach(string $schema): PDO
    {
        static::Boot();
        $pdo = new PDO('pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
            getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET search_path TO ' . $schema . ', public');
        DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);
        (new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
        (new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);
        static::ResetSchemaBoundState();
        return $pdo;
    }
}

function a40_canon($v)
{
    if (is_array($v)) {
        if ($v !== [] && array_keys($v) !== range(0, count($v) - 1)) { ksort($v); }
        return array_map('a40_canon', $v);
    }
    return $v;
}
