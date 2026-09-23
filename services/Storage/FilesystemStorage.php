<?php

namespace Victual\Services\Storage;

/**
 * Keeps files below <data path>/storage, in one folder per group - what this fork has
 * always done, and the default.
 *
 * In demo/prerelease mode the storage path gets a per demo instance suffix, mirroring the
 * separate demo databases, so that several demo instances can share one filesystem. That
 * suffix is the reason FILE_STORAGE=database is refused in those modes (plan 01 Q4):
 * UNIQUE(file_group, name) has no column for it.
 */
class FilesystemStorage extends FileStorage
{
	/** @var string Absolute path of the storage root, suffixed in demo/prerelease mode */
	private $StoragePath;

	public function __construct()
	{
		$this->StoragePath = VICTUAL_DATAPATH . '/storage';
		if (!file_exists($this->StoragePath))
		{
			mkdir($this->StoragePath);
		}

		if (VICTUAL_MODE === 'demo' || VICTUAL_MODE === 'prerelease')
		{
			$dbSuffix = VICTUAL_DEFAULT_LOCALE;
			if (defined('VICTUAL_DEMO_DB_SUFFIX'))
			{
				$dbSuffix = VICTUAL_DEMO_DB_SUFFIX;
			}

			$this->StoragePath = $this->StoragePath . '/' . $dbSuffix;
			if (!file_exists($this->StoragePath))
			{
				mkdir($this->StoragePath);
			}
		}
	}

	public function Exists(string $group, string $name): bool
	{
		return file_exists($this->GetFilePath($group, $name));
	}

	public function Read(string $group, string $name)
	{
		$filePath = $this->GetFilePath($group, $name);

		if (!file_exists($filePath))
		{
			return null;
		}

		$handle = fopen($filePath, 'rb');

		return $handle === false ? null : $handle;
	}

	public function Create(string $group, string $name, $source): void
	{
		self::AssertValidName($name);

		$filePath = $this->GetFilePath($group, $name);

		$fileHandle = fopen($filePath, 'xb');
		if ($fileHandle === false)
		{
			throw new \Exception("Error while creating file $name");
		}

		try
		{
			$this->CopySource($source, $fileHandle);
		}
		catch (\Throwable $ex)
		{
			// Nothing partial survives a failed create: the name would then be taken by a
			// file that was never accepted, and the exclusive mode above would refuse the
			// caller's retry.
			fclose($fileHandle);
			@unlink($filePath);
			throw $ex;
		}

		if (fclose($fileHandle) === false)
		{
			throw new \Exception("Error while closing file $name");
		}
	}

	public function Write(string $group, string $name, $source): void
	{
		self::AssertValidName($name);

		$filePath = $this->GetFilePath($group, $name);

		$fileHandle = fopen($filePath, 'wb');
		if ($fileHandle === false)
		{
			throw new \Exception("Error while creating file $name");
		}

		try
		{
			$this->CopySource($source, $fileHandle);
		}
		catch (\Throwable $ex)
		{
			// "wb" has already truncated whatever was here, so the previous content is
			// gone either way; removing the partial file leaves "nothing" rather than a
			// truncated file that still answers Exists().
			fclose($fileHandle);
			@unlink($filePath);
			throw $ex;
		}

		if (fclose($fileHandle) === false)
		{
			throw new \Exception("Error while closing file $name");
		}
	}

	public function Delete(string $group, string $name): void
	{
		$filePath = $this->GetFilePath($group, $name);

		if (file_exists($filePath))
		{
			unlink($filePath);
		}
	}

	public function ListNames(string $group, string $prefix): array
	{
		$names = [];

		foreach (scandir($this->GetGroupFolderPath($group)) as $file)
		{
			if (string_starts_with($file, $prefix))
			{
				$names[] = $file;
			}
		}

		return $names;
	}

	public function GetMimeType(string $group, string $name): ?string
	{
		$filePath = $this->GetFilePath($group, $name);

		if (!file_exists($filePath))
		{
			return null;
		}

		$mimeType = mime_content_type($filePath);

		return $mimeType === false ? null : $mimeType;
	}

	/**
	 * The absolute path for a file in the given group folder, creating the folder when
	 * needed (the file itself may or may not exist).
	 */
	private function GetFilePath(string $group, string $name): string
	{
		return $this->GetGroupFolderPath($group) . '/' . $name;
	}

	/**
	 * The absolute path of a group folder, creating it when needed.
	 */
	private function GetGroupFolderPath(string $group): string
	{
		$groupFolderPath = $this->StoragePath . '/' . $group;

		if (!file_exists($groupFolderPath))
		{
			mkdir($groupFolderPath);
		}

		return $groupFolderPath;
	}
}
