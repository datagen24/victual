<?php

namespace Victual\Helpers;

/**
 * The installation country (issue #486): the ISO 3166-1 alpha-2 code of the country the
 * household shops in, or nothing when the administrator has not said.
 *
 * This class is the read path. Code that needs the country calls Get() and never reads
 * VICTUAL_COUNTRY itself, so the place the value comes from can change without touching
 * the callers. Today the only source is the COUNTRY setting (config.php,
 * settingoverrides/COUNTRY.txt or the VICTUAL_COUNTRY environment variable, through
 * Setting() in helpers/extensions.php). A value stored by the setup flow (#484) does not
 * exist yet; issue #486 proposes that a configured COUNTRY wins over it when both are set.
 *
 * Unset means "behave as before the setting existed": Get() returns null, and a consumer
 * keeps its country-independent default.
 */
class InstallationCountry
{
	/**
	 * The 249 officially assigned ISO 3166-1 alpha-2 codes. Reserved codes such as "UK"
	 * (the United Kingdom is "GB") and "EU" are not countries a household shops in and
	 * are refused, so a typo fails at startup rather than silently matching no consumer.
	 */
	public const CODES = [
		'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ',
		'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS', 'BT', 'BV', 'BW', 'BY', 'BZ',
		'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN', 'CO', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ',
		'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ',
		'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET',
		'FI', 'FJ', 'FK', 'FM', 'FO', 'FR',
		'GA', 'GB', 'GD', 'GE', 'GF', 'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY',
		'HK', 'HM', 'HN', 'HR', 'HT', 'HU',
		'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT',
		'JE', 'JM', 'JO', 'JP',
		'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ',
		'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY',
		'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ',
		'NA', 'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ',
		'OM',
		'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY',
		'QA',
		'RE', 'RO', 'RS', 'RU', 'RW',
		'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS', 'ST', 'SV', 'SX', 'SY', 'SZ',
		'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ',
		'UA', 'UG', 'UM', 'US', 'UY', 'UZ',
		'VA', 'VC', 'VE', 'VG', 'VI', 'VN', 'VU',
		'WF', 'WS',
		'YE', 'YT',
		'ZA', 'ZM', 'ZW',
	];

	/**
	 * Returns the installation country as an upper-case alpha-2 code, or null when none
	 * is configured.
	 */
	public static function Get(): ?string
	{
		return self::Normalize((string)VICTUAL_COUNTRY);
	}

	/**
	 * Maps a configured value to the code Get() reports: surrounding whitespace is
	 * dropped, letters are upper-cased, and an empty value is null. A value that is not
	 * an assigned code is also null; ConfigurationValidator refuses such a value at
	 * startup, so a running installation never reaches that case.
	 *
	 * @param string $configured The raw COUNTRY setting
	 */
	public static function Normalize(string $configured): ?string
	{
		$code = strtoupper(trim($configured));

		return self::IsAssigned($code) ? $code : null;
	}

	/**
	 * Whether $code, compared case-insensitively, is an officially assigned ISO 3166-1
	 * alpha-2 code.
	 *
	 * @param string $code A candidate code
	 */
	public static function IsAssigned(string $code): bool
	{
		return in_array(strtoupper($code), self::CODES, true);
	}
}
