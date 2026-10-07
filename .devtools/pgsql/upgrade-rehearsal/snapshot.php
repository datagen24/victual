<?php

// Writes every base table of a database as JSON, for verify.py to compare across an upgrade.
//
//   php .devtools/pgsql/upgrade-rehearsal/snapshot.php <host> <database> > snapshot.json
//
// Plain PDO and the catalogue only, no application code, so it reads the source schema and
// the target schema the same way. Credentials are victual/victual, as in the suite.
//
// Each value is rendered so that a comparison cannot be fooled by the session zone:
// - `timestamp without time zone` as its wall clock, to the microsecond;
// - `timestamp with time zone` as microseconds since the epoch, an exact integer;
// - an infinite timestamp as `infinity` or `-infinity`;
// - every other type as its text form.

if ($argc !== 3)
{
	fwrite(STDERR, "Usage: php snapshot.php <host> <database>\n");
	exit(1);
}

$db = new PDO("pgsql:host={$argv[1]};port=5432;dbname={$argv[2]}", 'victual', 'victual', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("SET TIME ZONE 'UTC'");

$columns = $db->query(<<<'SQL'
	SELECT c.table_name, c.column_name, c.data_type
	FROM information_schema.columns c
	JOIN information_schema.tables t USING (table_schema, table_name)
	WHERE c.table_schema = 'public' AND t.table_type = 'BASE TABLE'
	ORDER BY c.table_name, c.ordinal_position
	SQL)->fetchAll(PDO::FETCH_ASSOC);

$tables = [];
foreach ($columns as $column)
{
	$tables[$column['table_name']][$column['column_name']] = $column['data_type'];
}

$snapshot = ['migration' => (int)$db->query('SELECT max(migration) FROM migrations')->fetchColumn(), 'server_version' => $db->query('SELECT version()')->fetchColumn(), 'tables' => []];
foreach ($tables as $table => $types)
{
	$pk = $db->query("SELECT a.attname FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey) WHERE i.indrelid = format('public.%I', '$table')::regclass AND i.indisprimary ORDER BY a.attnum")->fetchAll(PDO::FETCH_COLUMN);
	$key = $pk ? implode(" || '|' || ", array_map(fn($c) => "\"$c\"::text", $pk)) : 'ctid::text';
	$select = ["$key AS \"__key\""];
	foreach ($types as $name => $type)
	{
		$q = "\"$name\"";
		$select[] = match ($type)
		{
			'timestamp without time zone' => "CASE WHEN NOT isfinite($q) THEN $q::text ELSE to_char($q, 'YYYY-MM-DD HH24:MI:SS.US') END AS $q",
			'timestamp with time zone' => "CASE WHEN NOT isfinite($q) THEN $q::text ELSE (extract(epoch FROM $q) * 1000000)::numeric(30, 0)::text END AS $q",
			default => "$q::text AS $q"
		};
	}
	$rows = [];
	foreach ($db->query('SELECT ' . implode(', ', $select) . " FROM \"$table\"")->fetchAll(PDO::FETCH_ASSOC) as $row)
	{
		$k = $row['__key'];
		unset($row['__key']);
		$rows[$k] = $row;
	}
	ksort($rows, SORT_NATURAL);
	$snapshot['tables'][$table] = ['primary_key' => $pk, 'types' => $types, 'rows' => (object)$rows];
}

echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
