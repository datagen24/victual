<?php

namespace Victual\Services\Labels;

use Victual\Services\ApiKeyService;

class LabelWorkerCredentialService extends LabelService
{
    public const CREDENTIAL_SECONDS = 3600;
    public const SESSION_SECONDS = 86400;
    public const MATERIAL_SECONDS = 300;
    public function __construct(\PDO $db, private int $credentialSeconds = self::CREDENTIAL_SECONDS, private int $sessionSeconds = self::SESSION_SECONDS, private int $materialSeconds = self::MATERIAL_SECONDS)
    {
        parent::__construct($db);
        foreach ([$credentialSeconds,$sessionSeconds,$materialSeconds] as $duration) {
            if ($duration < 1) {
                throw new \InvalidArgumentException('Durations must be positive');
            }
        }
    }
    private function Worker(int $workerId): array
    {
        $this->Transaction();
        $w = $this->Query('SELECT * FROM label_workers WHERE id=? AND active=1 FOR UPDATE', [$workerId])->fetch(\PDO::FETCH_ASSOC);
        if (!$w) {
            $this->Refuse('worker_id', 'inactive_worker', 'Worker is inactive');
        }
        return $w;
    }
    public function PairingMaterial(int $workerId, int $ownerId): array
    {
        $w = $this->Worker($workerId);
        if ($w['configuration_mode'] !== 'paired') {
            $this->Refuse('worker_id', 'wrong_mode', 'Only paired workers accept pairing material');
        }
        $this->Query('UPDATE label_worker_sessions SET material_consumed_at=COALESCE(material_consumed_at,CURRENT_TIMESTAMP) WHERE worker_id=? AND started_at IS NULL', [$workerId]);
        $material = bin2hex(random_bytes(32));
        $session = $this->Query('INSERT INTO label_worker_sessions(worker_id,created_by_user_id,pairing_material_hash,material_expires_at) VALUES (?,?,?,CURRENT_TIMESTAMP+make_interval(secs=>?)) RETURNING id,material_expires_at', [$workerId,$ownerId,hash('sha256', $material),$this->materialSeconds])->fetch(\PDO::FETCH_ASSOC);
        return $session + ['material' => $material];
    }
    public function Pair(string $material): array
    {
        $this->Transaction();
        $this->Hex($material, 'material');
        $session = $this->Query('SELECT s.* FROM label_worker_sessions s JOIN label_workers w ON w.id=s.worker_id WHERE s.pairing_material_hash=? AND s.revoked_at IS NULL AND s.material_consumed_at IS NULL AND s.material_expires_at>clock_timestamp() AND w.active=1 AND w.configuration_mode=\'paired\' FOR UPDATE OF w,s', [hash('sha256', $material)])->fetch(\PDO::FETCH_ASSOC);
        if (!$session) {
            $this->Refuse('material', 'unauthorized', 'Invalid or expired pairing material');
        }
        $this->Query("UPDATE label_worker_sessions SET revoked_at=CURRENT_TIMESTAMP,revoked_reason='admin' WHERE worker_id=? AND id<>? AND revoked_at IS NULL", [$session['worker_id'],$session['id']]);
        $this->Query('UPDATE label_worker_sessions SET material_consumed_at=CURRENT_TIMESTAMP,started_at=CURRENT_TIMESTAMP,expires_at=CURRENT_TIMESTAMP+make_interval(secs=>?) WHERE id=?', [$this->sessionSeconds,$session['id']]);
        $this->Query('UPDATE label_workers SET requires_repairing=0 WHERE id=?', [$session['worker_id']]);
        return $this->Issue((int)$session['worker_id'], (int)$session['created_by_user_id'], (int)$session['id']);
    }
    public function IssueDeclared(int $workerId, int $ownerId): array
    {
        $w = $this->Worker($workerId);
        if ($w['configuration_mode'] !== 'declared') {
            $this->Refuse('worker_id', 'wrong_mode', 'Paired workers must pair');
        }
        return $this->Issue($workerId, $ownerId, null);
    }
    private function Issue(int $workerId, int $ownerId, ?int $sessionId, ?string $key = null): array
    {
        $key ??= bin2hex(random_bytes(32));
        $expires = $sessionId === null ? '2999-12-31 23:59:59' : $this->Query('SELECT LEAST(expires_at,CURRENT_TIMESTAMP+make_interval(secs=>?)) FROM label_worker_sessions WHERE id=?', [$this->credentialSeconds,$sessionId])->fetchColumn();
        $id = (int)$this->Query('INSERT INTO api_keys(api_key,key_hint,user_id,expires,key_type,description) VALUES (?,?,?,?,?,?) RETURNING id', [ApiKeyService::HashKey($key),ApiKeyService::HintFor($key),$ownerId,$expires,ApiKeyService::API_KEY_TYPE_LABEL_WORKER,'Label worker '.$workerId])->fetchColumn();
        $this->Query('INSERT INTO label_worker_credentials(api_key_id,worker_id,session_id) VALUES (?,?,?)', [$id,$workerId,$sessionId]);
        return ['credential_id' => $id,'credential' => $key,'expires_at' => (new \DateTimeImmutable($expires))->format('c'),'worker_id' => $workerId];
    }
    public function Authenticate(string $key, bool $rotation = false, string $keyType = ApiKeyService::API_KEY_TYPE_LABEL_WORKER): ?array
    {
        $sql = "SELECT c.*,k.user_id,k.expires,s.expires_at AS session_expires_at FROM label_worker_credentials c
   JOIN api_keys k ON k.id=c.api_key_id JOIN label_workers w ON w.id=c.worker_id
   LEFT JOIN label_worker_sessions s ON s.id=c.session_id
   WHERE k.api_key=? AND k.key_type=? AND w.active=1
   AND (c.session_id IS NULL OR (s.revoked_at IS NULL AND s.expires_at>clock_timestamp()))";
        // Rotation can recover a short-expired or consumed key, but never a revoked session.
        if (!$rotation) {
            $sql .= ' AND c.consumed_at IS NULL AND k.expires>clock_timestamp()';
        }
        $row = $this->Query($sql, [ApiKeyService::HashKey($key), $keyType])->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function Rotate(string $credential, string $requestId, string $material): array
    {
        $this->Transaction();
        $this->Hex($requestId, 'rotation_request_id');
        $this->Hex($material, 'material');
        $initial = $this->Authenticate($credential, true);
        if (!$initial || $initial['session_id'] === null) {
            $this->Refuse('credential', 'unauthorized', 'Invalid paired credential');
        }
        $this->Worker((int)$initial['worker_id']);
        $c = $this->Authenticate($credential, true);
        if (!$c) {
            $this->Refuse('credential', 'unauthorized', 'Session ended');
        }
        $other = $this->Query('SELECT api_key_id FROM label_worker_credentials WHERE worker_id=? AND rotation_request_id=?', [$c['worker_id'],$requestId])->fetchColumn();
        if ($other && (int)$other !== (int)$c['api_key_id']) {
            $this->Refuse('rotation_request_id', 'replay_mismatch', 'Request belongs to another credential');
        }
        if ($c['consumed_at'] !== null) {
            if ($c['rotation_request_id'] !== $requestId) {
                $this->Query("UPDATE label_worker_sessions SET revoked_at=CURRENT_TIMESTAMP,revoked_reason='credential_reuse' WHERE id=?", [$c['session_id']]);
                $this->Query('UPDATE label_workers SET requires_repairing=1 WHERE id=?', [$c['worker_id']]);
                // Return a refusal value so the controller commits the security event.
                return ['revoked' => true,'code' => 'credential_reuse'];
            }
            if (!hash_equals($c['rotation_material_hash'], hash('sha256', hex2bin($material)))) {
                $this->Refuse('material', 'replay_mismatch', 'Replay material differs');
            }
            $key = self::Derive((int)$c['api_key_id'], $requestId, $material);
            $next = $this->Query('SELECT id,expires FROM api_keys WHERE id=? AND api_key=?', [$c['successor_api_key_id'],ApiKeyService::HashKey($key)])->fetch(\PDO::FETCH_ASSOC);
            if (!$next) {
                $this->Refuse('credential', 'unauthorized', 'Successor was revoked');
            }
            return ['credential_id' => (int)$next['id'],'credential' => $key,'expires_at' => (new \DateTimeImmutable($next['expires']))->format('c'),'worker_id' => (int)$c['worker_id']];
        }
        $key = self::Derive((int)$c['api_key_id'], $requestId, $material);
        $next = $this->Issue((int)$c['worker_id'], (int)$c['user_id'], (int)$c['session_id'], $key);
        $this->Query('UPDATE label_worker_credentials SET consumed_at=CURRENT_TIMESTAMP,rotation_request_id=?,rotation_material_hash=?,successor_api_key_id=? WHERE api_key_id=?', [$requestId,hash('sha256', hex2bin($material)),$next['credential_id'],$c['api_key_id']]);
        return $next;
    }
    public static function Derive(int $id, string $requestId, string $material): string
    {
        return bin2hex(hash_hmac('sha256', $id.':'.$requestId, hex2bin($material), true));
    }
    private function Hex(string $value, string $field): void
    {
        if (!preg_match('/^[0-9a-f]{64}$/D', $value)) {
            $this->Refuse($field,'value_out_of_range','Expected 32 bytes as lowercase hexadecimal');
        }
    }
    public function Revoke(int $workerId): void
    {
        $this->Worker($workerId);
        $this->Query("UPDATE label_worker_sessions SET revoked_at=CURRENT_TIMESTAMP,revoked_reason='admin' WHERE worker_id=? AND revoked_at IS NULL",[$workerId]);
        // Retain historical credential bindings, but invalidate the key values permanently.
        $this->Query("UPDATE api_keys SET api_key='revoked:'||id::text,expires=CURRENT_TIMESTAMP WHERE id IN (SELECT api_key_id FROM label_worker_credentials WHERE worker_id=?)",[$workerId]);
    }
}
