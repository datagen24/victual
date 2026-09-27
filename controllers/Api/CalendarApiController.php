<?php

namespace Victual\Controllers\Api;

use Eluceo\iCal\Domain\Entity\Calendar;
use Eluceo\iCal\Domain\Entity\Event;
use Eluceo\iCal\Domain\Entity\TimeZone;
use Eluceo\iCal\Domain\ValueObject\Date;
use Eluceo\iCal\Domain\ValueObject\DateTime;
use Eluceo\iCal\Domain\ValueObject\SingleDay;
use Eluceo\iCal\Domain\ValueObject\TimeSpan;
use Eluceo\iCal\Domain\ValueObject\UniqueIdentifier;
use Eluceo\iCal\Presentation\Factory\CalendarFactory;
use Victual\Services\ApiKeyService;
use Victual\Services\CalendarService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves the /api/calendar endpoints: iCal export of all Victual events
 * (due products, chores, tasks, meal plan etc.) and the shareable link for it.
 */
class CalendarApiController extends BaseApiController
{
	/**
	 * GET /api/calendar/ical - exports all events from CalendarService as an iCal file
	 * (Content-Type text/calendar, served as attachment "Victual.ics"); events without
	 * a start are skipped, timed events are exported as zero-length occurrences.
	 * Returns a 400 JSON error response on failure.
	 *
	 * Sentinel dates (2999-12-31 and beyond) are excluded to prevent unbounded events
	 * and far-future timezone bounds. Event UIDs are deterministic (derived from event
	 * type and entity ID only, no date) so identical reads yield identical UIDs and a
	 * rescheduled entity keeps its UID across the date change (#511).
	 */
	public function Ical(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($response)
		{
			$events = CalendarService::GetInstance()->GetEvents();
			$minDate = null;
			$maxDate = null;

			$vCalendar = new Calendar();
			$vCalendar->setProductIdentifier('Victual');

			// Sentinel date threshold: exclude events at or beyond this date
			$sentinelThreshold = \DateTimeImmutable::createFromFormat('Y-m-d', '2999-01-01');

			foreach ($events as $event)
			{
				if (!isset($event['start']) || empty($event['start']))
				{
					continue;
				}

				// Extract the date portion to check against sentinel threshold
				$eventDate = \DateTimeImmutable::createFromFormat('Y-m-d', substr($event['start'], 0, 10));

				// Skip events at or beyond the sentinel threshold (never-expiring products, etc.)
				if ($eventDate >= $sentinelThreshold)
				{
					continue;
				}

				$description = '';
				if (isset($event['description']))
				{
					$description = $event['description'];
				}

				if ($event['date_format'] === 'date' || (isset($event['allDay']) && $event['allDay']))
				{
					// All-day event
					$date = new Date(\DateTimeImmutable::createFromFormat('Y-m-d', substr($event['start'], 0, 10)));
					$vEventOccurrence = new SingleDay($date);

					$compareDate = \DateTimeImmutable::createFromFormat('Y-m-d', substr($event['start'], 0, 10));
				}
				else
				{
					// Time-point event
					$start = new DateTime(\DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $event['start']), true);
					$end = new DateTime(\DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $event['start']), true);
					$vEventOccurrence = new TimeSpan($start, $end);

					$compareDate = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $event['start']);
				}

				// Create event with a deterministic UID based on event type and entity ID
				// only - no date component. Format: <event_type>-<entity_id>@victual.
				// #511 (maintainer decision, 2026-09-27): a UID identifies the entity's
				// calendar slot, not a specific occurrence of it, so rescheduling the
				// entity (a chore's next due date, a product's best-before date) changes
				// DTSTART without changing UID - the same event moved, not a new one.
				$uid = null;
				if (isset($event['event_type']) && isset($event['entity_id']))
				{
					$uid = new UniqueIdentifier($event['event_type'] . '-' . $event['entity_id'] . '@victual');
				}

				$vEvent = new Event($uid);
				$vEvent->setOccurrence($vEventOccurrence)
					->setSummary($event['title'])
					->setDescription($description);

				$vCalendar->addEvent($vEvent);

				if ($minDate == null || $compareDate < $minDate)
				{
					$minDate = $compareDate;
				}
				if ($maxDate == null || $compareDate > $maxDate)
				{
					$maxDate = $compareDate;
				}
			}

			if ($minDate != null && $maxDate != null)
			{
				$vCalendar->addTimeZone(TimeZone::createFromPhpDateTimeZone(new \DateTimeZone(date_default_timezone_get()), $minDate, $maxDate));
			}

			$response->getBody()->write((string)(new CalendarFactory())->createCalendar($vCalendar));
			$response = $response->withHeader('Content-Type', 'text/calendar; charset=utf-8');
			return $response->withHeader('Content-Disposition', 'attachment; filename="Victual.ics"');
		});
	}

	/**
	 * GET /api/calendar/ical/sharing-link - returns { "url": string }, a link to the
	 * iCal endpoint with an embedded special purpose API key ("secret" query parameter),
	 * or a 400 error response on failure.
	 */
	public function IcalSharingLink(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($response)
		{
			return $this->ApiResponse($response, [
				'url' => $this->AppContainer->get('UrlManager')->ConstructUrl('/api/calendar/ical?secret=' . ApiKeyService::GetInstance()->GetOrCreateApiKey(ApiKeyService::API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL))
			]);
		});
	}
}
