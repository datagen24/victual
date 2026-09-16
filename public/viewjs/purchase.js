// Purchase page logic: submits stock/products/{id}/add to book a purchase, plus the
// barcode-driven prefill and quantity-unit/price conversion math behind the purchase form.
// This file also serves as the shared "purchase dialog" script pulled in via @push('pageScripts')
// by stockoverview.blade.php, stockentries.blade.php and shoppinglist.blade.php, which embed
// purchase.blade.php (and thus this script) in an iframe/modal to let the user purchase inline.

// Holds the full stock/products/{id} API response for the currently selected product
// (set on ProductPicker "change", read by RefreshPriceHint() and PrefillBestBeforeDate())
var CurrentProductDetails;

// Handles the purchase form submit: validates, builds and POSTs the stock/products/{id}/add
// payload, then (depending on context) resets the form for the next purchase or notifies the
// embedding parent window/dialog.
$('#save-purchase-button').on('click', function (e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("purchase-form", true))
	{
		return;
	}

	// Don't submit while a combobox dropdown is still open (e.g. Enter was meant to select an option)
	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonForm = $('#purchase-form').serializeJSON();

	Victual.FrontendHelpers.BeginUiBusy("purchase-form");

	// Re-fetch the current product details (tare weight, stock QU, ...) right before submitting
	Victual.Api.Get('stock/products/' + jsonForm.product_id,
		function (productDetails)
		{
			var jsonData = {};
			jsonData.amount = jsonForm.amount; // Already converted to the product's stock quantity unit by ProductAmountPicker (hidden #amount field)
			jsonData.note = jsonForm.note;
			jsonData.stock_label_type = jsonForm.stock_label_type;

			if (!Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING)
			{
				jsonData.price = 0;
			}
			else
			{
				// Price/amount math: the API always expects a price per stock quantity unit.
				// #display_amount is entered in the QU currently selected in #qu_id; when tare
				// weight handling is enabled that select is locked to the stock QU (factor 1),
				// so subtracting tare_weight (also defined in the stock QU) directly is valid.
				var amount = Number.parseFloat(jsonForm.display_amount);
				if (BoolVal(productDetails.product.enable_tare_weight_handling))
				{
					amount -= productDetails.product.tare_weight;
				}

				// data-qu-factor = how many units of the selected (purchase) QU equal 1 stock QU unit,
				// so price-per-selected-QU * factor = price-per-stock-QU
				var price = Number.parseFloat(jsonForm.price * $("#qu_id option:selected").attr("data-qu-factor")).toFixed(Victual.UserSettings.stock_decimal_places_prices_input);
				if ($("input[name='price-type']:checked").val() == "total-price")
				{
					// jsonForm.price was a total price for the whole purchased amount -> divide by the (tare adjusted) amount to get the per-stock-unit price
					price = (price / amount).toFixed(Victual.UserSettings.stock_decimal_places_prices_input);
				}

				jsonData.price = price;
			}

			if (BoolVal(Victual.UserSettings.show_purchased_date_on_purchase))
			{
				jsonData.purchased_date = Victual.Components.SecondaryDateTimePicker.GetValue();
			}

			if (Victual.Components.DateTimePicker)
			{
				jsonData.best_before_date = Victual.Components.DateTimePicker.GetValue();
			}
			else
			{
				jsonData.best_before_date = null;
			}

			if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING)
			{
				jsonData.shopping_location_id = Victual.Components.ShoppingLocationPicker.GetValue();
			}
			if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
			{
				jsonData.location_id = Victual.Components.LocationPicker.GetValue();
			}

			// Book the purchase via the stock API
			Victual.Api.Post('stock/products/' + jsonForm.product_id + '/add', jsonData,
				function (result)
				{
					// If this purchase was triggered by scanning a known barcode, keep its last_price up to date
					if ($("#purchase-form").hasAttr("data-used-barcode"))
					{
						Victual.Api.Put('objects/product_barcodes/' + $("#purchase-form").attr("data-used-barcode"), { last_price: $("#price").val() },
							function (result)
							{ },
							function (xhr)
							{ }
						);
					}

					if (BoolVal(Victual.UserSettings.scan_mode_purchase_enabled))
					{
						Victual.UISound.Success();
					}

					// "Add barcode to existing product" flow: also persist the scanned barcode/qu/amount/note as a new product_barcodes row
					if (GetUriParam("flow") == "InplaceAddBarcodeToExistingProduct")
					{
						var jsonDataBarcode = {};
						jsonDataBarcode.barcode = GetUriParam("barcode");
						jsonDataBarcode.product_id = jsonForm.product_id;
						jsonDataBarcode.shopping_location_id = jsonForm.shopping_location_id;
						jsonDataBarcode.qu_id = jsonForm.qu_id;
						jsonDataBarcode.amount = jsonForm.display_amount;
						jsonDataBarcode.note = jsonForm.note;

						Victual.Api.Post('objects/product_barcodes', jsonDataBarcode,
							function (result)
							{
								$("#flow-info-InplaceAddBarcodeToExistingProduct").addClass("d-none");
								$('#barcode-lookup-disabled-hint').addClass('d-none');
								$('#barcode-lookup-hint').removeClass('d-none');
								window.history.replaceState({}, document.title, U("/purchase"));
							},
							function (xhr)
							{
								Victual.FrontendHelpers.EndUiBusy("purchase-form");
								Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
							}
						);
					}

					// Build the toastr success message (with an inline "Undo" link) shown after a successful purchase
					var amountMessage = Number.parseFloat(jsonForm.amount).toLocaleString({ minimumFractionDigits: 0, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_amounts });
					if (BoolVal(productDetails.product.enable_tare_weight_handling))
					{
						amountMessage = Number.parseFloat(jsonForm.amount) - productDetails.stock_amount - productDetails.product.tare_weight;
					}
					// Product and quantity unit names are text columns rendered into a
					// toastr message, which is an HTML sink - escaped at the point of use
					// (sweep finding S29).
					var successMessage = __t('Added %1$s of %2$s to stock', amountMessage + " " + __n(amountMessage, Victual.FrontendHelpers.EscapeHtml(productDetails.quantity_unit_stock.name), Victual.FrontendHelpers.EscapeHtml(productDetails.quantity_unit_stock.name_plural), true), Victual.FrontendHelpers.EscapeHtml(productDetails.product.name)) + '<br><a class="btn btn-secondary btn-sm mt-2" href="#" onclick="UndoStockTransaction(\'' + result[0].transaction_id + '\')"><i class="fa-solid fa-undo"></i> ' + __t("Undo") + '</a>';

					// Label printing (plan 32): StockService::AddProduct now issues the label and
					// enqueues its print job itself, inside the same transaction as the
					// booking, keyed off the stock_label_type the form already posted. There is
					// nothing left for the client to fire.

					Victual.EditObjectId = result[0].transaction_id;
					if (GetUriParam("embedded") !== undefined)
					{
						// Embedded (dialog) mode: save the stock userfields, then tell the parent window about the
						// change and close this dialog instead of resetting the in-place form
						Victual.Components.UserfieldsForm.Save(function ()
						{
							Victual.GetTopmostWindow().postMessage(WindowMessageBag("BroadcastMessage", WindowMessageBag("ProductChanged", jsonForm.product_id)), Victual.BaseUrl);
							window.parent.postMessage(WindowMessageBag("AfterItemAdded", GetUriParam("listitemid")), Victual.BaseUrl);
							window.parent.postMessage(WindowMessageBag("ShowSuccessMessage", successMessage), Victual.BaseUrl);
							window.parent.postMessage(WindowMessageBag("Ready"), Victual.BaseUrl);

							if (GetUriParam("flow") != "shoppinglistitemtostock")
							{
								window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
							}
						});
					}
					else
					{
						// Standalone page mode: save userfields, show the success toast and reset the form/pickers
						// so the next product can be purchased right away
						Victual.Components.UserfieldsForm.Save(function ()
						{
							Victual.FrontendHelpers.EndUiBusy("purchase-form");
							toastr.success(successMessage);
							Victual.Components.ProductPicker.FinishFlow();

							if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_TRACKING && BoolVal(Victual.UserSettings.show_warning_on_purchase_when_due_date_is_earlier_than_next))
							{
								if (moment(jsonData.best_before_date).isBefore(CurrentProductDetails.next_due_date))
								{
									toastr.warning(__t("This is due earlier than already in stock items"));
								}
							}

							Victual.Components.ProductAmountPicker.Reset();
							Victual.Components.ProductPicker.Clear();
							$("#purchase-form").removeAttr("data-used-barcode");
							$("#display_amount").attr("min", Victual.DefaultMinAmount);
							$('#display_amount').val(Victual.UserSettings.stock_default_purchase_amount);
							$(".input-group-productamountpicker").trigger("change");
							$('#price').val('');
							$("#tare-weight-handling-info").addClass("d-none");
							if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
							{
								Victual.Components.LocationPicker.Clear();
							}
							if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_TRACKING)
							{
								Victual.Components.DateTimePicker.Clear();
							}
							if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING)
							{
								Victual.Components.ShoppingLocationPicker.SetValue('');
							}
							Victual.Components.ProductPicker.GetInputElement().focus();
							Victual.Components.ProductCard.Refresh(jsonForm.product_id);
							if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_LABELS)
							{
								$("#stock_label_type").val(0);
							}

							$('#price-hint').text("");
							$('#note').val("");
							var priceTypeUnitPrice = $("#price-type-unit-price");
							var priceTypeUnitPriceLabel = $("[for=" + priceTypeUnitPrice.attr("id") + "]");
							priceTypeUnitPriceLabel.text(__t("Unit price"));
							Victual.Components.UserfieldsForm.Clear();

							Victual.FrontendHelpers.ValidateForm('purchase-form');
						});
					}
				},
				function (xhr)
				{
					Victual.FrontendHelpers.EndUiBusy("purchase-form");
					Victual.Api.DefaultErrorHandler(xhr);
				}
			);
		},
		function (xhr)
		{
			Victual.FrontendHelpers.EndUiBusy("purchase-form");
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});

