<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\FilesApiController;
use Victual\Services\FilesService;
use Victual\Services\Storage\DatabaseStorage;
use Victual\Services\Storage\FileSizeLimit;
use Victual\Services\Storage\FileStorage;
use Victual\Services\Storage\FilesystemStorage;
use Victual\Services\Storage\FileTooLargeException;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Files and the two storage backends (docs/plans/landed/01-file-storage.md).
 *
 * FilesystemStorage and DatabaseStorage are two implementations of one contract -
 * FileStorage's seven abstract methods - so every scenario that is about the contract is
 * written once and run against both through a #[DataProvider], and the durable state is
 * then inspected in the medium each one actually writes to (a directory, or the files
 * table) rather than through the backend's own Exists(). A backend that answered its own
 * questions consistently while storing nothing would pass a test that only asked it.
 *
 * What this deliberately does not repeat: tests/Pgsql/FileSizeLimitTest.php (the clamp's
 * own arithmetic and where it is announced) and the files phase
 * (.devtools/pgsql/files-import-tests.php, bin/victual-files-import). What is covered
 * here instead is what the storage layer and the API do with a file over the limit.
 * tests/Pgsql/RbacTest.php:270-324 covers the own-picture exception's refusals - a caller
 * who claims somebody else's picture name - so what is covered here is the case it stops
 * short of: the exception actually serving the caller's own bytes.
 */
class StorageFilesTest extends PgsqlSchemaTestCase
{
	/** The caller every test acts as; PgsqlSchemaTestCase fixes VICTUAL_USER_ID at this. */
	private const CALLER_ID = 9000;

	private static PDO $db;
	private static \DI\Container $container;
	private static FilesApiController $files;

	/** Real image bytes, so that content sniffing and downscaling have something to work on. */
	private static string $png;

	/** A minimal but genuine PDF, the one non-image type that is still served inline. */
	private static string $pdf;

	/** Where FilesystemStorage puts its group folders. */
	private static string $storageRoot;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$files = new FilesApiController(self::$container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::CALLER_ID . ", 'storagefiles-caller', 'fixture')");

