<?php

// Does API key expiry and rotation behave the way issue #130 (sweep S11's remaining half)
// asks for?
//
//   php apikey-tests.php
//
// PostgreSQL only: migrations/0280.pgsql.sql (api_keys.rotated_from_id) is above
// DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, so there is no second engine to
// compare against - and the subject here is dates and rows, not something a view
// comparison could see even if there were.
//
// Cases, following the issue's own Verification list:
//
//   1. A key created with a short lifetime is accepted before its expiry and refused after -
//      "before"/"after" simulated by moving the stored `expires` into the past rather than
//      by sleeping, since the assertion is about the comparison in IsValidApiKey(), not
//      about the clock.
//   2. A lifetime beyond the configured maximum is clamped, not refused - CreateApiKey()
//      takes a view-form value, not an API request, so it tolerates rather than 400s.
//   3. Omitting a lifetime gets the configured maximum, not the old year-2999 default.
//   4. Rotation: create-a-successor-then-retire. The predecessor keeps authenticating
//      through the rotation itself (no gap); after it is retired (deleted, the existing
//      action), it is refused while the successor keeps working throughout - no double
//      standard, no window where both or neither work.
//   5. RotateApiKey() refuses a special-purpose key type, so the calendar and label
//      credential rotation stories this issue must not regress cannot be reached through
//      the new code path at all.
//   6. Special-purpose key types created through ApiKeyService::CreateApiKey() directly
//      still get the year-2999 expiry, unaffected by the new lifetime logic - the control
//      that this change is additive for every type but the regular one.
//   7. The hint and hash behaviour from migrations 0263/0264 is unchanged: a regular key is
//      looked up by its SHA-256 hash and carries a four-character hint; a rotated
//      successor is no different.
//   8. The controller, not just the service: OpenApiController::RotateApiKey() refuses
//      someone else's key with the same 404 a missing row gets (so ids cannot be
//      enumerated, matching the existing DeleteObject ownership pattern for api_keys),
//      an admin may rotate a key that is not theirs, and a successful rotation renders
//      the manage-keys page with the new plaintext once rather than redirecting to it -
//      the same S11 reasoning /manageapikeys/new already follows.
//   9. The MCP key type (issue #208): hashed, finitely expiring, rotatable with its type and
//      read-only flag kept, never shown as readable, and found by the header lookup only
//      when the accepted types include it.
//
// Exit codes: 0 when every assertion holds, 1 otherwise.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';
define('VICTUAL_USER_ID', 9800);
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_LOCALE', 'en');
define('VICTUAL_AUTHENTICATED', true);
define('VICTUAL_USER_USERNAME', 'apikey-caller');
define('VICTUAL_USER_PICTURE_FILE_NAME', null);

use Victual\Services\ApiKeyService;
use Victual\Services\DatabaseService;
use Victual\Controllers\Api\OpenApiController;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$pdo = DatabaseService::GetInstance()->GetDbConnectionRaw();
$container = new DI\Container();
$container->set('view', new Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
$container->set('UrlManager', new Victual\Helpers\UrlManager(''));

function request(string $method = 'GET', $body = null)
{
	return (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/manageapikeys')
		->withParsedBody($body);
}

$checks = 0;
$failures = 0;

function check(bool $ok, string $message): void
{
	global $checks, $failures;

	if ($ok)
	{
		$checks++;
		printf("  ok     %s\n", $message);

		return;
	}

	$failures++;
	printf("  FAIL   %s\n", $message);
}

function ExpiresOf(int $apiKeyId): string
{
	global $pdo;

	$statement = $pdo->prepare('SELECT expires FROM api_keys WHERE id = ?');
	$statement->execute([$apiKeyId]);

	return (string)$statement->fetchColumn();
}

/** Simulates time passing without sleeping: moves a row's stored expiry into the past. */
function BackdateExpiry(int $apiKeyId): void
{
	global $pdo;

	$pdo->prepare("UPDATE api_keys SET expires = CURRENT_TIMESTAMP - INTERVAL '1 minute' WHERE id = ?")->execute([$apiKeyId]);
}

/** The explicit retirement action this issue asks for: deleting the row. */
function Retire(int $apiKeyId): void
{
	global $pdo;

	$pdo->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$apiKeyId]);
}

$pdo->exec('DELETE FROM api_keys WHERE user_id = 9800');
$pdo->exec('DELETE FROM users WHERE id = 9800');
$pdo->exec("INSERT INTO users (id, username, password) VALUES (9800, 'apikey-caller', 'fixture')");

$service = ApiKeyService::GetInstance();

echo "API key expiry and rotation (" . DatabaseService::GetInstance()->GetDialect()->GetName() . ")\n\n";

// --- 1. A short-lived key is accepted before its expiry and refused after ------------------

echo "1. expiry\n";

