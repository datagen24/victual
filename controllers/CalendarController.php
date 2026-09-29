<?php

namespace Victual\Controllers;

use Victual\Controllers\Users\User;
use Victual\Services\CalendarService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Slim route controller for the calendar view; event collection
 * (stock due dates, chores, tasks, meal plan etc.) is delegated to CalendarService.
 */
class CalendarController extends BaseController
{
	/**
	 * Serves the fullcalendar-based calendar view (route GET /calendar).
	 */
	public function Overview(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_CALENDAR_VIEW);

		return $this->RenderPage($response, 'calendar', [
			'fullcalendarEventSources' => CalendarService::GetInstance()->GetEvents()
		]);
	}
}
