<?php

/**
 * Global helper functions (loaded unconditionally, no namespace):
 * array/object search utilities, type conversion and validation helpers,
 * and the Setting()/DefaultUserSetting() functions used by config-dist.php
 * and data/config.php to define VICTUAL_* configuration constants.
 */

/**
 * Returns the first object in $array whose $propertyName equals (==) $propertyValue.
 *
 * @return object|null The matching object or null when none matches
 */
function FindObjectInArrayByPropertyValue($array, $propertyName, $propertyValue)
{
	foreach ($array as $object)
	{
		if ($object->{$propertyName} == $propertyValue)
		{
			return $object;
		}
	}

	return null;
}

/**
 * Returns all objects in $array whose $propertyName compares to $propertyValue.
 *
 * @param string $operator Comparison operator: '==', '>' or '<' (anything else matches nothing)
 * @return array The matching objects (empty array when none matches)
 */
function FindAllObjectsInArrayByPropertyValue($array, $propertyName, $propertyValue, $operator = '==')
{
	$returnArray = [];
	foreach ($array as $object)
	{
		switch ($operator)
		{
			case '==':
				if ($object->{$propertyName} == $propertyValue)
				{
					$returnArray[] = $object;
				}
				break;
			case '>':
				if ($object->{$propertyName} > $propertyValue)
				{
					$returnArray[] = $object;
				}
				break;
			case '<':

				if ($object->{$propertyName} < $propertyValue)
				{
					$returnArray[] = $object;
				}
				break;
		}
	}

	return $returnArray;
}

/**
 * Returns all scalar items in $array which compare to $value.
 *
 * @param string $operator Comparison operator: '==', '>' or '<' (anything else matches nothing)
 * @return array The matching items (empty array when none matches)
 */
function FindAllItemsInArrayByValue($array, $value, $operator = '==')
{
	$returnArray = [];
	foreach ($array as $item)
	{
		switch ($operator)
		{
			case '==':

				if ($item == $value)
				{
					$returnArray[] = $item;
				}
				break;
			case '>':

				if ($item > $value)
				{
					$returnArray[] = $item;
				}
				break;
			case '<':

				if ($item < $value)
				{
					$returnArray[] = $item;
				}
				break;
		}
	}

	return $returnArray;
}

/**
 * Sums the given property (cast to float) over all objects in $array.
 *
 * @return float
 */
function SumArrayValue($array, $propertyName)
{
	$sum = 0;
	foreach ($array as $object)
	{
		$sum += floatval($object->{$propertyName});
	}

	return $sum;
}

/**
 * Returns the constants of the given class via reflection.
 *
 * @param string $className Fully qualified class name
 * @param string|null $prefix When given, only constants whose name starts with this prefix are returned
 * @return array Constant name => value
 */
function GetClassConstants($className, $prefix = null)
{
	$r = new ReflectionClass($className);
	$constants = $r->getConstants();

	if ($prefix === null)
	{
		return $constants;
	}
	else
	{
		$matchingKeys = preg_grep('!^' . $prefix . '!', array_keys($constants));
		return array_intersect_key($constants, array_flip($matchingKeys));
	}
}

/**
 * Generates a random string of the given length from $allowedChars
 * (default: alphanumeric); cryptographically secure (uses random_int()),
 * so the result is suitable for session keys and API keys.
 *
 * @return string
 */
