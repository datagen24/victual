<?php

namespace Victual\Controllers;

use Victual\Services\UserfieldsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Slim route controller for the (household) equipment views:
 * the overview list and the create/edit form.
 */
class EquipmentController extends BaseController
{
	/**
	 * Serves the equipment create/edit form (route GET /equipment/{equipmentId}).
	 *
	 * @param array $args Route arguments; equipmentId is either an equipment id or the literal 'new' for create mode
	 */
	public function EditForm(Request $request, Response $response, array $args)
	{
		if ($args['equipmentId'] == 'new')
		{
			return $this->RenderPage($response, 'equipmentform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('equipment')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'equipmentform', [
				'equipment' => $this->DB->equipment($args['equipmentId']),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('equipment')
			]);
		}
	}

	/**
	 * Serves the equipment overview view (route GET /equipment).
	 */
	public function Overview(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'equipment', [
			'equipment' => $this->DB->equipment()->orderBy('name', 'COLLATE NOCASE'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('equipment'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('equipment')
		]);
	}
}
