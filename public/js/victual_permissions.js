// Shared permission tree. Keep explicit grants separately from effective ticks:
// removing a role must not silently remove an overlapping direct grant.
Victual.PermissionTree = function (canEdit)
{
	var nodes = {};
	$('input.permission-cb').each(function () { nodes[$(this).attr('data-perm-id')] = this; });
	function refresh()
	{
		function held(node)
		{
			var parent = nodes[$(node).attr('data-parent-id')];
			return $(node).attr('data-direct') === '1' || $(node).attr('data-inherited') === '1' || (parent && held(parent));
		}
		Object.keys(nodes).forEach(function (id)
		{
			var node = nodes[id];
			var parent = nodes[$(node).attr('data-parent-id')];
			node.checked = !!held(node);
			node.disabled = !canEdit || $(node).attr('data-inherited') === '1' || !!(parent && held(parent));
		});
	}
	$('input.permission-cb').on('change', function ()
	{
		$(this).attr('data-direct', this.checked ? '1' : '0');
		refresh();
	});
	refresh();
	return function ()
	{
		return Object.keys(nodes).filter(function (id) { return $(nodes[id]).attr('data-direct') === '1'; }).map(Number);
	};
};
