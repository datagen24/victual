// Powers the stock entries view (stockentries.blade.php): lists individual stock rows
// (optionally filtered to one product via Victual.Components.ProductPicker), and lets the
// user consume/open/undo a booking or print a Grocycode label directly from a row.
var stockEntriesTable = $('#stockentries-table').DataTable({
	'order': [[2, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 },
		{ 'visible': false, 'targets': 10 },
		{ "type": "num", "targets": 1 },
		{ "type": "custom-sort", "targets": 3 },
		{ "type": "html", "targets": 4 },
		{ "type": "custom-sort", "targets": 7 },
		{ "type": "html", "targets": 8 },
		{ "type": "html", "targets": 9 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#stockentries-table tbody').removeClass("d-none");
stockEntriesTable.columns.adjust().draw();

// Custom DataTables search plugin: restricts rows to the product selected in the
// product picker (column 1 holds the row's product id), or shows all when none is selected
$.fn.dataTable.ext.search.push(function(settings, data, dataIndex)
{
	var productId = Victual.Components.ProductPicker.GetValue();

	if (!productId || Number.isNaN(productId) || productId == data[stockEntriesTable.colReorder.transpose(1)])
	{
		return true;
	}

	return false;
});

// Custom DataTables search plugin: restricts rows to the location selected in the location
// filter and to everything stored beneath it. The row carries its location's id followed by
// every ancestor's in data-location-ancestors, so "is this row at or below the selected
// location" is one membership test. The commas around both sides are what stop id 3 from
// matching id 13.
$.fn.dataTable.ext.search.push(function(settings, data, dataIndex)
{
	if (settings.nTable !== $("#stockentries-table").get(0))
	{
		return true;
	}

	var value = $("#location-filter").val();

	if (!value || value === "all")
	{
		return true;
	}

	var cell = $(stockEntriesTable.row(dataIndex).node()).find("td[data-location-ancestors]").first();

	if (cell.length === 0)
	{
		return false;
	}

	return ("," + cell.attr("data-location-ancestors") + ",").indexOf("," + value + ",") !== -1;
});

// Resets the location filter and (unless embedded, e.g. opened from a product's stock
// entries link) the product picker
$("#clear-filter-button").on("click", function()
{
	$("#location-filter").val("all");
	$("#location-filter").trigger("change");

	if (GetUriParam("embedded") === undefined)
	{
		Victual.Components.ProductPicker.Clear();
	}

	stockEntriesTable.draw();
});

// Location filter dropdown. Matched against the row's data-location-ancestors attribute
// rather than against the location column's text, so that selecting a location finds the
// entries stored anywhere beneath it as well - plan 08 question 4 wanted the roll-up for
// filtering. Matching on text cannot do this: it would need the option's own path to be a
// prefix of the cell's, which is true of "Basement" and "Basement / StorageRoom" but also
// true of any location whose name merely starts the same way.
$("#location-filter").on("change", function()
{
	stockEntriesTable.draw();
});

// Re-run the custom search filter whenever the product picker's value or its text input changes
Victual.Components.ProductPicker.GetPicker().on('change', function(e)
{
	stockEntriesTable.draw();
});

Victual.Components.ProductPicker.GetInputElement().on('keyup', function(e)
{
	stockEntriesTable.draw();
});

// Consumes (or marks spoiled) a specific stock entry's amount, shows a toast with an
// "Undo" action, and refreshes that entry's row in place
$(document).on('click', '.stock-consume-button', function(e)
{
	e.preventDefault();

	Victual.FrontendHelpers.BeginUiBusy();

	var productId = $(e.currentTarget).attr('data-product-id');
	var locationId = $(e.currentTarget).attr('data-location-id');
	var specificStockEntryId = $(e.currentTarget).attr('data-stock-id');
	var stockRowId = $(e.currentTarget).attr('data-stockrow-id');
	var consumeAmount = Number.parseFloat($(e.currentTarget).attr('data-consume-amount'));

	var wasSpoiled = $(e.currentTarget).hasClass("stock-consume-button-spoiled");

	Victual.Api.Post('stock/products/' + productId + '/consume', { 'amount': consumeAmount, 'spoiled': wasSpoiled, 'location_id': locationId, 'stock_entry_id': specificStockEntryId, 'exact_amount': true },
		function(bookingResponse)
		{
			Victual.Api.Get('stock/products/' + productId,
				function(result)
				{
					// The product and quantity unit names are text columns rendered into a
					// toastr message, which is an HTML sink - escaped at the point of use
					// (sweep finding S29). The Undo anchor appended below is deliberate markup.
					var toastMessage = __t('Removed %1$s of %2$s from stock', consumeAmount.toLocaleString({ minimumFractionDigits: 0, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_amounts }) + " " + __n(consumeAmount, Victual.FrontendHelpers.EscapeHtml(result.quantity_unit_stock.name), Victual.FrontendHelpers.EscapeHtml(result.quantity_unit_stock.name_plural), true), Victual.FrontendHelpers.EscapeHtml(result.product.name));
					if (wasSpoiled)
					{
						toastMessage += "<br>(" + __t("Spoiled") + ")";
					}
					toastMessage += '<br><a class="btn btn-secondary btn-sm mt-2" href="#" onclick="UndoStockBookingEntry(' + bookingResponse[0].id + ',' + stockRowId + ', ' + bookingResponse[0].product_id + ')"><i class="fa-solid fa-undo"></i> ' + __t("Undo") + '</a>';

					Victual.FrontendHelpers.EndUiBusy();
					RefreshStockEntryRow(stockRowId);
					toastr.success(toastMessage);
					Victual.GetTopmostWindow().postMessage(WindowMessageBag("BroadcastMessage", WindowMessageBag("ProductChanged", productId)), Victual.BaseUrl);
				},
				function(xhr)
				{
					Victual.FrontendHelpers.EndUiBusy();
					Victual.Api.DefaultErrorHandler(xhr);
				}
			);
		},
		function(xhr)
		{
			Victual.FrontendHelpers.EndUiBusy();
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});

// Marks a specific stock entry's amount as opened; optionally moves it to a default
// "consume location" (server-driven, reported back via result.product.move_on_open)
$(document).on('click', '.product-open-button', function(e)
{
	e.preventDefault();

	Victual.FrontendHelpers.BeginUiBusy();

	var productId = $(e.currentTarget).attr('data-product-id');
	var specificStockEntryId = $(e.currentTarget).attr('data-stock-id');
	var stockRowId = $(e.currentTarget).attr('data-stockrow-id');
	var openAmount = Number.parseFloat($(e.currentTarget).attr('data-open-amount'));
	var button = $(e.currentTarget);

	Victual.Api.Post('stock/products/' + productId + '/open', { 'amount': openAmount, 'stock_entry_id': specificStockEntryId },
		function(bookingResponse)
		{
			Victual.Api.Get('stock/products/' + productId,
				function(result)
				{
					button.addClass("disabled");
					Victual.FrontendHelpers.EndUiBusy();
					toastr.success(__t('Marked %1$s of %2$s as opened', openAmount.toLocaleString({ minimumFractionDigits: 0, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_amounts }) + " " + __n(openAmount, Victual.FrontendHelpers.EscapeHtml(result.quantity_unit_stock.name), Victual.FrontendHelpers.EscapeHtml(result.quantity_unit_stock.name_plural), true), Victual.FrontendHelpers.EscapeHtml(result.product.name)) + '<br><a class="btn btn-secondary btn-sm mt-2" href="#" onclick="UndoStockBookingEntry(' + bookingResponse[0].id + ',' + stockRowId + ', ' + productId + ')"><i class="fa-solid fa-undo"></i> ' + __t("Undo") + '</a>');

					if (result.product.move_on_open == 1 && result.default_consume_location != null)
					{
						toastr.info('<span>' + __t("Moved to %1$s", Victual.FrontendHelpers.EscapeHtml(result.default_consume_location.name)) + "</span> <i class='fa-solid fa-exchange-alt'></i>");
					}

					RefreshStockEntryRow(stockRowId);
					Victual.GetTopmostWindow().postMessage(WindowMessageBag("BroadcastMessage", WindowMessageBag("ProductChanged", productId)), Victual.BaseUrl);
				},
				function(xhr)
				{
					Victual.FrontendHelpers.EndUiBusy();
					Victual.Api.DefaultErrorHandler(xhr);
				}
			);
		},
		function(xhr)
		{
			Victual.FrontendHelpers.EndUiBusy();
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});

// Fetches label data for a stock entry's Grocycode and forwards it to the configured
// label printer webhook (Victual.Webhooks.labelprinter), if any is set up
$(document).on('click', '.stockentry-grocycode-label-print', function(e)
{
	e.preventDefault();

	var stockId = $(e.currentTarget).attr('data-stock-id');
	Victual.Api.Get('stock/entry/' + stockId + '/printlabel', function(labelData)
	{
		if (Victual.Webhooks.labelprinter !== undefined)
		{
			Victual.FrontendHelpers.RunWebhook(Victual.Webhooks.labelprinter, labelData);
		}
	});
});

/**
 * Re-fetches a single stock entry (stock/entry/{id}) and updates its table row in place
 * (amount, due/purchase dates, location, price, opened state, styling for due/overdue),
 * rather than reloading the whole table. Falls back to a full page reload if the row
 * can no longer be found (e.g. after an undo created a different row id). Hides the row
 * entirely if the entry's amount has dropped to zero.
 * @param {number|string} stockRowId - stock entry id, matching the "stock-{id}-row" DOM id
 */
function RefreshStockEntryRow(stockRowId)
{
	Victual.Api.Get("stock/entry/" + stockRowId,
		function(result)
		{
			var stockRow = $('#stock-' + stockRowId + '-row');

			// If the stock row not exists / is invisible (happens after consume/undo because the undone new stock row has different id), just reload the page for now
			if (!stockRow.length || stockRow.hasClass("d-none"))
			{
				window.location.reload();
			}

			if (result == null || result.amount == 0)
			{
				animateCSS("#stock-" + stockRowId + "-row", "fadeOut", function()
				{
					$("#stock-" + stockRowId + "-row").addClass("d-none");
				});
			}
			else
			{
				var dueThreshold = moment().add(Victual.UserSettings.stock_due_soon_days, "days");
				var now = moment();
				var bestBeforeDate = moment(result.best_before_date);

				stockRow.removeClass("table-warning");
				stockRow.removeClass("table-danger");
				stockRow.removeClass("table-info");
				stockRow.removeClass("d-none");
				stockRow.removeAttr("style");
				if (now.isAfter(bestBeforeDate))
				{
					if (stockRow.attr("data-due-type") == 1)
					{
						stockRow.addClass("table-secondary");
					}
					else
					{
						stockRow.addClass("table-danger");
					}
				}
				else if (bestBeforeDate.isBefore(dueThreshold))
				{
					stockRow.addClass("table-warning");
				}

				animateCSS("#stock-" + stockRowId + "-row td:not(:first)", "flash");

				$('#stock-' + stockRowId + '-amount').text(result.amount);
				$('#stock-' + stockRowId + '-due-date').text(result.best_before_date);
				$('#stock-' + stockRowId + '-due-date-timeago').attr('datetime', result.best_before_date + ' 23:59:59');

				$(".stock-consume-button").attr('data-location-id', result.location_id);

				// The resolved view rather than the row, because the cell carries two things the
				// row cannot answer: the path it displays, and the ancestor chain the location
				// filter matches on. Updating the id and the text but not the ancestors would
				// leave a moved entry matching the location it came from and missing the one it
				// went to, until the page was reloaded - and nothing about the row would look
				// wrong. One request answers both: `path` is the same on every row for a given
				// descendant, and the ancestors are the rows themselves, ordered by depth so the
				// attribute reads the way the server writes it.
				Victual.Api.Get("objects/locations_resolved?query[]=descendant_location_id=" + result.location_id,
					function(resolvedRows)
					{
						resolvedRows.sort(function (left, right) { return left.depth - right.depth; });

						var locationCell = $(document).find('#stock-' + stockRowId + '-location');
						locationCell.attr('data-location-id', result.location_id);
						locationCell.attr('data-location-ancestors', resolvedRows.map(function (row) { return row.ancestor_location_id; }).join(','));
						locationCell.text(resolvedRows.length === 0 ? '' : resolvedRows[0].path);

						// The filter reads that attribute at draw time, so without this the row
						// keeps whatever visibility the old location earned it.
						stockEntriesTable.draw();
					}
				);

				Victual.Api.Get("stock/products/" + result.product_id,
					function(productDetails)
					{
						if (!result.price)
						{
							result.price = 0;
						}

						$('#stock-' + stockRowId + '-price').text(__t("%1$s per %2$s", (result.price * productDetails.qu_conversion_factor_purchase_to_stock).toLocaleString(undefined, { style: "currency", currency: Victual.Currency, minimumFractionDigits: Victual.UserSettings.stock_decimal_places_prices_display, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_prices_display }), productDetails.default_quantity_unit_purchase.name));
						$('#stock-' + stockRowId + '-price').attr("data-original-title", __t("%1$s per %2$s", result.price.toLocaleString(undefined, { style: "currency", currency: Victual.Currency, minimumFractionDigits: Victual.UserSettings.stock_decimal_places_prices_display, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_prices_display }), productDetails.quantity_unit_stock.name));

						if (productDetails.product.disable_open == 1)
						{
							$(".product-open-button[data-stockrow-id='" + stockRowId + "']").addClass("disabled");
						}
					}
				);

				$('#stock-' + stockRowId + '-note').text(result.note);
				$('#stock-' + stockRowId + '-purchased-date').text(result.purchased_date);
				$('#stock-' + stockRowId + '-purchased-date-timeago').attr('datetime', result.purchased_date + ' 23:59:59');

				if (result.shopping_location_id)
				{
					var shoppingLocationName = "";
					Victual.Api.Get("objects/shopping_locations/" + result.shopping_location_id,
						function(shoppingLocationResult)
						{
							shoppingLocationName = shoppingLocationResult.name;

							$('#stock-' + stockRowId + '-shopping-location').attr('data-shopping-location-id', result.location_id);
							$('#stock-' + stockRowId + '-shopping-location').text(shoppingLocationName);
						}
					);
				}
				else
				{
					$('#stock-' + stockRowId + '-shopping-location').text("");
				}

				if (result.open == 1)
				{
					$('#stock-' + stockRowId + '-opened-amount').text(__n(result.amount, 'Opened', 'Opened'));
				}
				else
				{
					$('#stock-' + stockRowId + '-opened-amount').text("");
					$(".product-open-button[data-stockrow-id='" + stockRowId + "']").removeClass("disabled");
				}
			}

			// Needs to be delayed because of the animation above the date-text would be wrong if fired immediately...
			setTimeout(function()
			{
				RefreshContextualTimeago("#stock-" + stockRowId + "-row");
				RefreshLocaleNumberDisplay("#stock-" + stockRowId + "-row");
			}, Victual.FormFocusDelay);
		},
		function(xhr)
		{
			Victual.FrontendHelpers.EndUiBusy();
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
}

// Reacts to a "ProductChanged" broadcast (e.g. from a stock booking elsewhere) by
// refreshing every visible row for that product
$(window).on("message", function(e)
{
	var data = e.originalEvent.data;

	if (data.Message == "ProductChanged")
	{
		$(".stock-consume-button[data-product-id='" + data.Payload + "']").each(function()
		{
			RefreshStockEntryRow($(this).attr("data-stockrow-id"));
		});
	};
});

// Apply the initial product filter (from the product picker's pre-filled value, if any)
Victual.Components.ProductPicker.GetPicker().trigger('change');