// Core barcode/product lookup flow: fires whenever a product gets selected (typed, picked or
// via barcode scan through the ProductPicker component) and prefills the whole purchase form
// (quantity unit, amount, price, store, location, due date, label type) from stock/products/{id},
// then - if the product itself was resolved via a barcode - looks up that barcode's own defaults
// (objects/product_barcodes_view) to override amount/qu/price/store/note, and finally triggers
// scan-mode auto-submit.
if (Victual.Components.ProductPicker !== undefined)
{
	Victual.Components.ProductPicker.GetPicker().on('change', function (e)
	{
		if (BoolVal(Victual.UserSettings.scan_mode_purchase_enabled))
		{
			Victual.UISound.BarcodeScannerBeep();
		}

		var productId = $(e.target).val();

		if (productId)
		{
			Victual.Components.ProductCard.Refresh(productId);

			// Load full product details and use them to prefill the rest of the form
			Victual.Api.Get('stock/products/' + productId,
				function (productDetails)
				{
					CurrentProductDetails = productDetails;

					// Reload the QU dropdown with the conversions applicable to this product
					Victual.Components.ProductAmountPicker.Reload(productDetails.product.id, productDetails.quantity_unit_stock.id);
					if (productDetails.product.enable_tare_weight_handling == 1)
					{
						// Tare weight handling: lock the QU picker to the stock quantity unit so
						// display_amount/tare_weight/amount all share the same unit (factor 1)
						Victual.Components.ProductAmountPicker.SetQuantityUnit(productDetails.quantity_unit_stock.id);
						$("#qu_id").attr("disabled", "");
					}
					else
					{
						Victual.Components.ProductAmountPicker.SetQuantityUnit(productDetails.default_quantity_unit_purchase.id);
					}
					$('#display_amount').val(Victual.UserSettings.stock_default_purchase_amount);
					$(".input-group-productamountpicker").trigger("change");

					// Coming from the shopping list "add to stock" flow: prefill QU/amount from the shopping list item (?quId=&amount=)
					if (GetUriParam("flow") === "shoppinglistitemtostock")
					{
						Victual.Components.ProductAmountPicker.SetQuantityUnit(GetUriParam("quId"));
						$('#display_amount').val(Number.parseFloat(GetUriParam("amount") * $("#qu_id option:selected").attr("data-qu-factor")));
					}

					$(".input-group-productamountpicker").trigger("change");

					// Prefill the store: prefer the product's last used shopping location, fall back to its default one
					if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING)
					{
						if (productDetails.last_shopping_location_id != null)
						{
							Victual.Components.ShoppingLocationPicker.SetId(productDetails.last_shopping_location_id);
						}
						else
						{
							Victual.Components.ShoppingLocationPicker.SetId(productDetails.default_shopping_location_id);
						}
					}

					if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
					{
						Victual.Components.LocationPicker.SetId(productDetails.location.id);
					}

					// Prefill the unit price field from the product's last purchase price (stored per stock QU),
					// converted back to a price per the currently selected QU by dividing by its factor
					if (productDetails.last_price == null || productDetails.last_price == 0)
					{
						$("#price").val("")
					}
					else
					{
						$('#price').val((productDetails.last_price / Number.parseFloat($("#qu_id option:selected").attr("data-qu-factor"))).toFixed(Victual.UserSettings.stock_decimal_places_prices_display));
					}

					var priceTypeUnitPrice = $("#price-type-unit-price");
					var priceTypeUnitPriceLabel = $("[for=" + priceTypeUnitPrice.attr("id") + "]");
					priceTypeUnitPriceLabel.text($("#qu_id option:selected").text() + " " + __t("price"));

					RefreshPriceHint();

					if (productDetails.product.enable_tare_weight_handling == 1)
					{
						// Minimum weighable amount = the container's tare weight plus what's already in stock
						// (QU is locked to the stock unit here, so the factor is 1 and this stays in stock units)
						var minAmount = productDetails.product.tare_weight / $("#qu_id option:selected").attr("data-qu-factor") + productDetails.stock_amount;
						$("#display_amount").attr("min", minAmount);
						$("#tare-weight-handling-info").removeClass("d-none");
					}
					else
					{
						$("#display_amount").attr("min", Victual.DefaultMinAmount);
						$("#tare-weight-handling-info").addClass("d-none");
					}

					// Suggest a due date based on the product's default due days (and freezer defaults, if applicable)
					PrefillBestBeforeDate(productDetails.product, productDetails.location);

					if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_LABELS)
					{
						$("#stock_label_type").val(productDetails.product.default_stock_label_type);
						$("#stock_label_type").trigger("change");
					}

					// Preselect the product's default purchase price type (unit price / total price)
					if (productDetails.product.default_purchase_price_type == 2)
					{
						$("#price-type-unit-price").click();
					}
					else if (productDetails.product.default_purchase_price_type == 3)
					{
						$("#price-type-total-price").click();
					}

					setTimeout(function ()
					{
						$('#display_amount').focus();
					}, Victual.FormFocusDelay);

					Victual.FrontendHelpers.ValidateForm('purchase-form');
					if (GetUriParam("flow") === "shoppinglistitemtostock" && BoolVal(Victual.UserSettings.shopping_list_to_stock_workflow_auto_submit_when_prefilled) && Victual.FrontendHelpers.ValidateForm("purchase-form"))
					{
						$("#save-purchase-button").click();
					}

					RefreshLocaleNumberInput();

					// If the product was resolved via a barcode (data-attribute "barcode" set on the hidden
					// #product_id input by the ProductPicker component), look up that barcode's own product_barcodes
					// row (amount/qu/store/price/note) to override the generic product defaults set above
					if (document.getElementById("product_id").getAttribute("barcode") != "null")
					{
						Victual.Api.Get('objects/product_barcodes_view?query[]=barcode=' + document.getElementById("product_id").getAttribute("barcode"),
							function (barcodeResult)
							{
								if (barcodeResult && barcodeResult.length > 0)
								{
									var barcode = barcodeResult[0];
									$("#purchase-form").attr("data-used-barcode", barcode.id);

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

										if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING && barcode.shopping_location_id != null)
										{
											Victual.Components.ShoppingLocationPicker.SetId(barcode.shopping_location_id);
										}

										if (barcode.last_price)
										{
											$("#price").val(barcode.last_price);
											$("#price-type-total-price").click();
										}

										if (barcode.note)
										{
											$("#note").val(barcode.note);
										}

										$(".input-group-productamountpicker").trigger("change");
										Victual.FrontendHelpers.ValidateForm('purchase-form');
										RefreshLocaleNumberInput();
									}
								}

								// Barcode has a defined amount, so don't force it back to a single unit
								ScanModeSubmit(false);
							}
						);
					}
					else
					{
						// No matching barcode: purchase was triggered by manual product selection, default scan-mode amount to 1
						$("#purchase-form").removeAttr("data-used-barcode");
						ScanModeSubmit();
					}

					$('#display_amount').trigger("keyup");
				}
			);
		}
	});
}

