// Powers the stock overview view (stockoverview.blade.php): the main per-product stock
// table (aggregated by product, one row per product/parent-product), the "due
// soon"/overdue/expired/missing summary widgets, and the consume/open/undo actions.
// Table row and status-widget updates are done in place via RefreshProductRow()/
// RefreshStatistics() rather than a full page reload, and via the "ProductChanged"
// postMessage broadcast used by other views (purchase/consume/transfer/...) after a booking.

var stockOverviewTable = $('#stock-overview-table').DataTable({
	'order': [[5, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 },
		{ 'searchable': false, "targets": 0 },
		{ 'visible': false, 'targets': 6 },
		{ 'visible': false, 'targets': 7 },
		{ 'visible': false, 'targets': 8 },
		{ 'visible': false, 'targets': 2 },
		{ 'visible': false, 'targets': 4 },
		{ 'visible': false, 'targets': 9 },
		{ 'visible': false, 'targets': 10 },
		{ 'visible': false, 'targets': 11 },
		{ 'visible': false, 'targets': 12 },
		{ 'visible': false, 'targets': 13 },
		{ 'visible': false, 'targets': 14 },
		{ 'visible': false, 'targets': 15 },
		{ 'visible': false, 'targets': 16 },
		{ 'visible': false, 'targets': 17 },
		{ 'visible': false, 'targets': 18 },
		{ 'visible': false, 'targets': 19 },
		{ "type": "custom-sort", "targets": 3 },
		{ "type": "html-num-fmt", "targets": 9 },
		{ "type": "html-num-fmt", "targets": 10 },
		{ "type": "html", "targets": 5 },
		{ "type": "html", "targets": 11 },
		{ "type": "custom-sort", "targets": 12 },
		{ "type": "html-num-fmt", "targets": 13 },
		{ "type": "custom-sort", "targets": 4 },
		{ "type": "custom-sort", "targets": 18 }
	].concat($.fn.dataTable.defaults.columnDefs)
});

$('#stock-overview-table tbody').removeClass("d-none");
stockOverviewTable.columns.adjust().draw();

// Location filter, matched against the hidden locations column (index 6). That column lists
// the id of every location the product is stocked at *and* the id of every ancestor of each
// of those, each wrapped in "xx...xx" so a substring match on one id cannot match another.
// The ancestors are what make the filter roll up: selecting "Basement" finds a product
// stocked at "Basement / StorageRoom / UprightFreezer / Door" (plan 08 question 4). The
// option values are ids rather than names, because a name is only unique among siblings now.
$("#location-filter").on("change", function ()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}
	else
	{
		value = "xx" + value + "xx";
	}

	stockOverviewTable.column(stockOverviewTable.colReorder.transpose(6)).search(value).draw();
});

// Product group filter, matched against the hidden product-group column (index 8),
// same "xx...xx" wrapping technique as the location filter
$("#product-group-filter").on("change", function ()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}
	else
	{
		value = "xx" + value + "xx";
	}

	stockOverviewTable.column(stockOverviewTable.colReorder.transpose(8)).search(value).draw();
});

// Status filter dropdown (e.g. "below min. stock amount"/"expired"/"due soon"), matched
// against the hidden status-info column (index 7)
$("#status-filter").on("change", function ()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	// Transfer CSS classes of selected element to dropdown element (for background)
	$(this).attr("class", $("#" + $(this).attr("id") + " option[value='" + value + "']").attr("class") + " form-control");

	stockOverviewTable.column(stockOverviewTable.colReorder.transpose(7)).search(value).draw();
});

// Clicking a summary widget (due soon/overdue/expired/missing) applies that status as the filter
$(".status-filter-message").on("click", function ()
{
	var value = $(this).data("status-filter");
	$("#status-filter").val(value);
	$("#status-filter").trigger("change");
});

// Resets all filters
function ClearAllFilters()
{
	$("#search").val("");
	$("#status-filter").val("all");
	$("#product-group-filter").val("all");
	$("#location-filter").val("all");
	stockOverviewTable.column(stockOverviewTable.colReorder.transpose(6)).search("").draw();
	stockOverviewTable.column(stockOverviewTable.colReorder.transpose(7)).search("").draw();
	stockOverviewTable.column(stockOverviewTable.colReorder.transpose(8)).search("").draw();
	stockOverviewTable.search("").draw();
}