function RandomString($length, $allowedChars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ')
{
	$randomString = '';
	for ($i = 0; $i < $length; $i++)
	{
		$randomString .= $allowedChars[random_int(0, strlen($allowedChars) - 1)];
	}

	return $randomString;
}

/**
 * Returns true when $array is associative (has non-sequential/string keys),
 * false for a plain indexed array.
 *
 * @return bool
 */
function IsAssociativeArray(array $array)
{
	$keys = array_keys($array);
	return array_keys($keys) !== $keys;
}

/**
 * Returns true when $dateString is a valid date in ISO format (Y-m-d).
 *
 * @return bool
 */
function IsIsoDate($dateString)
{
	$d = DateTime::createFromFormat('Y-m-d', $dateString);
	return $d && $d->format('Y-m-d') === $dateString;
}

/**
 * Returns true when $dateTimeString is a valid date/time in ISO format (Y-m-d H:i:s).
 *
 * @return bool
 */
function IsIsoDateTime($dateTimeString)
{
	$d = DateTime::createFromFormat('Y-m-d H:i:s', $dateTimeString);
	return $d && $d->format('Y-m-d H:i:s') === $dateTimeString;
}

/**
 * Converts a boolean to the string 'true' or 'false'.
 *
 * @return string
 */
function BoolToString(bool $bool)
{
	return $bool ? 'true' : 'false';
}

/**
 * Converts a boolean to 1 or 0.
 *
 * @return int
 */
function BoolToInt(bool $bool)
{
	return $bool ? 1 : 0;
}

/**
 * Normalizes a setting value coming from an external source (environment
 * variable or setting override file): trims trailing line breaks and converts
 * the strings 'true'/'false' (case insensitive) to real booleans.
 *
 * @return bool|string
 */
function ExternalSettingValue(string $value)
{
	$tvalue = rtrim($value, "\r\n");
	$lvalue = strtolower($tvalue);

	if ($lvalue === 'true')
	{
		return true;
	}
	elseif ($lvalue === 'false')
	{
		return false;
	}

	return $tvalue;
}

/**
 * Defines the configuration constant VICTUAL_$name with $value as default,
 * unless it is already defined or overridden by (in order of precedence)
 * a $name.txt file in VICTUAL_DATAPATH/settingoverrides or an environment
 * variable named VICTUAL_$name.
 *
 * @param string $name Setting name without the VICTUAL_ prefix
 * @param mixed $value Default value
 */
function Setting(string $name, $value)
{
	if (!defined('VICTUAL_' . $name))
	{
		// The content of a $name.txt file in /data/settingoverrides can overwrite the given setting (for embedded mode)
		$settingOverrideFile = VICTUAL_DATAPATH . '/settingoverrides/' . $name . '.txt';

		if (file_exists($settingOverrideFile))
		{
			define('VICTUAL_' . $name, ExternalSettingValue(file_get_contents($settingOverrideFile)));
		}
		elseif (getenv('VICTUAL_' . $name) !== false)
		{
			// An environment variable with the same name and prefix VICTUAL_ overwrites the given setting
			define('VICTUAL_' . $name, ExternalSettingValue(getenv('VICTUAL_' . $name)));
		}
		else
		{
			define('VICTUAL_' . $name, $value);
		}
	}
}

global $VICTUAL_DEFAULT_USER_SETTINGS;
$VICTUAL_DEFAULT_USER_SETTINGS = [];
/**
 * Registers the default value for a per-user setting (collected in the global
 * $VICTUAL_DEFAULT_USER_SETTINGS array); the first registration of a name wins.
 *
 * @param string $name User setting name
 * @param mixed $value Default value
 */
function DefaultUserSetting(string $name, $value)
{
	global $VICTUAL_DEFAULT_USER_SETTINGS;

	if (!array_key_exists($name, $VICTUAL_DEFAULT_USER_SETTINGS))
	{
		$VICTUAL_DEFAULT_USER_SETTINGS[$name] = $value;
	}
}

/**
 * Returns a display name for the given user row: "first last", one of the two
 * if only one is set, or the username when neither name is set.
 *
 * @param object $user A user row with first_name, last_name and username properties
 * @return string
 */
function GetUserDisplayName($user)
{
	$displayName = '';

	if (empty($user->first_name) && !empty($user->last_name))
	{
		$displayName = $user->last_name;
	}
	elseif (empty($user->last_name) && !empty($user->first_name))
	{
		$displayName = $user->first_name;
	}
	elseif (!empty($user->last_name) && !empty($user->first_name))
	{
		$displayName = $user->first_name . ' ' . $user->last_name;
	}
	else
	{
		$displayName = $user->username;
	}

	return $displayName;
}

/**
 * Returns true when $fileName is a plain "name.extension" file name without
 * path separators or other forbidden characters (/ ? * ; : { } \).
 *
 * @return bool
 */
function IsValidFileName($fileName)
{
	if (preg_match('=^[^/?*;:{}\\\\]+\.[^/?*;:{}\\\\]+$=', $fileName))
	{
		return true;
	}

	return false;
}

/**
 * Returns true when $text is valid JSON.
 *
 * @return bool
 */
function IsJsonString($text)
{
	json_decode($text);
	return (json_last_error() == JSON_ERROR_NONE);
}

/**
 * Returns true when $haystack starts with $needle.
 *
 * @return bool
 */
/**
 * Whether an IP address falls inside a CIDR range, or equals a bare address.
 *
 * Handles IPv4 and IPv6 by comparing the packed forms, so a v4 address is never
 * considered inside a v6 range or the reverse. An unparseable address or range is
 * not a match rather than an error - the caller's decision is "trusted or not",
 * and anything it cannot understand is not trusted.
 *
 * @param string $ip
 * @param string $cidr An address, or an address with a /prefix
 * @return bool
 */
function IsIpInCidr($ip, $cidr)
{
	$ipBinary = @inet_pton($ip);
	if ($ipBinary === false)
	{
		return false;
	}

	if (!str_contains($cidr, '/'))
	{
		$cidrBinary = @inet_pton($cidr);
		return $cidrBinary !== false && $ipBinary === $cidrBinary;
	}

	[$subnet, $prefixLength] = explode('/', $cidr, 2);
	$subnetBinary = @inet_pton(trim($subnet));

	// Different lengths mean one is IPv4 and the other IPv6, which never match
	if ($subnetBinary === false || strlen($subnetBinary) !== strlen($ipBinary))
	{
		return false;
	}

	if (!is_numeric(trim($prefixLength)))
	{
		return false;
	}

	$prefixLength = intval(trim($prefixLength));
	if ($prefixLength < 0 || $prefixLength > strlen($ipBinary) * 8)
	{
		return false;
	}

	$wholeBytes = intdiv($prefixLength, 8);
	$remainingBits = $prefixLength % 8;

	if ($wholeBytes > 0 && substr($ipBinary, 0, $wholeBytes) !== substr($subnetBinary, 0, $wholeBytes))
	{
		return false;
	}

	if ($remainingBits > 0)
	{
		$mask = chr((0xFF << (8 - $remainingBits)) & 0xFF);
		if ((substr($ipBinary, $wholeBytes, 1) & $mask) !== (substr($subnetBinary, $wholeBytes, 1) & $mask))
		{
			return false;
		}
	}

	return true;
}

/**
 * Whether an IP address matches any entry of a comma separated list of addresses and
 * CIDR ranges. An empty list matches nothing, which is the point: a caller using this
 * to decide whether to trust something must configure the list to trust anything.
 *
 * @param string $ip
 * @param string $list e.g. "10.0.0.0/8, 192.168.1.5, fd00::/8"
 * @return bool
 */
function IsIpInCidrList($ip, $list)
{
	foreach (explode(',', (string)$list) as $entry)
	{
		$entry = trim($entry);

		if ($entry !== '' && IsIpInCidr($ip, $entry))
		{
			return true;
		}
	}

	return false;
}

/**
 * Whether a stored URL is safe to place in an href.
 *
 * Escaping the value protects the attribute, not the navigation: `{{ }}` renders
 * `javascript:alert(1)` faithfully and the browser then runs it. Relative URLs and the
 * three schemes below are allowed; anything else carrying a scheme is refused. The probe
 * strips whitespace and control characters first because browsers ignore those inside a
 * scheme, so `java\nscript:` is the same URL to them and has to be to us. Sweep finding
 * S28.
 *
 * @param string|null $url
 * @return bool
 */
function IsSafeExternalUrl($url)
{
	$probe = preg_replace('/[\x00-\x20]+/', '', (string)$url);

	if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $probe, $matches))
	{
		return in_array(strtolower($matches[1]), ['http', 'https', 'mailto'], true);
	}

	// No scheme at all - a relative URL, which cannot navigate anywhere but this origin
	return true;
}

