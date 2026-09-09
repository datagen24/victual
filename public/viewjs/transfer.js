// Powers the "transfer stock" quick-entry view (transfer.blade.php): moves an amount of
// a product's stock from one location to another (POST stock/products/{id}/transfer).
// Can run standalone or "embedded" in a modal iframe opened from elsewhere (product page,
// barcode scan, etc.), signalled by the "embedded" URI param.

// Validates and submits the transfer. Fetches the product's details first (for the
// success message and tare-weight handling), then posts the transfer; on success,
// optionally links a scanned barcode to the product (barcode-scan flow), shows a toast
// with an inline "Undo" link, and either notifies the parent window to close (embedded
// mode) or resets the form in place for another transfer (standalone mode)

// Before anything can empty the "from" select: choosing a product rebuilds it from the
// product's stock locations, which the API names rather than paths, and a bare name is
// unique only among siblings now. The "to" select keeps the options the template gave it,
// so it needs nothing. See Victual.FrontendHelpers.RememberLocationPaths.
Victual.FrontendHelpers.RememberLocationPaths('#location_id_from');

$('#save-transfer-button').on('click', function (e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("transfer-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonForm = $('#transfer-form').serializeJSON();
	Victual.FrontendHelpers.BeginUiBusy("transfer-form");

	var apiUrl = 'stock/products/' + jsonForm.product_id + '/transfer';

	var jsonData = {};
	jsonData.amount = jsonForm.amount;
	jsonData.location_id_to = $("#location_id_to").val();
	jsonData.location_id_from = $("#location_id_from").val();

	if ($("#use_specific_stock_entry").is(":checked"))
	{
		jsonData.stock_entry_id = jsonForm.specific_stock_entry;
	}

	var bookingResponse = null;

	Victual.Api.Get('stock/products/' + jsonForm.product_id,
		function (productDetails)
		{
			Victual.Api.Post(apiUrl, jsonData,
				function (result)
				{
					bookingResponse = result;

					// Barcode-scan flow: additionally link the scanned barcode to the selected product
					if (GetUriParam("flow") === "InplaceAddBarcodeToExistingProduct")
					{
						var jsonDataBarcode = {};
						jsonDataBarcode.barcode = GetUriParam("barcode");
						jsonDataBarcode.product_id = jsonForm.product_id;

						Victual.Api.Post('objects/product_barcodes', jsonDataBarcode,
							function (result)
							{
								$("#flow-info-InplaceAddBarcodeToExistingProduct").addClass("d-none");
								$('#barcode-lookup-disabled-hint').addClass('d-none');
								$('#barcode-lookup-hint').removeClass('d-none');
								window.history.replaceState({}, document.title, U("/transfer"));
							},
							function (xhr)
							{
								Victual.FrontendHelpers.EndUiBusy("transfer-form");
								Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
							}
						);
					}

					// For tare-weight-handled products, the reported amount subtracts the tare
					// weight from the entered value; the message text is otherwise identical
					// to the else-branch below
					if (productDetails.product.enable_tare_weight_handling == 1)
					{
						// Product, quantity unit and both location names go into a toastr
						// message, which is rendered as HTML. The two location names come back
						// out of the DOM with .text(), which returns the decoded string, so
						// they need escaping here just like the two that came from the API
						// (sweep finding S29).
						var successMessage = __t('Transfered %1$s of %2$s from %3$s to %4$s', Math.abs(jsonForm.amount - productDetails.product.tare_weight) + " " + __n(jsonForm.amount, Victual.FrontendHelpers.EscapeHtml(productDetails.quantity_unit_stock.name), Victual.FrontendHelpers.EscapeHtml(productDetails.quantity_unit_stock.name_plural), true), Victual.FrontendHelpers.EscapeHtml(productDetails.product.name), Victual.FrontendHelpers.EscapeHtml($('option:selected', "#location_id_from").text()), Victual.FrontendHelpers.EscapeHtml($('option:selected', "#location_id_to").text())) + '<br><a class="btn btn-secondary btn-sm mt-2" href="#" onclick="UndoStockTransaction(\'' + bookingResponse[0].transaction_id + '\')"><i class="fa-solid fa-undo"></i> ' + __t("Undo") + '</a>';
					}
					else
					{
						var successMessage = __t('Transfered %1$s of %2$s from %3$s to %4$s', Math.abs(jsonForm.amount) + " " + __n(jsonForm.amount, Victual.FrontendHelpers.EscapeHtml(productDetails.quantity_unit_stock.name), Victual.FrontendHelpers.EscapeHtml(productDetails.quantity_unit_stock.name_plural), true), Victual.FrontendHelpers.EscapeHtml(productDetails.product.name), Victual.FrontendHelpers.EscapeHtml($('option:selected', "#location_id_from").text()), Victual.FrontendHelpers.EscapeHtml($('option:selected', "#location_id_to").text())) + '<br><a class="btn btn-secondary btn-sm mt-2" href="#" onclick="UndoStockTransaction(\'' + bookingResponse[0].transaction_id + '\')"><i class="fa-solid fa-undo"></i> ' + __t("Undo") + '</a>';
					}

					if (GetUriParam("embedded") !== undefined)
					{
						Victual.GetTopmostWindow().postMessage(WindowMessageBag("BroadcastMessage", WindowMessageBag("ProductChanged", jsonForm.product_id)), Victual.BaseUrl);
						window.parent.postMessage(WindowMessageBag("ShowSuccessMessage", successMessage), Victual.BaseUrl);
						window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
					}
					else
					{
						Victual.FrontendHelpers.EndUiBusy("transfer-form");
						toastr.success(successMessage);
						Victual.Components.ProductPicker.FinishFlow();

						// Show an info toast when the transfer moved the product into or out of a freezer location
						if ($("#location_id_from option:selected").attr("data-is-freezer") == 0 && $("#location_id_to option:selected").attr("data-is-freezer") == 1) // Frozen
						{
							toastr.info('<span>' + __t("Frozen") + "</span> <i class='fa-solid fa-snowflake'></i>");

							if (BoolVal(productDetails.product.should_not_be_frozen))
							{
								toastr.warning(__t("This product shouldn't be frozen"));
							}
						}
						if ($("#location_id_from option:selected").attr("data-is-freezer") == 1 && $("#location_id_to option:selected").attr("data-is-freezer") == 0) // Thawed
						{
							toastr.info('<span>' + __t("Thawed") + "</span> <i class='fa-solid fa-fire-alt'></i>");
						}

						// Reset the whole form in place for another transfer (standalone mode only)
						$("#specific_stock_entry").find("option").remove().end().append("<option></option>");
						$("#specific_stock_entry").attr("disabled", "");
						$("#specific_stock_entry").removeAttr("required");
						if ($("#use_specific_stock_entry").is(":checked"))
						{
							$("#use_specific_stock_entry").click();
						}

						Victual.Components.ProductAmountPicker.Reset();
						$("#location_id_from").find("option").remove().end().append("<option></option>");
						$("#display_amount").attr("min", Victual.DefaultMinAmount);
						$("#display_amount").removeAttr("max");
						$('#display_amount').val(Victual.UserSettings.stock_default_transfer_amount);
						RefreshLocaleNumberInput();
						$(".input-group-productamountpicker").trigger("change");
						$("#tare-weight-handling-info").addClass("d-none");
						Victual.Components.ProductPicker.Clear();
						$("#location_id_to").val("");
						$("#location_id_from").val("");
						Victual.Components.ProductPicker.GetInputElement().focus();
						Victual.Components.ProductCard.Refresh(jsonForm.product_id);
						Victual.FrontendHelpers.ValidateForm('transfer-form');
					}
				},
				function (xhr)
				{
					Victual.FrontendHelpers.EndUiBusy("transfer-form");
					Victual.Api.DefaultErrorHandler(xhr);
				}
			);
		},
		function (xhr)
		{
			Victual.FrontendHelpers.EndUiBusy("transfer-form");
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});

// When a product is picked: resets dependent fields, refreshes the product card/amount
// picker, rejects tare-weight-handled products (unsupported for transfer), rebuilds the
// "from" location dropdown from the product's actual stock locations (defaulting to its
// default location), and pre-fills fields from a matched barcode or Grocycode if present
Victual.Components.ProductPicker.GetPicker().on('change', function (e)
{
	$("#specific_stock_entry").find("option").remove().end().append("<option></option>");
	if ($("#use_specific_stock_entry").is(":checked") && GetUriParam("stockId") == null)
	{
		$("#use_specific_stock_entry").click();
	}
	$("#location_id_to").val("");
	if (GetUriParam("stockId") == null)
	{
		$("#location_id_from").val("");
	}

	var productId = $(e.target).val();

	if (productId)
	{
		Victual.Components.ProductCard.Refresh(productId);

		Victual.Api.Get('stock/products/' + productId,
			function (productDetails)
			{
				Victual.Components.ProductAmountPicker.Reload(productDetails.product.id, productDetails.quantity_unit_stock.id);
				Victual.Components.ProductAmountPicker.SetQuantityUnit(productDetails.quantity_unit_stock.id);

				// Tare-weight-handled products can't be transferred; reject the selection
				if (productDetails.product.enable_tare_weight_handling == 1)
				{
					Victual.Components.ProductPicker.GetPicker().parent().find(".invalid-feedback").text(__t('Products with tare weight enabled are currently not supported for transfer'));
					Victual.Components.ProductPicker.Clear();
					return;
				}

				// Rebuild the "from" location dropdown from the product's actual stock locations
				$("#location_id_from").find("option").remove().end().append("<option></option>");
				Victual.Api.Get("stock/products/" + productId + '/locations',
					function (stockLocations)
					{
						var setDefault = 0;
						stockLocations.forEach(stockLocation =>
						{
							if (productDetails.location.id == stockLocation.location_id)
							{
								$("#location_id_from").append($("<option>", {
									value: stockLocation.location_id,
									text: Victual.FrontendHelpers.LocationPath(stockLocation.location_id, stockLocation.location_name) + " (" + __t("Default location") + ")",
									"data-is-freezer": stockLocation.location_is_freezer
								}));
								$("#location_id_from").val(productDetails.location.id);
								$("#location_id_from").trigger('change');
								setDefault = 1;
							}
							else
							{
								$("#location_id_from").append($("<option>", {
									value: stockLocation.location_id,
									text: Victual.FrontendHelpers.LocationPath(stockLocation.location_id, stockLocation.location_name),
									"data-is-freezer": stockLocation.location_is_freezer
								}));
							}

							if (setDefault == 0)
							{
								$("#location_id_from").val(stockLocation.location_id);
								$("#location_id_from").trigger('change');
							}
						});

						if (GetUriParam("locationId") != null)
						{
							$("#location_id_from").val(GetUriParam("locationId"));
							$("#location_id_from").trigger("change");
						}
					}
				);

				// If the product was selected via a scanned barcode, pre-fill amount/QU from
				// that barcode's configured defaults (product_barcodes_view)
				if (document.getElementById("product_id").getAttribute("barcode") != "null")
				{
					Victual.Api.Get('objects/product_barcodes_view?query[]=barcode=' + document.getElementById("product_id").getAttribute("barcode"),
						function (barcodeResult)
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
									Victual.FrontendHelpers.ValidateForm('transfer-form');
									RefreshLocaleNumberInput();
								}
							}
						}
					);
				}

				// If a stock entry Grocycode was used, prefill location_from accordingly.
				// A Grocycode barcode has the form "grcy:p:<productId>:<stockId>" (4 parts);
				// gc[3] is the specific stock entry's id
				if ($("#product_id").data("grocycode"))
				{
					var gc = $("#product_id").attr("barcode").split(":");
					if (gc.length == 4)
					{
						Victual.Api.Get("objects/stock?query[]=stock_id=" + gc[3],
							function (stockEntries)
							{
								$("#location_id_from").val(stockEntries[0].location_id);
							}
						);
					}
				}

				if (productDetails.product.enable_tare_weight_handling == 1)
				{
					$("#display_amount").attr("min", productDetails.product.tare_weight);
					$("#tare-weight-handling-info").removeClass("d-none");
				}
				else
				{
					$("#display_amount").attr("min", Victual.DefaultMinAmount);
					$("#tare-weight-handling-info").addClass("d-none");
				}

				$('#display_amount').attr("data-stock-amount", productDetails.stock_amount);

				Victual.Components.ProductPicker.HideCustomError();
				Victual.FrontendHelpers.ValidateForm('transfer-form');
				setTimeout(function ()
				{
					$('#display_amount').focus();
				}, Victual.FormFocusDelay);
			}
		);
	}
});

