<?php

namespace Victual\Services\Labels;

/** Shared SQL helpers. Transaction ownership always remains with the caller. */
abstract class LabelService
{
    public function __construct(protected \PDO $db)
    {
    }
    protected function Transaction(): void
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('A caller-owned transaction is required');
        }
    }
    protected function Query(string $sql, array $args = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        return $stmt;
    }
    protected function Json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
    protected function Refuse(string $field, string $code, string $message): never
    {
        throw new LabelValidationException($field, $code, $message);
    }
}