/**
 * Prefills the #best_before_date DateTimePicker with a suggested due date, based on the
 * product's default_best_before_days (or default_best_before_days_after_freezing when the
 * selected stock location is a freezer). Never overwrites a date the user entered themselves.
 * @param {Object} product The product object (as returned by stock/products/{id}.product)
 * @param {Object} location The currently selected stock location object (may be null/undefined)
 */
function PrefillBestBeforeDate(product, location)
{
	if (!Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_TRACKING)
	{
		return;
	}

	if (location == null)
	{
		location = {}
	}

	var shortcutValue = $("#datetimepicker-shortcut").attr("data-datetimepicker-shortcut-value");
	var dueDateCurrent = Victual.Components.DateTimePicker.GetValue();
	var dueDateDefault = null;
	var dueDateFreezer = null;

	if (product.default_best_before_days != 0)
	{
		dueDateDefault = moment().add(product.default_best_before_days, 'days').format('YYYY-MM-DD');

		if (product.default_best_before_days == -1)
		{
			dueDateDefault = shortcutValue;
		}
	}

	if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_PRODUCT_FREEZING && BoolVal(location.is_freezer) && product.default_best_before_days_after_freezing != 0)
	{
		dueDateFreezer = moment().add(product.default_best_before_days_after_freezing, 'days').format('YYYY-MM-DD');

		if (product.default_best_before_days_after_freezing == -1)
		{
			dueDateFreezer = shortcutValue;
		}
	}

	// Set the default due date when currently no one is set
	if (dueDateDefault && !dueDateCurrent)
	{
		if (!$("#datetimepicker-shortcut").is(":checked") && dueDateDefault == shortcutValue)
		{
			$("#datetimepicker-shortcut").click();
		}
		else
		{
			Victual.Components.DateTimePicker.SetValue(dueDateDefault);
		}
	}

	// Set the default due date after freezing when currently no one is set or when it was previously set to the default due date
	// (so essentially don't overwrite a by the user different entered due date)
	if (dueDateFreezer && (!dueDateCurrent || dueDateCurrent == dueDateDefault))
	{
		if (!$("#datetimepicker-shortcut").is(":checked") && dueDateFreezer == shortcutValue)
		{
			$("#datetimepicker-shortcut").click();
		}
		else
		{
			Victual.Components.DateTimePicker.SetValue(dueDateFreezer);
		}
	}
}