// Initial default transfer amount, from user settings
$('#display_amount').val(Victual.UserSettings.stock_default_transfer_amount);
$(".input-group-productamountpicker").trigger("change");
Victual.FrontendHelpers.ValidateForm('transfer-form');
RefreshLocaleNumberInput();

// When the "from" location changes: prevents picking the same location as "to" (hides it
// in that dropdown), and rebuilds the specific-stock-entry dropdown plus the amount's max
// from the stock entries actually present at that location
$("#location_id_from").on('change', function (e)
{
	var locationId = $(e.target).val();
	var sumValue = 0;
	var stockId = null;

	if (locationId == $("#location_id_to").val())
	{
		$("#location_id_to").val("");
	}

	// Hide the same location
	$("#location_id_to option").removeClass("d-none");
	$("#location_id_to option[value='" + locationId + "']").addClass("d-none");

	if (GetUriParam("embedded") !== undefined)
	{
		stockId = GetUriParam('stockId');
	}

	// If a stock entry Grocycode was used, preselect that one
	if ($("#product_id").data("grocycode"))
	{
		var gc = $("#product_id").attr("barcode").split(":");
		if (gc.length == 4)
		{
			stockId = gc[3];
		}
	}

	$("#specific_stock_entry").find("option").remove().end().append("<option></option>");
	if (!$("#use_specific_stock_entry").is(":checked") && stockId != null)
	{
		$("#use_specific_stock_entry").click();
	}

	if (locationId)
	{
		Victual.Api.Get("stock/products/" + Victual.Components.ProductPicker.GetValue() + '/entries',
			function (stockEntries)
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
						if ($("#specific_stock_entry option[value='" + stockEntry.stock_id + "']").length == 0)
						{
							var noteTxt = "";
							if (stockEntry.note)
							{
								noteTxt = " " + stockEntry.note;
							}

							$("#specific_stock_entry").append($("<option>", {
								value: stockEntry.stock_id,
								amount: stockEntry.amount,
								text: __t("Amount: %1$s; Due on %2$s; Bought on %3$s", stockEntry.amount, moment(stockEntry.best_before_date).format("YYYY-MM-DD"), moment(stockEntry.purchased_date).format("YYYY-MM-DD")) + "; " + openTxt + noteTxt
							}));
						}

						if (stockEntry.stock_id == stockId)
						{
							$("#specific_stock_entry").val(stockId);
						}

						sumValue = sumValue + stockEntry.amount;
					}
				});
				$("#display_amount").attr("max", (sumValue * $("#qu_id option:selected").attr("data-qu-factor")).toFixed(Victual.UserSettings.stock_decimal_places_amounts));
				if (sumValue == 0)
				{
					$("#display_amount").parent().find(".invalid-feedback").text(__t('There are no units available at this location'));
				}
			}
		);
	}
});

