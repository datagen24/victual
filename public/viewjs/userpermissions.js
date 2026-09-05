var hadDirectAdmin = $('input.permission-cb[data-permission-name=ADMIN]').attr('data-direct') === '1';
var selectedPermissions = Victual.PermissionTree(Victual.CanEditPermissions);
function savePermissions(endpoint, body)
{
	Victual.FrontendHelpers.BeginUiBusy('permissions-form');
	Victual.Api.Put(endpoint, body, function () { window.location.reload(); }, function (xhr)
	{
		Victual.FrontendHelpers.EndUiBusy('permissions-form');
		Victual.Api.DefaultErrorHandler(xhr);
	});
}
$('#permission-save').on('click', function ()
{
	function save() { savePermissions('users/' + Victual.EditObjectId + '/permissions', { permissions: selectedPermissions() }); }
	if (Victual.EditObjectId == Victual.UserId && hadDirectAdmin && $('input.permission-cb[data-permission-name=ADMIN]').attr('data-direct') !== '1')
	{
		bootbox.confirm(__t('Are you sure you want to remove full permissions for yourself?'), function (confirmed) { if (confirmed) save(); });
	}
	else save();
});
$('#roles-save').on('click', function ()
{
	savePermissions('users/' + Victual.EditObjectId + '/roles', { roles: ($('#user-roles').val() || []).map(Number) });
});