$shortLivedKey = $service->CreateApiKey(ApiKeyService::API_KEY_TYPE_DEFAULT, 'Short-lived', 1);
$shortLivedId = $service->GetApiKeyId($shortLivedKey);

check($service->IsValidApiKey($shortLivedKey), 'a freshly created key with a 1-day lifetime authenticates');

BackdateExpiry($shortLivedId);

check(!$service->IsValidApiKey($shortLivedKey), 'the same key is refused once its expiry has passed');

// --- 2. A lifetime beyond the configured maximum is clamped ---------------------------------

echo "\n2. clamped to the configured maximum\n";

$maxDays = (int)VICTUAL_API_KEY_MAX_LIFETIME_DAYS;
$overLongKey = $service->CreateApiKey(ApiKeyService::API_KEY_TYPE_DEFAULT, 'Too long', $maxDays + 5000);
$overLongId = $service->GetApiKeyId($overLongKey);
$expectedMax = (new DateTimeImmutable())->modify("+{$maxDays} days")->format('Y-m-d');

check(substr(ExpiresOf($overLongId), 0, 10) === $expectedMax,
	"a requested lifetime of " . ($maxDays + 5000) . " days is clamped to the configured maximum of $maxDays days (expires " . ExpiresOf($overLongId) . ")");

// --- 3. Omitting a lifetime gets the configured maximum, not year 2999 ----------------------

echo "\n3. no lifetime given -> the configured maximum, not the old non-expiring default\n";

$defaultLifetimeKey = $service->CreateApiKey(ApiKeyService::API_KEY_TYPE_DEFAULT, 'No lifetime given');
$defaultLifetimeId = $service->GetApiKeyId($defaultLifetimeKey);

check(substr(ExpiresOf($defaultLifetimeId), 0, 10) === $expectedMax,
	'a key created with no lifetime argument gets the configured maximum (expires ' . ExpiresOf($defaultLifetimeId) . ')');
check(substr(ExpiresOf($defaultLifetimeId), 0, 4) !== '2999',
	'and specifically not the year 2999 - regular keys no longer default to practically-never-expiring');

// --- 4. Rotation: no gap, and the predecessor's retirement is explicit ----------------------

echo "\n4. rotation\n";

$predecessorKey = $service->CreateApiKey(ApiKeyService::API_KEY_TYPE_DEFAULT, 'Rotated key', 30);
$predecessorId = $service->GetApiKeyId($predecessorKey);

[$successorKey, $successorId] = $service->RotateApiKey($predecessorId);

check($successorKey !== $predecessorKey, 'the successor is a fresh random value, not the same key');
check($successorId !== $predecessorId, 'and a new row');

$successorRow = $pdo->query('SELECT rotated_from_id, description, key_type FROM api_keys WHERE id = ' . $successorId)->fetch(PDO::FETCH_ASSOC);
check((int)$successorRow['rotated_from_id'] === $predecessorId, 'the successor records which key it replaces');
check($successorRow['description'] === 'Rotated key', "the successor carries the predecessor's description");
check($successorRow['key_type'] === ApiKeyService::API_KEY_TYPE_DEFAULT, 'and the same key type');

check($service->IsValidApiKey($predecessorKey), 'the predecessor keeps authenticating right after rotation - no gap');
check($service->IsValidApiKey($successorKey), 'and the successor already authenticates - both work at once, by the caller\'s choice');

Retire($predecessorId);

check(!$service->IsValidApiKey($predecessorKey), 'the predecessor is refused once explicitly retired (deleted)');
check($service->IsValidApiKey($successorKey), 'the successor is unaffected by retiring its predecessor - ON DELETE SET NULL, not CASCADE');

$successorRotatedFromAfterRetire = $pdo->query('SELECT rotated_from_id FROM api_keys WHERE id = ' . $successorId)->fetchColumn();
check($successorRotatedFromAfterRetire === null, "the successor's lineage pointer is nulled rather than the row being taken down with its predecessor");

// --- 5. RotateApiKey() refuses a special-purpose key type ------------------------------------

echo "\n5. rotation is refused for a special-purpose key\n";

$calendarKey = $service->CreateApiKey(ApiKeyService::API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL, 'Calendar sharing');
$calendarId = $service->GetApiKeyId($calendarKey, ApiKeyService::API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL);

$refused = false;
try
{
	$service->RotateApiKey($calendarId);
}
catch (InvalidArgumentException $exception)
{
	$refused = true;
}

check($refused, 'rotating a calendar key through the new path is refused rather than silently handled');

// --- 6. Special-purpose key types are unaffected by the new lifetime logic ------------------

echo "\n6. special-purpose key types keep their year-2999 expiry\n";

check(substr(ExpiresOf($calendarId), 0, 4) === '2999', 'a calendar key created via CreateApiKey() is still practically non-expiring');

