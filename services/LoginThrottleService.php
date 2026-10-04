<?php

namespace Victual\Services;

use Victual\Services\Time\Instant;

/**
 * Rate limits password guessing, by counting failed attempts per username inside a rolling
 * window.
 *
 * Sweep finding S12. The state is a table rather than anything held between requests,
 * which is the finding's own emphasis and not an implementation preference: the deployment
 * target is a pod that scales to zero and stays down for a night at a time, so a counter
 * in process memory or APCu is reset for free by an attacker who waits - the same as
 * having no throttle, while looking like having one. ADR-0007 decided that shape before
 * this was built.
 *
 * **Per username, and only per username.** A first draft counted per client address too,
 * and behind a reverse proxy - this fork's stated deployment - REMOTE_ADDR is the proxy for
 * every request, so that counter would have held the entire instance for the window as soon
 * as anybody made ten mistakes. It would have been a global limit wearing a per-address
 * name, which is ADR-0007's own objection in a different disguise. Rate limiting a
 * misbehaving address needs the real client address, which only the proxy has, so it
 * belongs at the proxy layer and not here.
 *
 * What is left is the limit that binds wherever a request came from: one username, so many
 * guesses, per window. An attacker spreading attempts thinly across many usernames is the
 * proxy's to notice, and this class does not pretend otherwise.
 */
class LoginThrottleService extends BaseService
{
	/**
	 * Whether this username may be attempted at all right now.
	 */
	public function IsAttemptAllowed(string $username): bool
	{
		$limit = self::MaxAttempts();

		if ($limit <= 0)
		{
			return true;
		}

		return $this->CountSince($username, self::WindowStart()) < $limit;
	}

	/**
	 * Records a failed attempt, and takes the opportunity to drop everything that has
	 * fallen out of the window.
	 *
	 * Pruning here rather than on a schedule is what keeps the table bounded without a
	 * second moving part: attempts against a username nobody ever succeeds as would
	 * otherwise accumulate for ever, since the success path is what clears them.
	 */
	public function RecordFailedAttempt(string $username): void
	{
		$dbModTime = DatabaseService::GetInstance()->GetDbChangedTime();

		$this->DB->login_attempts()->where('row_created_timestamp <= :1', self::WindowStart())->delete();

		$this->DB->login_attempts()->createRow([
			'username' => $username
		])->save();

		// A refused login is not a data change, and clients poll on this value
		DatabaseService::GetInstance()->SetDbChangedTime($dbModTime);
	}

	/**
	 * Clears the counter a successful login earns back: this username's.
	 *
	 * An earlier draft cleared the rows for the *address* too, which was a bypass rather
	 * than a convenience - those are failures against other usernames, and a success proves
	 * nothing about them, so anybody could make nine guesses at `admin`, log in to their own
	 * account to wipe the slate, and repeat. Found in review of PR #68. The address counter
	 * has since gone entirely; this is the only counter there is, and clearing it is what a
	 * proof of the password earns.
	 */
	public function ClearAttempts(string $username): void
	{
		$dbModTime = DatabaseService::GetInstance()->GetDbChangedTime();

		$this->DB->login_attempts()->where('username', $username)->delete();

		DatabaseService::GetInstance()->SetDbChangedTime($dbModTime);
	}

	private static function MaxAttempts(): int
	{
		return (int)VICTUAL_LOGIN_THROTTLE_MAX_ATTEMPTS;
	}

	private static function WindowStart(): string
	{
		return Instant::FromNow(-((int)VICTUAL_LOGIN_THROTTLE_WINDOW_MINUTES * 60));
	}

	private function CountSince(string $username, string $since): int
	{
		return count($this->DB->login_attempts()->where('username = :1 AND row_created_timestamp > :2', $username, $since)->fetchAll());
	}
}
