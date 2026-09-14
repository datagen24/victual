// Product edit/create form logic: submits objects/products (create) or objects/products/{id}
// (edit) via the REST API, then manages the product's picture upload/deletion, its
// quantity-unit conversions and barcodes tables, and various field prefill/preset behaviors.

/**
 * Second step of saving a product: called after the product object itself has been created/updated.
 * Saves the product userfields, then either uploads the newly selected picture file (named
 * jsonData.picture_file_name, computed in the save-button handler) or - if no new picture was
 * selected - just proceeds. Finally redirects the browser according to Victual.ProductEditFormRedirectUri
 * / the closeAfterCreation, returnto and flow URL params, or otherwise to the product's edit/detail page.
 * @param {Object} result The API response from the objects/products POST/PUT call (used for created_object_id on create)
 * @param {string} location Base redirect path ('/products?product=' or '/product/'), with the product id appended
 * @param {Object} jsonData The serialized product form data that was just submitted (used here for picture_file_name)
 */
function saveProductPicture(result, location, jsonData)
{
	var productId = Victual.EditObjectId || result.created_object_id;
	Victual.EditObjectId = productId; // Victual.EditObjectId is not yet set when adding a product

	Victual.Components.UserfieldsForm.Save(() =>
	{
		if (jsonData.hasOwnProperty("picture_file_name") && !Victual.DeleteProductPictureOnSave)
		{
			// A new picture file was selected: upload it to the productpictures file group, then redirect
			Victual.Api.UploadFile($("#product-picture")[0].files[0], 'productpictures', jsonData.picture_file_name,
				(result) =>
				{
					if (Victual.ProductEditFormRedirectUri == "reload")
					{
						window.location.reload();
						return;
					}

					var returnTo = GetUriParam('returnto');
					if (GetUriParam("closeAfterCreation") !== undefined)
					{
						window.close();
					}
					else if (returnTo !== undefined)
					{
						if (GetUriParam("flow") !== undefined)
						{
							window.location.href = U(returnTo) + '&product-name=' + encodeURIComponent($('#name').val());
						}
						else
						{
							window.location.href = U(returnTo);
						}

					}
					else
					{
						window.location.href = U(location + productId);
					}

				},
				(xhr) =>
				{
					Victual.FrontendHelpers.EndUiBusy("product-form");
					Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
				}
			);
		}
		else
		{
			// No new picture to upload (picture unchanged or deleted): redirect right away
			if (Victual.ProductEditFormRedirectUri == "reload")
			{
				window.location.reload();
				return
			}

			var returnTo = GetUriParam('returnto');
			if (GetUriParam("closeAfterCreation") !== undefined)
			{
				window.close();
			}
			else if (returnTo !== undefined)
			{
				if (GetUriParam("flow") !== undefined)
				{
					window.location.href = U(returnTo) + '&product-name=' + encodeURIComponent($('#name').val());
				}
				else
				{
					window.location.href = U(returnTo);
				}
			}
			else
			{
				window.location.href = U(location + productId);
			}
		}
	});
}

