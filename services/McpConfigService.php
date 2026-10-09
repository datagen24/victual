<?php

namespace Victual\Services;

use PDO;

/**
 * Which MCP sidecar tools an administrator has switched on (ADR-0039).
 *
 * The sidecar keeps no configuration of its own. It asks Victual which tools are enabled on
 * every tools/list and tools/call, so a switch takes effect on the next request and nothing in
 * the container outlives a request (ADR-0007).
 *
 * The known tool names live here and not in the database: the table holds one row per tool an
 * administrator has ever set, and a tool with no row takes its default. Adding a tool is a code
 * change to KNOWN_TOOLS and to the sidecar, and needs no migration.
 */
class McpConfigService extends BaseService
{
	/** The six read tools: on unless an administrator turned one off. Order is the sidecar's tools/list order. */
	const READ_TOOLS = ['stock_overview', 'expiring_soon', 'missing_products', 'find_product', 'shopping_list', 'recipes_i_can_cook'];

	/** The three write tools: off until an administrator turns one on (docs/mcp-interface-spec.md section 6). */
	const WRITE_TOOLS = ['add_to_shopping_list', 'consume_product', 'purchase_product'];

	/** @return string[] every tool name the sidecar serves, in tools/list order */
	public static function KnownTools(): array
	{
		return array_merge(self::READ_TOOLS, self::WRITE_TOOLS);
	}

	public static function IsWriteTool(string $tool): bool
	{
		return in_array($tool, self::WRITE_TOOLS, true);
	}

	/**
	 * The effective switch for every known tool: the stored value where there is a row, the
	 * default where there is not. A stored row for a name this build no longer knows is ignored.
	 *
	 * @return array<string, bool> tool name => enabled, in KnownTools() order
	 */
	public function GetToolStates(): array
	{
		$stored = [];
		foreach ($this->Pdo()->query('SELECT tool_name, enabled FROM mcp_tool_settings') as $row)
		{
			$stored[$row['tool_name']] = (bool)$row['enabled'];
		}

		$states = [];
		foreach (self::KnownTools() as $tool)
		{
			$states[$tool] = $stored[$tool] ?? !self::IsWriteTool($tool);
		}

		return $states;
	}

	/** @return string[] the enabled tool names, in KnownTools() order */
	public function GetEnabledTools(): array
	{
		return array_keys(array_filter($this->GetToolStates()));
	}

	/**
	 * Stores the given switches and leaves every other tool as it was.
	 *
	 * All names are checked before any row is written, so one unknown name refuses the whole
	 * request. The caller wraps this in a transaction.
	 *
	 * @param array<string, bool> $switches tool name => enabled
	 * @param int $userId the administrator making the change
	 * @throws \InvalidArgumentException on an unknown tool name
	 */
	public function SetTools(array $switches, int $userId): void
	{
		$unknown = array_values(array_diff(array_map('strval', array_keys($switches)), self::KnownTools()));
		if ($unknown !== [])
		{
			throw new \InvalidArgumentException('Unknown MCP tool: ' . implode(', ', $unknown));
		}

		$statement = $this->Pdo()->prepare(
			'INSERT INTO mcp_tool_settings (tool_name, enabled, updated_at, updated_by) VALUES (:tool, :enabled, now(), :user)
			ON CONFLICT (tool_name) DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = EXCLUDED.updated_at, updated_by = EXCLUDED.updated_by'
		);

		foreach ($switches as $tool => $enabled)
		{
			$statement->bindValue(':tool', (string)$tool);
			$statement->bindValue(':enabled', (bool)$enabled, PDO::PARAM_BOOL);
			$statement->bindValue(':user', $userId, PDO::PARAM_INT);
			$statement->execute();
		}
	}

	private function Pdo(): PDO
	{
		return DatabaseService::GetInstance()->GetDbConnectionRaw();
	}
}