$("#clear-filter-button").on("click", ClearAllFilters);

// Free-text search box, debounced via Delay()
$("#search").on("keyup", Delay(function ()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	stockOverviewTable.search(value).draw();
}, Victual.FormFocusDelay));

// Fetches label data for a product's Grocycode and forwards it to the configured
// label printer webhook (Victual.Webhooks.labelprinter), if any is set up
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

// Consumes (or marks spoiled) the given amount of a product's aggregated stock
// (allow_subproduct_substitution lets sub-products cover the amount too), shows a toast
// with an inline "Undo" link (calling the UndoStockTransaction() helper from
// public/js/victual_stock_dialogs.js, loaded on every page), and refreshes both that
// product's row and the summary widgets
$(document).on('click', '.product-consume-button', function (e)
{
	e.preventDefault();

	Victual.FrontendHelpers.BeginUiBusy();

	var productId = $(e.currentTarget).attr('data-product-id');
	var consumeAmount = Number.parseFloat($(e.currentTarget).attr('data-consume-amount'));
	var originalTotalStockAmount = Number.parseFloat($(e.currentTarget).attr('data-original-total-stock-amount'));
	var wasSpoiled = $(e.currentTarget).hasClass("product-consume-button-spoiled");

	Victual.Api.Post('stock/products/' + productId + '/consume', { 'amount': consumeAmount, 'spoiled': wasSpoiled, 'allow_subproduct_substitution': true },
		function (bookingResponse)
		{
			Victual.Api.Get('stock/products/' + productId,
				function (result)
				{
					// For tare-weight-handled products, the toast reports the original total
					// stock amount rather than the (weight-derived) consumeAmount; the message
					// text itself is otherwise identical to the else-branch below.
					//
					// The product and quantity unit names are text columns rendered into a
					// toastr message, which is an HTML sink, so they are escaped at the point
					// of use (sweep finding S29). The Undo anchor appended after them is
					// deliberate markup, which is why toastr.options.escapeHtml is not the fix.
					if (result.product.enable_tare_weight_handling == 1)
					{
						var toastMessage = __t('Removed %1$s of %2$s from stock', originalTotalStockAmount.toLocaleString({ minimumFractionDigits: 0, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_amounts }) + " " + __n(consumeAmount, Victual.FrontendHelpers.EscapeHtml(result.quantity_unit_stock.name), Victual.FrontendHelpers.EscapeHtml(result.quantity_unit_stock.name_plural), true), Victual.FrontendHelpers.EscapeHtml(result.product.name)) + '<br><a class="btn btn-secondary btn-sm mt-2" href="#" onclick="UndoStockTransaction(\'' + bookingResponse[0].transaction_id + '\')"><i class="fa-solid fa-undo"></i> ' + __t("Undo") + '</a>';
					}
					else
					{
						var toastMessage = __t('Removed %1$s of %2$s from stock', consumeAmount.toLocaleString({ minimumFractionDigits: 0, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_amounts }) + " " + __n(consumeAmount, Victual.FrontendHelpers.EscapeHtml(result.quantity_unit_stock.name), Victual.FrontendHelpers.EscapeHtml(result.quantity_unit_stock.name_plural), true), Victual.FrontendHelpers.EscapeHtml(result.product.name)) + '<br><a class="btn btn-secondary btn-sm mt-2" href="#" onclick="UndoStockTransaction(\'' + bookingResponse[0].transaction_id + '\')"><i class="fa-solid fa-undo"></i> ' + __t("Undo") + '</a>';
					}

					if (wasSpoiled)
					{
						toastMessage += " (" + __t("Spoiled") + ")";
					}

					Victual.FrontendHelpers.EndUiBusy();
					toastr.success(toastMessage);
					RefreshStatistics();
					RefreshProductRow(productId);
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
			Victual.FrontendHelpers.EndUiBusy();
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});

// Marks the given amount of a product's aggregated stock as opened; optionally moves it
// to a default "consume location" (server-driven, reported via result.product.move_on_open)
$(document).on('click', '.product-open-button', function (e)
{
	e.preventDefault();

	Victual.FrontendHelpers.BeginUiBusy();

	var productId = $(e.currentTarget).attr('data-product-id');
	var productName = $(e.currentTarget).attr('data-product-name');
	var productQuName = $(e.currentTarget).attr('data-product-qu-name');
	var amount = Number.parseFloat($(e.currentTarget).attr('data-open-amount'));
	var button = $(e.currentTarget);

	Victual.Api.Post('stock/products/' + productId + '/open', { 'amount': amount, 'allow_subproduct_substitution': true },
		function (bookingResponse)
		{
			Victual.Api.Get('stock/products/' + productId,
				function (result)
				{
					Victual.FrontendHelpers.EndUiBusy();
					// productQuName and productName come from data- attributes read back with
					// .attr(), which returns the decoded string - so the escaping the template
					// applied when it wrote them is not in effect here (sweep finding S29).
					toastr.success(__t('Marked %1$s of %2$s as opened', amount.toLocaleString({ minimumFractionDigits: 0, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_amounts }) + " " + Victual.FrontendHelpers.EscapeHtml(productQuName), Victual.FrontendHelpers.EscapeHtml(productName)) + '<br><a class="btn btn-secondary btn-sm mt-2" href="#" onclick="UndoStockTransaction(\'' + bookingResponse[0].transaction_id + '\')"><i class="fa-solid fa-undo"></i> ' + __t("Undo") + '</a>');

					if (result.product.move_on_open == 1 && result.default_consume_location != null)
					{
						toastr.info('<span>' + __t("Moved to %1$s", Victual.FrontendHelpers.EscapeHtml(result.default_consume_location.name)) + "</span> <i class='fa-solid fa-exchange-alt'></i>");
					}

					RefreshStatistics();
					RefreshProductRow(productId);
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
			Victual.FrontendHelpers.EndUiBusy();
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});

/**
 * Refreshes the top summary widgets: the total product count/value (GET stock,
 * gated by VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING for whether value is shown), and the
 * due-soon/overdue/expired/missing-below-min-stock counts (GET stock/volatile). Products
 * with hide_on_stock_overview set are excluded from all counts. Called on load and after
 * any stock-changing action.
 */
function RefreshStatistics()
{
	Victual.Api.Get('stock',
		function (result)
		{
			if (!Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING)
			{
				$("#info-current-stock").text(__n(result.filter(x => !BoolVal(x.product.hide_on_stock_overview)).length, '%s Product', '%s Products'));
			}
			else
			{
				var valueSum = 0;
				result.forEach(element =>
				{
					valueSum += element.value;
				});

				$("#info-current-stock").text(__n(result.filter(x => !BoolVal(x.product.hide_on_stock_overview)).length, '%s Product', '%s Products') + ", " + __t('%s total value', valueSum.toLocaleString(undefined, { style: "currency", currency: Victual.Currency })));
			}
		}
	);

	var nextXDays = $("#info-duesoon-products").data("next-x-days");
	Victual.Api.Get('stock/volatile?due_soon_days=' + nextXDays,
		function (result)
		{
			var dueProducts = result.due_products.filter(x => !BoolVal(x.product.hide_on_stock_overview));
			var overdueProducts = result.overdue_products.filter(x => !BoolVal(x.product.hide_on_stock_overview));
			var expiredProducts = result.expired_products.filter(x => !BoolVal(x.product.hide_on_stock_overview));
			var missingProducts = result.missing_products.filter(x => !BoolVal(x.product.hide_on_stock_overview));

			$("#info-duesoon-products").html('<span class="d-block d-md-none">' + dueProducts.length + ' <i class="fa-solid fa-clock"></i></span><span class="d-none d-md-block">' + __n(dueProducts.length, '%s product is due', '%s products are due') + ' ' + __n(nextXDays, 'within the next day', 'within the next %s days') + '</span>');
			$("#info-overdue-products").html('<span class="d-block d-md-none">' + overdueProducts.length + ' <i class="fa-solid fa-times-circle"></i></span><span class="d-none d-md-block">' + __n(overdueProducts.length, '%s product is overdue', '%s products are overdue') + '</span>');
			$("#info-expired-products").html('<span class="d-block d-md-none">' + expiredProducts.length + ' <i class="fa-solid fa-times-circle"></i></span><span class="d-none d-md-block">' + __n(expiredProducts.length, '%s product is expired', '%s products are expired') + '</span>');
			$("#info-missing-products").html('<span class="d-block d-md-none">' + missingProducts.length + ' <i class="fa-solid fa-exclamation-circle"></i></span><span class="d-none d-md-block">' + __n(missingProducts.length, '%s product is below defined min. stock amount', '%s products are below defined min. stock amount') + '</span>');
		}
	);

	RefreshMissingProductGroups();
}

// Plan 03. The short product groups, named rather than counted: "two groups are below their
// minimum" does not tell anybody what to buy, and the whole premise of a group minimum is
// that the user picks which member to buy. So each group is listed with what it is short by,
// and clicking one filters the table to that group's products.
//
// Every value that came out of the database is placed with .text() on a node built here -
// never concatenated into a string handed to .html(). A product group name is user input and
// jQuery parses a string beginning with "<" as HTML; see AGENTS.md and plan 21. The counter
// line above is .html() only because the one value it interpolates is an array length.
function RefreshMissingProductGroups()
{
	Victual.Api.Get('objects/product_groups_missing',
		function (result)
		{
			var container = $("#info-missing-product-groups");
			var list = $("#missing-product-groups-list");
			list.empty();

			if (result.length === 0)
			{
				container.addClass("d-none");
				return;
			}

			container.removeClass("d-none");
			container.html('<span class="d-block d-md-none">' + result.length + ' <i class="fa-solid fa-layer-group"></i></span><span class="d-none d-md-block">' + __n(result.length, '%s product group is below defined min. stock amount', '%s product groups are below defined min. stock amount') + '</span>');

			result.forEach(function (group)
			{
				var button = $('<button class="btn btn-link btn-sm p-0 text-body missing-product-group-button" type="button"></button>');
				button.attr("data-product-group-name", group.name);
				button.text(group.name);

				var shortfall = $('<span class="text-muted ml-2"></span>');
				shortfall.text(__t('%s missing', group.amount_missing));

				list.append($("<li></li>").append(button).append(shortfall));
			});
		}
	);
}

// Clearing first is not tidiness. A row added to the page because its group is short has an
// empty hidden location cell and an empty hidden status cell - it is in no location and has
// no due state, both correctly - so a location or status filter left over from earlier in the
// session hides the very rows this click exists to reveal.
$(document).on("click", ".missing-product-group-button", function ()
{
	var name = $(this).attr("data-product-group-name");
	ClearAllFilters();
	$("#product-group-filter").val(name);
	$("#product-group-filter").trigger("change");
});
RefreshStatistics();

/**
 * Re-fetches a single product's stock details (stock/products/{id}) and updates its
 * table row in place (amount, value, next due date, opened/aggregated amounts, due/
 * low-stock row styling, consume/open button enabled state), rather than reloading the
 * whole table. Recurses to refresh the parent product's row too, since its aggregated
 * amount depends on this one. Hides the row entirely if stock has dropped to zero and
 * the "show all out of stock products" setting is off.
 * @param {number|string} productId - product id, matching the "product-{id}-row" DOM id
 */
function RefreshProductRow(productId)
{
	productId = productId.toString();

	Victual.Api.Get('stock/products/' + productId,
		function (result)
		{
			// Also refresh the parent product, if any
			if (result.product.parent_product_id)
			{
				RefreshProductRow(result.product.parent_product_id);
			}

			if (!result.next_due_date)
			{
				result.next_due_date = "2888-12-31"; // Unknown
			}

			var productRow = $('#product-' + productId + '-row');
			var dueSoonThreshold = moment().add($("#info-duesoon-products").data("next-x-days"), "days");
			var now = moment();
			var nextDueDate = moment(result.next_due_date);

			productRow.removeClass("table-warning");
			productRow.removeClass("table-danger");
			productRow.removeClass("table-secondary");
			productRow.removeClass("table-info");
			productRow.removeClass("d-none");
			productRow.removeAttr("style");
			if (now.isAfter(nextDueDate))
			{
				if (result.product.due_type == 1)
				{
					productRow.addClass("table-secondary");
				}
				else
				{
					productRow.addClass("table-danger");
				}
			}
			else if (nextDueDate.isBefore(dueSoonThreshold))
			{
				productRow.addClass("table-warning");
			}
			else if (result.product.min_stock_amount > 0 && result.stock_amount_aggregated < result.product.min_stock_amount)
			{
				productRow.addClass("table-info");
			}

			if (!BoolVal(Victual.UserSettings.stock_overview_show_all_out_of_stock_products) && result.stock_amount == 0 && result.stock_amount_aggregated == 0 && result.product.min_stock_amount == 0)
			{
				animateCSS("#product-" + productId + "-row", "fadeOut", function ()
				{
					$("#product-" + productId + "-row").addClass("d-none");
				});
			}
			else
			{
				animateCSS("#product-" + productId + "-row td:not(:first)", "flash");

				$('#product-' + productId + '-qu-name').text(__n(result.stock_amount, result.quantity_unit_stock.name, result.quantity_unit_stock.name_plural, true));
				$('#product-' + productId + '-amount').text(result.stock_amount);
				$('#product-' + productId + '-consume-all-button').attr('data-consume-amount', result.stock_amount);
				$('#product-' + productId + '-value').text(result.stock_value);
				$('#product-' + productId + '-next-due-date').text(result.next_due_date);
				$('#product-' + productId + '-next-due-date-timeago').attr('datetime', result.next_due_date);

				var openedAmount = result.stock_amount_opened || 0;
				if (openedAmount > 0)
				{
					$('#product-' + productId + '-opened-amount').text(__t('%s opened', openedAmount));
				}
				else
				{
					$('#product-' + productId + '-opened-amount').text("");
				}

				if (result.stock_amount_aggregated == 0)
				{
					$(".product-consume-button[data-product-id='" + productId + "']").addClass("disabled");
					$(".product-open-button[data-product-id='" + productId + "']").addClass("disabled");
				}
				else
				{
					$(".product-consume-button[data-product-id='" + productId + "']").removeClass("disabled");
					$(".product-open-button[data-product-id='" + productId + "']").removeClass("disabled");
				}

				if (result.product.disable_open == 1 || result.stock_amount == result.stock_amount_opened)
				{
					$(".product-open-button[data-product-id='" + productId + "']").addClass("disabled");
				}
			}

			$('#product-' + productId + '-next-due-date').text(result.next_due_date);
			$('#product-' + productId + '-next-due-date-timeago').attr('datetime', result.next_due_date + ' 23:59:59');

			if (result.stock_amount_opened > 0)
			{
				$('#product-' + productId + '-opened-amount').text(__t('%s opened', result.stock_amount_opened));
			}
			else
			{
				$('#product-' + productId + '-opened-amount').text("");
			}

			if (result.is_aggregated_amount == 1)
			{
				$('#product-' + productId + '-amount-aggregated').text(result.stock_amount_aggregated);

				if (result.stock_amount_opened_aggregated > 0)
				{
					$('#product-' + productId + '-opened-amount-aggregated').text(__t('%s opened', result.stock_amount_opened_aggregated));
				}
				else
				{
					$('#product-' + productId + '-opened-amount-aggregated').text("");
				}
			}

			// Needs to be delayed because of the animation above the date-text would be wrong if fired immediately...
			setTimeout(function ()
			{
				RefreshContextualTimeago("#product-" + productId + "-row");
				RefreshLocaleNumberDisplay("#product-" + productId + "-row");
			}, Victual.FormFocusDelay);
		},
		function (xhr)
		{
			Victual.FrontendHelpers.EndUiBusy();
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
}

// Reacts to a "ProductChanged" broadcast (e.g. from purchase/consume/transfer forms in
// a modal iframe elsewhere) by refreshing that product's row and the summary widgets
$(window).on("message", function (e)
{
	var data = e.originalEvent.data;

	if (data.Message === "ProductChanged")
	{
		RefreshProductRow(data.Payload);
		RefreshStatistics();
	}
});
