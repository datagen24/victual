// View script for the inventory page (views/inventory.blade.php):
// lets the user enter the counted total stock amount of a product - the difference to the
// current stock amount is booked as an add or consume correction via the stock inventory API.

// Details of the currently selected product (result of GET /api/stock/products/{id}),
// cached for RefreshPriceHint (tare weight handling)
var CurrentProductDetails;

// Form submit: books the new total amount via POST /api/stock/products/{id}/inventory
// (new_amount, best_before_date, price - converted to stock QU via the selected option's
// data-qu-factor -, note and, feature flag dependent, shopping_location_id / location_id /
// purchased_date); afterwards optionally adds a barcode, triggers label printing and resets the form
$('#save-inventory-button').on('click', function (e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("inventory-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonForm = $('#inventory-form').serializeJSON();
	Victual.FrontendHelpers.BeginUiBusy("inventory-form");

	Victual.Api.Get('stock/products/' + jsonForm.product_id,
		function (productDetails)
		{
			var price = "";
			if (jsonForm.price)
			{
				price = Number.parseFloat(jsonForm.price * $("#qu_id option:selected").attr("data-qu-factor")).toFixed(Victual.UserSettings.stock_decimal_places_prices_input);
			}

			var jsonData = {};
			jsonData.new_amount = jsonForm.amount;
			jsonData.best_before_date = Victual.Components.DateTimePicker.GetValue();
			jsonData.note = jsonForm.note;
			jsonData.stock_label_type = jsonForm.stock_label_type;
			if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING)
			{
				jsonData.shopping_location_id = Victual.Components.ShoppingLocationPicker.GetValue();
			}
			if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
			{
				jsonData.location_id = Victual.Components.LocationPicker.GetValue();
			}
			if (Victual.UserSettings.show_purchased_date_on_purchase)
			{
				jsonData.purchased_date = Victual.Components.SecondaryDateTimePicker.GetValue();
			}

			jsonData.price = price;

			var bookingResponse = null;

			Victual.Api.Post('stock/products/' + jsonForm.product_id + '/inventory', jsonData,
				function (result)
				{
					bookingResponse = result;

					// Barcode workflow (?flow=InplaceAddBarcodeToExistingProduct&barcode=...):
					// additionally attach the scanned barcode to the product (POST /api/objects/product_barcodes)
					if (GetUriParam("flow") === "InplaceAddBarcodeToExistingProduct")
					{
						var jsonDataBarcode = {};
						jsonDataBarcode.barcode = GetUriParam("barcode");
						jsonDataBarcode.product_id = jsonForm.product_id;
						jsonDataBarcode.shopping_location_id = jsonForm.shopping_location_id;
						jsonDataBarcode.note = jsonForm.note;

						Victual.Api.Post('objects/product_barcodes', jsonDataBarcode,
							function (result)
							{
								$("#flow-info-InplaceAddBarcodeToExistingProduct").addClass("d-none");
								$('#barcode-lookup-disabled-hint').addClass('d-none');
								$('#barcode-lookup-hint').removeClass('d-none');
								window.history.replaceState({}, document.title, U("/inventory"));
							},
							function (xhr)
							{
								Victual.FrontendHelpers.EndUiBusy("inventory-form");
								Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
							}
						);
					}

					// Label printing (plan 32): StockService::AddProduct now issues the label and
					// enqueues its print job itself, inside the same transaction as the
					// booking, keyed off the stock_label_type the form already posted. There is
					// nothing left for the client to fire.

					// Reload the product to build the success message (with undo link for the
					// booked transaction), save userfields and reset the form for the next entry;
					// when embedded (?embedded) the enclosing modal is closed via postMessage instead
					Victual.EditObjectId = result[0].transaction_id;
					Victual.Api.Get('stock/products/' + jsonForm.product_id,
						function (result)
						{
							// Product and quantity unit names are text columns rendered into a
							// toastr message, which is an HTML sink - escaped at the point of
							// use (sweep finding S29).
							var successMessage = __t('Stock amount of %1$s is now %2$s', Victual.FrontendHelpers.EscapeHtml(result.product.name), result.stock_amount + " " + __n(result.stock_amount, Victual.FrontendHelpers.EscapeHtml(result.quantity_unit_stock.name), Victual.FrontendHelpers.EscapeHtml(result.quantity_unit_stock.name_plural), true)) + '<br><a class="btn btn-secondary btn-sm mt-2" href="#" onclick="UndoStockTransaction(\'' + bookingResponse[0].transaction_id + '\')"><i class="fa-solid fa-undo"></i> ' + __t("Undo") + '</a>';

							if (GetUriParam("embedded") !== undefined)
							{
								Victual.Components.UserfieldsForm.Save(function ()
								{
									Victual.GetTopmostWindow().postMessage(WindowMessageBag("BroadcastMessage", WindowMessageBag("ProductChanged", jsonForm.product_id)), Victual.BaseUrl);
									window.parent.postMessage(WindowMessageBag("ShowSuccessMessage", successMessage), Victual.BaseUrl);
									window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
								});

							}
							else
							{
								Victual.Components.UserfieldsForm.Save(function ()
								{
									Victual.FrontendHelpers.EndUiBusy("inventory-form");
									toastr.success(successMessage);
									Victual.Components.ProductPicker.FinishFlow();

									Victual.Components.ProductAmountPicker.Reset();
									$('#inventory-change-info').addClass('d-none');
									$("#tare-weight-handling-info").addClass("d-none");
									$("#display_amount").attr("min", "0");
									$('#display_amount').val('');
									$('#display_amount').removeAttr("data-not-equal");
									$(".input-group-productamountpicker").trigger("change");
									$('#price').val('');
									$('#note').val('');
									$('#price-hint').text("");
									Victual.Components.DateTimePicker.Clear();
									Victual.Components.ProductPicker.Clear();
									if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING)
									{
										Victual.Components.ShoppingLocationPicker.SetValue('');
									}
									Victual.Components.ProductPicker.GetInputElement().focus();
									Victual.Components.ProductCard.Refresh(jsonForm.product_id);
									Victual.Components.UserfieldsForm.Clear();

									if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_LABELS)
									{
										$("#stock_label_type").val(0);
									}

									Victual.FrontendHelpers.ValidateForm('inventory-form');
								});
							}
						},
						function (xhr)
						{
							Victual.FrontendHelpers.EndUiBusy();
							Victual.Api.DefaultErrorHandler(xhr);
						}
					);
				},
				function (xhr)
				{
					Victual.FrontendHelpers.EndUiBusy("inventory-form");
					Victual.Api.DefaultErrorHandler(xhr);
				}
			);
		},
		function (xhr)
		{
			Victual.FrontendHelpers.EndUiBusy("inventory-form");
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});

