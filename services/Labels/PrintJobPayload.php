<?php

namespace Victual\Services\Labels;

class PrintJobPayload
{
    public const PAYLOAD_VERSION = 1;
    public static function Build(string $uid, array $location, int $printerId): array
    {
        return ['payload_version' => self::PAYLOAD_VERSION, 'label_uid' => $uid,
         'kind' => 'location', 'printer_id' => $printerId, 'captured_fields' => ['name' => $location['name']]];
    }
    public static function DescribeUnreadable(mixed $payload): ?string
    {
        if (!is_array($payload) || ($payload['payload_version'] ?? null) !== self::PAYLOAD_VERSION) {
            return 'Unsupported label payload version';
        }
        if (!is_string($payload['label_uid'] ?? null) || !preg_match('/^[0-9A-F][0-9A-HJKMNP-TV-Z]{12}$/D', $payload['label_uid'])) {
            return 'Invalid label uid';
        }
        if (($payload['kind'] ?? null) !== 'location' || !is_int($payload['printer_id'] ?? null) || $payload['printer_id'] < 1) {
            return 'Invalid label target';
        }
        if (!is_string($payload['captured_fields']['name'] ?? null)) {
            return 'Missing captured location name';
        }
        return null;
    }
}
