// Powers the "edit stock entry" modal form (stockentryform.blade.php). Unlike most forms
// here this is edit-only (PUT stock/entry/{Victual.EditObjectRowId}) - there's no create
// mode, since new stock entries are made via the purchase/consume workflows.

// Validates and submits the form (PUT, always - no create mode), optionally prints a
// label and saves userfields, then notifies the parent window to broadcast the product
// change, show a success toast (with an inline Undo link) and close the modal
$('#save-stockentry-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("stockentry-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonForm = $('#stockentry-form').serializeJSON();
	Victual.FrontendHelpers.BeginUiBusy("stockentry-form");

	if (jsonForm.price)
	{
		price = Number.parseFloat(jsonForm.price).toFixed(Victual.UserSettings.stock_decimal_places_prices_input);
	}

	var jsonData = {};
	jsonData.amount = jsonForm.amount;
	jsonData.best_before_date = Victual.Components.DateTimePicker.GetValue();
	jsonData.purchased_date = Victual.Components.SecondaryDateTimePicker.GetValue();
	jsonData.note = jsonForm.note;
	jsonData.price = price;
	jsonData.open = $("#open").is(":checked");

	if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING)
	{
		jsonData.shopping_location_id = Victual.Components.ShoppingLocationPicker.GetValue();
	}

	if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
	{
		jsonData.location_id = Victual.Components.LocationPicker.GetValue();
	}
	else
	{
		jsonData.location_id = 1;
	}

	Victual.Api.Put("stock/entry/" + Victual.EditObjectRowId, jsonData,
		function(result)
		{
			Victual.EditObjectId = result[0].transaction_id;

			Victual.Components.UserfieldsForm.Save(function()
			{
				var successMessage = __t('Stock entry successfully updated') + '<br><a class="btn btn-secondary btn-sm mt-2" href="#" onclick="UndoStockBookingEntry(\'' + result.id + '\',\'' + Victual.EditObjectRowId + '\')"><i class="fa-solid fa-undo"></i> ' + __t("Undo") + '</a>';

				Victual.GetTopmostWindow().postMessage(WindowMessageBag("BroadcastMessage", WindowMessageBag("ProductChanged", Victual.EditObjectProductId)), Victual.BaseUrl);
				window.parent.postMessage(WindowMessageBag("ShowSuccessMessage", successMessage), Victual.BaseUrl);
				window.parent.postMessage(WindowMessageBag("Ready"), Victual.BaseUrl);
				window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
			});
		},
		function(xhr)
		{
			Victual.FrontendHelpers.EndUiBusy("stockentry-form");
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});

Victual.FrontendHelpers.ValidateForm('stockentry-form');

// Live-validates on every keystroke
$('#stockentry-form input').keyup(function(event)
{
	Victual.FrontendHelpers.ValidateForm('stockentry-form');
});

// Enter key submits the form (if valid) instead of doing a default form submit
$('#stockentry-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('stockentry-form'))
		{
			return false;
		}
		else
		{
			$('#save-stockentry-button').click();
		}
	}
});

// Re-validate as the best-before-date (DateTimePicker) and purchased-date (SecondaryDateTimePicker)
// fields change
Victual.Components.DateTimePicker.GetInputElement().on('change', function(e)
{
	Victual.FrontendHelpers.ValidateForm('stockentry-form');
});

Victual.Components.DateTimePicker.GetInputElement().on('keypress', function(e)
{
	Victual.FrontendHelpers.ValidateForm('stockentry-form');
});

Victual.Components.SecondaryDateTimePicker.GetInputElement().on('change', function(e)
{
	Victual.FrontendHelpers.ValidateForm('stockentry-form');
});

Victual.Components.SecondaryDateTimePicker.GetInputElement().on('keypress', function(e)
{
	Victual.FrontendHelpers.ValidateForm('stockentry-form');
});

// Show the product's stock quantity unit name next to the amount field
Victual.Api.Get('stock/products/' + Victual.EditObjectProductId,
	function(productDetails)
	{
		$('#amount_qu_unit').text(productDetails.quantity_unit_stock.name);
	}
);

// Selects the amount field's content on focus for quick overtyping
$("#amount").on("focus", function(e)
{
	$(this).select();
});

// Initial setup: load userfields, focus the amount field, run initial validation
Victual.Components.UserfieldsForm.Load();
setTimeout(function()
{
	$('#amount').focus();
}, Victual.FormFocusDelay);
Victual.FrontendHelpers.ValidateForm("stockentry-form");

// The print action (views/components/label_print_widget.blade.php), plan 32. Replaces the
// old "reprint on save" checkbox with the standard standalone print action every kind shares.
Victual.LabelPrinting.Wire({
	kind: 'stock_entry',
	trigger: '#stockentry-form-button',
	printerSelect: '#stockentry-form-printer',
	status: '#stockentry-form-status'
});