$labelWorkerKey = $service->CreateApiKey(ApiKeyService::API_KEY_TYPE_LABEL_WORKER, 'Label worker (direct call, not the real pairing path)');
$labelWorkerId = $service->GetApiKeyId($labelWorkerKey, ApiKeyService::API_KEY_TYPE_LABEL_WORKER);
check(substr(ExpiresOf($labelWorkerId), 0, 4) === '2999',
	'and so is a label-worker-type key created the same way, even though LabelWorkerCredentialService never actually calls CreateApiKey() for real ones');

// --- 7. Hint and hash behaviour from 0263/0264 is unchanged ----------------------------------

echo "\n7. hint and hash unchanged\n";

$hashRow = $pdo->query("SELECT api_key, key_hint FROM api_keys WHERE id = $successorId")->fetch(PDO::FETCH_ASSOC);
check($hashRow['api_key'] === hash('sha256', $successorKey), 'the successor is stored as its SHA-256 hash, exactly as any other regular key');
check($hashRow['key_hint'] === substr($successorKey, -4), 'and its hint is the last four characters of the plaintext');
check(preg_match('/^[0-9a-f]{64}$/', $hashRow['api_key']) === 1, 'the stored value has the shape a hash has, not a 50-character plaintext key');

// --- 8. The controller: ownership, ids that cannot be enumerated, and the render-once page ---

echo "\n8. OpenApiController::RotateApiKey()\n";

$pdo->exec("DELETE FROM users WHERE id = 9801");
$pdo->exec("INSERT INTO users (id, username, password) VALUES (9801, 'apikey-other-user', 'fixture')");
$otherUsersKey = $service->CreateApiKey(ApiKeyService::API_KEY_TYPE_DEFAULT, 'A key owned by another user');
$otherUsersKeyId = $service->GetApiKeyId($otherUsersKey);
$pdo->prepare('UPDATE api_keys SET user_id = 9801 WHERE id = ?')->execute([$otherUsersKeyId]);

$api = new OpenApiController($container);

$notFoundOnAnotherUsersKey = false;
try
{
	$api->RotateApiKey(request('POST'), new Response(), ['id' => (string)$otherUsersKeyId]);
}
catch (Slim\Exception\HttpNotFoundException $exception)
{
	$notFoundOnAnotherUsersKey = true;
}

check($notFoundOnAnotherUsersKey, "a non-admin is refused with the same 404 a missing row gets when rotating someone else's key, so ids cannot be enumerated");
check($service->IsValidApiKey($otherUsersKey), "and that key is untouched - the refused attempt created no successor");

$notFoundOnNonInteger = false;
try
{
	$api->RotateApiKey(request('POST'), new Response(), ['id' => 'not-a-number']);
}
catch (Slim\Exception\HttpNotFoundException $exception)
{
	$notFoundOnNonInteger = true;
}

check($notFoundOnNonInteger, 'a non-integer id is refused the same way rather than reaching the database');

$notFoundOnSpecialPurpose = false;
try
{
	$api->RotateApiKey(request('POST'), new Response(), ['id' => (string)$calendarId]);
}
catch (Slim\Exception\HttpNotFoundException $exception)
{
	$notFoundOnSpecialPurpose = true;
}

check($notFoundOnSpecialPurpose, 'the controller refuses a special-purpose key the same way the service does, so the UI action cannot reach it either');

// An admin may rotate a key that is not theirs - the same rule DeleteObject already applies
// to api_keys, extended here rather than re-invented.
$pdo->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9800, id FROM permission_hierarchy WHERE name = 'ADMIN'");

$adminResponse = $api->RotateApiKey(request('POST'), new Response(), ['id' => (string)$otherUsersKeyId]);
$adminResponseBody = (string)$adminResponse->getBody();

check($adminResponse->getStatusCode() === 200, 'an admin rotating a key that belongs to another user succeeds');
check(str_contains($adminResponseBody, 'A key owned by another user'), "the rendered page carries the predecessor's description");
check(!str_contains($adminResponseBody, $otherUsersKey), 'and does not repeat the predecessor\'s plaintext - only the successor\'s');

$successorOwnerRow = $pdo->query("SELECT user_id FROM api_keys WHERE rotated_from_id = $otherUsersKeyId")->fetch(PDO::FETCH_ASSOC);
check($successorOwnerRow !== false && (int)$successorOwnerRow['user_id'] === 9801,
	"the successor belongs to the predecessor's own user (9801), not to the admin (9800) who clicked Rotate");

$pdo->exec("DELETE FROM user_permissions WHERE user_id = 9800");

// The plain "add" path, through the controller, with the new expires_in_days field.
$createResponse = $api->CreateNewApiKey(request('POST', ['description' => 'Via the controller', 'expires_in_days' => '10']), new Response(), []);
$createBody = (string)$createResponse->getBody();

