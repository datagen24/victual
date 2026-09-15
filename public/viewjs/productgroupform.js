// Powers the product group create/edit form (views/productgroupform.blade.php), usually
// shown embedded in a modal: saves the product group via the objects/product_groups API,
// through the shared form factory (public/js/victual_entity.js).
//
// Plan 12 step 4: this form used to post "CloseLastModal" after a successful save, and
// its list reloaded the whole page on that message. It now posts "Reload" the way
// locationform, batteryform, taskcategoryform and the rest do - which also fixes the
// standalone page, `/productgroup/{id}`. That had no embedded branch at all, so a save
// outside a dialog succeeded and then sat there with every input still disabled by
// BeginUiBusy, because nothing navigated afterwards.

Victual.EntityForm({
	form: 'product-group-form',
	save: '#save-product-group-button',
	endpoint: 'objects/product_groups',
	list: '/productgroups',
	body: function (jsonData)
	{
		// serializeJSON() hands back the empty string for an unselected <select>, and the
		// column is a nullable integer: "" would be written as a parent id of 0, which is a
		// product group that does not exist. A root group posts null - the same fix
		// locationform.js applies to parent_location_id (issue #159).
		jsonData.parent_product_group_id = jsonData.parent_product_group_id === '' || jsonData.parent_product_group_id === undefined
			? null
			: parseInt(jsonData.parent_product_group_id, 10);

		return jsonData;
	}
});
