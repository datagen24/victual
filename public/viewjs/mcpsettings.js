// View script for the MCP settings page (views/mcpsettings.blade.php): each switch saves
// itself through PUT /api/mcp/config (ADR-0039 decision 4). The message is set with .text(),
// and the tool name comes from a data attribute the server rendered from its own fixed list.
$(document).on("change", ".mcp-tool-switch", function ()
{
	var input = $(this);
	var tool = String(input.attr("data-tool"));
	var wanted = input.prop("checked");
	var tools = {};
	tools[tool] = wanted;

	input.prop("disabled", true);
	Victual.Api.Put("mcp/config", { "tools": tools },
		function ()
		{
			input.prop("disabled", false);
			$(document).find("#mcp-settings-message").text(__t(wanted ? "%s is on." : "%s is off.", tool));
		},
		function (xhr)
		{
			input.prop("disabled", false);
			input.prop("checked", !wanted);
			var detail = {};
			try { detail = JSON.parse(xhr.responseText || "{}"); } catch (error) { /* Use the fallback for a body that is not JSON. */ }
			$(document).find("#mcp-settings-message").text(detail.error_message || __t("Could not save the MCP settings."));
		}
	);
});
