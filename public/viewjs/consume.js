// View script for consume.blade.php - the stock "Consume"/"Open" form. Wires up the
// ProductPicker, ProductAmountPicker, RecipePicker and LocationPicker components, drives the
// specific-stock-entry dropdown, tare-weight/min-max handling and scan mode, and posts
// consumption/opening bookings to the stock API.
// Consumes stock: POST stock/products/{id}/consume. Reads the amount/exact-amount/spoiled/
// specific-stock-entry/location/recipe fields from the form and, when embedded, notifies the
// parent window instead of resetting the form in place.

// Before anything can empty the select: choosing a product rebuilds it from the product's
// stock locations, which the API names rather than paths, and a bare name is unique only
// among siblings now. See Victual.FrontendHelpers.RememberLocationPaths.
Victual.FrontendHelpers.RememberLocationPaths('#location_id');

$('#save-consume-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("consume-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonForm = $('#consume-form').serializeJSON();
	Victual.FrontendHelpers.BeginUiBusy("consume-form");

	var apiUrl = 'stock/products/' + jsonForm.product_id + '/consume';

	var jsonData = {};
	jsonData.amount = jsonForm.amount;
	jsonData.exact_amount = $('#consume-exact-amount').is(':checked');
	jsonData.spoiled = $('#spoiled').is(':checked');
	jsonData.allow_subproduct_substitution = true;

	if ($("#use_specific_stock_entry").is(":checked"))
	{
		jsonData.stock_entry_id = jsonForm.specific_stock_entry;
	}

	if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
	{
		jsonData.location_id = $("#location_id").val();
	}

	if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_RECIPES && Victual.Components.RecipePicker.GetValue().toString().length > 0)
	{
		jsonData.recipe_id = Victual.Components.RecipePicker.GetValue();
	}

	var bookingResponse = null;
	Victual.Api.Get('stock/products/' + jsonForm.product_id,
		function(productDetails)
		{
			Victual.Api.Post(apiUrl, jsonData,
				function(result)
				{
					if (BoolVal(Victual.UserSettings.scan_mode_consume_enabled))
					{
						Victual.UISound.Success();
					}

					bookingResponse = result;

					// "Add barcode to existing product" workflow: after consuming, also attach
					// the scanned barcode (that didn't resolve to a product) to the chosen product
					if (GetUriParam("flow") === "InplaceAddBarcodeToExistingProduct")
					{
						var jsonDataBarcode = {};
						jsonDataBarcode.barcode = GetUriParam("barcode");
						jsonDataBarcode.product_id = jsonForm.product_id;

						Victual.Api.Post('objects/product_barcodes', jsonDataBarcode,
							function(result)
							{
								$("#flow-info-InplaceAddBarcodeToExistingProduct").addClass("d-none");
								$('#barcode-lookup-disabled-hint').addClass('d-none');
								$('#barcode-lookup-hint').removeClass('d-none');
								window.history.replaceState({}, document.title, U("/consume"));
							},
							function(xhr)
							{
								Victual.FrontendHelpers.EndUiBusy("consume-form");
								Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
							}
						);
					}

					$("#specific_stock_entry").find("option").remove().end().append("<option></option>");
					if ($("#use_specific_stock_entry").is(":checked"))
					{
						$("#use_specific_stock_entry").click();
					}

					// Tare-weight products consume "amount minus tare/current weight" unless an exact amount was entered
					if (productDetails.product.enable_tare_weight_handling == 1 && !jsonData.exact_amount)
					{
						// The product and quantity unit names go into a toastr message, which
						// is rendered as HTML - they are text columns and are escaped here, at
						// the point of use (sweep finding S29). The Undo anchor below is
						// deliberate markup, which is why toastr.options.escapeHtml is not the
						// fix.
						var successMessage = __t('Removed %1$s of %2$s from stock', Math.abs(jsonForm.amount - (productDetails.product.tare_weight + productDetails.stock_amount)) + " " + __n(jsonForm.amount, Victual.FrontendHelpers.EscapeHtml(productDetails.quantity_unit_stock.name), Victual.FrontendHelpers.EscapeHtml(productDetails.quantity_unit_stock.name_plural), true), Victual.FrontendHelpers.EscapeHtml(productDetails.product.name)) + '<br><a class="btn btn-secondary btn-sm mt-2" href="#" onclick="UndoStockTransaction(\'' + bookingResponse[0].transaction_id + '\')"><i class="fa-solid fa-undo"></i> ' + __t("Undo") + '</a>';
					}
					else
					{
						var successMessage = __t('Removed %1$s of %2$s from stock', Math.abs(jsonForm.amount) + " " + __n(jsonForm.amount, Victual.FrontendHelpers.EscapeHtml(productDetails.quantity_unit_stock.name), Victual.FrontendHelpers.EscapeHtml(productDetails.quantity_unit_stock.name_plural), true), Victual.FrontendHelpers.EscapeHtml(productDetails.product.name)) + '<br><a class="btn btn-secondary btn-sm mt-2" href="#" onclick="UndoStockTransaction(\'' + bookingResponse[0].transaction_id + '\')"><i class="fa-solid fa-undo"></i> ' + __t("Undo") + '</a>';
					}

					// When embedded (e.g. in a modal iframe), notify the parent window instead of
					// updating this page's own form
					if (GetUriParam("embedded") !== undefined)
					{
						Victual.GetTopmostWindow().postMessage(WindowMessageBag("BroadcastMessage", WindowMessageBag("ProductChanged", jsonForm.product_id)), Victual.BaseUrl);
						window.parent.postMessage(WindowMessageBag("ShowSuccessMessage", successMessage), Victual.BaseUrl);
						window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
					}
					else
					{
						// Reset the form for the next entry (scan mode / repeated consumption)
						Victual.FrontendHelpers.EndUiBusy("consume-form");
						toastr.success(successMessage);
						Victual.Components.ProductPicker.FinishFlow();

						Victual.Components.ProductAmountPicker.Reset();
						$("#display_amount").attr("min", Victual.DefaultMinAmount);
						$("#display_amount").removeAttr("max");
						if (BoolVal(Victual.UserSettings.stock_default_consume_amount_use_quick_consume_amount))
						{
							$('#display_amount').val(productDetails.product.quick_consume_amount * $("#qu_id option:selected").attr("data-qu-factor"));
						}
						else
						{
							$('#display_amount').val(Victual.UserSettings.stock_default_consume_amount);
						}
						RefreshLocaleNumberInput();
						$(".input-group-productamountpicker").trigger("change");
						$("#tare-weight-handling-info").addClass("d-none");
						Victual.Components.ProductPicker.Clear();
						if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_RECIPES)
						{
							Victual.Components.RecipePicker.Clear();
						}
						if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
						{
							$("#location_id").find("option").remove().end().append("<option></option>");
						}
						Victual.Components.ProductPicker.GetInputElement().focus();
						Victual.Components.ProductCard.Refresh(jsonForm.product_id);
						Victual.FrontendHelpers.ValidateForm('consume-form');
						$("#consume-exact-amount-group").addClass("d-none");
					}
				},
				function(xhr)
				{
					Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
					Victual.FrontendHelpers.EndUiBusy("consume-form");
				}
			);
		},
		function(xhr)
		{
			Victual.FrontendHelpers.EndUiBusy("consume-form");
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});

