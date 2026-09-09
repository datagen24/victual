// The label template list: create one, then design it.
//
// A template with no published version is called out rather than shown as ordinary, because
// printing pins a published version and there is deliberately no fallback to the draft or to
// "the latest" - so an unpublished template is a design nothing can print from yet.

$(document).find('#create-template-button').on('click', function()
{
	var name = $(document).find('#new-template-name').val();
	var region = document.getElementById('label-templates-message');

	function report(kind, text)
	{
		while (region.firstChild !== null)
		{
			region.removeChild(region.firstChild);
		}
		var box = document.createElement('div');
		box.className = 'alert alert-' + kind;
		box.appendChild(document.createTextNode(text));
		region.appendChild(box);
	}

	if (!name)
	{
		report('danger', __t('A template needs a name'));
		return;
	}

	Victual.Api.Post('labels/templates', { 'name': name, 'entity_kind': 'location' },
		function(template)
		{
			window.location.href = U('/labeltemplate/' + template.id);
		},
		function(xhr)
		{
			// responseText, not response: the wrapper hands over the raw request.
			var body = {};
			try { body = JSON.parse(xhr.responseText || '{}'); } catch (error) { /* fall through */ }
			report('danger', body.error_message || __t('The template was not created'));
		});
});