// Product selection: loads the product (GET /api/stock/products/{id}) and prefills the form -
// amount picker QUs, current stock amount (with data-not-equal so a booking of 0 is invalid),
// tare weight minimum, last price, last shopping location/default location and the due date
// from the product's default_best_before_days (-1 = "never expires" shortcut); when the picker
// was filled by barcode scan, amount/QU/note defaults from the matching product_barcodes_view
// row are applied on top
Victual.Components.ProductPicker.GetPicker().on('change', function (e)
{
	var productId = $(e.target).val();

	if (productId)
	{
		Victual.Components.ProductCard.Refresh(productId);

		Victual.Api.Get('stock/products/' + productId,
			function (productDetails)
			{
				CurrentProductDetails = productDetails;

				Victual.Components.ProductAmountPicker.Reload(productDetails.product.id, productDetails.quantity_unit_stock.id);
				Victual.Components.ProductAmountPicker.SetQuantityUnit(productDetails.quantity_unit_stock.id);

				$('#display_amount').attr("data-stock-amount", productDetails.stock_amount)
				$('#display_amount').attr('data-not-equal', productDetails.stock_amount * $("#qu_id option:selected").attr("data-qu-factor"));

				if (productDetails.product.enable_tare_weight_handling == 1)
				{
					$("#display_amount").attr("min", productDetails.product.tare_weight);
					$("#tare-weight-handling-info").removeClass("d-none");
				}
				else
				{
					$("#display_amount").attr("min", "0");
					$("#tare-weight-handling-info").addClass("d-none");
				}

				if (productDetails.last_price)
				{
					$('#price').val((productDetails.last_price / Number.parseFloat($("#qu_id option:selected").attr("data-qu-factor"))).toFixed(Victual.UserSettings.stock_decimal_places_prices_display));
				}
				else
				{
					$('#price').val("");
				}

				RefreshLocaleNumberInput();
				if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING)
				{
					Victual.Components.ShoppingLocationPicker.SetId(productDetails.last_shopping_location_id);
				}
				if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
				{
					Victual.Components.LocationPicker.SetId(productDetails.location.id);
				}

				if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_TRACKING)
				{
					if (productDetails.product.default_best_before_days.toString() !== '0')
					{
						if (productDetails.product.default_best_before_days == -1)
						{
							if (!$("#datetimepicker-shortcut").is(":checked"))
							{
								$("#datetimepicker-shortcut").click();
							}
						}
						else
						{
							Victual.Components.DateTimePicker.SetValue(moment().add(productDetails.product.default_best_before_days, 'days').format('YYYY-MM-DD'));
						}
					}
				}

				if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_LABELS)
				{
					$("#stock_label_type").val(productDetails.product.default_stock_label_type);
					$("#stock_label_type").trigger("change");
				}

				if (document.getElementById("product_id").getAttribute("barcode") != "null")
				{
					Victual.Api.Get('objects/product_barcodes_view?query[]=barcode=' + document.getElementById("product_id").getAttribute("barcode"),
						function (barcodeResult)
						{
							if (barcodeResult)
							{
								var barcode = barcodeResult[0];

								if (barcode)
								{
									if (barcode.amount)
									{
										$("#display_amount").val(barcode.amount);
										$("#display_amount").select();
									}

									if (barcode.qu_id)
									{
										Victual.Components.ProductAmountPicker.SetQuantityUnit(barcode.qu_id);
									}

									if (barcode.note)
									{
										$("#note").val(barcode.note);
									}

									$(".input-group-productamountpicker").trigger("change");
									Victual.FrontendHelpers.ValidateForm('inventory-form');
									RefreshLocaleNumberInput();
								}
							}
						}
					);
				}

				$('#display_amount').val(productDetails.stock_amount);
				RefreshLocaleNumberInput();
				$(".input-group-productamountpicker").trigger("change");
				setTimeout(function ()
				{
					$('#display_amount').focus();
				}, Victual.FormFocusDelay);
				$('#display_amount').trigger('keyup');
				RefreshPriceHint();
			}
		);
	}
});