// Marks stock as opened without consuming it: POST stock/products/{id}/open. Reads amount and
// (optionally) the specific-stock-entry field the same way the consume handler does.
$('#save-mark-as-open-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("consume-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonForm = $('#consume-form').serializeJSON();
	Victual.FrontendHelpers.BeginUiBusy("consume-form");

	var apiUrl = 'stock/products/' + jsonForm.product_id + '/open';

	jsonData = {};
	jsonData.amount = jsonForm.amount;
	jsonData.allow_subproduct_substitution = true;

	if ($("#use_specific_stock_entry").is(":checked"))
	{
		jsonData.stock_entry_id = jsonForm.specific_stock_entry;
	}

	Victual.Api.Get('stock/products/' + jsonForm.product_id,
		function(productDetails)
		{
			Victual.Api.Post(apiUrl, jsonData,
				function(result)
				{
					$("#specific_stock_entry").find("option").remove().end().append("<option></option>");
					if ($("#use_specific_stock_entry").is(":checked"))
					{
						$("#use_specific_stock_entry").click();
					}

					Victual.FrontendHelpers.EndUiBusy("consume-form");
					toastr.success(__t('Marked %1$s of %2$s as opened', Number.parseFloat(jsonForm.amount).toLocaleString({ minimumFractionDigits: 0, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_amounts }) + " " + __n(jsonForm.amount, Victual.FrontendHelpers.EscapeHtml(productDetails.quantity_unit_stock.name), Victual.FrontendHelpers.EscapeHtml(productDetails.quantity_unit_stock.name_plural), true), Victual.FrontendHelpers.EscapeHtml(productDetails.product.name)) + '<br><a class="btn btn-secondary btn-sm mt-2" href="#" onclick="UndoStockTransaction(\'' + result[0].transaction_id + '\')"><i class="fa-solid fa-undo"></i> ' + __t("Undo") + '</a>');

					// Inform the user when opening also relocated the stock entry (product's move_on_open setting)
					if (productDetails.product.move_on_open == 1 && productDetails.default_consume_location != null)
					{
						toastr.info('<span>' + __t("Moved to %1$s", Victual.FrontendHelpers.EscapeHtml(productDetails.default_consume_location.name)) + "</span> <i class='fa-solid fa-exchange-alt'></i>");
					}

					if (BoolVal(Victual.UserSettings.stock_default_consume_amount_use_quick_consume_amount))
					{
						$('#display_amount').val(productDetails.product.quick_consume_amount * $("#qu_id option:selected").attr("data-qu-factor"));
					}
					else
					{
						$('#display_amount').val(Victual.UserSettings.stock_default_consume_amount);
					}
					RefreshLocaleNumberInput();
					$(".input-group-productamountpicker").trigger("change");
					Victual.Components.ProductPicker.Clear();
					Victual.Components.ProductPicker.GetInputElement().focus();
					Victual.FrontendHelpers.ValidateForm('consume-form');
				},
				function(xhr)
				{
					Victual.FrontendHelpers.EndUiBusy("consume-form");
					Victual.Api.DefaultErrorHandler(xhr);
				}
			);
		},
		function(xhr)
		{
			Victual.FrontendHelpers.EndUiBusy("consume-form");
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});
var sumValue = 0;
// Location selector changed: rebuilds the specific-stock-entry dropdown for the new location.
// When embedded with a pre-selected stock entry (stockId URI param) or when the product was
// scanned via Grocycode (which encodes a specific stock_id), that entry is auto-selected.
$("#location_id").on('change', function(e)
{
	var locationId = $(e.target).val();
	$("#specific_stock_entry").find("option").remove().end().append("<option></option>");
	if ($("#use_specific_stock_entry").is(":checked"))
	{
		$("#use_specific_stock_entry").click();
	}

	if (GetUriParam("embedded") !== undefined)
	{
		OnLocationChange(locationId, GetUriParam('stockId'));
	}
	else
	{
		// try to get stock id from Grocycode
		if ($("#product_id").data("grocycode"))
		{
			var gc = $("#product_id").attr("barcode").split(":");
			if (gc.length == 4)
			{
				Victual.Api.Get("stock/products/" + Victual.Components.ProductPicker.GetValue() + '/entries?query[]=stock_id=' + gc[3],
					function(stockEntries)
					{
						OnLocationChange(stockEntries[0].location_id, gc[3]);
						$('#display_amount').val(stockEntries[0].amount);
					}
				);
			}
			else
			{
				OnLocationChange(locationId, null);
			}
		}
		else
		{
			OnLocationChange(locationId, null);
		}
	}
});

/**
 * Populates #specific_stock_entry with the stock entries at the given location
 * (GET stock/products/{id}/entries), tallies sumValue (total available amount there),
 * refreshes the product details/form, and auto-submits in scan mode when the product was
 * identified unambiguously via barcode or Grocycode.
 * @param {number} locationId Location to show stock entries for.
 * @param {number|null} stockId Stock entry id to pre-select, if known.
 */
function OnLocationChange(locationId, stockId)
{
	sumValue = 0;

	if (locationId)
	{
		if ($("#location_id").val() != locationId)
		{
			$("#location_id").val(locationId);
		}

		Victual.Api.Get("stock/products/" + Victual.Components.ProductPicker.GetValue() + '/entries?include_sub_products=true',
			function(stockEntries)
			{
				stockEntries.forEach(stockEntry =>
				{
					var openTxt = __t("Not opened");
					if (stockEntry.open == 1)
					{
						openTxt = __n(stockEntry.amount, "Opened", "Opened");
					}

					if (stockEntry.location_id == locationId)
					{
						var noteTxt = "";
						if (stockEntry.note)
						{
							noteTxt = " " + stockEntry.note;
						}

						$("#specific_stock_entry").append($("<option>", {
							"value": stockEntry.stock_id,
							"text": __t("Amount: %1$s; Due on %2$s; Bought on %3$s", stockEntry.amount, moment(stockEntry.best_before_date).format("YYYY-MM-DD"), moment(stockEntry.purchased_date).format("YYYY-MM-DD")) + "; " + openTxt + noteTxt,
							"data-amount": stockEntry.amount,
							"data-id": stockEntry.id
						}));

						sumValue = sumValue + (stockEntry.amount || 0);

						if (stockEntry.stock_id == stockId)
						{
							$("#use_specific_stock_entry").click();
							$("#specific_stock_entry").val(stockId);
						}
					}
				});

				Victual.Api.Get('stock/products/' + Victual.Components.ProductPicker.GetValue(),
					function(productDetails)
					{
						current_productDetails = productDetails;
						RefreshForm();
					}
				);

				if (document.getElementById("product_id").getAttribute("barcode") == "null" || $("#product_id").data("grocycode"))
				{
					ScanModeSubmit();
				}
			}
		);
	}
}

// Product selected: refreshes the ProductCard, reloads the amount/quantity-unit picker
// (Victual.Components.ProductAmountPicker) for the product's stock QU, rebuilds the location
// dropdown (GET stock/products/{id}/locations) defaulting to the product's default consume
// location, applies tare-weight min/max limits, and looks up any barcode-specific amount/QU
// (GET objects/product_barcodes) to prefill the amount and trigger scan-mode auto-submit.
Victual.Components.ProductPicker.GetPicker().on('change', function(e)
{
	if (BoolVal(Victual.UserSettings.scan_mode_consume_enabled))
	{
		Victual.UISound.BarcodeScannerBeep();
	}

	$("#specific_stock_entry").find("option").remove().end().append("<option></option>");
	if ($("#use_specific_stock_entry").is(":checked"))
	{
		$("#use_specific_stock_entry").click();
	}
	$("#location_id").val("");

	var productId = $(e.target).val();

	if (productId)
	{
		Victual.Components.ProductCard.Refresh(productId);

		Victual.Api.Get('stock/products/' + productId,
			function(productDetails)
			{
				current_productDetails = productDetails;

				Victual.Components.ProductAmountPicker.Reload(productDetails.product.id, productDetails.quantity_unit_stock.id);
				Victual.Components.ProductAmountPicker.SetQuantityUnit(productDetails.default_quantity_unit_consume.id);
				if (BoolVal(Victual.UserSettings.stock_default_consume_amount_use_quick_consume_amount))
				{
					$('#display_amount').val(productDetails.product.quick_consume_amount * $("#qu_id option:selected").attr("data-qu-factor"));
				}
				else
				{
					$('#display_amount').val(Victual.UserSettings.stock_default_consume_amount);
				}
				RefreshLocaleNumberInput();
				$(".input-group-productamountpicker").trigger("change");

				var defaultLocationId = productDetails.location.id;
				if (productDetails.product.default_consume_location_id)
				{
					defaultLocationId = productDetails.product.default_consume_location_id;
				}

				$("#location_id").find("option").remove().end().append("<option></option>");
				Victual.Api.Get("stock/products/" + productId + '/locations?include_sub_products=true',
					function(stockLocations)
					{
						var setDefault = 0;
						var stockAmountAtDefaultLocation = 0;
						var addedLocations = [];
						stockLocations.forEach(stockLocation =>
						{
							if (stockLocation.location_id == defaultLocationId)
							{
								if (!addedLocations.includes(stockLocation.location_id))
								{
									$("#location_id").append($("<option>", {
										value: stockLocation.location_id,
										text: Victual.FrontendHelpers.LocationPath(stockLocation.location_id, stockLocation.location_name) + " (" + __t("Default location") + ")"
									}));
									$("#location_id").val(defaultLocationId);
									$("#location_id").trigger('change');
									setDefault = 1;
								}
								stockAmountAtDefaultLocation += stockLocation.amount;
							}
							else
							{
								if (!addedLocations.includes(stockLocation.location_id))
								{
									$("#location_id").append($("<option>", {
										value: stockLocation.location_id,
										text: Victual.FrontendHelpers.LocationPath(stockLocation.location_id, stockLocation.location_name)
									}));
								}
							}

							addedLocations.push(stockLocation.location_id);

							if (setDefault == 0)
							{
								$("#location_id").val(defaultLocationId);
								$("#location_id").trigger('change');
							}
						});

						if (stockAmountAtDefaultLocation == 0)
						{
							$("#location_id option")[1].selected = true;
							$("#location_id").trigger('change');
						}

						if (document.getElementById("product_id").getAttribute("barcode") != "null")
						{
							Victual.Api.Get('objects/product_barcodes?query[]=barcode=' + document.getElementById("product_id").getAttribute("barcode"),
								function(barcodeResult)
								{
									if (barcodeResult != null)
									{
										var barcode = barcodeResult[0];

										if (barcode != null)
										{
											if (barcode.amount != null)
											{
												$("#display_amount").val(barcode.amount);
												$("#display_amount").select();
											}

											if (barcode.qu_id != null)
											{
												Victual.Components.ProductAmountPicker.SetQuantityUnit(barcode.qu_id);
											}

											$(".input-group-productamountpicker").trigger("change");
											Victual.FrontendHelpers.ValidateForm('consume-form');
											RefreshLocaleNumberInput();
											ScanModeSubmit(false);
										}
									}
								}
							);
						}
					}
				);

				if (productDetails.product.enable_tare_weight_handling == 1)
				{
					$("#display_amount").attr("min", productDetails.product.tare_weight);
					$('#display_amount').attr('max', (productDetails.stock_amount + productDetails.product.tare_weight).toFixed(Victual.UserSettings.stock_decimal_places_amounts));
					$("#tare-weight-handling-info").removeClass("d-none");
				}
				else
				{
					$("#display_amount").attr("min", Victual.DefaultMinAmount);
					$("#tare-weight-handling-info").addClass("d-none");
				}

				Victual.Components.ProductPicker.HideCustomError();
				Victual.FrontendHelpers.ValidateForm('consume-form');
				setTimeout(function()
				{
					$('#display_amount').focus();
				}, Victual.FormFocusDelay);

				if (productDetails.stock_amount == productDetails.stock_amount_opened || productDetails.product.enable_tare_weight_handling == 1 || productDetails.product.disable_open == 1)
				{
					$("#save-mark-as-open-button").addClass("disabled");
				}
				else
				{
					$("#save-mark-as-open-button").removeClass("disabled");
				}
			}
		);
	}
});

$('#display_amount').val(Victual.UserSettings.stock_default_consume_amount);
$(".input-group-productamountpicker").trigger("change");
Victual.FrontendHelpers.ValidateForm('consume-form');

$('#display_amount').on('focus', function(e)
{
	$(this).select();
});

$('#price').on('focus', function(e)
{
	$(this).select();
});


$('#consume-form input').keyup(function(event)
{
	Victual.FrontendHelpers.ValidateForm('consume-form');
});

$('#consume-form select').change(function(event)
{
	Victual.FrontendHelpers.ValidateForm('consume-form');
});

$('#consume-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('consume-form'))
		{
			return false;
		}
		else
		{
			$('#save-consume-button').click();
		}
	}
});

