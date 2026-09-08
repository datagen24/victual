// View script for the locations list (views/locations.blade.php):
// DataTables listing of all stock locations with search, delete and a "show disabled" filter.
// Everything here is the shared list factory (public/js/victual_entity.js), which owns the
// DataTable wiring, the search box, the "show disabled" toggle and the delete confirmation
// - including escaping the location name into that confirmation's message.

Victual.EntityList({
	table: '#locations-table',
	list: '/locations',
	showDisabled: true,
	delete: {
		button: '.location-delete-button',
		idAttr: 'data-location-id',
		nameAttr: 'data-location-name',
		endpoint: 'objects/locations',
		message: 'Are you sure you want to delete location "%s"?'
	}
});

// The print action, wired to the list's buttons and the page's printer chooser. The logic
// itself is Victual.LabelPrinting (public/js/victual_label_print.js), because the location
// form offers the same action and two copies of an idempotency rule is one too many.
Victual.LabelPrinting.Wire({
	trigger: '.location-print-button',
	within: '#locations-table',
	printerSelect: '#location-label-printer',
	status: '#location-print-status'
});
