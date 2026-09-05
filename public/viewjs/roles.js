Victual.EntityList({
	table: '#roles-table', list: '/roles',
	delete: { button: '.role-delete-button', idAttr: 'data-role-id', nameAttr: 'data-role-name', endpoint: 'roles', message: 'Are you sure you want to delete role "%s"?' }
});
