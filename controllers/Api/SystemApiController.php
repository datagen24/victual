<?php

namespace Victual\Controllers\Api;

use Victual\Services\ApplicationService;
use Victual\Services\DatabaseService;
use Victual\Services\LocalizationService;
use Victual\Services\Storage\FileSizeLimit;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves the /api/system endpoints: instance info, server time, database change
 * detection, exposed configuration and localization strings.
 */
class SystemApiController extends BaseApiController
{
	/**
	 * The config settings which are exposed through GET /api/system/config.
	 *
	 * This is deliberately an allowlist: the endpoint used to filter the defined
	 * constants by a blocklist of a few internal names, which meant every newly
	 * added setting was leaked automatically - the DB_HOST/DB_USER/DB_PASSWORD and
	 * LDAP_BIND_PW credentials of this fork among them. Anything a client does not
	 * explicitly need stays out by default; add a name here only after checking
	 * that it is safe to hand to any API key holder.
	 *
	 * The names are stored without the VICTUAL_ prefix, matching the output shape.
	 * Additionally all VICTUAL_FEATURE_FLAG_* constants are exposed (see GetConfig),
	 * those are non-sensitive by construction and the web UI already receives all
	 * of them anyway.
	 */
	private const EXPOSED_SETTINGS = [
		'MODE',
		'CURRENCY',
		'ENERGY_UNIT',
		'DEFAULT_LOCALE',
		'CALENDAR_FIRST_DAY_OF_WEEK',
		'CALENDAR_SHOW_WEEK_OF_YEAR',
		'MEAL_PLAN_FIRST_DAY_OF_WEEK',
		'ENTRY_PAGE',
		'BASE_PATH',
		'BASE_URL',
		'DISABLE_URL_REWRITING',
		'GROCYCODE_TYPE'
	];

	/**
	 * GET /api/system/config - returns the config settings which are safe to expose
	 * to clients (self::EXPOSED_SETTINGS plus all VICTUAL_FEATURE_FLAG_* constants, plus
	 * the effective FILE_STORAGE_MAX_SIZE_MB) as a key/value map with the VICTUAL_ prefix
	 * stripped. Settings which are not defined are omitted (no null values). 400 error
	 * response on failure.
	 */
	public function GetConfig(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($response)
		{
			$returnArray = [];

			foreach (self::EXPOSED_SETTINGS as $setting)
			{
				if (defined('VICTUAL_' . $setting))
				{
					$returnArray[$setting] = constant('VICTUAL_' . $setting);
				}
			}

			// The upload limit is reported as the value actually in force rather than as the
			// configured one: FILE_STORAGE_MAX_SIZE_MB is clamped to PHP's own
			// upload_max_filesize and post_max_size at startup, and a client told 64 by an
			// installation that will refuse anything over 2 has been told a number that is
			// not true. It is therefore computed here rather than read out of the constant,
			// which is why it is not simply another name in EXPOSED_SETTINGS. Sweep S10 /
			// plan 01 Q2.
			$returnArray['FILE_STORAGE_MAX_SIZE_MB'] = FileSizeLimit::EffectiveMaxMegabytes();

			// Feature flags are not sensitive by definition, so all of them are exposed
			$constants = get_defined_constants();
			foreach ($constants as $constant => $value)
			{
				if (str_starts_with($constant, 'VICTUAL_FEATURE_FLAG_'))
				{
					// Without the "VICTUAL_" prefix, the shape the exposed settings above use
					$returnArray[substr($constant, 8)] = $value;
				}
			}

			return $this->ApiResponse($response, $returnArray);
		});
	}

	/**
	 * GET /api/system/db-changed-time - returns { "changed_time": string }, the time
	 * the database was last modified (used by clients for change polling).
	 */
	public function GetDbChangedTime(Request $request, Response $response, array $args)
	{
		return $this->ApiResponse($response, [
			'changed_time' => DatabaseService::GetInstance()->GetDbChangedTime()
		]);
	}

	/**
	 * GET /api/system/info - returns information about the installed Victual version and environment.
	 */
	public function GetSystemInfo(Request $request, Response $response, array $args)
	{
		return $this->ApiResponse($response, ApplicationService::GetInstance()->GetSystemInfo($request));
	}

	/**
	 * GET /api/system/time - returns the current server time; the optional integer
	 * query parameter "offset" (seconds) is applied to it. Returns a 400 error
	 * response when offset is not a valid integer.
	 */
	public function GetSystemTime(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($request, $response)
		{
			$offset = 0;
			$params = $request->getQueryParams();
			if (isset($params['offset']))
			{
				if (filter_var($params['offset'], FILTER_VALIDATE_INT) === false)
				{
					throw new \Exception('Query parameter "offset" is not a valid integer');
				}

				$offset = $params['offset'];
			}

			return $this->ApiResponse($response, ApplicationService::GetInstance()->GetSystemTime($offset));
		});
	}

	/**
	 * POST /api/system/log-missing-localization - adds the body field "text" to the
	 * localization POT file when it is not translated yet. That only happens when
	 * VICTUAL_MODE is "dev", in any other mode the request is accepted and ignored.
	 * Returns 204 on success, 400 on error.
	 */
	public function LogMissingLocalization(Request $request, Response $response, array $args)
	{
		if (VICTUAL_MODE === 'dev')
		{
			try
			{
				$requestBody = $this->GetParsedAndFilteredRequestBody($request);

				LocalizationService::GetInstance()->CheckAndAddMissingTranslationToPot($requestBody['text']);
			}
			catch (\Exception $ex)
			{
				return $this->GenericErrorResponse($response, $ex->getMessage());
			}
		}

		return $this->EmptyApiResponse($response);
	}

	/**
	 * GET /api/system/localization-strings - returns the localization strings of the
	 * current language as JSON, with a 30 day Cache-Control header.
	 */
	public function GetLocalizationStrings(Request $request, Response $response, array $args)
	{
		return $this->ApiResponse($response, json_decode(LocalizationService::GetInstance()->GetPoAsJsonString()), true);
	}
}