/**
 * Updates the #price-hint text ("means X per <stock QU>") when the price is entered
 * in a quantity unit other than the stock QU; converts the entered price using the
 * selected #qu_id option's data-qu-factor and subtracts the tare weight if enabled.
 * Clears the hint when amount/price are 0 or the units already match.
 */
function RefreshPriceHint()
{
	if ($('#amount').val() == 0 || $('#price').val() == 0)
	{
		$('#price-hint').text("");
		return;
	}

	if ($("#qu_id").attr("data-destination-qu-name") != $("#qu_id option:selected").text())
	{
		var amount = $('#display_amount').val();
		if (BoolVal(CurrentProductDetails.product.enable_tare_weight_handling))
		{
			amount -= CurrentProductDetails.product.tare_weight;
		}

		var price = Number.parseFloat($('#price').val() * $("#qu_id option:selected").attr("data-qu-factor")).toFixed(Victual.UserSettings.stock_decimal_places_prices_display);
		$('#price-hint').text(__t('means %1$s per %2$s', price.toLocaleString(undefined, { style: "currency", currency: Victual.Currency, minimumFractionDigits: Victual.UserSettings.stock_decimal_places_prices_display, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_prices_display }), $("#qu_id").attr("data-destination-qu-name")));
	}
	else
	{
		$('#price-hint').text("");
	}
};

// Initial state: empty amount, validate once
$('#display_amount').val('');
$(".input-group-productamountpicker").trigger("change");
Victual.FrontendHelpers.ValidateForm('inventory-form');

// Focus handling: focus the product picker normally; in a picker workflow or embedded mode
// the product is already known, so trigger its change handler to prefill the form
if (Victual.Components.ProductPicker.InAnyFlow() === false && GetUriParam("embedded") === undefined)
{
	setTimeout(function ()
	{
		Victual.Components.ProductPicker.GetInputElement().focus();

	}, Victual.FormFocusDelay);
}
else
{
	Victual.Components.ProductPicker.GetPicker().trigger('change');

	if (Victual.Components.ProductPicker.InProductModifyWorkflow())
	{
		setTimeout(function ()
		{
			Victual.Components.ProductPicker.GetInputElement().focus();

		}, Victual.FormFocusDelay);
	}
}

// Redirect focus back to the product picker while no product is selected
$('#display_amount').on('focus', function (e)
{
	if (Victual.Components.ProductPicker.GetValue().length === 0)
	{
		setTimeout(function ()
		{
			Victual.Components.ProductPicker.GetInputElement().focus();

		}, Victual.FormFocusDelay);
	}
	else
	{
		$(this).select();
	}
});

// Live-validate on any input; Enter submits the form when valid
$('#inventory-form input').keyup(function (event)
{
	Victual.FrontendHelpers.ValidateForm('inventory-form');
});

$('#inventory-form input').keydown(function (event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('inventory-form'))
		{
			return false;
		}
		else
		{
			$('#save-inventory-button').click();
		}
	}
});


