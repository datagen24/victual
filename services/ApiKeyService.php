<?php

namespace Victual\Services;

/**
 * Manages API keys: creation, validation and lookup, including the special purpose
 * key used for the iCal calendar sharing URL.
 */
class ApiKeyService extends BaseService
{
	/**
	 * API key types: "default" for regular user-created keys, the special purpose
	 * type for the anonymously accessible calendar iCal export URL.
	 */
	const API_KEY_TYPE_DEFAULT = 'default';
	const API_KEY_TYPE_LABEL_WORKER = 'label-worker';
	const API_KEY_TYPE_LABEL_VERIFIER = 'label-verifier';

	/**
	 * The renderer's credential, and the answer to ADR-0021 question 1 / plan 27 question 8.
	 *
	 * A renderer may read its assigned inputs and upload its own result and **may not claim a
	 * print attempt**. That is two authorities at two granularities, so it is answered with
	 * two mechanisms that already exist rather than with a new one: the **key type** scopes
	 * which routes the credential is accepted on at all, exactly as the worker type and the
	 * calendar type are scoped, and the **generation token** the claim hands back is the
	 * per-resource grant - it names one render request, one generation, and expires with the
	 * lease. Nothing a renderer holds addresses a printer, and no route it can reach opens an
	 * attempt.
	 */
	const API_KEY_TYPE_LABEL_RENDERER = 'label-renderer';
	const API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL = 'special-purpose-calendar-ical';

	/**
	 * The value stored in api_keys.api_key for a key of the given type.
	 *
	 * A regular key is stored as its SHA-256 hash, so that a copy of the database is not a
	 * set of live credentials (plan 11, question 4). Clients keep sending the same string;
	 * only what is on disk changed, in migration 0264.
	 *
	 * SHA-256 rather than password_hash(): a key is looked up *by value* on every
	 * authenticated request, which salted bcrypt cannot do without scanning the table, and
	 * these are 50 characters of random alphabet - roughly 250 bits of entropy. Brute force
	 * is not the threat model; a leaked table is, and an unsalted hash of a high-entropy
	 * secret is exactly right for that.
	 *
	 * **A special-purpose calendar key is stored as issued, deliberately.** The application
	 * has to hand its URL back to whoever asks for the sharing link, and it cannot do that
	 * from a hash; regenerating the key instead would break every calendar application
	 * already subscribed. The exposure is bounded and is not the API - such a key is
	 * accepted on the calendar-ical route only, and only for its own key_type - so what a
	 * leaked table yields is the household's calendar rather than an account.
	 */
	public static function StoredValueOf(string $apiKey, string $keyType): string
	{
		return $keyType === self::API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL ? $apiKey : self::HashKey($apiKey);
	}

	/**
	 * The SHA-256 hash of a key, as stored.
	 */
	public static function HashKey(string $apiKey): string
	{
		return hash('sha256', $apiKey);
	}

	/**
	 * The last four characters of a key, kept so that the manage-keys screen can still tell
	 * two keys apart once neither can be read back.
	 */
	public static function HintFor(string $apiKey): string
	{
		return substr($apiKey, -4);
	}

