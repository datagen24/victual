<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\PermissionMissingException;
use Victual\Controllers\Users\User;
use Victual\Services\FilesService;
use Victual\Services\Storage\FileStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * Serves the /api/files endpoints for uploading, serving and deleting files
 * (e.g. product or recipe pictures). The {group} route argument must be one of
 * the FileGroups defined in the OpenAPI spec and the {fileName} route argument
 * is expected to be BASE64 encoded.
 *
 * Writing is gated by the permission that governs the records the group belongs to,
 * and both the extension and, for images, the content are checked before a file is
 * kept. Serving answers with a type from a fixed list or hands the file over as a
 * download, never with a sniffed type inline. Sweep finding S2.
 */
class FilesApiController extends BaseApiController
{
	/**
	 * Extensions that may be stored as an image, and are served inline when their
	 * content really is one.
	 */
	const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

	/**
	 * Which permission lets a caller upload to or delete from a group. Holding any one
	 * of the listed permissions is enough. These mirror the permission that governs the
	 * record the file hangs off: equipment manuals follow equipment, recipe pictures
	 * follow recipes, and userfield files follow the userfield write path, which
	 * GenericEntityApiController::SetUserfields gates on MASTER_DATA_EDIT.
	 */
	const GROUP_WRITE_PERMISSIONS = [
		'productpictures' => [User::PERMISSION_MASTER_DATA_EDIT],
		'recipepictures' => [User::PERMISSION_RECIPES],
		'equipmentmanuals' => [User::PERMISSION_EQUIPMENT],
		'userfiles' => [User::PERMISSION_MASTER_DATA_EDIT],
		// The route carries no user id, so this is "may edit some user" rather than
		// "may edit this user". Binding a picture to its owner needs the id in the
		// request and belongs with the user permission work in plan 15 / sweep S6.
		'userpictures' => [User::PERMISSION_USERS_EDIT, User::PERMISSION_USERS_EDIT_SELF]
	];

	/**
	 * What may be stored in each group. An upload of anything else is refused rather
	 * than stored and dealt with at serving time: a file the browser will not be asked
	 * to render is still a file every other user can be handed a link to.
	 */
	const GROUP_ALLOWED_EXTENSIONS = [
		'productpictures' => self::IMAGE_EXTENSIONS,
		'recipepictures' => self::IMAGE_EXTENSIONS,
		'userpictures' => self::IMAGE_EXTENSIONS,
		'equipmentmanuals' => ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'],
		// Userfields of type file take whatever a household attaches to a record. The
		// list is deliberately about document formats, and deliberately without the
		// ones a browser executes in this origin - svg, html, xhtml, xml, js.
		'userfiles' => [
			'pdf', 'txt', 'md', 'csv', 'rtf',
			'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
			'odt', 'ods', 'odp', 'zip',
			'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic'
		]
	];

	/**
	 * Content types that are served inline. Everything else is a download.
	 *
	 * Deliberately without image/svg+xml, which is a script document. PDF is here
	 * because the equipment manual is shown in an <embed> and a PDF cannot reach the
	 * page that embeds it; it is served with this exact type and X-Content-Type-Options,
	 * so it is never treated as anything else.
	 */
	const INLINE_SERVED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

	/**
	 * The picture sizes a downscaled variant may be cached at.
	 *
	 * Sweep finding S10: best_fit_height/best_fit_width were only checked with is_numeric,
	 * and every distinct pair decodes the image again and stores another copy, so a caller
	 * could ask for a million of them. Only five sizes are actually asked for - 32 and 64
	 * for the list thumbnails, 250 for a userfield picture, 400 for the detail views - and
	 * 800 is here as a sane larger default for a client that wants something better than a
	 * detail view without inventing its own size.
	 *
	 * A value outside the list snaps to the nearest one rather than being refused, so an
	 * existing client asking for 401 keeps getting a picture. It is a cache key, not a
	 * contract: the response is still an image of about the size that was asked for.
	 */
	const ALLOWED_BEST_FIT_SIZES = [32, 64, 250, 400, 800];

