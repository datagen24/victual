<?php

namespace Victual\Controllers\Api;

use Victual\Services\Time\Instant;

use Eluceo\iCal\Domain\Entity\Calendar;
use Eluceo\iCal\Domain\Entity\Event;
use Eluceo\iCal\Domain\Entity\TimeZone;
use Eluceo\iCal\Domain\ValueObject\Date;
use Eluceo\iCal\Domain\ValueObject\DateTime;
use Eluceo\iCal\Domain\ValueObject\SingleDay;
use Eluceo\iCal\Domain\ValueObject\TimeSpan;
use Eluceo\iCal\Domain\ValueObject\UniqueIdentifier;
use Eluceo\iCal\Presentation\Factory\CalendarFactory;
use Slim\Exception\HttpInternalServerErrorException;
use Victual\Controllers\Users\User;
use Victual\Services\ApiKeyService;
use Victual\Services\CalendarService;
use Victual\Services\Mqtt\StateSnapshotAssembler;
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
	 * Sentinel dates (StateSnapshotAssembler::NO_DUE_DATE_SENTINEL_FROM, 2888-01-01, and
	 * beyond) are excluded to prevent unbounded events and far-future timezone bounds.
	 * Event UIDs are deterministic (derived from event type and entity ID only, no date)
	 * so identical reads yield identical UIDs and a rescheduled entity keeps its UID
	 * across the date change (#511). The UID's domain part is VICTUAL_CALENDAR_UID_DOMAIN
	 * (default "victual", matching every UID this endpoint emitted before that setting
	 * existed) - RFC 5545 requires a UID to be globally unique, which a fixed domain
	 * cannot guarantee once more than one installation's feed reaches the same client.
	 * A malformed event (missing the type/id metadata a UID needs) is a server fault and
	 * answers 500; every other failure answers 400.
	 */
	public function Ical(Request $request, Response $response, array $args)
	{
		// The shared "secret" query parameter is meant to be reachable by an external calendar
		// application with no session at all (docs/manual, ApiKeyAuthenticator's
		// CalendarSharingSecret() - this route is the one place that parameter is accepted).
		// Whoever holds a valid special-purpose calendar key *is* authorized by holding it -
		// that is the whole design of a shareable calendar link, the same way a stock
		// print-job link or a password reset link authorizes by possession rather than by a
		// permission grant looked up afterwards. So CALENDAR_VIEW is required only for the
		// session-authenticated path; a request carrying a secret that validates against
		// ApiKeyService is let through on the strength of the secret alone, matching exactly
		// what ApiKeyAuthenticator::Authenticate() already validated to reach this controller
		// at all. The check is repeated here (not read off request state) because nothing
		// upstream records *which* branch authenticated the request, only that one did.
		$secret = $request->getQueryParams()['secret'] ?? null;
		$hasValidCalendarSecret = is_string($secret) && $secret !== ''
			&& ApiKeyService::GetInstance()->IsValidApiKey($secret, ApiKeyService::API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL);

		if (!$hasValidCalendarSecret)
		{
			User::CheckPermission($request, User::PERMISSION_CALENDAR_VIEW);
		}

		return $this->HandleApiCall($response, function () use ($request, $response)
		{
			$events = CalendarService::GetInstance()->GetEvents();
			$minDate = null;
			$maxDate = null;

			$vCalendar = new Calendar();
			$vCalendar->setProductIdentifier('Victual');

			// Sentinel date threshold: exclude events at or beyond this date. Reuses
			// StateSnapshotAssembler::NO_DUE_DATE_SENTINEL_FROM rather than a separate
			// literal - it is the same "anything from here on means no real due date"
			// concept the MQTT snapshot already established (2888-12-31 substituted for
			// a null best-before date, 2999-12-31 for a battery with no charge interval;
			// both sentinels, never real occurrences). This widens this endpoint's own
			// former cutoff (2999-01-01) down to 2888-01-01, deliberately: a stock row's
			// null best_before_date is already filtered a few lines below by the
			// isset()/empty() check on $event['start'] and never reaches this comparison
			// as the literal string '2888-12-31' today, but aligning the threshold means
			// it is excluded here too if a future read path ever writes that convention
			// into stock_current directly, rather than being excluded only by accident of
			// today's NULL representation. No legitimate event uses a date within eight
			// centuries of either constant. The '!' resets every field createFromFormat()
			// does not receive to the Unix epoch, including time-of-day - without it, both
			// this threshold and $eventDate below inherit the current wall-clock time,
			// which makes a date exactly at the threshold compare on time-of-day rather
			// than date alone.
			$sentinelThreshold = \DateTimeImmutable::createFromFormat('!Y-m-d', StateSnapshotAssembler::NO_DUE_DATE_SENTINEL_FROM);

			// The domain part of every UID this request emits (#511: CodeRabbit review
			// comment 4117467865 on an earlier revision - a fixed "@victual" makes two
			// installations' feeds collide on identical UIDs for different items in one
			// calendar client, and RFC 5545 requires a UID to be globally unique).
			// Sanitized the same way GetNodeId() sanitizes VICTUAL_MQTT_TOPIC_PREFIX
			// (services/Mqtt/DiscoveryPayloadBuilder.php): replace every character outside
			// the allowed set rather than refuse the request, and fall back to the default
			// only if nothing usable is left. The allowed set is letters, digits, '.' (a
			// domain's label separator) and '-'; anything else - including a literal '@',
			// which would corrupt the "type-id@domain" shape into "type-id@dom@ain" - is
			// replaced with '-'. "Nothing usable" is not only an empty string: a value made
			// entirely of disallowed characters (e.g. '@@@') sanitizes to a non-empty run of
			// '-' ('---'), which is exactly as meaningless as an empty value and must fall
			// back the same way. trim() against '.-' strips a value of only those two
			// characters down to '', so the check catches both cases with one comparison.
			// Left at the default 'victual', this release's UIDs are unchanged; changing the
			// setting away from its current effective value gives every event a new UID, so
			// subscribed clients replace all events once.
			$uidDomain = preg_replace('/[^A-Za-z0-9.-]/', '-', (string)VICTUAL_CALENDAR_UID_DOMAIN);
			if (trim($uidDomain, '.-') === '')
			{
				$uidDomain = 'victual';
			}

			foreach ($events as $event)
			{
				if (!isset($event['start']) || empty($event['start']))
				{
					continue;
				}

				// Extract the date portion to check against sentinel threshold. '!' as above:
				// a date-only comparison must not carry today's time-of-day.
				$eventDate = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($event['start'], 0, 10));

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
					// Time-point event. The start is an instant (ADR-0027 decision 2); iCalendar
					// gets it as the configured zone's wall clock with TZID and the VTIMEZONE
					// added below (RFC 5545 3.3.5 form 3), so a subscriber shows it at the
					// right moment in its own zone across a DST change. Not the API's RFC
					// 3339 string, which is not an iCalendar DATE-TIME.
					$startInstant = Instant::ParseStored($event['start']);
					if ($startInstant === null)
					{
						continue;
					}

					$local = \DateTime::createFromImmutable($startInstant->setTimezone(Instant::ServerZone()));
					$start = new DateTime(\DateTimeImmutable::createFromMutable($local), true);
					$end = new DateTime(\DateTimeImmutable::createFromMutable($local), true);
					$vEventOccurrence = new TimeSpan($start, $end);

					$compareDate = $startInstant->setTimezone(Instant::ServerZone());
				}

				// Create event with a deterministic UID based on event type and entity ID
				// only - no date component. Format: <event_type>-<entity_id>@<uidDomain>.
				// #511 (maintainer decision, 2026-09-27): a UID identifies the entity's
				// calendar slot, not a specific occurrence of it, so rescheduling the
				// entity (a chore's next due date, a product's best-before date) changes
				// DTSTART without changing UID - the same event moved, not a new one.
				//
				// A missing event_type/entity_id throws rather than falling through to
				// Event's own default (a randomly generated UID, eluceo/ical's behaviour
				// when none is given): silently accepting that fallback is exactly how
				// #511 happened, and a future event source that forgets to set these two
				// keys must fail loudly at request time, not quietly reintroduce
				// non-deterministic UIDs for just that source. This is this application
				// being wrong about its own data shape, not a caller mistake, so it is a
				// 500 (HttpInternalServerErrorException, an HttpSpecializedException - see
				// HandleApiCall's docblock) carrying only this message, never a \RuntimeException,
				// which HandleApiCall's generic \Exception catch would otherwise answer 400.
				if (!isset($event['event_type']) || !isset($event['entity_id']))
				{
					throw new HttpInternalServerErrorException(
						$request,
						'Calendar event is missing event_type/entity_id required for a stable UID (#511)'
						. (isset($event['title']) ? ": {$event['title']}" : '')
					);
				}
				$uid = new UniqueIdentifier($event['event_type'] . '-' . $event['entity_id'] . '@' . $uidDomain);

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
		User::CheckPermission($request, User::PERMISSION_CALENDAR_VIEW);

		return $this->HandleApiCall($response, function () use ($response)
		{
			return $this->ApiResponse($response, [
				'url' => $this->AppContainer->get('UrlManager')->ConstructUrl('/api/calendar/ical?secret=' . ApiKeyService::GetInstance()->GetOrCreateApiKey(ApiKeyService::API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL))
			]);
		});
	}
}
