<?php

namespace Victual\Services;

use Victual\Services\Storage\FileStorage;
use Gumlet\ImageResize;
use Gumlet\ImageResizeException;

/**
 * Manages uploaded files (product pictures, equipment manuals, ...), organized in per
 * purpose groups, including cached downscaled versions of images.
 *
 * Where the bytes actually live is the storage backend's business
 * (Victual\Services\Storage\FileStorage); this service knows only names. That is why
 * everything here takes and returns a file *name* rather than a path - a path is the one
 * thing a database backend cannot supply.
 */
class FilesService extends BaseService
{
	/**
	 * Value of the "force_serve_as" request option: serve the file inline as a
	 * picture instead of as a download.
	 */
	const FILE_SERVE_TYPE_PICTURE = 'picture';

	/**
	 * The infix that marks a cached downscaled copy, which is also the prefix scan the
	 * delete path uses to find every copy of one image.
	 */
	const DOWNSCALED_INFIX = '__downscaledto';

	/** What follows the infix: "<height|auto>x<width|auto>.<ext>". */
	private const DERIVATIVE_PATTERN = '/^(.+)__downscaledto(?:\d+|auto)x(?:\d+|auto)\.([^.]+)\z/s';

	/**
	 * The name a downscaled copy of $fileName is cached under. null for either limit means
	 * "no limit", written as 'auto'.
	 */
	public static function DerivativeName(string $fileName, ?int $bestFitHeight, ?int $bestFitWidth): string
	{
		return pathinfo($fileName, PATHINFO_FILENAME) . self::DOWNSCALED_INFIX . ($bestFitHeight ? $bestFitHeight : 'auto') . 'x' . ($bestFitWidth ? $bestFitWidth : 'auto') . '.' . pathinfo($fileName, PATHINFO_EXTENSION);
	}

	/**
	 * $fileName followed by what it is a derivative of, one suffix at a time, root last. A
	 * name that is not a derivative is a chain of one. A derivative can be created from a
	 * stored name that is itself a derivative name (a value or an upload may name one), so
	 * the whole chain is what an authorization decision has to look at.
	 *
	 * @return string[]
	 */
	public static function DerivativeChain(string $fileName): array
	{
		$chain = [$fileName];
		while (preg_match(self::DERIVATIVE_PATTERN, $fileName, $match) === 1)
		{
			$fileName = $match[1] . '.' . $match[2];
			$chain[] = $fileName;
		}

		return $chain;
	}

	/** The original that $fileName is, or is a (chain of) cached derivative(s) of. */
	public static function RootOf(string $fileName): string
	{
		$chain = self::DerivativeChain($fileName);

		return $chain[count($chain) - 1];
	}

	/**
	 * Returns the name of a downscaled copy of the given image, created on first use and
	 * cached alongside the original as "<name>__downscaledto<h>x<w>.<ext>".
	 * Falls back to the original file name when resizing fails.
	 *
	 * Resizing works on bytes rather than on files - ImageResize::createFromString() and
	 * getImageAsString() - because a database backend has no path to hand the library.
	 *
	 * @param string $group Group name, e.g. "productpictures"
	 * @param string $fileName
	 * @param int|null $bestFitHeight Maximum height in pixels, or null for no height limit
	 * @param int|null $bestFitWidth Maximum width in pixels, or null for no width limit
	 * @return string Name of the file to serve
	 */
	public function DownscaleImage($group, $fileName, $bestFitHeight = null, $bestFitWidth = null)
	{
		$storage = FileStorage::GetInstance();

		$fileNameDownscaled = self::DerivativeName($fileName, $bestFitHeight, $bestFitWidth);

		try
		{
			if (!$storage->Exists($group, $fileNameDownscaled))
			{
				$source = $storage->Read($group, $fileName);
				if ($source === null)
				{
					// Mirrors what "new ImageResize(<missing path>)" used to do: hand the
					// original name back and let the caller answer 404 for it.
					return $fileName;
				}

				$image = ImageResize::createFromString(stream_get_contents($source));
				fclose($source);

				if ($bestFitHeight !== null && $bestFitWidth !== null)
				{
					$image->resizeToBestFit($bestFitWidth, $bestFitHeight);
				}
				elseif ($bestFitHeight !== null)
				{
					$image->resizeToHeight($bestFitHeight);
				}
				elseif ($bestFitWidth !== null)
				{
					$image->resizeToWidth($bestFitWidth);
				}

				// A GET that writes. It is a cache fill rather than a state change - the
				// row is derived from the original and can be dropped and regenerated at
				// any time - but "GETs do not write" is otherwise a nice invariant, so it
				// is worth saying out loud that this one does.
				//
				// It writes twice, in fact: getImageAsString() is a tempnam() in
				// sys_get_temp_dir() that the library saves to, reads back and unlinks. So
				// this line needs a writable temporary directory, not only a writable
				// storage backend, and a deployment with a read-only root filesystem has to
				// provide one - which is why the image sets sys_temp_dir and names /tmp
				// among the paths it needs mounted (Dockerfile, plan 10 verification 4).
				$storage->Write($group, $fileNameDownscaled, $image->getImageAsString());
			}
		}
		catch (ImageResizeException $ex)
		{
			return $fileName;
		}

		return $fileNameDownscaled;
	}

	/**
	 * Deletes the given file; for images, also all of its cached "__downscaledto" copies.
	 *
	 * @param string $group Group name
	 * @param string $fileName
	 */
	public function DeleteFile($group, $fileName)
	{
		$storage = FileStorage::GetInstance();

		if ($storage->Exists($group, $fileName))
		{
			$fileNameWithoutExtension = pathinfo($fileName, PATHINFO_FILENAME);

			// Then the file is an image
			if ($this->IsImage($group, $fileName))
			{
				// Also delete all corresponding "__downscaledto" files when deleting an image
				foreach ($storage->ListNames($group, $fileNameWithoutExtension . self::DOWNSCALED_INFIX) as $name)
				{
					$storage->Delete($group, $name);
				}
			}

			$storage->Delete($group, $fileName);
		}
	}

	/**
	 * Whether the stored file really is an image, decided by its content.
	 *
	 * getimagesizefromstring() rather than getimagesize(), because the latter needs a
	 * path. The two are the same detection code over the same bytes; what differs is that
	 * this one holds the file in memory, which is bounded by the upload cap
	 * (FILE_STORAGE_MAX_SIZE_MB, sweep S10).
	 */
	private function IsImage(string $group, string $fileName): bool
	{
		$stream = FileStorage::GetInstance()->Read($group, $fileName);
		if ($stream === null)
		{
			return false;
		}

		$content = stream_get_contents($stream);
		fclose($stream);

		return $content !== false && getimagesizefromstring($content) !== false;
	}
}