// Re-run the due date prefill whenever the target stock location changes, so moving to/from a freezer location updates the suggested due date
if (Victual.Components.LocationPicker !== undefined)
{
	Victual.Components.LocationPicker.GetPicker().on('change', function (e)
	{
		if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_PRODUCT_FREEZING)
		{
			Victual.Api.Get('objects/locations/' + Victual.Components.LocationPicker.GetValue(),
				function (location)
				{
					PrefillBestBeforeDate(CurrentProductDetails.product, location);
				},
				function (xhr)
				{ }
			);
		}
	});
}

// Initial page setup: seed the amount field with the user's default purchase amount and validate the still-empty form
$('#display_amount').val(Victual.UserSettings.stock_default_purchase_amount);
RefreshLocaleNumberInput();
$(".input-group-productamountpicker").trigger("change");
Victual.FrontendHelpers.ValidateForm('purchase-form');

if (Victual.Components.ProductPicker)
{
	if (Victual.Components.ProductPicker.InAnyFlow() === false && GetUriParam("embedded") === undefined)
	{
		// Plain page load, no product preselected: focus the product picker so scanning/typing can start right away
		setTimeout(function ()
		{
			Victual.Components.ProductPicker.GetInputElement().focus();
		}, Victual.FormFocusDelay);
	}
	else
	{
		// A product was already preselected by the picker (e.g. via URL flow/barcode/copy) - trigger its change handler to prefill the form
		Victual.Components.ProductPicker.GetPicker().trigger('change');

		if (Victual.Components.ProductPicker.InProductModifyWorkflow())
		{
			setTimeout(function ()
			{
				Victual.Components.ProductPicker.GetInputElement().focus();
			}, Victual.FormFocusDelay);
		}
	}
}

