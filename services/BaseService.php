<?php

namespace Victual\Services;

/**
 * Base class for all services: provides the shared LessQL database connection
 * and a per-subclass singleton via GetInstance().
 */
class BaseService
{
	public function __construct()
	{
		$this->DB = DatabaseService::GetInstance()->GetDbConnection();
	}

	private static $Instances = [];

	/** @var \LessQL\Database The shared LessQL database connection */
	protected $DB;

	/**
	 * Returns the singleton instance of the called subclass (one instance per class).
	 *
	 * @return static
	 */
	public static function GetInstance()
	{
		$className = get_called_class();
		if (!isset(self::$Instances[$className]))
		{
			self::$Instances[$className] = new $className();
		}

		return self::$Instances[$className];
	}

	/**
	 * Test-only: clears the process-global singleton instance cache.
	 *
	 * PHPUnit test classes that run in the same process and each set up their own schema
	 * need to reset cached instances before setup so that a second class does not reach
	 * the first class's dropped schema through a reused service singleton.
	 * Called from PgsqlSchemaTestCase::setUpBeforeClass() only.
	 *
	 * @internal Test support only
	 */
	public static function ResetInstancesForTest()
	{
		self::$Instances = [];
	}
}