// Specific stock entry selected/cleared: caps #display_amount's max to either the single
// entry's amount, or (when 'any entry' is chosen) the summed amount across all entries at
// the current location.
$("#specific_stock_entry").on("change", function(e)
{
	if ($(e.target).val() == "")
	{
		sumValue = 0;
		Victual.Api.Get("stock/products/" + Victual.Components.ProductPicker.GetValue() + '/entries?include_sub_products=true',
			function(stockEntries)
			{
				stockEntries.forEach(stockEntry =>
				{
					if (stockEntry.location_id == $("#location_id").val() || stockEntry.location_id == "")
					{
						sumValue = sumValue + stockEntry.amount_aggregated;
					}
				});
				$("#display_amount").attr("max", sumValue.toFixed(Victual.UserSettings.stock_decimal_places_amounts));
				if (sumValue == 0)
				{
					$("#display_amount").parent().find(".invalid-feedback").text(__t('There are no units available at this location'));
				}
			}
		);
	}
	else
	{
		$("#display_amount").attr("max", Number.parseFloat($('option:selected', this).attr('data-amount')).toFixed(Victual.UserSettings.stock_decimal_places_amounts));
	}
});

// Toggles whether a specific stock entry must be chosen (enables/requires #specific_stock_entry)
$("#use_specific_stock_entry").on("change", function()
{
	var value = $(this).is(":checked");

	if (value)
	{
		$("#specific_stock_entry").removeAttr("disabled");
		$("#specific_stock_entry").attr("required", "");
	}
	else
	{
		$("#specific_stock_entry").attr("disabled", "");
		$("#specific_stock_entry").removeAttr("required");
		$("#specific_stock_entry").val("");
	}

	Victual.FrontendHelpers.ValidateForm("consume-form");
});

