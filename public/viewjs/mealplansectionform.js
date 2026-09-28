// View script for the meal plan section create/edit form (views/mealplansectionform.blade.php):
// saves a meal plan section via the objects/meal_plan_sections API endpoints, through the
// shared form factory (public/js/victual_entity.js). A meal plan section carries no
// userfields, so the factory's userfields round trip is switched off.
//
// This form's Enter-to-submit used to click `#save-mealplansections-button` - plural,
// and no such element exists - so Enter did nothing. The factory calls the save function
// directly rather than a button selector, so it cannot drift that way again.

Victual.EntityForm({
	form: 'mealplansection-form',
	save: '#save-mealplansection-button',
	endpoint: 'objects/meal_plan_sections',
	list: '/mealplansections',
	userfields: false,
	comboboxGuard: false,
	// serializeJSON() hands back the empty string for a blank numberpicker
	// (views/components/numberpicker.blade.php is a plain <input type="number">), and
	// sort_number is a nullable integer: "" is not a valid integer literal, so the generic
	// entity endpoint's write was refused (400, issue #574) instead of leaving the column
	// NULL. Same reasoning as locationform.js's parent_location_id/storage_class_id/
	// tare_weight handling.
	body: function (jsonData)
	{
		jsonData.sort_number = jsonData.sort_number === '' || jsonData.sort_number === undefined
			? null
			: parseInt(jsonData.sort_number, 10);

		return jsonData;
	}
});