/**
 * The given URL when it is safe to link to, and "#" when it is not - so an unsafe value is
 * still visible as the link's text without being navigable. See IsSafeExternalUrl().
 *
 * @param string|null $url
 * @return string
 */
function SafeExternalUrl($url)
{
	return IsSafeExternalUrl($url) ? (string)$url : '#';
}

/**
 * Whether the given request path addresses the JSON API rather than a rendered page.
 *
 * The comparison is made after VICTUAL_BASE_PATH is removed, because Slim's
 * setBasePath() only affects routing - $request->getUri()->getPath() still carries the
 * prefix the installation is mounted under. A bare string_starts_with($path, '/api/')
 * therefore answers false for every API request on an installation in a subdirectory,
 * which is the difference between a JSON error body and an HTML error page.
 *
 * @param string $path The request path, as returned by $request->getUri()->getPath()
 * @return bool
 */
function IsApiRoutePath($path)
{
	$basePath = rtrim(VICTUAL_BASE_PATH, '/');

	if ($basePath !== '' && string_starts_with($path, $basePath))
	{
		$path = substr($path, strlen($basePath));
	}

	return string_starts_with($path, '/api/');
}

/**
 * Whether the stored value of the given api_keys row is the key itself rather than a hash
 * of it.
 *
 * Only a special-purpose key is: the application has to be able to hand its URL back to
 * whoever asks for the calendar sharing link, and it cannot do that from a hash. A regular
 * key is stored as SHA-256 - see Victual\Services\ApiKeyService::StoredValueOf().
 *
 * @param object $apiKey A row from api_keys
 * @return bool
 */