// Focusing the amount field with no product selected yet redirects focus back to the product picker instead
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

$('#price').on('focus', function (e)
{
	$(this).select();
});

// Re-validate on every keystroke so button state / invalid-feedback stays current
$('#purchase-form input').keyup(function (event)
{
	Victual.FrontendHelpers.ValidateForm('purchase-form');
});

// Enter key anywhere in the form submits the purchase (when valid)
$('#purchase-form input').keydown(function (event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('purchase-form'))
		{
			return false;
		}
		else
		{
			$('#save-purchase-button').click();
		}
	}
});

// Due date picker: re-validate the form on every change/keypress
if (Victual.Components.DateTimePicker)
{
	Victual.Components.DateTimePicker.GetInputElement().on('change', function (e)
	{
		Victual.FrontendHelpers.ValidateForm('purchase-form');
	});

	Victual.Components.DateTimePicker.GetInputElement().on('keypress', function (e)
	{
		Victual.FrontendHelpers.ValidateForm('purchase-form');
	});
}

// Purchased date picker (only rendered when show_purchased_date_on_purchase is enabled): same re-validation, plus an initial input trigger
if (Victual.Components.SecondaryDateTimePicker)
{
	Victual.Components.SecondaryDateTimePicker.GetInputElement().on('change', function (e)
	{
		Victual.FrontendHelpers.ValidateForm('purchase-form');
	});

	Victual.Components.SecondaryDateTimePicker.GetInputElement().on('keypress', function (e)
	{
		Victual.FrontendHelpers.ValidateForm('purchase-form');
	});

	Victual.Components.SecondaryDateTimePicker.GetInputElement().trigger("input");
}