		self::$png = self::MakePng(120, 80);
		self::$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
		self::$storageRoot = VICTUAL_DATAPATH . '/storage';
	}

	/**
	 * The two backends, by the name the FILE_STORAGE setting uses for them.
	 */
	public static function backends(): array
	{
		return [
			'filesystem backend' => ['filesystem'],
			'database backend' => ['database']
		];
	}

	// -------------------------------------------------------------------------------
	// The contract both backends implement
	// -------------------------------------------------------------------------------

	/**
	 * Store, read back, list, delete - and the bytes are gone from the medium afterwards,
	 * which is the half a backend cannot be trusted to answer about itself.
	 */
	#[DataProvider('backends')]
	public function testStoreReadListAndDeleteRoundTrip(string $backend): void
	{
		$group = 'storagefiles-roundtrip';
		$storage = self::Storage($backend, [$group]);

		self::assertFalse($storage->Exists($group, 'trip.png'), 'Nothing is stored under this name yet');

		$storage->Create($group, 'trip.png', self::$png);
		// A second name sharing no prefix with the first: a listing that returned
		// everything would otherwise look like a correct prefix scan.
		$storage->Create($group, 'decoy.png', self::MakePng(10, 10));

		self::assertTrue($storage->Exists($group, 'trip.png'));
		self::assertSame(self::$png, self::ReadAll($storage, $group, 'trip.png'), 'What was stored reads back byte identical');
		self::assertSame(self::$png, self::DurableBytes($backend, $group, 'trip.png'), 'The medium itself holds those bytes');
		self::assertSame('image/png', $storage->GetMimeType($group, 'trip.png'), 'The type is sniffed from the content');

		$listed = $storage->ListNames($group, 'trip');
		self::assertSame(['trip.png'], $listed, 'The prefix scan lists the matching name and nothing else');

		$storage->Delete($group, 'trip.png');

		self::assertFalse($storage->Exists($group, 'trip.png'), 'Deleted');
		self::assertNull($storage->Read($group, 'trip.png'), 'Reading a deleted name is an absence, not an error');
		self::assertNotContains('trip.png', self::DurableNames($backend, $group), 'The bytes are gone from the medium, not merely hidden');
		self::assertContains('decoy.png', self::DurableNames($backend, $group), 'Deleting one file leaves every other name alone');
	}

	/**
	 * Create is exclusive (it mirrors fopen($path, 'xb')), so a second caller cannot
	 * replace a file by guessing its name - and the refusal leaves the first caller's
	 * bytes exactly as they were.
	 */
	#[DataProvider('backends')]
	public function testCreateRefusesATakenNameAndLeavesTheStoredBytesAlone(string $backend): void
	{
		$group = 'storagefiles-exclusive';
		$storage = self::Storage($backend, [$group]);

		$storage->Create($group, 'taken.png', self::$png);

		$refused = null;
		try
		{
			// The refusal itself is the application's: fopen($path, 'xb') emits a warning
			// before returning false, and Create() turns that false into the exception
			// under test. Suppressed here rather than fixed, because application code is
			// out of scope for this work - see the hand-back note.
			@$storage->Create($group, 'taken.png', 'replacement bytes');
		}
		catch (\Exception $ex)
		{
			$refused = $ex;
		}

		self::assertNotNull($refused, 'A name that is already taken is refused');
		self::assertSame(self::$png, self::DurableBytes($backend, $group, 'taken.png'), 'The refused create did not overwrite anything');
		self::assertSame(['taken.png'], self::DurableNames($backend, $group), 'And it did not leave a second entry behind');
	}

	/**
	 * Write is the overwriting counterpart, and overwriting is a replacement rather than
	 * an addition - the file must not end up stored twice.
	 */
	#[DataProvider('backends')]
	public function testWriteReplacesTheStoredBytesWithoutDuplicatingTheEntry(string $backend): void
	{
		$group = 'storagefiles-write';
		$storage = self::Storage($backend, [$group]);
		$replacement = self::MakePng(40, 30);

		$storage->Write($group, 'replaceable.png', self::$png);
		$storage->Write($group, 'replaceable.png', $replacement);

		self::assertSame($replacement, self::DurableBytes($backend, $group, 'replaceable.png'), 'The second write won');
		self::assertSame(['replaceable.png'], self::DurableNames($backend, $group), 'One name, one entry');
	}

	/**
	 * Missing data has to be answered, not stumbled over: every one of these is an
	 * ordinary return value rather than a PHP warning (phpunit.xml sets
	 * failOnWarning="true", so a warning here fails the run) or an exception.
	 */
	#[DataProvider('backends')]
	public function testMissingDataIsAnsweredRatherThanWarnedAbout(string $backend): void
	{
		$group = 'storagefiles-missing';
		$storage = self::Storage($backend, [$group]);

		self::assertFalse($storage->Exists($group, 'never-stored.png'), 'A name that was never stored does not exist');
		self::assertNull($storage->Read($group, 'never-stored.png'), 'Reading it is null');
		self::assertNull($storage->GetMimeType($group, 'never-stored.png'), 'It has no content type');
		self::assertSame([], $storage->ListNames($group, 'never-stored'), 'An empty group lists nothing under a prefix');

		// Deleting something that is not there is documented as not an error.
		$storage->Delete($group, 'never-stored.png');
		self::assertSame([], self::DurableNames($backend, $group), 'And it created nothing on the way');
	}

	/**
	 * ListNames is documented as "the names of the files in this group whose name starts
	 * with $prefix", and the empty prefix is the case that shows the two backends do not
	 * agree on it.
	 *
	 * DEFECT (services/Storage/FilesystemStorage.php:132-145): ListNames hands back
	 * scandir()'s "." and "..", so the filesystem backend reports two names that are not
	 * files and were never stored. DatabaseStorage returns the empty list the contract
	 * describes. Nothing is exploitable today because the one caller
	 * (FilesService::DeleteFile) always passes a non-empty prefix, and the entries would
	 * then be filtered out by string_starts_with - which is why the current behaviour is
	 * pinned here with an assertion rather than skipped: a future caller passing "" would
	 * ask the filesystem backend to delete "." and "..". The correct behaviour is for both
	 * backends to return [] for a group holding no files.
	 */
	#[DataProvider('backends')]
	public function testListingAnEmptyGroupWithTheEmptyPrefix(string $backend): void
	{
		$group = 'storagefiles-emptylist';
		$storage = self::Storage($backend, [$group]);

		// Make the group exist without putting a file in it, so "empty" is not "absent".
		$storage->Create($group, 'present.txt', 'present');
		$storage->Delete($group, 'present.txt');

		$listed = $storage->ListNames($group, '');

		if ($backend === 'filesystem')
		{
			self::assertSame(['.', '..'], $listed, 'Current behaviour, and a defect: the directory entries are reported as file names');
		}
		else
		{
			self::assertSame([], $listed, 'A group holding no files lists no names');
		}
	}

	/**
	 * A stored name must not reach outside the group it was stored in. The API refuses
	 * every one of these before a backend sees it (IsValidFileName), which is asserted
	 * separately; this is the backends' own answer.
	 *
	 * DEFECT (services/Storage/FilesystemStorage.php:165-168): GetFilePath concatenates
	 * the name onto the group folder with no normalisation, so a name containing ".."
	 * traverses out of the storage root and writes wherever the traversal lands - here,
	 * straight into the data path. DatabaseStorage cannot do this: a name is a column
	 * value. The current behaviour is pinned rather than skipped because the escape is
	 * reachable from at least one caller that is not the files API
	 * (services/StockService.php:1036-1037 builds a product picture name from the
	 * caller-supplied barcode), and a test that merely skipped would stop reporting it.
	 * The correct behaviour is for a name that resolves outside the group folder to be
	 * refused by the backend.
	 */
	#[DataProvider('backends')]
	public function testAStoredNameIsNotConfinedToItsGroupFolder(string $backend): void
	{
		$group = 'storagefiles-escape';
		$storage = self::Storage($backend, [$group]);
		$escaped = VICTUAL_DATAPATH . '/storagefiles-escape-marker.txt';

		try
		{
			$storage->Create($group, '../../storagefiles-escape-marker.txt', 'escaped');
		}
		catch (\Exception $ex)
		{
			// Either backend refusing the name outright would be the correct answer, and
			// is not what happens; the assertions below say what each one does instead.
		}

		if ($backend === 'filesystem')
		{
			self::assertFileExists($escaped, 'Current behaviour, and a defect: the name traversed out of the storage root');
			self::assertSame('escaped', file_get_contents($escaped), 'The bytes really did land outside the root');
			unlink($escaped);
		}
		else
		{
			self::assertFileDoesNotExist($escaped, 'A database backend has no path to traverse');
			self::assertSame('escaped', self::DurableBytes($backend, $group, '../../storagefiles-escape-marker.txt'), 'The traversal is stored as the literal name it is');
		}

		// A leading slash is not an absolute path here: the name is appended to the group
		// folder, so it stays inside it. This is the one of the four hostile shapes that
		// the concatenation happens to get right, and it is asserted rather than assumed
		// because the file landing at the root of the filesystem is what it would mean if
		// it were wrong.
		$storage->Create($group, '/storagefiles-absolute.txt', 'not absolute');
		self::assertFileDoesNotExist('/storagefiles-absolute.txt', 'A leading slash did not make the name an absolute path');

		if ($backend === 'filesystem')
		{
			self::assertSame('not absolute', file_get_contents(self::$storageRoot . '/' . $group . '//storagefiles-absolute.txt'), 'It stayed inside the group folder');
		}
		else
		{
			self::assertSame('not absolute', self::DurableBytes($backend, $group, '/storagefiles-absolute.txt'), 'It is stored as the literal name it is');
		}

		// A name naming a subdirectory that does not exist is refused by both, and stores
		// nothing under either spelling.
		$refused = null;
		try
		{
			// Suppressed for the same reason as in the exclusive-create case above: the
			// failing fopen() warns on its way to the false the backend acts on.
			@$storage->Create($group, 'sub/nested.txt', 'nested');
		}
		catch (\Throwable $ex)
		{
			$refused = $ex;
		}

		if ($backend === 'filesystem')
		{
			self::assertNotNull($refused, 'A directory separator in a name is refused rather than silently creating a tree');
			self::assertFileDoesNotExist(self::$storageRoot . '/' . $group . '/sub/nested.txt');
		}
	}

	/**
	 * The upload cap (FILE_STORAGE_MAX_SIZE_MB, reconciled with php.ini by FileSizeLimit)
	 * bounds what can be written, so a source over it is refused while it streams and
	 * nothing partial is left behind. Both source shapes are checked because the two
	 * writers genuinely have one each: an upload is a stream, a downscaled image a string.
	 */
	#[DataProvider('backends')]
	public function testASourceOverTheEffectiveLimitIsRefusedAndNothingPartialSurvives(string $backend): void
	{
		$group = 'storagefiles-toolarge';
		$storage = self::Storage($backend, [$group]);
		$maximum = FileSizeLimit::EffectiveMaxBytes();

		$refused = null;
		try
		{
			$storage->Create($group, 'too-large-string.txt', str_repeat('a', $maximum + 1));
		}
		catch (FileTooLargeException $ex)
		{
			$refused = $ex;
		}

		self::assertNotNull($refused, 'One byte over the limit is refused');
		self::assertStringContainsString('FILE_STORAGE_MAX_SIZE_MB', $refused->getMessage(), 'The refusal names the limit that was hit');
		self::assertSame([], self::DurableNames($backend, $group), 'Nothing partial survives the refused string write');

		$refused = null;
		try
		{
			$storage->Create($group, 'too-large-stream.txt', self::StreamOf(str_repeat('b', $maximum + FileStorage::COPY_CHUNK_SIZE)));
		}
		catch (FileTooLargeException $ex)
		{
			$refused = $ex;
		}

		self::assertNotNull($refused, 'A stream over the limit is refused too');
		self::assertSame([], self::DurableNames($backend, $group), 'And the chunks already written are discarded, not left as a truncated file');

		// The boundary itself: exactly the limit is accepted and stored whole.
		$storage->Create($group, 'exactly-at-the-limit.txt', self::StreamOf(str_repeat('c', $maximum)));
		self::assertSame($maximum, strlen((string)self::DurableBytes($backend, $group, 'exactly-at-the-limit.txt')), 'The largest allowed source is stored in full');

		// An empty source is the other boundary, and is a file rather than an absence.
		$storage->Create($group, 'empty.txt', '');
		self::assertTrue($storage->Exists($group, 'empty.txt'), 'A zero byte file exists');
		self::assertSame('', self::DurableBytes($backend, $group, 'empty.txt'), 'And holds nothing');
		self::assertSame('', self::ReadAll($storage, $group, 'empty.txt'), 'and reads back as a stream of no bytes rather than as null');

		// An overwrite that runs into the limit must not leave a truncated file behind
		// either. The two backends differ in what survives, and both are deliberate: the
		// filesystem one has already truncated the file by opening it "wb", so it removes
		// the name rather than leaving half a file that still answers Exists(); the
		// database one buffers before it writes, so the row it was replacing is untouched.
		$storage->Write($group, 'overwritten.txt', 'the bytes that were there first');

		$refused = null;
		try
		{
			$storage->Write($group, 'overwritten.txt', str_repeat('d', $maximum + 1));
		}
		catch (FileTooLargeException $ex)
		{
			$refused = $ex;
		}

		self::assertNotNull($refused, 'An oversized overwrite is refused');

		if ($backend === 'filesystem')
		{
			self::assertFalse($storage->Exists($group, 'overwritten.txt'), 'Nothing truncated is left under the name');
			self::assertNotContains('overwritten.txt', self::DurableNames($backend, $group), 'and the medium holds nothing under it');
		}
		else
		{
			self::assertSame('the bytes that were there first', self::DurableBytes($backend, $group, 'overwritten.txt'), 'The row being replaced is untouched');
		}
	}

	/**
	 * The database backend's two importer-facing questions, asked of what is actually in
	 * the column rather than of what was handed to Write().
	 */
	public function testTheDatabaseBackendReportsTheStoredSizeAndDigest(): void
	{
		$group = 'storagefiles-digest';
		$storage = self::Storage('database', [$group]);
		self::assertInstanceOf(DatabaseStorage::class, $storage);

		$storage->Write($group, 'digested.png', self::$png);

		self::assertSame(strlen(self::$png), $storage->GetSizeBytes($group, 'digested.png'));
		self::assertSame(hash('sha256', self::$png), $storage->GetContentDigest($group, 'digested.png'), 'The digest is of the bytes that were stored');
		self::assertNull($storage->GetSizeBytes($group, 'never-stored.png'), 'A row that is not there has no size');
		self::assertNull($storage->GetContentDigest($group, 'never-stored.png'), 'and no digest');
	}

	/**
	 * A cached downscaled copy is a derivative row, so that every thumbnail can be dropped
	 * with one statement (plan 01) - and an original is not.
	 */
	public function testTheDatabaseBackendMarksDerivativesAsSuch(): void
	{
		$group = 'storagefiles-derivative';
		$storage = self::Storage('database', [$group]);

		$storage->Write($group, 'original.png', self::$png);
		$storage->Write($group, 'original' . FilesService::DOWNSCALED_INFIX . '32xauto.png', self::MakePng(32, 21));

		$statement = self::$db->prepare('SELECT name, is_derivative FROM files WHERE file_group = ?');
		$statement->execute([$group]);

		$flags = [];
		foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row)
		{
			$flags[$row['name']] = (int)$row['is_derivative'];
		}
		ksort($flags);

		self::assertSame([
			'original.png' => 0,
			'original' . FilesService::DOWNSCALED_INFIX . '32xauto.png' => 1
		], $flags, 'A cached downscaled copy is a derivative row; the original is not');
	}

	// -------------------------------------------------------------------------------
	// FilesService, above the backends
	// -------------------------------------------------------------------------------

	/**
	 * Which backend the service works through is the FILE_STORAGE setting's answer, and it
	 * is one instance for the whole request.
	 */
	public function testTheConfiguredBackendIsSelectedOncePerRequest(): void
	{
		self::ResetBackendSelection();

		$storage = FileStorage::GetInstance();

		self::assertSame('filesystem', VICTUAL_FILE_STORAGE, 'This phase runs on the default setting');
		self::assertInstanceOf(FilesystemStorage::class, $storage, 'The setting selects the backend');
		self::assertSame($storage, FileStorage::GetInstance(), 'One backend instance per request, not one per call');
	}

	/**
	 * In demo and prerelease mode the filesystem backend puts its group folders under a per
	 * demo instance suffix, so that several demo instances can share one filesystem. That
	 * suffix is the reason FILE_STORAGE=database is refused in those modes (plan 01 Q4):
	 * UNIQUE(file_group, name) has no column for it.
	 *
	 * VICTUAL_MODE is fixed for a process, so each case is its own. The suffix defaults to
	 * the configured locale and is overridden by VICTUAL_DEMO_DB_SUFFIX, which a demo
	 * deployment sets in its own config.php.
	 */
	public function testDemoModeKeepsEachInstancesFilesUnderItsOwnSuffix(): void
	{
		self::Storage('filesystem', ['storagefiles-demo']);

		foreach (['' => VICTUAL_DEFAULT_LOCALE, 'demo-seven' => 'demo-seven'] as $configured => $expectedSuffix)
		{
			$name = 'demo-' . ($configured === '' ? 'default' : $configured) . '.txt';
			$expectedPath = self::$storageRoot . '/' . $expectedSuffix . '/storagefiles-demo/' . $name;

			// The suffixed folders are outside every group this class empties, and the
			// create below is exclusive, so a previous run's file has to go first.
			if (is_file($expectedPath))
			{
				unlink($expectedPath);
			}

			// VICTUAL_MODE is a setting, so the environment is how a demo instance's mode
			// reaches the child, exactly as it reaches a demo container.
			$result = self::runSubprocess('filesystem', ['DEMOSTORAGE', (string)$configured, 'storagefiles-demo', $name], ['VICTUAL_MODE' => 'demo']);

			self::assertSame('FilesystemStorage', $result['backend'], 'Demo mode runs on the filesystem backend');
			self::assertFileExists($expectedPath, 'The file is under this instance\'s suffix');
			self::assertFileDoesNotExist(self::$storageRoot . '/storagefiles-demo/' . $name, 'and not in the unsuffixed location a production install would use');
		}
	}

	/**
	 * A downscaled copy is created on first use, cached under the documented name, and the
	 * second request reuses it rather than decoding the image again.
	 */
	#[DataProvider('backends')]
	public function testDownscaleImageCachesACopyAndThenReusesIt(string $backend): void
	{
		$group = 'storagefiles-downscale';
		$storage = self::Storage($backend, [$group]);
		$service = FilesService::GetInstance();

		$storage->Create($group, 'big.png', self::MakePng(400, 300));

		$downscaled = $service->DownscaleImage($group, 'big.png', 64, null);

		self::assertSame('big' . FilesService::DOWNSCALED_INFIX . '64xauto.png', $downscaled, 'The cached copy is named for the size it was cut to');
		self::assertContains($downscaled, self::DurableNames($backend, $group), 'and was actually stored');

		$cached = self::DurableBytes($backend, $group, $downscaled);
		$size = getimagesizefromstring((string)$cached);
		self::assertSame(64, $size[1], 'The cached copy really is 64 pixels high');
		self::assertNotSame(self::$png, $cached, 'and is not simply a copy of the original');

		// The second call must not re-encode: the stored bytes staying identical is what
		// says the cache was used rather than silently rewritten.
		self::assertSame($downscaled, $service->DownscaleImage($group, 'big.png', 64, null));
		self::assertSame($cached, self::DurableBytes($backend, $group, $downscaled), 'The second request served the cached copy');

		// Both dimensions given is the best-fit path, and gets its own cache entry.
		$bestFit = $service->DownscaleImage($group, 'big.png', 32, 32);
		self::assertSame('big' . FilesService::DOWNSCALED_INFIX . '32x32.png', $bestFit);
		$bestFitSize = getimagesizefromstring((string)self::DurableBytes($backend, $group, $bestFit));
		self::assertLessThanOrEqual(32, max($bestFitSize[0], $bestFitSize[1]), 'Best fit bounds both dimensions');

		// Width only is the third branch.
		$byWidth = $service->DownscaleImage($group, 'big.png', null, 50);
		self::assertSame('big' . FilesService::DOWNSCALED_INFIX . 'autox50.png', $byWidth);
		self::assertSame(50, getimagesizefromstring((string)self::DurableBytes($backend, $group, $byWidth))[0], 'Width only resizes by width');
	}

	/**
	 * Downscaling something that cannot be resized falls back to the original name and
	 * caches nothing, so the caller is left to answer for the original file.
	 */
	#[DataProvider('backends')]
	public function testDownscaleImageFallsBackToTheOriginalName(string $backend): void
	{
		$group = 'storagefiles-downscale-fallback';
		$storage = self::Storage($backend, [$group]);
		$service = FilesService::GetInstance();

		self::assertSame('missing.png', $service->DownscaleImage($group, 'missing.png', 64, null), 'Nothing to resize gives the original name back');
		self::assertSame([], self::DurableNames($backend, $group), 'and stores nothing');

		$storage->Create($group, 'not-an-image.png', 'this is not a picture');
		self::assertSame('not-an-image.png', $service->DownscaleImage($group, 'not-an-image.png', 64, null), 'Content that is not an image gives the original name back');
		self::assertSame(['not-an-image.png'], self::DurableNames($backend, $group), 'and stores no cached copy');
	}

	/**
	 * Deleting an image takes its cached copies with it - and nothing else. The decoys
	 * matter: the database backend finds the copies with a LIKE over a user supplied file
	 * name, so a name containing "_" must not match its own wildcard.
	 */
	#[DataProvider('backends')]
	public function testDeleteFileRemovesTheCachedCopiesOfThatImageOnly(string $backend): void
	{
		$group = 'storagefiles-delete';
		$storage = self::Storage($backend, [$group]);
		$service = FilesService::GetInstance();

		$storage->Create($group, 'a_b.png', self::$png);
		$storage->Create($group, 'a_b' . FilesService::DOWNSCALED_INFIX . '32xauto.png', self::MakePng(32, 21));
		$storage->Create($group, 'a_b' . FilesService::DOWNSCALED_INFIX . '64xauto.png', self::MakePng(64, 43));
		// "axb" matches "a_b" if the underscore reaches LIKE unescaped.
		$storage->Create($group, 'axb' . FilesService::DOWNSCALED_INFIX . '32xauto.png', self::MakePng(32, 21));
		$storage->Create($group, 'unrelated.png', self::$png);

		$service->DeleteFile($group, 'a_b.png');

		$remaining = self::DurableNames($backend, $group);
		sort($remaining);
		self::assertSame(['axb' . FilesService::DOWNSCALED_INFIX . '32xauto.png', 'unrelated.png'], $remaining, 'Only that image and its own cached copies went');
	}

	/**
	 * A file whose content is not an image has no cached copies to find, so the prefix
	 * scan is not run for it - a name that merely looks like one of its thumbnails stays.
	 */
	#[DataProvider('backends')]
	public function testDeleteFileOfANonImageLeavesLookalikeNamesAlone(string $backend): void
	{
		$group = 'storagefiles-delete-nonimage';
		$storage = self::Storage($backend, [$group]);
		$service = FilesService::GetInstance();

		$storage->Create($group, 'manual.pdf', self::$pdf);
		$storage->Create($group, 'manual' . FilesService::DOWNSCALED_INFIX . '32xauto.pdf', self::$pdf);

		$service->DeleteFile($group, 'manual.pdf');

		self::assertSame(['manual' . FilesService::DOWNSCALED_INFIX . '32xauto.pdf'], self::DurableNames($backend, $group));

		// And deleting a name that is not there changes nothing at all.
		$service->DeleteFile($group, 'manual.pdf');
		self::assertSame(['manual' . FilesService::DOWNSCALED_INFIX . '32xauto.pdf'], self::DurableNames($backend, $group), 'Deleting an absent file is a no-op');
	}

	/**
	 * A backend that cannot reach what it stores has to fail, not report an absence.
	 *
	 * This is the pattern plan 01's own review named: a failure that returns a falsy value
	 * takes on the meaning of a legitimate answer. The files table going missing under the
	 * database backend is the version of it the request path can meet, and the API has to
	 * answer "something went wrong" rather than the 404 that means "there is no such file".
	 */
	public function testAnUnreachableBackendFailsRatherThanReportingAnAbsence(): void
	{
		$group = 'storagefiles-unreachable';
		$storage = self::Storage('database', [$group]);
		$storage->Write($group, 'present.png', self::$png);

		// Granted before the table goes: every statement inside an aborted transaction
		// fails, so the fixture has to be in place first.
		self::grant(['STOCK_VIEW']);

		self::$db->beginTransaction();
		self::$db->exec('DROP TABLE files');

		$failed = null;
		try
		{
			$storage->Exists($group, 'present.png');
		}
		catch (\PDOException $ex)
		{
			$failed = $ex;
		}

		self::assertNotNull($failed, 'A backend that cannot read its store says so');

		// The same distinction on the write path: Create() turns the unique constraint into
		// "this name is taken", and a store it cannot reach must not be reported as that -
		// a caller acting on it would go looking for a file that is not there.
		$failedCreate = null;
		try
		{
			$storage->Create($group, 'new-arrival.png', self::$png);
		}
		catch (\PDOException $ex)
		{
			$failedCreate = $ex;
		}

		self::assertNotNull($failedCreate, 'An exclusive create that cannot reach the store fails as itself');
		self::assertStringNotContainsString('Error while creating file', $failedCreate->getMessage(), 'and not as the message a taken name produces');

		$response = $this->expectStatus(
			fn () => self::$files->ServeFile(self::request(), new Response(), ['group' => 'productpictures', 'fileName' => base64_encode('present.png')]),
			400,
			'An unreachable store is an error, not a 404'
		);
		self::assertStringNotContainsString('files', (string)$response->getBody(), 'and the driver message does not reach the caller');

		self::$db->rollBack();

		self::assertTrue($storage->Exists($group, 'present.png'), 'The store is reachable again once the transaction is undone');
	}

	// -------------------------------------------------------------------------------
	// The API above the service
	// -------------------------------------------------------------------------------

	/**
	 * Upload, serve, delete through the API, against both backends, with the durable state
	 * checked after each write.
	 */
	#[DataProvider('backends')]
	public function testUploadServeAndDeleteThroughTheApi(string $backend): void
	{
		self::Storage($backend, ['productpictures']);
		self::grant(['MASTER_DATA_EDIT', 'STOCK_VIEW']);

		$fileName = base64_encode('api-trip.png');

		$this->expectStatus(fn () => self::$files->UploadFile(self::requestWithRawBody('PUT', self::$png), new Response(), ['group' => 'productpictures', 'fileName' => $fileName]), 204, 'Upload accepted');
		self::assertSame(self::$png, self::DurableBytes($backend, 'productpictures', 'api-trip.png'), 'The uploaded bytes are what was stored');

		$response = $this->expectStatus(fn () => self::$files->ServeFile(self::request(), new Response(), ['group' => 'productpictures', 'fileName' => $fileName]), 200, 'Served');
		self::assertSame(self::$png, (string)$response->getBody(), 'Served byte identical to what was uploaded');
		self::assertSame('image/png', $response->getHeaderLine('Content-Type'), 'An image is served as the image it is');
		self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'), 'A sniffing browser cannot turn it into something else');
		self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
		self::assertStringStartsWith('inline;', $response->getHeaderLine('Content-Disposition'), 'An image type on the inline list renders rather than downloads');

		$this->expectStatus(fn () => self::$files->DeleteFile(self::request('DELETE'), new Response(), ['group' => 'productpictures', 'fileName' => $fileName]), 204, 'Deleted');
		self::assertNull(self::DurableBytes($backend, 'productpictures', 'api-trip.png'), 'and the bytes are gone');
		$this->expectStatus(fn () => self::$files->ServeFile(self::request(), new Response(), ['group' => 'productpictures', 'fileName' => $fileName]), 404, 'Serving what is no longer there is a 404');
	}

	/**
	 * Anything that is not on the inline list is handed over as bytes to save, under a
	 * type that names nothing in particular - the file is never a document this origin
	 * renders. A PDF is the documented exception, because an equipment manual is embedded.
	 */
	#[DataProvider('backends')]
	public function testWhatIsServedInlineAndWhatIsHandedOverAsADownload(string $backend): void
	{
		self::Storage($backend, ['equipmentmanuals', 'userfiles']);
		self::grant(['MASTER_DATA_EDIT', 'EQUIPMENT']);

		$this->expectStatus(fn () => self::$files->UploadFile(self::requestWithRawBody('PUT', self::$pdf), new Response(), ['group' => 'equipmentmanuals', 'fileName' => base64_encode('manual.pdf')]), 204, 'PDF accepted');
		$pdfResponse = $this->expectStatus(fn () => self::$files->ServeFile(self::request(), new Response(), ['group' => 'equipmentmanuals', 'fileName' => base64_encode('manual.pdf')]), 200, 'PDF served');
		self::assertSame('application/pdf', $pdfResponse->getHeaderLine('Content-Type'));
		self::assertStringStartsWith('inline;', $pdfResponse->getHeaderLine('Content-Disposition'), 'A PDF is the one non-image served inline');

		$this->expectStatus(fn () => self::$files->UploadFile(self::requestWithRawBody('PUT', 'plain text, not a picture'), new Response(), ['group' => 'userfiles', 'fileName' => base64_encode('notes.txt')]), 204, 'Text file accepted');
		$textResponse = $this->expectStatus(fn () => self::$files->ServeFile(self::request(), new Response(), ['group' => 'userfiles', 'fileName' => base64_encode('notes.txt')]), 200, 'Text file served');
		self::assertSame('application/octet-stream', $textResponse->getHeaderLine('Content-Type'), 'Whatever it turned out to be, it is bytes');
		self::assertStringStartsWith('attachment;', $textResponse->getHeaderLine('Content-Disposition'), 'and a download rather than a document');
		self::assertSame('nosniff', $textResponse->getHeaderLine('X-Content-Type-Options'));
	}

	/**
	 * The second BASE64 name in the route argument is the name the download is offered
	 * under; the stored file is still the first one.
	 */
	public function testTheDownloadNameIsTheSecondEncodedName(): void
	{
		$storage = self::Storage('filesystem', ['userfiles']);
		self::grant(['MASTER_DATA_EDIT']);

		$storage->Write('userfiles', 'stored-name.txt', 'contents');

		$args = ['group' => 'userfiles', 'fileName' => base64_encode('stored-name.txt') . '_' . base64_encode('what the user asked for.txt')];
		$response = $this->expectStatus(fn () => self::$files->ServeFile(self::request(), new Response(), $args), 200, 'Served under two names');

		self::assertSame('contents', (string)$response->getBody(), 'The first name is the file that was read');
		// RFC 5987, so that a quote or a space in the name cannot end the parameter.
		self::assertSame("attachment; filename*=UTF-8''what%20the%20user%20asked%20for.txt", $response->getHeaderLine('Content-Disposition'), 'The second name is what it is offered as');
	}

	/**
	 * An upload is refused when the extension is not one the group takes, when the content
	 * is not what the extension claims, and when the name is already taken - and in every
	 * case the store is left exactly as it was.
	 */
	#[DataProvider('backends')]
	public function testUploadRefusalsLeaveTheStoreAsItWas(string $backend): void
	{
		self::Storage($backend, ['productpictures']);
		self::grant(['MASTER_DATA_EDIT', 'STOCK_VIEW']);

		// An extension the group does not accept. svg is deliberately absent from every
		// image list, being a script document.
		$this->expectStatus(fn () => self::$files->UploadFile(self::requestWithRawBody('PUT', '<svg xmlns="http://www.w3.org/2000/svg"/>'), new Response(), ['group' => 'productpictures', 'fileName' => base64_encode('drawing.svg')]), 400, 'Extension the group does not accept');
		self::assertNull(self::DurableBytes($backend, 'productpictures', 'drawing.svg'), 'and nothing was stored');

		// An image extension over content that is not an image: stored, checked, removed.
		$this->expectStatus(fn () => self::$files->UploadFile(self::requestWithRawBody('PUT', '<html><script>alert(1)</script></html>'), new Response(), ['group' => 'productpictures', 'fileName' => base64_encode('script.png')]), 400, 'Content that is not the image the name claims');
		self::assertNull(self::DurableBytes($backend, 'productpictures', 'script.png'), 'and what was written while checking is taken back out');

		// A name that is already taken: the exclusive create is what stops one caller
		// replacing another's picture by guessing its name.
		$this->expectStatus(fn () => self::$files->UploadFile(self::requestWithRawBody('PUT', self::$png), new Response(), ['group' => 'productpictures', 'fileName' => base64_encode('taken.png')]), 204, 'First upload accepted');
		// Suppressed: on the filesystem backend the refusal is a warning-emitting fopen
		// inside the application, the same one testCreateRefusesATakenName... documents.
		$this->expectStatus(fn () => @self::$files->UploadFile(self::requestWithRawBody('PUT', 'replacement'), new Response(), ['group' => 'productpictures', 'fileName' => base64_encode('taken.png')]), 400, 'Second upload of the same name refused');
		self::assertSame(self::$png, self::DurableBytes($backend, 'productpictures', 'taken.png'), 'and the first caller\'s bytes are untouched');

		// A file name that is not one.
		$this->expectStatus(fn () => self::$files->UploadFile(self::requestWithRawBody('PUT', self::$png), new Response(), ['group' => 'productpictures', 'fileName' => base64_encode('no-extension')]), 400, 'A name with no extension is refused');
	}

	/**
	 * A body over the effective limit is a 413 rather than the 400 every other upload
	 * failure gets, because it is the one refusal a client can act on by sending less -
	 * and nothing partial is left in the store.
	 */
	#[DataProvider('backends')]
	public function testAnUploadOverTheLimitAnswers413AndStoresNothing(string $backend): void
	{
		self::Storage($backend, ['userfiles']);
		self::grant(['MASTER_DATA_EDIT']);

		$body = str_repeat('a', FileSizeLimit::EffectiveMaxBytes() + 1);
		$response = $this->expectStatus(
			fn () => self::$files->UploadFile(self::requestWithRawBody('PUT', $body), new Response(), ['group' => 'userfiles', 'fileName' => base64_encode('oversized.txt')]),
			413,
			'A body over the limit'
		);

		self::assertStringContainsString('FILE_STORAGE_MAX_SIZE_MB', (string)$response->getBody(), 'The refusal names the limit');
		self::assertNull(self::DurableBytes($backend, 'userfiles', 'oversized.txt'), 'and nothing, partial or whole, was kept');
		self::assertNotContains('oversized.txt', self::DurableNames($backend, 'userfiles'), 'not even an empty file under the name');
	}

	/**
	 * Every route refuses a file name that is not one before a backend sees it, which is
	 * what keeps the traversal the filesystem backend would otherwise perform out of reach
	 * of the files API.
	 */
	#[DataProvider('backends')]
	public function testTheApiRefusesANameThatCouldEscapeItsGroup(string $backend): void
	{
		self::Storage($backend, ['productpictures']);
		self::grant(['MASTER_DATA_EDIT', 'STOCK_VIEW']);

		$escaped = VICTUAL_DATAPATH . '/storagefiles-api-escape.txt';

		foreach (['../../storagefiles-api-escape.txt', '/storagefiles-api-escape.txt', 'sub/storagefiles-api-escape.txt'] as $hostileName)
		{
			$args = ['group' => 'productpictures', 'fileName' => base64_encode($hostileName)];

			$this->expectStatus(fn () => self::$files->UploadFile(self::requestWithRawBody('PUT', 'escaped'), new Response(), $args), 400, "Upload refuses " . urlencode($hostileName));
			$this->expectStatus(fn () => self::$files->ServeFile(self::request(), new Response(), $args), 400, "Serve refuses " . urlencode($hostileName));
			$this->expectStatus(fn () => self::$files->DeleteFile(self::request('DELETE'), new Response(), $args), 400, "Delete refuses " . urlencode($hostileName));
		}

		self::assertFileDoesNotExist($escaped, 'No refused name reached the filesystem');
		self::assertSame([], self::DurableNames($backend, 'sub'), 'and none of them created a group of its own');
	}

	/**
	 * A file name containing a null byte is not refused, and what happens next depends on
	 * the backend. Neither answer is the one an invalid name should get.
	 *
	 * DEFECT (helpers/extensions.php:468-476). IsValidFileName's character class excludes
	 * "/?*;:{}\\" and nothing else, so a null byte is a valid character in a file name and
	 * all three routes accept one. Then:
	 *
	 * - on the filesystem backend the name reaches fopen(), which raises a ValueError. That
	 *   is an \Error, and HandleApiCall's catch chain ends at \Exception, so nothing
	 *   answers it: in production the request dies above the controller as a 500, from a
	 *   caller supplied string, where an invalid name is a 400.
	 * - on the database backend the driver truncates the name at the null byte, so the
	 *   upload answers 204 and stores a row under a name the caller never sent - and the
	 *   name the extension check was applied to is not the name that was stored. That is
	 *   GROUP_ALLOWED_EXTENSIONS ("an upload of anything else is refused rather than
	 *   stored") being bypassed, which the second half of this test demonstrates.
	 *
	 * The correct behaviour is for IsValidFileName to refuse a name containing a null byte,
	 * so that every route answers 400 on both backends. Pinned with assertions rather than
	 * skipped, because both current answers are worse than a refusal and a skipped test
	 * would stop reporting them.
	 */
	#[DataProvider('backends')]
	public function testANullByteInAFileNameIsNotRefused(string $backend): void
	{
		self::Storage($backend, ['productpictures', 'userfiles']);
		self::grant(['MASTER_DATA_EDIT', 'STOCK_VIEW']);

		self::assertTrue(IsValidFileName("null\0byte.png"), 'Current behaviour, and the root of the defect: a null byte passes the file name check');

		$args = ['group' => 'productpictures', 'fileName' => base64_encode("null\0byte.png")];

		if ($backend === 'filesystem')
		{
			$raised = null;
			try
			{
				self::$files->UploadFile(self::requestWithRawBody('PUT', self::$png), new Response(), $args);
			}
			catch (\ValueError $ex)
			{
				$raised = $ex;
			}

			self::assertNotNull($raised, 'Current behaviour, and a defect: the upload dies with an uncaught ValueError rather than answering 400');
			self::assertSame([], self::DurableNames($backend, 'productpictures'), 'Nothing was stored');

			return;
		}

		$this->expectStatus(fn () => self::$files->UploadFile(self::requestWithRawBody('PUT', self::$png), new Response(), $args), 204, 'Current behaviour, and a defect: the upload reports success');
		self::assertSame(['null'], self::DurableNames($backend, 'productpictures'), 'and stored the file under the truncation of the name, which is not the name that was sent');

		// The consequence, in the group whose allow list is explicitly about keeping out
		// the formats a browser executes in this origin: the extension check sees "txt"
		// and the store ends up holding an .svg.
		$this->expectStatus(fn () => self::$files->UploadFile(self::requestWithRawBody('PUT', '<svg xmlns="http://www.w3.org/2000/svg"/>'), new Response(), ['group' => 'userfiles', 'fileName' => base64_encode("bypass.svg\0.txt")]), 204, 'Current behaviour, and the point of the defect: the extension check passes on "txt"');
		self::assertSame(['bypass.svg'], self::DurableNames($backend, 'userfiles'), 'and an extension userfiles deliberately excludes is what was stored');

		// Negative control: the same file under its own name is refused, which is what the
		// group allow list is supposed to do in both cases.
		$this->expectStatus(fn () => self::$files->UploadFile(self::requestWithRawBody('PUT', '<svg xmlns="http://www.w3.org/2000/svg"/>'), new Response(), ['group' => 'userfiles', 'fileName' => base64_encode('honest.svg')]), 400, 'The same file under its own name is refused');
		self::assertSame(['bypass.svg'], self::DurableNames($backend, 'userfiles'), 'and stores nothing');
	}

	/**
	 * Writing to a group needs the permission that governs the records the group belongs
	 * to, and a caller without it changes nothing.
	 */
	public function testWritingToAGroupNeedsThePermissionThatGovernsIt(): void
	{
		$storage = self::Storage('filesystem', array_keys(FilesApiController::GROUP_WRITE_PERMISSIONS));

		$bodies = [
			'productpictures' => ['picture.png', self::$png],
			'recipepictures' => ['recipe.png', self::$png],
			'equipmentmanuals' => ['manual.pdf', self::$pdf],
			// Long enough that getimagesizefromstring(), which the delete path asks about
			// every file, does not emit a notice about reading past the end of it.
			'userfiles' => ['attachment.txt', 'an attached note, in plain text and nothing else'],
			'userpictures' => ['avatar.png', self::$png]
		];

		foreach (FilesApiController::GROUP_WRITE_PERMISSIONS as $group => $permissions)
		{
			[$name, $body] = $bodies[$group];
			$args = ['group' => $group, 'fileName' => base64_encode($name)];

			self::grant([]);
			$this->expectStatus(fn () => self::$files->UploadFile(self::requestWithRawBody('PUT', $body), new Response(), $args), 403, "$group upload refused without " . $permissions[0]);
			self::assertFalse($storage->Exists($group, $name), "$group: the refused upload wrote nothing");
			$this->expectStatus(fn () => self::$files->DeleteFile(self::request('DELETE'), new Response(), $args), 403, "$group delete refused without " . $permissions[0]);

			// Holding any one of the listed permissions is enough, so the last one is
			// checked as well as the first - userpictures is the group that lists two.
			self::grant([$permissions[count($permissions) - 1]]);
			$this->expectStatus(fn () => self::$files->UploadFile(self::requestWithRawBody('PUT', $body), new Response(), $args), 204, "$group upload accepted with " . $permissions[count($permissions) - 1]);
			self::assertTrue($storage->Exists($group, $name), "$group: the accepted upload stored the file");

			if ($group === 'userpictures')
			{
				// The write permission alone is "may edit some user", not "may edit this
				// user": deleting a picture that is not the caller's own needs USERS_EDIT
				// on top of it (sweep S6), and USERS_EDIT_SELF is exactly the natural grant
				// that must not be enough. The picture survives the refusal.
				$this->expectStatus(fn () => self::$files->DeleteFile(self::request('DELETE'), new Response(), $args), 403, 'USERS_EDIT_SELF does not delete a picture that is not the caller\'s');
				self::assertTrue($storage->Exists($group, $name), 'and the refused delete left the picture where it was');

				// A picture no user row claims is orphaned, and deleting it needs
				// USERS_EDIT and nothing more - there is no owner to compare against.
				self::grant(['USERS_EDIT']);
			}

			$this->expectStatus(fn () => self::$files->DeleteFile(self::request('DELETE'), new Response(), $args), 204, "$group delete accepted");
			self::assertFalse($storage->Exists($group, $name), "$group: and took it back out");
		}
	}

	/**
	 * Reading a group the caller has no permission for is refused before the file is
	 * looked up, and a group whose read policy is a decided null needs nothing beyond
	 * being authenticated. The refusals themselves are RbacTest's
	 * (testFileGroupReadPolicies); what is asserted here is that a permitted read serves
	 * the actual bytes and a refused one serves none.
	 */
	public function testReadingAGroupWithoutItsPermissionServesNothing(): void
	{
		$storage = self::Storage('filesystem', ['recipepictures', 'userfiles']);
		$storage->Write('recipepictures', 'secret-recipe.png', self::$png);
		$storage->Write('userfiles', 'open-attachment.txt', 'open');

		self::grant([]);
		$this->expectStatus(fn () => self::$files->ServeFile(self::request(), new Response(), ['group' => 'recipepictures', 'fileName' => base64_encode('secret-recipe.png')]), 403, 'A stored file is still refused without RECIPES_VIEW');

		// The negative control for the refusal above: the same caller, holding nothing,
		// does get a group whose read policy is a decided "no further permission needed".
		$open = $this->expectStatus(fn () => self::$files->ServeFile(self::request(), new Response(), ['group' => 'userfiles', 'fileName' => base64_encode('open-attachment.txt')]), 200, 'A group mapped to null is open to any authenticated caller');
		self::assertSame('open', (string)$open->getBody());

		self::grant(['RECIPES_VIEW']);
		$served = $this->expectStatus(fn () => self::$files->ServeFile(self::request(), new Response(), ['group' => 'recipepictures', 'fileName' => base64_encode('secret-recipe.png')]), 200, 'and is served with it');
		self::assertSame(self::$png, (string)$served->getBody(), 'The permitted read is of the real bytes');
	}

	/**
	 * A requested best-fit size snaps to the nearest size the cache is allowed to hold, so
	 * that a client asking for a size nobody listed still gets a picture while the number
	 * of distinct copies of one image stays bounded (sweep S10).
	 */
	public function testABestFitSizeSnapsToAnAllowedCacheSize(): void
	{
		$backend = 'filesystem';
		$storage = self::Storage($backend, ['productpictures']);
		self::grant(['MASTER_DATA_EDIT', 'STOCK_VIEW']);

		// 1200x900, so that every allowed size below is a genuine reduction: ImageResize
		// does not enlarge, and a source smaller than the requested size would come back
		// at its own height and make the snapping unobservable.
		$storage->Write('productpictures', 'snap.png', self::MakePng(1200, 900));

		foreach ([[401, 400], [33, 32], [48, 32], [1000000, 800], [1, 32]] as [$requested, $expected])
		{
			$response = $this->expectStatus(
				fn () => self::$files->ServeFile(self::request('GET', ['force_serve_as' => 'picture', 'best_fit_height' => (string)$requested]), new Response(), ['group' => 'productpictures', 'fileName' => base64_encode('snap.png')]),
				200,
				"best_fit_height=$requested"
			);

			self::assertSame($expected, getimagesizefromstring((string)$response->getBody())[1], "best_fit_height=$requested is served at $expected");
		}

		$cached = array_values(array_filter(self::DurableNames($backend, 'productpictures'), fn ($name) => str_starts_with($name, 'snap' . FilesService::DOWNSCALED_INFIX)));
		sort($cached);
		self::assertSame([
			'snap' . FilesService::DOWNSCALED_INFIX . '32xauto.png',
			'snap' . FilesService::DOWNSCALED_INFIX . '400xauto.png',
			'snap' . FilesService::DOWNSCALED_INFIX . '800xauto.png'
		], $cached, 'Five requested heights produced three cache entries, one per allowed size');

		// Width is snapped by the same rule, and is what a userfield picture asks for.
		$byWidth = $this->expectStatus(
			fn () => self::$files->ServeFile(self::request('GET', ['force_serve_as' => 'picture', 'best_fit_width' => '249']), new Response(), ['group' => 'productpictures', 'fileName' => base64_encode('snap.png')]),
			200,
			'best_fit_width=249'
		);
		self::assertSame(250, getimagesizefromstring((string)$byWidth->getBody())[0], 'best_fit_width=249 is served at 250');

		// A non-numeric or empty size is not a size at all, and is ignored rather than
		// snapped - the original is served.
		$original = $this->expectStatus(
			fn () => self::$files->ServeFile(self::request('GET', ['force_serve_as' => 'picture', 'best_fit_height' => 'huge']), new Response(), ['group' => 'productpictures', 'fileName' => base64_encode('snap.png')]),
			200,
			'best_fit_height=huge'
		);
		self::assertSame(900, getimagesizefromstring((string)$original->getBody())[1], 'A size that is not a number leaves the picture alone');
	}

	/**
	 * The own-picture read exception: every authenticated user's avatar renders in the nav
	 * bar, so reading their own picture needs nothing beyond being logged in.
	 *
	 * RbacTest covers what the exception refuses (a caller claiming somebody else's
	 * picture name, and a name two rows claim); it cannot cover what it permits, because
	 * no file exists there and every permitted read ends in a 404. This one stores the
	 * picture first, so the exception is measured by the bytes it hands back. Each case is
	 * its own process because VICTUAL_USER_PICTURE_FILE_NAME cannot be redefined, and the
	 * same subprocess is run against both backends because which one FileStorage selects
	 * is the FILE_STORAGE setting's answer, read at boot.
	 */
	public function testAnOwnPictureIsServedWithoutUsersRead(): void
	{
		$own = self::MakePng(64, 64);
		$other = self::MakePng(48, 48);

		// Written through both backends, so that whichever the subprocess is configured
		// for finds them.
		foreach (['filesystem', 'database'] as $backend)
		{
			$storage = self::Storage($backend, ['userpictures']);
			$storage->Write('userpictures', 'storagefiles-own.png', $own);
			$storage->Write('userpictures', 'storagefiles-other.png', $other);
		}

		self::$db->exec("INSERT INTO users(id, username, password, picture_file_name) VALUES (9100, 'storagefiles-picture-owner', 'fixture', 'storagefiles-own.png')");
		self::$db->exec("INSERT INTO users(id, username, password, picture_file_name) VALUES (9101, 'storagefiles-picture-other', 'fixture', 'storagefiles-other.png')");

		foreach (['filesystem' => 'FilesystemStorage', 'database' => 'DatabaseStorage'] as $setting => $expectedClass)
		{
			// The caller (9100) holds no permission at all, so a 200 here is the
			// own-picture exception and nothing else.
			$result = self::runSubprocess($setting, ['SERVE', '9100', 'storagefiles-own.png', 'storagefiles-own.png']);
			self::assertSame($expectedClass, $result['backend'], "FILE_STORAGE=$setting selects $expectedClass");
			self::assertSame(200, $result['status'], "Own picture served on the $setting backend");
			self::assertSame(hash('sha256', $own), $result['sha256'], 'and it is the caller\'s own picture, byte identical');

			// The negative control: the same caller, the same group, somebody else's
			// picture. Without USERS_READ the exception is all they had.
			$refused = self::runSubprocess($setting, ['SERVE', '9100', 'storagefiles-own.png', 'storagefiles-other.png']);
			self::assertSame(403, $refused['status'], "Another user's picture is refused on the $setting backend");
			self::assertNull($refused['sha256'], 'and no bytes were served');
		}
	}

	// -------------------------------------------------------------------------------
	// Fixtures and plumbing
	// -------------------------------------------------------------------------------

	/**
	 * Installs and returns the named backend as the one FileStorage::GetInstance() hands
	 * out, so that FilesService and FilesApiController - which both resolve the backend
	 * through it - run against the backend this case is about. The setting itself is fixed
	 * for the process (a constant), so the selection it drives is checked separately, in a
	 * subprocess.
	 */
	private static function Storage(string $backend, array $groupsToEmpty = []): FileStorage
	{
		$instance = $backend === 'database' ? new DatabaseStorage() : new FilesystemStorage();
		(new ReflectionProperty(FileStorage::class, 'Instance'))->setValue(null, $instance);

		// Every case states what it expects the whole of a group to hold, so it starts from
		// a group holding nothing - the datapath and the schema outlive a single method,
		// and an exclusive Create() against a leftover file would fail for the wrong reason.
		foreach ($groupsToEmpty as $group)
		{
			foreach (self::DurableNames($backend, $group) as $name)
			{
				$instance->Delete($group, $name);
			}
		}

		return $instance;
	}

	/**
	 * Drops the installed backend so that the next GetInstance() resolves the setting the
	 * way a request would.
	 */
	private static function ResetBackendSelection(): void
	{
		(new ReflectionProperty(FileStorage::class, 'Instance'))->setValue(null, null);
	}

	/**
	 * Every name the medium itself holds for this group - a directory listing, or the
	 * rows in the files table - never the backend's own answer about them.
	 *
	 * @return string[]
	 */
	private static function DurableNames(string $backend, string $group): array
	{
		if ($backend === 'database')
		{
			$statement = self::$db->prepare('SELECT name FROM files WHERE file_group = ? ORDER BY name');
			$statement->execute([$group]);

			return $statement->fetchAll(PDO::FETCH_COLUMN);
		}

		$folder = self::$storageRoot . '/' . $group;
		if (!is_dir($folder))
		{
			return [];
		}

		$names = array_values(array_diff(scandir($folder), ['.', '..']));
		sort($names);

		return $names;
	}

	/**
	 * The bytes the medium itself holds under this name, or null when it holds none.
	 */
	private static function DurableBytes(string $backend, string $group, string $name): ?string
	{
		if ($backend === 'database')
		{
			$statement = self::$db->prepare('SELECT content FROM files WHERE file_group = ? AND name = ?');
			$statement->execute([$group, $name]);
			$statement->bindColumn(1, $content, PDO::PARAM_LOB);

			if ($statement->fetch(PDO::FETCH_BOUND) === false)
			{
				return null;
			}

			return is_resource($content) ? stream_get_contents($content) : (string)$content;
		}

		$path = self::$storageRoot . '/' . $group . '/' . $name;

		return is_file($path) ? file_get_contents($path) : null;
	}

	/**
	 * Everything a backend's Read() hands back, as bytes.
	 */
	private static function ReadAll(FileStorage $storage, string $group, string $name): ?string
	{
		$stream = $storage->Read($group, $name);
		if ($stream === null)
		{
			return null;
		}

		$content = stream_get_contents($stream);
		fclose($stream);

		return (string)$content;
	}

	/**
	 * A readable stream over the given bytes, which is the shape an upload arrives in.
	 *
	 * @return resource
	 */
	private static function StreamOf(string $bytes)
	{
		$stream = fopen('php://temp', 'w+b');
		fwrite($stream, $bytes);
		rewind($stream);

		return $stream;
	}

	/**
	 * A PNG of the given size, generated rather than committed so that the round trip
	 * compares real image bytes without a fixture file to keep in step.
	 */
	private static function MakePng(int $width, int $height): string
	{
		$image = imagecreatetruecolor($width, $height);
		imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 10, 120, 200));
		// A second block, so that a downscaled copy is not a flat colour and a comparison
		// between two sizes has something to be different about.
		imagefilledrectangle($image, 0, 0, max(1, intdiv($width, 2)), max(1, intdiv($height, 2)), imagecolorallocate($image, 230, 40, 40));

		ob_start();
		imagepng($image);
		$bytes = (string)ob_get_clean();
		imagedestroy($image);

		return $bytes;
	}

	private static function request(string $method = 'GET', array $queryParams = [])
	{
		return (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api')->withQueryParams($queryParams);
	}

	private static function requestWithRawBody(string $method, string $bytes)
	{
		return (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api')
			->withBody((new StreamFactory())->createStream($bytes));
	}

	private static function grant(array $names): void
	{
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = ' . self::CALLER_ID . '; DELETE FROM user_roles WHERE user_id = ' . self::CALLER_ID);
		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ' . self::CALLER_ID . ', id FROM permission_hierarchy WHERE name = ?');
		foreach ($names as $name)
		{
			$statement->execute([$name]);
		}
	}

	/**
	 * A permission refusal thrown above HandleApiCall arrives as an exception rather than
	 * a response, because calling a controller directly skips Slim's error middleware.
	 */
	private function expectStatus(callable $work, int $expected, string $message)
	{
		try
		{
			$response = $work();
			$actual = $response->getStatusCode();
		}
		catch (HttpException $ex)
		{
			$actual = $ex->getCode();
			$response = null;
		}

		self::assertSame($expected, $actual, "$message: expected $expected, got $actual " . ($response === null ? '' : (string)$response->getBody()));

		return $response;
	}

	/**
	 * @param string[] $arguments The helper's own argument list, starting with its mode
	 * @return array{backend: string, status: int|null, sha256: string|null}
	 */
	private static function runSubprocess(string $fileStorageSetting, array $arguments, array $extraEnvironment = []): array
	{
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$environment = array_merge($inherited, [
			'STORAGEFILES_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
			'VICTUAL_FILE_STORAGE' => $fileStorageSetting,
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD')
		], $extraEnvironment);

		$process = proc_open(
			array_merge([PHP_BINARY, __DIR__ . '/storagefiles-subprocess-helper.php'], $arguments),
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$environment
		);

		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$result = json_decode((string)$output, true);
		self::assertIsArray($result, 'The storage files helper printed no JSON for ' . implode(' ', $arguments) . ". stdout: $output\nstderr: $errors");

		return $result;
	}
}
