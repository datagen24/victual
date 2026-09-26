<?php

namespace Victual\Services;

use Gettext\Translation;
use Gettext\Translations;
use Gettext\Translator;

/**
 * Gettext-based translation of UI strings, plus a second translator dedicated to
 * quantity unit names (whose singular/plural forms live in the database, not in .po files).
 * One instance per locale, obtained via GetInstance().
 */
class LocalizationService extends BaseService
{
	/**
	 * @param string $locale Locale directory name under localization/, e.g. "en", "de", "pt_BR"
	 */
	public function __construct(string $locale)
	{
		parent::__construct();

		$this->Locale = $locale;
		$this->LoadLocalizations($locale);
	}

	protected $Po;
	protected $PoQu;
	protected $Pot;
	protected $PotMain;
	protected $Translator;
	protected $TranslatorQu;
	protected $Locale;
	private static $InstanceMap = [];

	/**
	 * Dev mode only: appends $text to localization/strings.pot when it is not yet
	 * known in any .pot file, so new source strings get collected while developing.
	 * No-op in any other VICTUAL_MODE.
	 */
	public function CheckAndAddMissingTranslationToPot($text)
	{
		if (VICTUAL_MODE === 'dev')
		{
			if ($this->Pot->find('', $text) === false && empty($text) === false)
			{
				$translation = new Translation('', $text);
				$this->PotMain[] = $translation;
				$this->PotMain->toPoFile(__DIR__ . '/../localization/strings.pot');
			}
		}
	}

	/**
	 * Number of plural forms the locale defines (from the .po "Plural-Forms" header),
	 * defaulting to 2 when the header is absent.
	 */
	public function GetPluralCount()
	{
		if ($this->Po->getHeader(Translations::HEADER_PLURAL) !== null)
		{
			return intval($this->Po->getPluralForms()[0]);
		}
		else
		{
			return 2;
		}
	}

	/**
	 * The locale's plural selection formula as a C-like expression in n
	 * (from the .po "Plural-Forms" header), defaulting to "(n != 1)".
	 */
	public function GetPluralDefinition()
	{
		if ($this->Po->getHeader(Translations::HEADER_PLURAL) !== null)
		{
			return $this->Po->getPluralForms()[1];
		}
		else
		{
			return '(n != 1)';
		}
	}

	/**
	 * All loaded UI translations as a JSON string, for the client side translator.
	 */
	public function GetPoAsJsonString()
	{
		return $this->Po->toJsonString();
	}

	/**
	 * The quantity unit name translations as a JSON string, for the client side translator.
	 */
	public function GetPoAsJsonStringQu()
	{
		return $this->PoQu->toJsonString();
	}

	/**
	 * Translates a singular/plural pair, choosing the form for $number and
	 * sprintf-ing $number into the result (forms are expected to contain %s).
	 *
	 * @param int|float $number Quantity deciding the plural form (absolute value is used)
	 * @param bool $isQu True to translate against quantity unit names instead of UI strings
	 * @return string
	 */
	public function __n($number, $singularForm, $pluralForm, $isQu = false)
	{
		$this->CheckAndAddMissingTranslationToPot($singularForm);

		if (empty($pluralForm))
		{
			$pluralForm = $singularForm;
		}

		if ($isQu)
		{
			return sprintf($this->TranslatorQu->ngettext($singularForm, $pluralForm, abs(floatval($number))), $number);
		}
		else
		{
			return sprintf($this->Translator->ngettext($singularForm, $pluralForm, abs(floatval($number))), $number);
		}
	}

	/**
	 * Translates $text, optionally filling sprintf placeholders.
	 *
	 * @param string $text The source (msgid) string, which may contain sprintf placeholders
	 * @param mixed ...$placeholderValues Values for the placeholders; a single array argument
	 * is spread over all placeholders (vsprintf), otherwise only the first value is used
	 * @return string
	 */
	public function __t($text, ...$placeholderValues)
	{
		$this->CheckAndAddMissingTranslationToPot($text);

		if (func_num_args() === 1)
		{
			return $this->Translator->gettext($text);
		}
		else
		{
			if (is_array(...$placeholderValues))
			{
				return vsprintf($this->Translator->gettext($text), ...$placeholderValues);
			}
			else
			{
				return sprintf($this->Translator->gettext($text), array_shift($placeholderValues));
			}
		}
	}

	/**
	 * Per-locale singleton; an empty $locale means the configured VICTUAL_LOCALE.
	 */
	public static function GetInstance(string $locale = '')
	{
		if (empty($locale))
		{
			$locale = VICTUAL_LOCALE;
		}

		if (!isset(self::$InstanceMap[$locale]))
		{
			self::$InstanceMap[$locale] = new self($locale);
		}

		return self::$InstanceMap[$locale];
	}