function ApiKeyIsReadable($apiKey)
{
	// The user-issued types (regular and MCP) are stored as a SHA-256 hash; "readable" was
	// `!== default` until the MCP type arrived, which would have shown an MCP key's hash as
	// though it were the key, and offered it as a QR code (issue #208).
	return !in_array($apiKey->key_type, \Victual\Services\ApiKeyService::USER_ISSUED_KEY_TYPES, true);
}

/**
 * What the manage-keys screen shows in the "key" column.
 *
 * A regular key is a hash on disk and unreadable by design, so what is shown is the hint -
 * its last four characters - which is enough to tell two of them apart and not enough to
 * use. Keys created before the hashing migration have no hint, so those show nothing at
 * all rather than a misleading blank-looking value.
 *
 * @param object $apiKey A row from api_keys
 * @return string
 */
function ApiKeyDisplayValue($apiKey)
{
	if (ApiKeyIsReadable($apiKey))
	{
		return (string)$apiKey->api_key;
	}

	return empty($apiKey->key_hint) ? '••••' : '••••' . $apiKey->key_hint;
}

function string_starts_with($haystack, $needle)
{
	return (substr($haystack, 0, strlen($needle)) === $needle);
}

/**
 * Returns true when $haystack ends with $needle (an empty $needle always matches).
 *
 * @return bool
 */
function string_ends_with($haystack, $needle)
{
	$length = strlen($needle);

	if ($length == 0)
	{
		return true;
	}

	return (substr($haystack, -$length) === $needle);
}

global $VICTUAL_REQUIRED_FRONTEND_PACKAGES;
$VICTUAL_REQUIRED_FRONTEND_PACKAGES = [];
/**
 * Marks the given frontend packages (npm package names) as required for the
 * current page, so that their CSS/JS assets get included (collected in the
 * global $VICTUAL_REQUIRED_FRONTEND_PACKAGES array, deduplicated).
 */
function require_frontend_packages(array $packages)
{
	global $VICTUAL_REQUIRED_FRONTEND_PACKAGES;

	$VICTUAL_REQUIRED_FRONTEND_PACKAGES = array_unique(array_merge($VICTUAL_REQUIRED_FRONTEND_PACKAGES, $packages));
}

/**
 * Recursively deletes all files and subfolders inside $folderPath
 * (the folder itself is kept).
 */
function EmptyFolder($folderPath)
{
	foreach (glob("{$folderPath}/*") as $item)
	{
		if (is_dir($item))
		{
			EmptyFolder($item);
			rmdir($item);
		}
		else
		{
			unlink($item);
		}
	}
}
