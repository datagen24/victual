// View script for the battery create/edit form (views/batteryform.blade.php):
// saves via POST /api/objects/batteries (create) or PUT /api/objects/batteries/{id}
// (edit, using Victual.EditObjectId) including userfields - all of it the shared form
// factory (public/js/victual_entity.js) - plus grocycode label printing, which is this
// page's own.

Victual.EntityForm({
	form: 'battery-form',
	save: '#save-battery-button',
	endpoint: 'objects/batteries',
	list: '/batteries'
});

// The print action (views/components/label_print_widget.blade.php), plan 32.
Victual.LabelPrinting.Wire({
	kind: 'battery',
	trigger: '#battery-form-button',
	printerSelect: '#battery-form-printer',
	status: '#battery-form-status'
});
