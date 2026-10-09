<?php
// SPIKE ONLY. Shared helpers for the ADR-0042 probes: a plain PDO connection and a scratch
// schema. Nothing under services/, migrations/ or controllers/ is touched.

// PHP 8.5 deprecates PDO::pgsqlCopyFromArray(); the notice would corrupt the JSON on stdout.
error_reporting(E_ALL & ~E_DEPRECATED);

function adr42_dsn(): string
{
	return 'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PGDATABASE');
}

function adr42_connect(?string $schema = null, ?string $sessionZone = null): PDO
{
	$pdo = new PDO(adr42_dsn(), getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	if ($schema !== null)
	{
		$pdo->exec('SET search_path TO ' . $schema . ', public');
	}
	if ($sessionZone !== null)
	{
		$pdo->exec('SET TIME ZONE ' . $pdo->quote($sessionZone));
	}
	return $pdo;
}

function adr42_schema(PDO $pdo, string $tag): string
{
	$schema = 'adr42_' . $tag . '_' . getmypid();
	$pdo->exec("DROP SCHEMA IF EXISTS $schema CASCADE");
	$pdo->exec("CREATE SCHEMA $schema");
	$pdo->exec("SET search_path TO $schema, public");
	return $schema;
}

function adr42_load_sql(PDO $pdo, string $file): void
{
	$pdo->exec(file_get_contents(__DIR__ . '/' . $file));
}

function adr42_emit(array $out): void
{
	echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
}

function adr42_environment(PDO $pdo): array
{
	return [
		'php' => PHP_VERSION,
		'php_timezone' => date_default_timezone_get(),
		'php_ini_date_timezone' => ini_get('date.timezone'),
		'php_tzdata' => timezone_version_get(),
		'postgres' => $pdo->query('SHOW server_version')->fetchColumn(),
		'postgres_server_timezone_default' => $pdo->query('SHOW TimeZone')->fetchColumn(),
	];
}