	/**
	 * Creates a new random API key for the current user and returns it.
	 *
	 * A regular key (API_KEY_TYPE_DEFAULT) gets a real, finite expiry: $lifetimeDays when
	 * given, clamped to VICTUAL_API_KEY_MAX_LIFETIME_DAYS, or that maximum when omitted
	 * (issue #130, sweep S11's expiry half). Every other key type keeps the year-2999
	 * expiry this always set - the calendar sharing key and the label worker/verifier/
	 * renderer credentials each already have their own expiry and rotation story (the
	 * latter through ADR-0019's paired rotation, in LabelWorkerCredentialService, which
	 * writes api_keys directly and never calls this method) and are untouched by it.
	 *
	 * @param int|null $lifetimeDays Regular keys only; null means the configured maximum
	 * @param int|null $rotatedFromId The predecessor this key replaces, for RotateApiKey()
	 * @param int|null $ownerId Row owner; null means the current user (every caller except
	 *                          RotateApiKey(), which passes the predecessor's owner - an
	 *                          admin rotating someone else's key must not mint a row owned
	 *                          by the admin instead of by the key's actual owner
	 * @return string The newly generated API key
	 */
	public function CreateApiKey(string $keyType = self::API_KEY_TYPE_DEFAULT, ?string $description = null, ?int $lifetimeDays = null, ?int $rotatedFromId = null, ?int $ownerId = null)
	{
		$newApiKey = $this->GenerateKey();

		$apiKeyRow = $this->DB->api_keys()->createRow([
			'api_key' => self::StoredValueOf($newApiKey, $keyType),
			'key_hint' => self::HintFor($newApiKey),
			'user_id' => $ownerId ?? VICTUAL_USER_ID,
			'expires' => $this->ExpiryFor($keyType, $lifetimeDays),
			'key_type' => $keyType,
			'description' => $description,
			'rotated_from_id' => $rotatedFromId
		]);
		$apiKeyRow->save();

		// The only moment the plaintext of a regular key exists. The caller shows it once;
		// nothing can produce it again.
		return $newApiKey;
	}

	/**
	 * The `expires` value a newly created key of the given type gets.
	 */
	private function ExpiryFor(string $keyType, ?int $lifetimeDays): string
	{
		if ($keyType !== self::API_KEY_TYPE_DEFAULT)
		{
			return '2999-12-31 23:59:59';
		}

		$maxDays = max(1, (int)VICTUAL_API_KEY_MAX_LIFETIME_DAYS);
		$days = $lifetimeDays === null ? $maxDays : max(1, min($lifetimeDays, $maxDays));

		return date('Y-m-d H:i:s', strtotime("+{$days} days"));
	}

	/**
	 * Rotates a regular API key: creates a successor of the same type and description,
	 * carrying a fresh random value, a fresh expiry and a link back to the key it
	 * replaces, and returns its plaintext and row id.
	 *
	 * Create-a-successor-then-retire (issue #130): this does not touch the predecessor at
	 * all. It keeps authenticating exactly as before, so a client can be switched over to
	 * the successor with no gap; retiring the predecessor is the caller's own, separate,
	 * explicit act (deleting it, the existing DELETE /api/objects/api_keys/{id} - every
	 * key is retired that way), never a side effect of rotating.
	 *
	 * Restricted to API_KEY_TYPE_DEFAULT on purpose: the special-purpose key types each
	 * have their own rotation story already (ADR-0019's paired rotation for label
	 * credentials; the calendar key is meant to be long-lived and handed out as a URL) and
	 * this must not regress them by offering a second, conflicting one.
	 *
	 * The successor is owned by the predecessor's own user, not by whoever is calling.
	 * OpenApiController::RotateApiKey() lets an admin rotate a key that belongs to someone
	 * else (the same rule DeleteObject already applies to api_keys), and CreateApiKey()
	 * otherwise always writes the *current* user as owner - passing that through here
	 * unexamined would have handed the admin a row that authenticates as the admin, not as
	 * the household member whose key it is meant to replace.
	 *
	 * @return array{0:string,1:int} [the successor's plaintext key, its row id]
	 */
	public function RotateApiKey(int $apiKeyId, ?int $lifetimeDays = null): array
	{
		$predecessor = $this->DB->api_keys($apiKeyId);

		if ($predecessor === null || $predecessor->key_type !== self::API_KEY_TYPE_DEFAULT)
		{
			throw new \InvalidArgumentException('Only a regular API key can be rotated');
		}

		$newApiKey = $this->CreateApiKey(self::API_KEY_TYPE_DEFAULT, $predecessor->description, $lifetimeDays, $apiKeyId, (int)$predecessor->user_id);

		return [$newApiKey, $this->GetApiKeyId($newApiKey)];
	}

	/**
	 * Returns the row id of the given API key.
	 *
	 * @param string $apiKey
	 * @return int
	 */
	public function GetApiKeyId($apiKey, string $keyType = self::API_KEY_TYPE_DEFAULT)
	{
		$apiKey = $this->DB->api_keys()->where('api_key', self::StoredValueOf($apiKey, $keyType))->fetch();
		return $apiKey->id;
	}

