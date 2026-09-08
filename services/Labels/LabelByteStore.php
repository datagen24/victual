<?php

namespace Victual\Services\Labels;

/**
 * The label subsystem's bytes, in `files`, under groups the OpenAPI FileGroups enum
 * deliberately does not name.
 *
 * Two things follow from that omission, and both are the point. `FilesApiController`
 * validates `{group}` against the enum on every one of its three routes, so an asset or an
 * artifact is refused by a check that already exists rather than by one this plan had to
 * remember to add - which matters because `ServeFile` gates reads by a hardcoded per-group
 * chain and lets an unlisted group through on authentication alone (sweep finding S32). And
 * the only path to these bytes is this subsystem's own authorized endpoints, which check the
 * owning request, job and entity kind rather than treating a digest as a capability.
 *
 * It writes through the caller's PDO rather than through `FileStorage::GetInstance()`
 * deliberately. Plan 27 question 7's answer requires the database backend whenever the label
 * subsystem is enabled - so there is no filesystem case to abstract over - and going through
 * the caller's connection is what lets a print run inside the same transaction as the row
 * that references it, and lets the regression suite run against a disposable schema.
 */
class LabelByteStore extends LabelService
{
    public const GROUP_ASSETS = 'labelassets';
    public const GROUP_ARTIFACTS = 'labelartifacts';

    /** Bounded on both the compressed bytes and, by the caller, the decoded pixels. */
    public const MAX_BYTES = 8388608;

    public function Put(string $group, string $name, string $bytes, string $mimeType): void
    {
        if (strlen($bytes) === 0 || strlen($bytes) > self::MAX_BYTES) {
            $this->Refuse('content', 'value_out_of_range', 'Stored bytes are between 1 and ' . self::MAX_BYTES . ' bytes');
        }
        $statement = $this->db->prepare('INSERT INTO files (file_group, name, mime_type, size_bytes, is_derivative, content) VALUES (?, ?, ?, ?, 0, ?)');
        $statement->bindValue(1, $group);
        $statement->bindValue(2, $name);
        $statement->bindValue(3, $mimeType);
        $statement->bindValue(4, strlen($bytes), \PDO::PARAM_INT);
        $statement->bindValue(5, $bytes, \PDO::PARAM_LOB);
        $statement->execute();
    }

    /** @return string|null The exact bytes, or null when they have been collected. */
    public function Get(string $group, string $name): ?string
    {
        $row = $this->Query('SELECT content FROM files WHERE file_group=? AND name=?', [$group, $name])->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $content = $row['content'];
        // PDO hands bytea back as a stream on some builds and a string on others.
        return is_resource($content) ? stream_get_contents($content) : (string)$content;
    }

    public function Delete(string $group, string $name): void
    {
        $this->Query('DELETE FROM files WHERE file_group=? AND name=?', [$group, $name]);
    }

    /** A storage name that carries no household data and cannot collide. */
    public static function NameFor(string $prefix, string $digest, string $extension): string
    {
        return $prefix . '-' . $digest . '.' . $extension;
    }
}
