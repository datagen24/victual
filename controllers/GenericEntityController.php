<?php

namespace Victual\Controllers;

use Victual\Controllers\Users\User;
use Victual\Services\UserfieldsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Slim route controller for the user-defined entity system views: userfields
 * (custom fields on built-in entities), userentities (user-defined entity types)
 * and userobjects (instances of those user-defined entities).
 */
class GenericEntityController extends BaseController
{
	/**
	 * The permission(s) required to view a page for the given generic-entity kind, mirroring
	 * GenericEntityApiController's write gate for the same entity exactly (MASTER_DATA_EDIT
	 * for every kind here, plus ADMIN for 'userentities'/'userfields' -
	 * ExposedEntityEditRequiresAdmin in victual.openapi.json) rather than inventing a new
	 * *_VIEW leaf for data that already has no separate read policy
	 * (controllers/Users/EntityReadPolicy.php maps all three to null).
	 */
	private static function CheckViewPermission(Request $request, string $entity): void
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);

		if ($entity === 'userentities' || $entity === 'userfields')
		{
			User::CheckPermission($request, User::PERMISSION_ADMIN);
		}
	}

	/**
	 * Serves the userentities list view (route GET /userentities).
	 */
	public function UserentitiesList(Request $request, Response $response, array $args)
	{
		self::CheckViewPermission($request, 'userentities');

		return $this->RenderPage($response, 'userentities', [
			'userentities' => $this->DB->userentities()->orderBy('name', 'COLLATE NOCASE')
		]);
	}

	/**
	 * Serves the userentity create/edit form (route GET /userentity/{userentityId}).
	 *
	 * @param array $args Route arguments; userentityId is either a userentity id or the literal 'new' for create mode
	 */
	public function UserentityEditForm(Request $request, Response $response, array $args)
	{
		self::CheckViewPermission($request, 'userentities');

		if ($args['userentityId'] == 'new')
		{
			return $this->RenderPage($response, 'userentityform', [
				'mode' => 'create'
			]);
		}
		else
		{
			return $this->RenderPage($response, 'userentityform', [
				'mode' => 'edit',
				'userentity' => $this->DB->userentities($args['userentityId'])
			]);
		}
	}

	/**
	 * Serves the userfield create/edit form (route GET /userfield/{userfieldId}).
	 *
	 * @param array $args Route arguments; userfieldId is either a userfield id or the literal 'new' for create mode
	 */
	public function UserfieldEditForm(Request $request, Response $response, array $args)
	{
		self::CheckViewPermission($request, 'userfields');

		if ($args['userfieldId'] == 'new')
		{
			return $this->RenderPage($response, 'userfieldform', [
				'mode' => 'create',
				'userfieldTypes' => UserfieldsService::GetInstance()->GetFieldTypes(),
				'entities' => UserfieldsService::GetInstance()->GetEntities()
			]);
		}
		else
		{
			return $this->RenderPage($response, 'userfieldform', [
				'mode' => 'edit',
				'userfield' => UserfieldsService::GetInstance()->GetField($args['userfieldId']),
				'userfieldTypes' => UserfieldsService::GetInstance()->GetFieldTypes(),
				'entities' => UserfieldsService::GetInstance()->GetEntities()
			]);
		}
	}

	/**
	 * Serves the userfields list view (route GET /userfields).
	 */
	public function UserfieldsList(Request $request, Response $response, array $args)
	{
		self::CheckViewPermission($request, 'userfields');

		return $this->RenderPage($response, 'userfields', [
			'userfields' => UserfieldsService::GetInstance()->GetAllFields(),
			'entities' => UserfieldsService::GetInstance()->GetEntities()
		]);
	}

	/**
	 * Serves the userobject create/edit form (route GET /userobject/{userentityName}/{userobjectId}).
	 *
	 * @param array $args Route arguments; userentityName selects the userentity,
	 *                    userobjectId is either a userobject id or the literal 'new' for create mode
	 */
	public function UserobjectEditForm(Request $request, Response $response, array $args)
	{
		self::CheckViewPermission($request, 'userobjects');

		$userentity = $this->DB->userentities()->where('name = :1', $args['userentityName'])->fetch();

		if ($args['userobjectId'] == 'new')
		{
			return $this->RenderPage($response, 'userobjectform', [
				'userentity' => $userentity,
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('userentity-' . $args['userentityName'])
			]);
		}
		else
		{
			return $this->RenderPage($response, 'userobjectform', [
				'userentity' => $userentity,
				'mode' => 'edit',
				'userobject' => $this->DB->userobjects($args['userobjectId']),
				'userfields' => UserfieldsService::GetInstance()->GetFields('userentity-' . $args['userentityName'])
			]);
		}
	}

	/**
	 * Serves the userobjects list view for one userentity (route GET /userobjects/{userentityName}).
	 */
	public function UserobjectsList(Request $request, Response $response, array $args)
	{
		self::CheckViewPermission($request, 'userobjects');

		$userentity = $this->DB->userentities()->where('name = :1', $args['userentityName'])->fetch();

		return $this->RenderPage($response, 'userobjects', [
			'userentity' => $userentity,
			'userobjects' => $this->DB->userobjects()->where('userentity_id = :1', $userentity->id),
			'userfields' => UserfieldsService::GetInstance()->GetFields('userentity-' . $args['userentityName']),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('userentity-' . $args['userentityName'])
		]);
	}
}