// Quantity unit changed: re-evaluate min/max amount limits for the new unit
$("#qu_id").on("change", function()
{
	RefreshForm();
});

// When embedded (opened from another view in a modal), pick up the product/location/stock
// entry to prefill from URI params and kick off the product-picker change chain
if (GetUriParam("embedded") !== undefined)
{
	var locationId = GetUriParam('locationId');

	if (typeof locationId === 'undefined')
	{
		Victual.Components.ProductPicker.GetPicker().trigger('change');
	}
	else
	{
		$("#location_id").val(locationId);
		$("#location_id").trigger('change');
		$("#use_specific_stock_entry").click();
		$("#use_specific_stock_entry").trigger('change');
		Victual.Components.ProductPicker.GetPicker().trigger('change');
	}
}

// Default input field
setTimeout(function()
{
	Victual.Components.ProductPicker.GetInputElement().focus();
}, Victual.FormFocusDelay);

// Requests audio permission when scan mode is first enabled (beeps are used for feedback)
$(document).on("change", "#scan-mode", function(e)
{
	if ($(this).prop("checked"))
	{
		Victual.UISound.AskForPermission();
	}
});

// Toggle button that proxies clicks to the underlying #scan-mode checkbox and updates its label/color
$("#scan-mode-button").on("click", function(e)
{
	$("#scan-mode").click();
	$("#scan-mode-button").toggleClass("btn-success").toggleClass("btn-danger");
	if ($("#scan-mode").prop("checked"))
	{
		$("#scan-mode-status").text(__t("on"));
	}
	else
	{
		$("#scan-mode-status").text(__t("off"));
	}
});