// Recompute the "means X per Y" price hint whenever the price, its type (unit/total) or the amount changes
$('#price').on('keyup', function (e)
{
	RefreshPriceHint();
});

$('#price-type-unit-price').on('change', function (e)
{
	RefreshPriceHint();
});

$('#price-type-total-price').on('change', function (e)
{
	RefreshPriceHint();
});

$('#display_amount').on('change', function (e)
{
	RefreshPriceHint();
	Victual.FrontendHelpers.ValidateForm('purchase-form');
});

/**
 * Updates the "means X per Y" hint text (#price-hint) shown below the price field, converting
 * the entered price into a price per stock quantity unit for display - mirrors the same
 * unit-price/total-price/QU-factor math used to build jsonData.price in the save handler above,
 * but rendered with stock_decimal_places_prices_display instead of being persisted.
 * Only shown when there is something to convert: total-price mode, or a purchase QU different
 * from the stock QU (#qu_id's data-destination-qu-name). Reads CurrentProductDetails for tare weight handling.
 */
function RefreshPriceHint()
{
	if ($('#amount').val() == 0 || $('#price').val() == 0)
	{
		$('#price-hint').text("");
		return;
	}

	if ($("input[name='price-type']:checked").val() == "total-price" || $("#qu_id").attr("data-destination-qu-name") != $("#qu_id option:selected").text())
	{
		var amount = Number.parseFloat($('#display_amount').val());
		if (BoolVal(CurrentProductDetails.product.enable_tare_weight_handling))
		{
			amount -= CurrentProductDetails.product.tare_weight;
		}

		// price-per-selected-QU * factor (selected-QU units per 1 stock-QU unit) = price-per-stock-QU
		var price = Number.parseFloat($('#price').val() * $("#qu_id option:selected").attr("data-qu-factor")).toFixed(Victual.UserSettings.stock_decimal_places_prices_display);
		if ($("input[name='price-type']:checked").val() == "total-price")
		{
			price = (price / amount).toFixed(Victual.UserSettings.stock_decimal_places_prices_display);
		}

		$('#price-hint').text(__t('means %1$s per %2$s', price.toLocaleString(undefined, { style: "currency", currency: Victual.Currency, minimumFractionDigits: Victual.UserSettings.stock_decimal_places_prices_display, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_prices_display }), $("#qu_id").attr("data-destination-qu-name")));
	}
	else
	{
		$('#price-hint').text("");
	}
};