	/**
	 * Throws unless the caller may write to the given group.
	 *
	 * @throws PermissionMissingException
	 */
	protected function CheckGroupWritePermission(Request $request, string $group): void
	{
		$permissions = self::GROUP_WRITE_PERMISSIONS[$group] ?? null;
		if ($permissions === null)
		{
			// A group in the OpenAPI enum with no entry here is a mistake, not a free pass
			throw new \Exception('No write permission is defined for this file group');
		}

		foreach ($permissions as $permission)
		{
			if (User::HasPermissions($permission))
			{
				return;
			}
		}

		throw new PermissionMissingException($request, $permissions[0]);
	}

	/**
	 * Throws unless the given file name carries an extension the group accepts.
	 */
	protected function CheckFileExtension(string $group, string $fileName): void
	{
		$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

		if (!in_array($extension, self::GROUP_ALLOWED_EXTENSIONS[$group] ?? []))
		{
			throw new \Exception('File type not allowed for this file group');
		}
	}

	/**
	 * DELETE /api/files/{group}/{fileName} - deletes the given file.
	 * Returns 204 on success or a 400 error response (invalid file group or filename).
	 */
	public function DeleteFile(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($args, $request, $response)
		{
			if (!in_array($args['group'], $this->GetOpenApispec()->components->schemas->FileGroups->enum))
			{
				throw new EInvalidApiQuery('Invalid file group');
			}

			$this->CheckGroupWritePermission($request, $args['group']);

			if (IsValidFileName(base64_decode($args['fileName'])))
			{
				$fileName = base64_decode($args['fileName']);
			}
			else
			{
				throw new EInvalidApiQuery('Invalid filename');
			}

			if ($args['group'] === 'userpictures')
			{
				$this->CheckUserPictureDeletion($request, $fileName);
			}

			FilesService::GetInstance()->DeleteFile($args['group'], $fileName);

			return $this->EmptyApiResponse($response);
		});
	}

	/**
	 * GET /api/files/{group}/{fileName} - streams the given file with a type from
	 * INLINE_SERVED_TYPES when its content is one of those, and as a download otherwise,
	 * with a 30 day Cache-Control header. {fileName} may also be two BASE64 encoded names
	 * joined by "_" (actual file name + download file name). Query parameters
	 * force_serve_as=picture with optional best_fit_height/best_fit_width serve a
	 * downscaled image variant, at the nearest of ALLOWED_BEST_FIT_SIZES. A file that does
	 * not exist is a 404; an invalid group or filename is a 400, which it was not before
	 * wave 2 - every failure here used to be re-thrown as a 404, so "you asked wrongly"
	 * and "it is not here" were the same answer.
	 */
	public function ServeFile(Request $request, Response $response, array $args)
	{
		if ($args['group'] === 'productpictures') User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		if ($args['group'] === 'recipepictures') User::CheckPermission($request, User::PERMISSION_RECIPES_VIEW);
		return $this->HandleApiCall($response, function () use ($args, $request, $response)
		{
			if (!in_array($args['group'], $this->GetOpenApispec()->components->schemas->FileGroups->enum))
			{
				throw new EInvalidApiQuery('Invalid file group');
			}

			if (str_contains($args['fileName'], '_'))
			{
				$fileInfo = explode('_', $args['fileName']);
				$fileName = $this->CheckFileName($fileInfo[0]);
				$fileNameOutput = $this->CheckFileName($fileInfo[1]);
				$storedName = $this->GetStoredName($args['group'], $fileName, $request->getQueryParams());
			}
			else
			{
				$fileName = $this->CheckFileName($args['fileName']);
				$fileNameOutput = $fileName;
				$storedName = $this->GetStoredName($args['group'], $fileName, $request->getQueryParams());
			}

			$storage = FileStorage::GetInstance();
			$stream = $storage->Read($args['group'], $storedName);

			if ($stream !== null)
			{
				$mimeType = $storage->GetMimeType($args['group'], $storedName);

				if (in_array($mimeType, self::INLINE_SERVED_TYPES))
				{
					$disposition = 'inline';
				}
				else
				{
					// Whatever this turned out to be, it is handed over as bytes to save,
					// not as a document to render in this origin
					$disposition = 'attachment';
					$mimeType = 'application/octet-stream';
				}

				$response = $response->withHeader('Cache-Control', 'private, no-store');
				$response = $response->withHeader('Content-Type', $mimeType);
				// Load-bearing, not belt and braces: a GIF/HTML polyglot passes getimagesize
				// and sniffs as image/gif, so it is served inline - and it stays an image
				// only because this header stops the browser sniffing its way to text/html.
				// Removing this re-opens that path even with the checks above intact.
				$response = $response->withHeader('X-Content-Type-Options', 'nosniff');
				// RFC 5987 encoded, so a quote in the name cannot end the parameter
				$response = $response->withHeader('Content-Disposition', $disposition . '; filename*=UTF-8\'\'' . rawurlencode($fileNameOutput));
				return $response->withBody(new Stream($stream));
			}
			else
			{
				throw new EObjectNotFound('File not found');
			}
		});
	}

	/**
	 * PUT /api/files/{group}/{fileName} - stores the raw request body as a new file,
	 * streamed into the configured storage in 1 MB chunks. Fails when the file already
	 * exists (exclusive create), when the extension is not one the group accepts, or when
	 * a file stored under an image extension is not an image. Returns 204 on success, 413
	 * when the body exceeds the effective upload limit, or a 400 error response.
	 */
	public function UploadFile(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($args, $request, $response)
		{
			if (!in_array($args['group'], $this->GetOpenApispec()->components->schemas->FileGroups->enum))
			{
				throw new EInvalidApiQuery('Invalid file group');
			}

			$this->CheckGroupWritePermission($request, $args['group']);

			$fileName = $this->CheckFileName($args['fileName']);
			$this->CheckFileExtension($args['group'], $fileName);

			$storage = FileStorage::GetInstance();
			$storage->Create($args['group'], $fileName, $this->GetRequestBodyStream($request));

			// A name ending in .png that holds a script is the whole of the problem, so
			// what was stored has to be what the extension said it was.
			//
			// Two limits worth knowing. This runs *after* the body is stored, so it bounds
			// what can be served, not what can be written - what bounds the write is the
			// FILE_STORAGE_MAX_SIZE_MB cap the storage enforces while streaming (sweep
			// S10). And it only fires for IMAGE_EXTENSIONS, so the bmp/tif/tiff/heic that
			// GROUP_ALLOWED_EXTENSIONS admits into userfiles are stored unvalidated; they
			// are safe because they sniff to a type outside INLINE_SERVED_TYPES and are
			// therefore downloaded rather than rendered.
			if (in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS)
				&& !$this->IsStoredFileAnImage($storage, $args['group'], $fileName))
			{
				$storage->Delete($args['group'], $fileName);
				throw new \Exception('File is not a valid image');
			}

			return $this->EmptyApiResponse($response);
		});
	}

	/**
	 * Refuses the deletion of somebody else's user picture.
	 *
	 * The route carries no user id, so the group permission alone is "may edit some user"
	 * rather than "may edit this user" - and USERS_EDIT_SELF is a natural grant, since it
	 * is what lets a person change their own password. Without this check it would also
	 * let them delete every other user's picture. Uploading needs no equivalent check: the
	 * name is new, so there is nothing to take away.
	 *
	 * The owner is recovered from users.picture_file_name, which is what makes this a
	 * route gap rather than a model gap - the id is missing from the request, not from the
	 * data. On top of "may edit some user" the caller has to be able to administer the one
	 * whose picture this is, which is the same subset rule EditUser applies (sweep finding
	 * S6). The sweep left that as an open question in either direction; it is answered
	 * yes, because deleting the avatar of someone whose permissions you do not hold is the
	 * same act of administering them, and because answering no would make this the one
	 * place the rule does not reach.
	 *
	 * A picture no user row claims is orphaned, and deleting it needs USERS_EDIT and
	 * nothing more: there is no owner to compare against.
	 *
	 * @throws PermissionMissingException
	 */
	private function CheckUserPictureDeletion(Request $request, string $fileName): void
	{
		if (defined('VICTUAL_USER_PICTURE_FILE_NAME') && $fileName === VICTUAL_USER_PICTURE_FILE_NAME)
		{
			// Own picture - the group permission was enough
			return;
		}

		User::CheckPermission($request, User::PERMISSION_USERS_EDIT);

		$owner = $this->DB->users()->where('picture_file_name', $fileName)->fetch();

		if ($owner !== null)
		{
			User::CheckMayAdminister($request, (int)$owner->id);
		}
	}

	/**
	 * BASE64 decodes $fileName and returns it; throws when the decoded name is not a valid filename.
	 */
	protected function CheckFileName(string $fileName)
	{
		if (IsValidFileName(base64_decode($fileName)))
		{
			$fileName = base64_decode($fileName);
		}
		else
		{
			throw new EInvalidApiQuery('Invalid filename');
		}

		return $fileName;
	}

	/**
	 * The request body as a readable stream, for the storage to consume.
	 *
	 * detach() hands over the underlying resource, which is php://input for a raw PUT.
	 * Nothing downstream reads the request body again. When it has already been consumed
	 * (the body parsing middleware does that for a form encoded or JSON content type) the
	 * detached resource is at EOF and the stored file is empty, which is what happened
	 * before this method existed too.
	 *
	 * @return resource|string
	 */
	protected function GetRequestBodyStream(Request $request)
	{
		$stream = $request->getBody()->detach();

		return $stream === null ? (string)$request->getBody() : $stream;
	}

	/**
	 * Whether what was just stored under $fileName really is an image.
	 *
	 * getimagesizefromstring() rather than getimagesize(), which needs a path the database
	 * backend cannot supply. Same detection code over the same bytes; the difference is
	 * that this holds the file in memory, bounded by the upload cap.
	 */
	protected function IsStoredFileAnImage(FileStorage $storage, string $group, string $fileName): bool
	{
		$stream = $storage->Read($group, $fileName);
		if ($stream === null)
		{
			return false;
		}

		$content = stream_get_contents($stream);
		fclose($stream);

		return $content !== false && getimagesizefromstring($content) !== false;
	}

	/**
	 * Snaps a requested best-fit size to the nearest of ALLOWED_BEST_FIT_SIZES.
	 *
	 * Snapping rather than refusing so that a client asking for a size nobody listed keeps
	 * getting a picture; what it bounds is how many distinct copies of one image can be
	 * decoded and stored (sweep S10). A tie goes to the smaller size.
	 */
	protected function ClampBestFitSize($requested): int
	{
		$requested = (int)$requested;
		$nearest = self::ALLOWED_BEST_FIT_SIZES[0];

		foreach (self::ALLOWED_BEST_FIT_SIZES as $allowed)
		{
			if (abs($allowed - $requested) < abs($nearest - $requested))
			{
				$nearest = $allowed;
			}
		}

		return $nearest;
	}

	/**
	 * Resolves which stored file answers this request; when the query parameters ask for
	 * force_serve_as=picture, a downscaled variant honoring best_fit_height/best_fit_width
	 * is created and its name returned instead.
	 */
	protected function GetStoredName(string $group, string $fileName, array $queryParams = [])
	{
		$forceServeAs = null;
		if (isset($queryParams['force_serve_as']) && !empty($queryParams['force_serve_as']))
		{
			$forceServeAs = $queryParams['force_serve_as'];
		}

		if ($forceServeAs == FilesService::FILE_SERVE_TYPE_PICTURE)
		{
			$bestFitHeight = null;
			if (isset($queryParams['best_fit_height']) && !empty($queryParams['best_fit_height']) && is_numeric($queryParams['best_fit_height']))
			{
				$bestFitHeight = $this->ClampBestFitSize($queryParams['best_fit_height']);
			}

			$bestFitWidth = null;
			if (isset($queryParams['best_fit_width']) && !empty($queryParams['best_fit_width']) && is_numeric($queryParams['best_fit_width']))
			{
				$bestFitWidth = $this->ClampBestFitSize($queryParams['best_fit_width']);
			}

			return FilesService::GetInstance()->DownscaleImage($group, $fileName, $bestFitHeight, $bestFitWidth);
		}

		return $fileName;
	}
}