// Form submit: serializes #product-form, POSTs a new product or PUTs the edited one, then hands
// off to saveProductPicture() for the picture upload/redirect step. Two submit buttons share this
// handler ("Save & continue" vs "Save & return to products"), distinguished via data-location.
$('.save-product-button').on('click', function (e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("product-form", true))
	{
		return;
	}

	var jsonData = $('#product-form').serializeJSON();
	// The parent product picker's field is named "product_id" in the form but the API expects "parent_product_id"
	var parentProductId = jsonData.product_id;
	delete jsonData.product_id;
	jsonData.parent_product_id = parentProductId;
	Victual.FrontendHelpers.BeginUiBusy("product-form");

	if ($("#product-picture")[0].files.length > 0)
	{
		// A new picture was selected: generate a random, sanitized file name for it (actual upload happens in saveProductPicture)
		jsonData.picture_file_name = RandomString() + CleanFileName($("#product-picture")[0].files[0].name);
	}

	// "Save & continue" keeps editing the same product; "Save & return to products" redirects back to the product list
	const location = $(e.currentTarget).attr('data-location') == 'return' ? '/products?product=' : '/product/';

	if (Victual.EditMode == 'create')
	{
		Victual.Api.Post('objects/products', jsonData,
			(result) => saveProductPicture(result, location, jsonData),
			(xhr) =>
			{
				Victual.FrontendHelpers.EndUiBusy("product-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			});
		return;
	}

	if (Victual.DeleteProductPictureOnSave)
	{
		// User marked the current picture for deletion: clear it server-side and delete the file itself
		jsonData.picture_file_name = null;

		Victual.Api.DeleteFile(Victual.ProductPictureFileName, 'productpictures',
			function (result)
			{
				// Nothing to do
			},
			function (xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("product-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}

	Victual.Api.Put('objects/products/' + Victual.EditObjectId, jsonData,
		(result) => saveProductPicture(result, location, jsonData),
		function (xhr)
		{
			Victual.FrontendHelpers.EndUiBusy("product-form");
			Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
		}
	);
});

// "Create new product with name" in-place flow (e.g. triggered from a product picker's "create new" option): prefill the name from the URL
if (GetUriParam("flow") == "InplaceNewProductWithName")
{
	$('#name').val(GetUriParam("name"));
}

// When arriving via an external flow/return URL, hide the "return to products" save option and its hint (only "continue" makes sense there)
if (GetUriParam("flow") !== undefined || GetUriParam("returnto") !== undefined)
{
	$("#save-hint").addClass("d-none");
	$(".save-product-button[data-location='return']").addClass("d-none");
}

// Keep the "per <QU stock>" info texts (tare weight, quick consume/open, energy) in sync with the selected stock quantity unit
$('.input-group-qu').on('change', function (e)
{
	$("#tare_weight_qu_info").text($("#qu_id_stock option:selected").text());
	$("#quick_consume_qu_info").text($("#qu_id_stock option:selected").text());
	$("#quick_open_qu_info").text($("#qu_id_stock option:selected").text());
	$("#energy_qu_info").text(Victual.EnergyUnit + " / " + $("#qu_id_stock option:selected").text());

	Victual.FrontendHelpers.ValidateForm('product-form');
});

// Re-validate on every keystroke and enable/disable the (embedded, dialog-only) QU conversion/barcode "Add" buttons accordingly
$('#product-form input').keyup(function (event)
{
	Victual.FrontendHelpers.ValidateForm('product-form');
	$(".input-group-qu").trigger("change");
	$("#product-form select").trigger("select");

	if (!Victual.FrontendHelpers.ValidateForm('product-form'))
	{
		$("#qu-conversion-add-button").addClass("disabled");
		$("#barcode-add-button").addClass("disabled");
	}
	else
	{
		$("#qu-conversion-add-button").removeClass("disabled");
	}
});

$('#location_id').change(function (event)
{
	Victual.FrontendHelpers.ValidateForm('product-form');
});

// Enter key anywhere in the form triggers the primary/default submit button
$('#product-form input').keydown(function (event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('product-form'))
		{
			return false;
		}
		else
		{
			$('.default-submit-button').click();
		}
	}
});

// Enable/disable the tare weight input alongside its checkbox
$("#enable_tare_weight_handling").on("click", function ()
{
	if (this.checked)
	{
		$("#tare_weight").removeAttr("disabled");
	}
	else
	{
		$("#tare_weight").attr("disabled", "");
	}

	Victual.FrontendHelpers.ValidateForm("product-form");
});

// Selecting a new picture file cancels any pending "delete current picture" state and updates the file input label
$("#product-picture").on("change", function (e)
{
	$("#product-picture-label").removeClass("d-none");
	$("#product-picture-label-none").addClass("d-none");
	$("#delete-current-product-picture-on-save-hint").addClass("d-none");
	$("#current-product-picture").addClass("d-none");
	Victual.DeleteProductPictureOnSave = false;
});

// Victual.DeleteProductPictureOnSave flags the current picture for deletion on the next save (actually deleted/cleared in the save handlers above)
Victual.DeleteProductPictureOnSave = false;
$("#delete-current-product-picture-button").on("click", function (e)
{
	Victual.DeleteProductPictureOnSave = true;
	$("#current-product-picture").addClass("d-none");
	$("#delete-current-product-picture-on-save-hint").removeClass("d-none");
	$("#product-picture-label").addClass("d-none");
	$("#product-picture-label-none").removeClass("d-none");
});

// Product specific QU conversions table (edit mode only): sorted by "Quantity unit from", first column (row actions) not orderable/searchable
var quConversionsTable = $('#qu-conversions-table-products').DataTable({
	'order': [[1, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#qu-conversions-table-products tbody').removeClass("d-none");
quConversionsTable.columns.adjust().draw();

// Barcodes table (edit mode only): sorted/fixed-sorted by barcode, row actions column not orderable/searchable, store/qu columns (indices 5/6) hidden by default
var barcodeTable = $('#barcode-table').DataTable({
	'order': [[1, 'asc']],
	"orderFixed": [[1, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 },
		{ 'visible': false, 'targets': 5 },
		{ 'visible': false, 'targets': 6 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#barcode-table tbody').removeClass("d-none");
barcodeTable.columns.adjust().draw();

// Initial page setup: load the products userfields, prime the QU info texts/validation state and focus the name field
Victual.Components.UserfieldsForm.Load();
$("#name").trigger("keyup");
$('.input-group-qu').trigger('change');
Victual.FrontendHelpers.ValidateForm('product-form');
setTimeout(function ()
{
	$('#name').focus();
}, Victual.FormFocusDelay);

// Print the product's grocycode via the configured label printer webhook
$(document).on('click', '.product-grocycode-label-print', function (e)
{
	e.preventDefault();

	var productId = $(e.currentTarget).attr('data-product-id');
	Victual.Api.Get('stock/products/' + productId + '/printlabel', function (labelData)
	{
		if (Victual.Webhooks.labelprinter !== undefined)
		{
			Victual.FrontendHelpers.RunWebhook(Victual.Webhooks.labelprinter, labelData);
		}
	});
});

// Delete a QU conversion row (after confirmation), then reload the page by re-submitting the product form with a "reload" redirect
$(document).on('click', '.qu-conversion-delete-button', function (e)
{
	var objectId = $(e.currentTarget).attr('data-qu-conversion-id');

	bootbox.confirm({
		message: __t('Are you sure you want to remove this conversion?'),
		closeButton: false,
		buttons: {
			confirm: {
				label: __t('Yes'),
				className: 'btn-success'
			},
			cancel: {
				label: __t('No'),
				className: 'btn-danger'
			}
		},
		callback: function (result)
		{
			if (result === true)
			{
				Victual.Api.Delete('objects/quantity_unit_conversions/' + objectId, {},
					function (result)
					{
						Victual.ProductEditFormRedirectUri = "reload";
						$('#save-product-button').click();
					}
				);
			}
		}
	});
});

// Delete a barcode row (after confirmation), then reload the page by re-submitting the product form with a "reload" redirect
$(document).on('click', '.barcode-delete-button', function (e)
{
	var objectId = $(e.currentTarget).attr('data-barcode-id');

	bootbox.confirm({
		message: __t('Are you sure you want to remove this barcode?'),
		closeButton: false,
		buttons: {
			confirm: {
				label: __t('Yes'),
				className: 'btn-success'
			},
			cancel: {
				label: __t('No'),
				className: 'btn-danger'
			}
		},
		callback: function (result)
		{
			if (result === true)
			{
				Victual.Api.Delete('objects/product_barcodes/' + objectId, {},
					function (result)
					{
						Victual.ProductEditFormRedirectUri = "reload";
						$('#save-product-button').click();
					}
				);
			}
		}
	});
});

// Remembers the previously selected stock QU so the handler below can tell "still following stock QU" apart from "user picked their own value"
var quIdStockBefore = $("#qu_id_stock").val();
$('#qu_id_stock').change(function (e)
{
	// Preset qu_id_purchase / qu_id_consume / qu_id_price by qu_id_stock if unset or identical
	// For each of the 3 dependent QU selects: adopt the new stock QU selection when that select is
	// still empty (selectedIndex 0), or when its current value still equals the *previous* stock QU
	// value (i.e. it was never manually overridden away from following the stock QU) - this way a
	// later change to qu_id_stock keeps propagating, but a deliberately-different user choice sticks.

	var quIdStock = $('#qu_id_stock');
	var quIdPurchase = $('#qu_id_purchase');
	var quIdConsume = $('#qu_id_consume');
	var quIdPrice = $('#qu_id_price');

	if (quIdPurchase[0].selectedIndex === 0 && quIdStock[0].selectedIndex !== 0 || quIdStockBefore == quIdPurchase.val())
	{
		quIdPurchase[0].selectedIndex = quIdStock[0].selectedIndex;
	}

	if (quIdConsume[0].selectedIndex === 0 && quIdStock[0].selectedIndex !== 0 || quIdStockBefore == quIdConsume.val())
	{
		quIdConsume[0].selectedIndex = quIdStock[0].selectedIndex;
	}

	if (quIdPrice[0].selectedIndex === 0 && quIdStock[0].selectedIndex !== 0 || quIdStockBefore == quIdPrice.val())
	{
		quIdPrice[0].selectedIndex = quIdStock[0].selectedIndex;
	}

	quIdStockBefore = quIdStock.val();

	Victual.FrontendHelpers.ValidateForm('product-form');
});

// Reload the page when a QU-conversion/barcode dialog (opened via show-as-dialog-link, see the related-links buttons in the Blade view) posts back that it changed something
$(window).on("message", function (e)
{
	var data = e.originalEvent.data;

	if (data.Message === "ProductBarcodesChanged" || data.Message === "ProductQUConversionChanged")
	{
		window.location.reload();
	}
});

// "Duplicate product" flow (?copy-of=<id> on the create form): fetch the source product and copy
// its field values into this (still empty) create form. Deliberately omits identity/display fields
// (name, picture, barcodes, QU conversions) so the user creates a distinct new product.
if (Victual.EditMode == "create" && GetUriParam("copy-of") != undefined)
{
	Victual.Api.Get('objects/products/' + GetUriParam("copy-of"),
		function (sourceProduct)
		{
			if (sourceProduct.parent_product_id != null)
			{
				Victual.Components.ProductPicker.SetId(sourceProduct.parent_product_id);
			}
			if (sourceProduct.description)
			{
				$("#description").summernote("pasteHTML", sourceProduct.description);
			}
			$("#location_id").val(sourceProduct.location_id);
			if (sourceProduct.shopping_location_id != null)
			{
				Victual.Components.ShoppingLocationPicker.SetId(sourceProduct.shopping_location_id);
			}
			$("#min_stock_amount").val(sourceProduct.min_stock_amount);
			if (BoolVal(sourceProduct.cumulate_min_stock_amount_of_sub_products))
			{
				$("#cumulate_min_stock_amount_of_sub_products").prop("checked", true);
			}
			$("#default_best_before_days").val(sourceProduct.default_best_before_days);
			$("#default_best_before_days_after_open").val(sourceProduct.default_best_before_days_after_open);
			if (sourceProduct.product_group_id != null)
			{
				$("#product_group_id").val(sourceProduct.product_group_id);
			}
			$("#qu_id_stock").val(sourceProduct.qu_id_stock);
			$("#qu_id_purchase").val(sourceProduct.qu_id_purchase);
			if (BoolVal(sourceProduct.enable_tare_weight_handling))
			{
				$("#enable_tare_weight_handling").prop("checked", true);
			}
			$("#tare_weight").val(sourceProduct.tare_weight);
			if (BoolVal(sourceProduct.not_check_stock_fulfillment_for_recipes))
			{
				$("#not_check_stock_fulfillment_for_recipes").prop("checked", true);
			}
			if (sourceProduct.calories != null)
			{
				$("#calories").val(sourceProduct.calories);
			}
			$("#default_best_before_days_after_freezing").val(sourceProduct.default_best_before_days_after_freezing);
			$("#default_best_before_days_after_thawing").val(sourceProduct.default_best_before_days_after_thawing);
			$("#quick_consume_amount").val(sourceProduct.quick_consume_amount);
			$("#quick_open_amount").val(sourceProduct.quick_open_amount);
			$("#default_consume_location_id").val(sourceProduct.default_consume_location_id);
			$("#quick_refill_amount").val(sourceProduct.quick_refill_amount);
			$("#default_refill_location_id_from").val(sourceProduct.default_refill_location_id_from);
			$("#default_refill_location_id_to").val(sourceProduct.default_refill_location_id_to);
			if (BoolVal(sourceProduct.no_own_stock))
			{
				$("#no_own_stock").prop("checked", true);
			}
			if (BoolVal(sourceProduct.hide_on_stock_overview))
			{
				$("#hide_on_stock_overview").prop("checked", true);
			}
			if (BoolVal(sourceProduct.auto_reprint_stock_label))
			{
				$("#auto_reprint_stock_label").prop("checked", true);
			}
			$("#default_stock_label_type").val(sourceProduct.default_stock_label_type);
			if (BoolVal(sourceProduct.move_on_open))
			{
				$("#move_on_open").prop("checked", true);
			}
			if (BoolVal(sourceProduct.treat_opened_as_out_of_stock))
			{
				$("#treat_opened_as_out_of_stock").prop("checked", true);
			}

			Victual.FrontendHelpers.ValidateForm('product-form');
		}
	);
}
// Plain create form (not a copy): apply the user's configured product presets (product_presets_* user
// settings, "-1"/"0" meaning "no preset") as initial field values
else if (Victual.EditMode === 'create')
{
	if (Victual.UserSettings.product_presets_location_id.toString() !== '-1')
	{
		$("#location_id").val(Victual.UserSettings.product_presets_location_id);
	}

	if (Victual.UserSettings.product_presets_product_group_id.toString() !== '-1')
	{
		$("#product_group_id").val(Victual.UserSettings.product_presets_product_group_id);
	}

	if (Victual.UserSettings.product_presets_qu_id.toString() !== '-1')
	{
		$("select.input-group-qu").val(Victual.UserSettings.product_presets_qu_id);
	}

	if (Victual.UserSettings.product_presets_default_due_days.toString() !== '0')
	{
		$("#default_best_before_days").val(Victual.UserSettings.product_presets_default_due_days);
	}

	if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_PRODUCT_OPENED_TRACKING)
	{
		$("#treat_opened_as_out_of_stock").prop("checked", BoolVal(Victual.UserSettings.product_presets_treat_opened_as_out_of_stock));
	}

	if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_LABEL_PRINTER)
	{
		$("#default_stock_label_type").val(Victual.UserSettings.product_presets_default_stock_label_type);
	}
}

// When a parent product is selected, disable this product's own min_stock_amount field if the parent
// already cumulates its sub products' min stock amounts (see "cumulate_min_stock_amount_of_sub_products")
Victual.Components.ProductPicker.GetPicker().on('change', function (e)
{
	var parentProductId = $(e.target).val();

	if (parentProductId)
	{
		Victual.Api.Get('objects/products/' + parentProductId,
			function (parentProduct)
			{
				if (BoolVal(parentProduct.cumulate_min_stock_amount_of_sub_products))
				{

					$("#min_stock_amount").attr("disabled", "");
				}
				else
				{
					$('#min_stock_amount').removeAttr("disabled");
				}
			}
		);
	}
	else
	{
		$('#min_stock_amount').removeAttr("disabled");
	}
});

Victual.FrontendHelpers.ValidateForm("product-form");
Victual.Components.ProductPicker.GetPicker().trigger("change"); // Apply the min_stock_amount enable/disable state for a preselected parent product

// In edit mode "Save & continue" is no longer the default (Enter-triggered) submit button, since
// on create it double as "create & then edit further"; on edit both buttons behave the same way
if (Victual.EditMode == "edit")
{
	$(".save-product-button").toggleClass("default-submit-button");
}
