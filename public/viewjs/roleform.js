var selectedPermissions = Victual.PermissionTree(Victual.CanEditPermissions);
$('#role-save').on('click', function ()
{
	if (!Victual.FrontendHelpers.ValidateForm('role-form', true)) return;
	Victual.FrontendHelpers.BeginUiBusy('role-form');
	var data = { name: $('#role-name').val(), description: $('#role-description').val() };
	function failed(xhr)
	{
		Victual.FrontendHelpers.EndUiBusy('role-form');
		Victual.Api.DefaultErrorHandler(xhr);
	}
	function saveGrants()
	{
		Victual.Api.Put('roles/' + Victual.EditObjectId + '/permissions', { permissions: selectedPermissions() }, function () { window.location.href = U('/roles'); }, failed);
	}
	if (Victual.EditObjectId)
	{
		Victual.Api.Put('roles/' + Victual.EditObjectId, data, saveGrants, failed);
	}
	else
	{
		data.code = $('#role-code').val();
		Victual.Api.Post('roles', data, function (result)
		{
			Victual.EditObjectId = result.created_object_id;
			$('#role-code').prop('disabled', true);
			saveGrants();
		}, failed);
	}
});