// Requesting the notification-sound permission the first time scan mode gets enabled
$("#scan-mode").on("change", function (e)
{
	if ($(this).prop("checked"))
	{
		Victual.UISound.AskForPermission();
	}
});

// Header "Scan mode" toggle button: mirrors/toggles the underlying (hidden) #scan-mode checkbox and its on/off label + styling
$("#scan-mode-button").on("click", function (e)
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

// Keep the "Unit price" radio label in sync with the currently selected quantity unit (e.g. "kg price") and refresh the price hint
$('#qu_id').on('change', function (e)
{
	var priceTypeUnitPrice = $("#price-type-unit-price");
	var priceTypeUnitPriceLabel = $("[for=" + priceTypeUnitPrice.attr("id") + "]");
	priceTypeUnitPriceLabel.text($("#qu_id option:selected").text() + " " + __t("price"));
	RefreshPriceHint();
});

/**
 * When scan mode is enabled, auto-submits the purchase form after a barcode/product was
 * resolved and the form validates - called after the barcode lookup flow completes.
 * @param {boolean} [singleUnit=true] When true, forces #display_amount to 1 before submitting
 *   (used when no explicit amount was found on the scanned barcode); pass false when the
 *   barcode already provided its own amount so it should be kept as-is.
 */
function ScanModeSubmit(singleUnit = true)
{
	if (BoolVal(Victual.UserSettings.scan_mode_purchase_enabled))
	{
		if (singleUnit)
		{
			$("#display_amount").val(1);
			$(".input-group-productamountpicker").trigger("change");
		}

		Victual.FrontendHelpers.ValidateForm("purchase-form");
		if (Victual.FrontendHelpers.ValidateForm('purchase-form'))
		{
			$('#save-purchase-button').click();
		}
		else
		{
			toastr.warning(__t("Scan mode is on but not all required fields could be populated automatically"));
			Victual.UISound.Error();
		}
	}
}

// "Label per unit" hint: shows how many labels will be printed (one per stock unit) based on the current stock amount
if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_LABELS)
{
	$("#stock_label_type, #amount").on("change", function (e)
	{
		if ($("#stock_label_type").val() == 2)
		{
			$("#stock-entry-label-info").text(__n(Number.parseFloat($("#amount").val()), "This means 1 label will be printed", "This means %1$s labels will be printed"));
		}
		else
		{
			$("#stock-entry-label-info").text("");
		}
	});
}

// Load the stock entity's userfields into the purchase form
if (Victual.Components.UserfieldsForm)
{
	Victual.Components.UserfieldsForm.Load();
}