// Re-scale the amount's max when the quantity unit changes (converted via the QU's factor)
$("#qu_id").on('change', function (e)
{
	$("#display_amount").attr("max", (Number.parseFloat($('#display_amount').attr("data-stock-amount")) * $("#qu_id option:selected").attr("data-qu-factor")).toFixed(Victual.UserSettings.stock_decimal_places_amounts));
});

// Selects the amount field's content on focus for quick overtyping
$('#display_amount').on('focus', function (e)
{
	$(this).select();
});

// Live-validates on every keystroke / select change
$('#transfer-form input').keyup(function (event)
{
	Victual.FrontendHelpers.ValidateForm('transfer-form');
});

$('#transfer-form select').change(function (event)
{
	Victual.FrontendHelpers.ValidateForm('transfer-form');
});

// Enter key submits the form (if valid) instead of doing a default form submit
$('#transfer-form input').keydown(function (event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('transfer-form'))
		{
			return false;
		}
		else
		{
			$('#save-transfer-button').click();
		}
	}
});

// When a specific stock entry is picked, cap the amount's max at that entry's amount;
// when cleared ("Any"), fall back to the sum of all entries at the selected location
$("#specific_stock_entry").on("change", function (e)
{
	if ($(e.target).val() == "")
	{
		var sumValue = 0;
		Victual.Api.Get("stock/products/" + Victual.Components.ProductPicker.GetValue() + '/entries',
			function (stockEntries)
			{
				stockEntries.forEach(stockEntry =>
				{
					if (stockEntry.location_id == $("#location_id_from").val() || stockEntry.location_id == "")
					{
						sumValue = sumValue + stockEntry.amount;
					}
				});
				$("#display_amount").attr("max", (sumValue * $("#qu_id option:selected").attr("data-qu-factor")).toFixed(Victual.UserSettings.stock_decimal_places_amounts));
				if (sumValue == 0)
				{
					$("#display_amount").parent().find(".invalid-feedback").text(__t('There are no units available at this location'));
				}
			}
		);
	}
	else
	{
		$("#display_amount").attr("max", Number.parseFloat($('option:selected', this).attr('amount')).toFixed(Victual.UserSettings.stock_decimal_places_amounts));
	}
});