$('#consume-exact-amount').on('change', RefreshForm);
var current_productDetails;
/**
 * Re-applies min/max amount constraints and the tare-weight info hint based on the currently
 * selected product (current_productDetails), quantity unit and 'exact amount' checkbox state.
 * Called after a product/location/QU/specific-stock-entry change.
 */
function RefreshForm()
{
	var productDetails = current_productDetails;
	if (!productDetails)
	{
		return;
	}

	if (productDetails.product.enable_tare_weight_handling == 1)
	{
		$("#consume-exact-amount-group").removeClass("d-none");
	}
	else
	{
		$("#consume-exact-amount-group").addClass("d-none");
	}

	if (productDetails.product.enable_tare_weight_handling == 1 && !$('#consume-exact-amount').is(':checked'))
	{
		$("#display_amount").attr("min", productDetails.product.tare_weight);
		$('#display_amount').attr('max', (sumValue + productDetails.product.tare_weight).toFixed(Victual.UserSettings.stock_decimal_places_amounts));
		$("#tare-weight-handling-info").removeClass("d-none");
	}
	else
	{
		$("#tare-weight-handling-info").addClass("d-none");

		$("#display_amount").attr("min", Victual.DefaultMinAmount);
		$('#display_amount').attr('max', (sumValue * $("#qu_id option:selected").attr("data-qu-factor")).toFixed(Victual.UserSettings.stock_decimal_places_amounts));

		if (sumValue == 0)
		{
			$("#display_amount").parent().find(".invalid-feedback").text(__t('There are no units available at this location'));
		}
	}

	if (productDetails.has_childs)
	{
		$("#display_amount").removeAttr("max");
	}

	Victual.FrontendHelpers.ValidateForm("consume-form");
}

/**
 * Auto-submits the consume form when scan mode is enabled and the form validates - used so
 * scanning a barcode can complete a consumption without further user interaction.
 * @param {boolean} singleUnit When true, forces the amount to 1 before submitting.
 */
function ScanModeSubmit(singleUnit = true)
{
	if (BoolVal(Victual.UserSettings.scan_mode_consume_enabled))
	{
		if (singleUnit)
		{
			$("#display_amount").val(1);
			$(".input-group-productamountpicker").trigger("change");
		}

		if (Victual.FrontendHelpers.ValidateForm('consume-form'))
		{
			$('#save-consume-button').click();
		}
		else
		{
			toastr.warning(__t("Scan mode is on but not all required fields could be populated automatically"));
			Victual.UISound.Error();
		}
	}
}