	/**
	 * Returns the current user's valid (unexpired) key of the given type, creating one
	 * when they have none; only available for the recoverable calendar key (returns null for other types).
	 *
	 * Scoped to the current user, which it was not: the lookup matched on key_type alone,
	 * so the first person to open the calendar sharing dialog created the key and every
	 * other household member was then handed *that* key - a URL that authenticates as its
	 * creator, and that a subscribed calendar application keeps using indefinitely
	 * (sweep finding S17). Nobody noticed because the branch that consumes these keys was
	 * itself unreachable; both halves are fixed together.
	 *
	 * @param string $keyType One of the API_KEY_TYPE_* constants
	 * @return string|null
	 */
	public function GetOrCreateApiKey($keyType)
	{
		if ($keyType !== self::API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL)
		{
			return null;
		}

		$apiKeyRow = $this->DB->api_keys()->where('key_type = :1 AND user_id = :2 AND expires > :3', $keyType, VICTUAL_USER_ID, date('Y-m-d H:i:s', time()))->fetch();

		if ($apiKeyRow !== null)
		{
			return $apiKeyRow->api_key;
		}

		return $this->CreateApiKey($keyType);
	}

	/**
	 * Returns the user row the given API key belongs to, or null for an unknown key.
	 *
	 * @param string $apiKey
	 * @return \LessQL\Row|null
	 */
	public function GetUserByApiKey($apiKey, string $keyType = self::API_KEY_TYPE_DEFAULT)
	{
		$apiKeyRow = $this->DB->api_keys()->where('api_key', self::StoredValueOf($apiKey, $keyType))->fetch();

		if ($apiKeyRow !== null)
		{
			return $this->DB->users($apiKeyRow->user_id);
		}

		return null;
	}

	/**
	 * Checks that the given key exists with the given type and is not expired, and stamps
	 * last_used on the first success of each day (without advancing the db changed time,
	 * since that would make API clients refetch unchanged data).
	 *
	 * @param string|null $apiKey
	 * @param string $keyType One of the API_KEY_TYPE_* constants
	 * @return bool
	 */
	public function IsValidApiKey($apiKey, $keyType = self::API_KEY_TYPE_DEFAULT)
	{
		if ($apiKey === null || empty($apiKey))
		{
			return false;
		}

		$apiKeyRow = $this->DB->api_keys()->where('api_key = :1 AND expires > :2 AND key_type = :3', self::StoredValueOf($apiKey, $keyType), date('Y-m-d H:i:s', time()), $keyType)->fetch();

		if ($apiKeyRow === null)
		{
			return false;
		}

		// Only once a day, not once a request. A read-only GET used to issue a write on
		// every call, which is a write on the hot path of the endpoint clients poll most
		// and an invalidation of the row's cache line for a value nobody reads to the
		// second. The manage-keys screen shows a date; a date is what is kept accurate.
		$today = date('Y-m-d');

		if (substr((string)$apiKeyRow->last_used, 0, 10) !== $today)
		{
			// This should not change the database file modification time as this is used
			// to determine if REALLY something has changed
			$dbModTime = DatabaseService::GetInstance()->GetDbChangedTime();
			$apiKeyRow->update([
				'last_used' => date('Y-m-d H:i:s', time())
			]);
			DatabaseService::GetInstance()->SetDbChangedTime($dbModTime);
		}

		return true;
	}

	/**
	 * Deletes the given API key.
	 *
	 * @param string $apiKey
	 */
	public function RemoveApiKey($apiKey, string $keyType = self::API_KEY_TYPE_DEFAULT)
	{
		$this->DB->api_keys()->where('api_key', self::StoredValueOf($apiKey, $keyType))->delete();
	}

	/**
	 * Generates a random 50 character key.
	 *
	 * @return string
	 */
	private function GenerateKey()
	{
		return RandomString(50);
	}
}