// Keep the "must differ from current stock amount" validation (data-not-equal)
// in sync with the selected quantity unit's conversion factor
$('#qu_id').on('change', function (e)
{
	$('#display_amount').attr('data-not-equal', Number.parseFloat($('#display_amount').attr('data-stock-amount')) * Number.parseFloat($("#qu_id option:selected").attr("data-qu-factor")));
	Victual.FrontendHelpers.ValidateForm('inventory-form');
});

Victual.Components.DateTimePicker.GetInputElement().on('change', function (e)
{
	Victual.FrontendHelpers.ValidateForm('inventory-form');
});

Victual.Components.DateTimePicker.GetInputElement().on('keypress', function (e)
{
	Victual.FrontendHelpers.ValidateForm('inventory-form');
});

$('#price').on('focus', function (e)
{
	$(this).select();
});

// Recalculate the booking preview whenever amount or quantity unit changes:
// shows "This means X will be added to/removed from stock" (#inventory-change-info),
// stores the delta in #amount's data-estimated-booking-amount and makes due date and
// location required only when stock will be added (respecting tare weight handling)
$('#display_amount,#qu_id').on('keyup change', function (e)
{
	var productId = Victual.Components.ProductPicker.GetValue();
	var newAmount = Number.parseFloat($('#amount').val());

	if (productId)
	{
		Victual.Api.Get('stock/products/' + productId,
			function (productDetails)
			{
				var productStockAmount = productDetails.stock_amount || 0;

				var containerWeight = 0.0;
				if (productDetails.product.enable_tare_weight_handling == 1)
				{
					containerWeight = productDetails.product.tare_weight;
				}

				var estimatedBookingAmount = (newAmount - productStockAmount - containerWeight).toFixed(Victual.UserSettings.stock_decimal_places_amounts);
				$("#amount").attr("data-estimated-booking-amount", estimatedBookingAmount).trigger("change");
				estimatedBookingAmount = Math.abs(estimatedBookingAmount);
				$('#inventory-change-info').removeClass('d-none');

				if (productDetails.product.enable_tare_weight_handling == 1 && newAmount < containerWeight)
				{
					$('#inventory-change-info').addClass('d-none');
				}
				else if (newAmount > productStockAmount + containerWeight)
				{
					$('#inventory-change-info').text(__t('This means %s will be added to stock', estimatedBookingAmount.toLocaleString() + ' ' + __n(estimatedBookingAmount, productDetails.quantity_unit_stock.name, productDetails.quantity_unit_stock.name_plural, true)));
					Victual.Components.DateTimePicker.GetInputElement().attr('required', '');
					if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
					{
						Victual.Components.LocationPicker.GetInputElement().attr('required', '');
					}
				}
				else if (newAmount < productStockAmount + containerWeight)
				{
					$('#inventory-change-info').text(__t('This means %s will be removed from stock', estimatedBookingAmount.toLocaleString() + ' ' + __n(estimatedBookingAmount, productDetails.quantity_unit_stock.name, productDetails.quantity_unit_stock.name_plural, true)));
					Victual.Components.DateTimePicker.GetInputElement().removeAttr('required');
					if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
					{
						Victual.Components.LocationPicker.GetInputElement().removeAttr('required');
					}
				}
				else if (newAmount == productStockAmount)
				{
					$('#inventory-change-info').addClass('d-none');
				}

				if (!Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_TRACKING)
				{
					Victual.Components.DateTimePicker.GetInputElement().removeAttr('required');
				}

				RefreshPriceHint();
				Victual.FrontendHelpers.ValidateForm('inventory-form');
			}
		);
	}
});

$('#qu_id').on('change', function (e)
{
	RefreshPriceHint();
});

$("#display_amount").attr("min", "0");

// Label printer feature: when "label per unit" is selected, show how many labels
// the estimated booking amount would print
if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_LABELS)
{
	$("#stock_label_type, #amount").on("change", function (e)
	{
		if ($("#stock_label_type").val() == 2)
		{
			var estimatedBookingAmount = Number.parseFloat($("#amount").attr("data-estimated-booking-amount"));
			if (estimatedBookingAmount > 0)
			{
				$("#stock-entry-label-info").text(__n(estimatedBookingAmount, "This means 1 label will be printed", "This means %1$s labels will be printed"));
			}
			else
			{
				$("#stock-entry-label-info").text("");
			}
		}
		else
		{
			$("#stock-entry-label-info").text("");
		}
	});
}

// Load userfield inputs for the stock entity
Victual.Components.UserfieldsForm.Load();
