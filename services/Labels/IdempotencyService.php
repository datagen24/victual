<?php

namespace Victual\Services\Labels;

use Victual\Helpers\CanonicalJson;

/**
 * Idempotency keys, scoped to a principal and an operation and bound to a fingerprint of the
 * request.
 *
 * Three properties, and the third is the one that is usually missing. Same key and same
 * inputs returns the original resource. Same key and *changed* inputs is a conflict rather
 * than a replay, because a client that edited the request and reused the key did not mean to
 * get the old answer. And **authorization is rechecked before a stored result is returned**,
 * because a key is not a capability: the person replaying it may have lost the grant that
 * made the first call legal.
 *
 * The lifetime is published rather than assumed. A client treating an expired key as a
 * guaranteed-safe retry prints twice, so `RETENTION_SECONDS` is a number this service will
 * answer with rather than a number it keeps to itself.
 */
class IdempotencyService extends LabelService
{
    /**
     * 24 hours. Long enough to cover a browser reload, an offline scanner reconnecting and a
     * retried request; short enough that the table does not become a second job history.
     * Plan 27 question 4 leaves the number open pending representative measurements, so this
     * is stated as a starting value with its reasoning rather than as a measured one.
     */
    public const RETENTION_SECONDS = 86400;

    public static function Fingerprint(array $request): string
    {
        return CanonicalJson::Digest($request);
    }

    /**
     * @return array{replay: bool, row: array|null} A replay carries the stored resource;
     *         otherwise the caller does the work and calls Record().
     */
    public function Begin(int $userId, string $operation, ?string $key, array $request): array
    {
        $this->Transaction();

        if ($key === null || $key === '') {
            return ['replay' => false, 'row' => null];
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key)) {
            $this->Refuse('idempotency_key', 'invalid_value', 'An idempotency key is 8-128 characters of letters, digits, dot, underscore, colon and hyphen');
        }

        $fingerprint = self::Fingerprint($request);
        $this->Query('DELETE FROM label_idempotency_keys WHERE expires_at<=CURRENT_TIMESTAMP');

        $existing = $this->Query('SELECT * FROM label_idempotency_keys WHERE principal_user_id=? AND operation=? AND idempotency_key=? FOR UPDATE',
            [$userId, $operation, $key])->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {
            if (!hash_equals((string)$existing['request_fingerprint'], $fingerprint)) {
                $this->Refuse('idempotency_key', 'idempotency_conflict', 'That key was used for a different request; a second intentional action needs a new key');
            }
            if ($existing['resource_id'] === null) {
                // The first attempt reserved the key and did not finish. Answering "in
                // progress" is the only truthful answer: repeating the work here could
                // produce a second physical label.
                $this->Refuse('idempotency_key', 'idempotency_in_progress', 'A request with that key is still in progress');
            }
            return ['replay' => true, 'row' => $existing + ['response' => json_decode((string)$existing['response'], true)]];
        }

        $this->Query('INSERT INTO label_idempotency_keys(principal_user_id,operation,idempotency_key,request_fingerprint,expires_at)
            VALUES (?,?,?,?,CURRENT_TIMESTAMP+make_interval(secs=>?))', [$userId, $operation, $key, $fingerprint, self::RETENTION_SECONDS]);

        return ['replay' => false, 'row' => null];
    }

    public function Record(int $userId, string $operation, ?string $key, string $resourceKind, int $resourceId, array $response): void
    {
        if ($key === null || $key === '') {
            return;
        }
        $this->Query('UPDATE label_idempotency_keys SET resource_kind=?,resource_id=?,response=?::jsonb WHERE principal_user_id=? AND operation=? AND idempotency_key=?',
            [$resourceKind, $resourceId, $this->Json($response), $userId, $operation, $key]);
    }
}