// Toggles whether a specific stock entry must be picked (vs. transferring from the
// location's stock in general)
$("#use_specific_stock_entry").on("change", function ()
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
		$("#location_id_from").trigger('change');
	}

	Victual.FrontendHelpers.ValidateForm("transfer-form");
});

// Embedded-mode init: when opened with a preset location (e.g. from a location's stock
// entries page), pre-select it and jump straight to specific-stock-entry mode; otherwise
// just trigger the product picker's change handler to initialize dependent fields
if (GetUriParam("embedded") !== undefined)
{
	var locationId = GetUriParam('locationId');

	if (typeof locationId === 'undefined')
	{
		Victual.Components.ProductPicker.GetPicker().trigger('change');
		setTimeout(function ()
		{
			Victual.Components.ProductPicker.GetInputElement().focus();
		}, Victual.FormFocusDelay);
	}
	else
	{

		$("#location_id_from").val(locationId);
		$("#location_id_from").trigger('change');
		$("#use_specific_stock_entry").click();
		$("#use_specific_stock_entry").trigger('change');
		Victual.Components.ProductPicker.GetPicker().trigger('change');
	}
}

// Default input field
setTimeout(function ()
{
	Victual.Components.ProductPicker.GetInputElement().focus();
}, Victual.FormFocusDelay);
