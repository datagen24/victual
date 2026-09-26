<?php

namespace Victual\Services;

/**
 * Cookie-based login sessions: creation, validation and removal of rows in the
 * sessions table. The session key doubles as the value of the victual_session cookie.
 */
class SessionService extends BaseService
{
	const SESSION_COOKIE_NAME = 'victual_session';

	/**
	 * How long a session created with "stay logged in" is valid for, in seconds.
	 *
	 * Bounded rather than infinite: a stolen session key is a credential, and one that
	 * never expires is one nothing ever takes away. VICTUAL_SESSION_STAY_LOGGED_IN_DAYS
	 * configures it. Sweep finding S3 / plan 15-B2, question 3.
	 */
	public static function GetStayLoggedInLifetimeSeconds(): int
	{
		return intval(VICTUAL_SESSION_STAY_LOGGED_IN_DAYS) * 86400;
	}

	/**
	 * Creates a session for the user and returns the new session key.
	 *
	 * @param int $userId
	 * @param bool $stayLoggedInPermanently True expires the session
	 * VICTUAL_SESSION_STAY_LOGGED_IN_DAYS from now, false expires it 30 days from now
	 * @return string The 50 character random session key
	 */
	public function CreateSession($userId, $stayLoggedInPermanently = false)
	{
		$newSessionKey = $this->GenerateKey();
		$expires = date('Y-m-d H:i:s', time() + 2592000);

		// Default is that sessions expire in 30 days
		if ($stayLoggedInPermanently === true)
		{
			$expires = date('Y-m-d H:i:s', time() + self::GetStayLoggedInLifetimeSeconds());
		}

		$sessionRow = $this->DB->sessions()->createRow([
			'user_id' => $userId,
			'session_key' => $newSessionKey,
			'expires' => $expires
		]);
		$sessionRow->save();

		return $newSessionKey;
	}

	/**
	 * The user with the lowest id (normally the initially created admin), used as the
	 * acting user in modes without authentication (dev/demo/prerelease, embedded
	 * installs, VICTUAL_DISABLE_AUTH).
	 */
	public function GetDefaultUser()
	{
		return $this->DB->users()->orderBy('id')->limit(1)->fetch();
	}

	/**
	 * The user row the session key belongs to, or null for an unknown key
	 * (expiry is NOT checked here - use IsValidSession() for that).
	 */
	public function GetUserBySessionKey($sessionKey)
	{
		$sessionRow = $this->DB->sessions()->where('session_key', $sessionKey)->fetch();
		if ($sessionRow !== null)
		{
			return $this->DB->users($sessionRow->user_id);
		}

		return null;
	}

	/**
	 * True when the key belongs to a session that has not expired. Also stamps the
	 * session's last_used time, while restoring the database changed time afterwards
	 * so this bookkeeping write does not make clients think data changed.
	 *
	 * @param string|null $sessionKey
	 * @return bool
	 */
	public function IsValidSession($sessionKey)
	{
		if ($sessionKey === null || empty($sessionKey))
		{
			return false;
		}
		else
		{
			$sessionRow = $this->DB->sessions()->where('session_key = :1 AND expires > :2', $sessionKey, date('Y-m-d H:i:s', time()))->fetch();
			if ($sessionRow !== null)
			{
				// This should not change the database file modification time as this is used
				// to determine if REALLY something has changed
				$dbModTime = DatabaseService::GetInstance()->GetDbChangedTime();
				$sessionRow->update([
					'last_used' => date('Y-m-d H:i:s', time())
				]);
				DatabaseService::GetInstance()->SetDbChangedTime($dbModTime);

				return true;
			}
			else
			{
				return false;
			}
		}
	}

	/**
	 * Deletes every session whose expiry has passed.
	 *
	 * Nothing pruned this table before, so it grew for the life of the installation and
	 * kept a row for every session anybody ever had - which is a needless record of who
	 * was logged in from when, and a needless thing for a database dump to carry (sweep
	 * finding S19). It runs on login: the one moment there is already a reason to be
	 * writing here, and the one that scales with logins rather than with requests.
	 *
	 * Like IsValidSession()'s bookkeeping write, this restores the database changed time
	 * afterwards - housekeeping is not a data change and clients poll on that value.
	 */
	public function RemoveExpiredSessions()
	{
		$dbModTime = DatabaseService::GetInstance()->GetDbChangedTime();
		$this->DB->sessions()->where('expires <= :1', date('Y-m-d H:i:s', time()))->delete();
		DatabaseService::GetInstance()->SetDbChangedTime($dbModTime);
	}

	/**
	 * Deletes the session (logout); unknown keys are a no-op.
	 */
	public function RemoveSession($sessionKey)
	{
		$this->DB->sessions()->where('session_key', $sessionKey)->delete();
	}

	/**
	 * Deletes every session of $userId except $exceptSessionKey (when given), so that a
	 * password change can drop every session it invalidates while leaving the one that
	 * proved the current password - a self-service change, made from a session of its own
	 * - logged in.
	 *
	 * $exceptSessionKey is compared as a plain string against this user's own rows only,
	 * so a key that does not belong to $userId (an administrator's own session while
	 * resetting somebody else's password, or none at all for an API-key-authenticated
	 * request) excepts nothing and every session of $userId is cleared - which is the
	 * intended reading of "an administrator changing another user's password revokes all
	 * of that user's sessions" (issue #513): there is no session of the target's own to
	 * spare.
	 *
	 * Like IsValidSession()'s and RemoveExpiredSessions()'s bookkeeping, this restores the
	 * database changed time afterwards: the write a password change itself makes is the
	 * change a polling client should see, and clearing superseded sessions is a
	 * consequence of it rather than a second one.
	 */
	public function RemoveOtherSessions(int $userId, ?string $exceptSessionKey = null): void
	{
		$dbModTime = DatabaseService::GetInstance()->GetDbChangedTime();

		$query = $this->DB->sessions()->where('user_id', $userId);

		if ($exceptSessionKey !== null)
		{
			$query = $query->where('session_key != :1', $exceptSessionKey);
		}

		$query->delete();

		DatabaseService::GetInstance()->SetDbChangedTime($dbModTime);
	}

	private function GenerateKey()
	{
		return RandomString(50);
	}
}
