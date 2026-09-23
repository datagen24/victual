<?php

namespace Victual\Services\Storage;

/**
 * Where uploaded files live - product and recipe pictures, user pictures, user files and
 * equipment manuals - and the only thing in the tree that knows.
 *
 * Everything above this class works in (group, name) pairs and streams of bytes; nothing
 * above it opens a path. That is the whole point: the current code is path oriented
 * (file_exists, mime_content_type($path), fopen($path), new ImageResize($path)), and the
 * paths are what stop the container from being volume free. See
 * docs/plans/landed/01-file-storage.md.
 *
 * A "source" here is either a string of bytes or a readable stream resource, because the
 * two writers genuinely have one each: an upload arrives as the request body's stream,
 * and a downscaled image is produced as a string.
 */
abstract class FileStorage
{
	/**
	 * How much of a source is moved per read, mirroring the 1 MB chunks the upload path
	 * used when it wrote straight to disk.
	 */
	const COPY_CHUNK_SIZE = 1048576;

	/** Values of the FILE_STORAGE setting. */
	const STORAGE_FILESYSTEM = 'filesystem';
	const STORAGE_DATABASE = 'database';

	/** @var FileStorage|null The per request backend instance */
	private static $Instance = null;

	/**
	 * The backend named by the FILE_STORAGE setting.
	 *
	 * One instance per request, like the services: a backend holds no state that must
	 * outlive the process (ADR-0007), only the resolved storage path or the database
	 * connection it borrows. The setting's value is validated at startup
	 * (ConfigurationValidator::checkFileStorage), so anything but the two known names has
	 * already been refused by the time this runs.
	 */
	public static function GetInstance(): FileStorage
	{
		if (self::$Instance === null)
		{
			self::$Instance = VICTUAL_FILE_STORAGE === self::STORAGE_DATABASE
				? new DatabaseStorage()
				: new FilesystemStorage();
		}

		return self::$Instance;
	}

	/**
	 * Whether a file with this name exists in this group.
	 *
	 * @param string $group Group name, e.g. "productpictures"
	 * @param string $name File name, e.g. "apple.jpg"
	 */
	abstract public function Exists(string $group, string $name): bool;

	/**
	 * Opens the file for reading.
	 *
	 * Returns a stream rather than bytes so that serving a large file does not have to
	 * hold it in memory, and null rather than throwing so that "not there" stays the
	 * ordinary control flow it is on the serving path.
	 *
	 * @return resource|null A readable stream positioned at the start, or null when the
	 *                       file does not exist
	 */
	abstract public function Read(string $group, string $name);

	/**
	 * Stores a file that must not exist yet, mirroring fopen($path, 'xb').
	 *
	 * Exclusive on purpose: an upload names its own file, so silently overwriting would
	 * let one caller replace another's picture by guessing its name.
	 *
	 * @param string|resource $source Bytes, or a readable stream
	 * @throws \Exception When a file of that name already exists, or the write fails
	 */
	abstract public function Create(string $group, string $name, $source): void;

	/**
	 * Stores a file, replacing any file of the same name.
	 *
	 * @param string|resource $source Bytes, or a readable stream
	 * @throws \Exception When the write fails
	 */
	abstract public function Write(string $group, string $name, $source): void;

	/**
	 * Deletes the file if it exists. Deleting something that is not there is not an error.
	 */
	abstract public function Delete(string $group, string $name): void;

	/**
	 * The names of the files in this group whose name starts with $prefix.
	 *
	 * The one caller is the cached-downscale cleanup, which is why this is a prefix scan
	 * rather than a general query.
	 *
	 * @return string[]
	 */
	abstract public function ListNames(string $group, string $prefix): array;

	/**
	 * The content type of the stored file, sniffed from its content rather than trusted
	 * from its name, or null when the file does not exist.
	 */
	abstract public function GetMimeType(string $group, string $name): ?string;

	/**
	 * Refuses a name that is not a valid single file name - one containing a directory
	 * separator or a traversal segment. Applied by both backends' Create() and Write(), so
	 * a caller that reaches a backend directly rather than through the files API -
	 * services/StockService.php's barcode-picture write is the one that does - cannot make
	 * FilesystemStorage write outside its group folder or DatabaseStorage store a name
	 * FilesystemStorage would refuse.
	 *
	 * @throws InvalidFileNameException When $name is not a valid single file name
	 */
	protected static function AssertValidName(string $name): void
	{
		if (!IsValidFileName($name))
		{
			throw new InvalidFileNameException("Invalid file name: $name");
		}
	}

	/**
	 * Copies a source into an open sink in COPY_CHUNK_SIZE chunks, refusing to write more
	 * than the effective upload limit.
	 *
	 * The limit is checked while streaming rather than afterwards, which is the whole
	 * point of sweep S10: a raw PUT body is not subject to post_max_size, so a check that
	 * runs once the body is stored bounds what can be served rather than what can be
	 * written. Whatever has already been written when the limit is passed is the caller's
	 * to discard, and both backends do.
	 *
	 * @param string|resource $source Bytes, or a readable stream
	 * @param resource $sink An open, writable stream
	 * @return int The number of bytes written
	 * @throws FileTooLargeException When the source is larger than the effective limit
	 * @throws \Exception When a write fails
	 */
	protected function CopySource($source, $sink): int
	{
		$maximum = FileSizeLimit::EffectiveMaxBytes();
		$written = 0;

		if (is_string($source))
		{
			if (strlen($source) > $maximum)
			{
				throw new FileTooLargeException(self::TooLargeMessage($maximum));
			}

			$length = strlen($source);
			for ($offset = 0; $offset < $length; $offset += self::COPY_CHUNK_SIZE)
			{
				$chunk = substr($source, $offset, self::COPY_CHUNK_SIZE);
				if (fwrite($sink, $chunk) === false)
				{
					throw new \Exception('Error while writing file');
				}

				$written += strlen($chunk);
			}

			return $written;
		}

		while (($chunk = fread($source, self::COPY_CHUNK_SIZE)) !== false && $chunk !== '')
		{
			$written += strlen($chunk);

			if ($written > $maximum)
			{
				// Stop reading here: the rest of the body is never pulled off the wire and
				// what was written so far is thrown away by the caller.
				throw new FileTooLargeException(self::TooLargeMessage($maximum));
			}

			if (fwrite($sink, $chunk) === false)
			{
				throw new \Exception('Error while writing file');
			}
		}

		return $written;
	}

	/**
	 * The refusal message, which names the limit so that a caller can tell a file that is
	 * too large from a file that was rejected for some other reason.
	 */
	private static function TooLargeMessage(int $maximum): string
	{
		return 'File is larger than the ' . FileSizeLimit::FormatMegabytes($maximum)
			. ' MB upload limit (FILE_STORAGE_MAX_SIZE_MB)';
	}
}