	/**
	 * Loads and merges all .po files of the locale into one translation set, then builds
	 * the separate quantity unit translation set from the active quantity_units rows
	 * (skipped silently while the database is not yet initialised/migrated).
	 */
	private function LoadLocalizations()
	{
		$locale = $this->Locale;

		if (VICTUAL_MODE === 'dev')
		{
			$this->PotMain = Translations::fromPoFile(__DIR__ . '/../localization/strings.pot');

			$this->Pot = Translations::fromPoFile(__DIR__ . '/../localization/chore_period_types.pot');
			$this->Pot = $this->Pot->mergeWith(Translations::fromPoFile(__DIR__ . '/../localization/chore_assignment_types.pot'));
			$this->Pot = $this->Pot->mergeWith(Translations::fromPoFile(__DIR__ . '/../localization/component_translations.pot'));
			$this->Pot = $this->Pot->mergeWith(Translations::fromPoFile(__DIR__ . '/../localization/stock_transaction_types.pot'));
			$this->Pot = $this->Pot->mergeWith(Translations::fromPoFile(__DIR__ . '/../localization/strings.pot'));
			$this->Pot = $this->Pot->mergeWith(Translations::fromPoFile(__DIR__ . '/../localization/userfield_types.pot'));
			$this->Pot = $this->Pot->mergeWith(Translations::fromPoFile(__DIR__ . '/../localization/permissions.pot'));
			$this->Pot = $this->Pot->mergeWith(Translations::fromPoFile(__DIR__ . '/../localization/locales.pot'));
			$this->Pot = $this->Pot->mergeWith(Translations::fromPoFile(__DIR__ . '/../localization/demo_data.pot'));
		}

		$this->Po = Translations::fromPoFile(__DIR__ . "/../localization/$locale/strings.po");

		if (file_exists(__DIR__ . "/../localization/$locale/chore_assignment_types.po"))
		{
			$this->Po = $this->Po->mergeWith(Translations::fromPoFile(__DIR__ . "/../localization/$locale/chore_assignment_types.po"));
		}

		if (file_exists(__DIR__ . "/../localization/$locale/component_translations.po"))
		{
			$this->Po = $this->Po->mergeWith(Translations::fromPoFile(__DIR__ . "/../localization/$locale/component_translations.po"));
		}

		if (file_exists(__DIR__ . "/../localization/$locale/stock_transaction_types.po"))
		{
			$this->Po = $this->Po->mergeWith(Translations::fromPoFile(__DIR__ . "/../localization/$locale/stock_transaction_types.po"));
		}

		if (file_exists(__DIR__ . "/../localization/$locale/chore_period_types.po"))
		{
			$this->Po = $this->Po->mergeWith(Translations::fromPoFile(__DIR__ . "/../localization/$locale/chore_period_types.po"));
		}

		if (file_exists(__DIR__ . "/../localization/$locale/userfield_types.po"))
		{
			$this->Po = $this->Po->mergeWith(Translations::fromPoFile(__DIR__ . "/../localization/$locale/userfield_types.po"));
		}

		if (file_exists(__DIR__ . "/../localization/$locale/permissions.po"))
		{
			$this->Po = $this->Po->mergeWith(Translations::fromPoFile(__DIR__ . "/../localization/$locale/permissions.po"));
		}

		if (file_exists(__DIR__ . "/../localization/$locale/locales.po"))
		{
			$this->Po = $this->Po->mergeWith(Translations::fromPoFile(__DIR__ . "/../localization/$locale/locales.po"));
		}

		if (VICTUAL_MODE !== 'production' && file_exists(__DIR__ . "/../localization/$locale/demo_data.po"))
		{
			$this->Po = $this->Po->mergeWith(Translations::fromPoFile(__DIR__ . "/../localization/$locale/demo_data.po"));
		}

		$this->Translator = new Translator();
		$this->Translator->loadTranslations($this->Po);

		$this->PoQu = new Translations();

		$quantityUnits = null;
		try
		{
			$quantityUnits = $this->DB->quantity_units()->where('active = 1')->fetchAll();
		}
		catch (\Exception $ex)
		{
			// Happens when database is not initialised or migrated...
		}

		if ($quantityUnits !== null)
		{
			$this->PoQu->setHeader(Translations::HEADER_PLURAL, $this->Po->getHeader(Translations::HEADER_PLURAL));

			foreach ($quantityUnits as $quantityUnit)
			{
				$translation = new Translation('', $quantityUnit['name']);
				$translation->setTranslation($quantityUnit['name']);
				$translation->setPlural($quantityUnit['name_plural']);
				$translation->setPluralTranslations(preg_split('/\r\n|\r|\n/', $quantityUnit['plural_forms'] ?? ''));

				$this->PoQu[] = $translation;
			}

			$this->TranslatorQu = new Translator();
			$this->TranslatorQu->loadTranslations($this->PoQu);
		};
	}

	/**
	 * Test-only: clears the per-locale singleton instance cache.
	 *
	 * See BaseService::ResetInstancesForTest().
	 *
	 * @internal Test support only
	 */
	public static function ResetInstancesForTest()
	{
		self::$InstanceMap = [];
	}
}