check($createResponse->getStatusCode() === 200, 'CreateNewApiKey renders rather than redirects, so the plaintext is shown exactly once');
check(str_contains($createBody, 'Via the controller'), 'the rendered page carries the description that was posted');

// --- 9. The MCP key type (issue #208) --------------------------------------------------------

echo "\n9. MCP keys\n";

$mcpKey = $service->CreateApiKey(ApiKeyService::API_KEY_TYPE_MCP, 'Assistant', 30, null, null, true);
$mcpId = $service->GetApiKeyId($mcpKey, ApiKeyService::API_KEY_TYPE_MCP);
$mcpRow = $pdo->query("SELECT api_key, key_hint, key_type, read_only, expires FROM api_keys WHERE id = $mcpId")->fetch(PDO::FETCH_ASSOC);

check($mcpRow['key_type'] === ApiKeyService::API_KEY_TYPE_MCP, 'an MCP key is stored with its own type');
check($mcpRow['api_key'] === hash('sha256', $mcpKey), 'and as a SHA-256 hash, like a regular key - it is not readable back');
check((int)$mcpRow['read_only'] === 1, 'the read-only flag is stored when asked for');
check(substr($mcpRow['expires'], 0, 4) !== '2999', 'an MCP key gets a finite expiry, like a regular key (expires ' . $mcpRow['expires'] . ')');
check(!ApiKeyIsReadable((object)$mcpRow), 'the manage-keys screen does not treat an MCP key as readable, so it never shows or QR-encodes the hash');

check($service->FindValidApiKey($mcpKey, ApiKeyService::USER_ISSUED_KEY_TYPES) !== null, 'the header lookup over the user-issued types finds an MCP key');
check($service->FindValidApiKey($mcpKey, [ApiKeyService::API_KEY_TYPE_MCP]) !== null, 'and so does a lookup narrowed to the MCP type');
check($service->FindValidApiKey($mcpKey, [ApiKeyService::API_KEY_TYPE_DEFAULT]) === null, 'a lookup narrowed to the regular type does not');
check($service->FindValidApiKey($successorKey, [ApiKeyService::API_KEY_TYPE_MCP]) === null, 'and a regular key does not pass a lookup narrowed to the MCP type');
check($service->FindValidApiKey($mcpKey, []) === null, 'an empty set of accepted types matches nothing');

$regularDefaultFlag = $pdo->query("SELECT read_only FROM api_keys WHERE id = $successorId")->fetchColumn();
check((int)$regularDefaultFlag === 0, 'a key created without the flag is not read-only - existing keys keep the authority they had');

[$mcpSuccessorKey, $mcpSuccessorId] = $service->RotateApiKey($mcpId);
$mcpSuccessorRow = $pdo->query("SELECT key_type, read_only, rotated_from_id FROM api_keys WHERE id = $mcpSuccessorId")->fetch(PDO::FETCH_ASSOC);
check($mcpSuccessorRow['key_type'] === ApiKeyService::API_KEY_TYPE_MCP, 'rotating an MCP key produces an MCP key');
check((int)$mcpSuccessorRow['read_only'] === 1, 'and the successor keeps the read-only flag - rotation cannot widen a key');
check((int)$mcpSuccessorRow['rotated_from_id'] === $mcpId, 'and records its lineage like any rotation');

$mcpCreate = $api->CreateNewApiKey(request('POST', ['description' => 'Via the controller, MCP', 'key_type' => 'mcp', 'read_only' => '1']), new Response(), []);
$mcpCreateBody = (string)$mcpCreate->getBody();
$mcpCreatedRow = $pdo->query("SELECT key_type, read_only FROM api_keys WHERE description = 'Via the controller, MCP'")->fetch(PDO::FETCH_ASSOC);
check($mcpCreatedRow !== false && $mcpCreatedRow['key_type'] === 'mcp' && (int)$mcpCreatedRow['read_only'] === 1,
	'the manage-keys form creates a read-only MCP key when it posts key_type=mcp and read_only=1');
check(str_contains($mcpCreateBody, 'Authorization: Bearer'), 'and the one-time reveal says how an MCP client presents it');

$api->CreateNewApiKey(request('POST', ['description' => 'Posted a special-purpose type', 'key_type' => ApiKeyService::API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL]), new Response(), []);
$smuggledType = $pdo->query("SELECT key_type FROM api_keys WHERE description = 'Posted a special-purpose type'")->fetchColumn();
check($smuggledType === ApiKeyService::API_KEY_TYPE_DEFAULT, 'a special-purpose type posted to the form is issued as a regular key, not as the type asked for');

echo "\n";

if ($failures === 0)
{
	echo "EVERY API KEY EXPIRY AND ROTATION CASE ANSWERED AS EXPECTED ($checks assertions)\n";
	exit(0);
}

echo $failures . " case(s) did not answer as expected\n";
exit(1);
