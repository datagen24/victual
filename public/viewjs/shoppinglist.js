// Powers the shopping list view (shoppinglist.blade.php): renders/filters the active
// shopping list, toggles item done-state, drives the "add to stock" workflow and printing.

// Main shopping list table, grouped by product group (column 3) and pre-sorted by
// the done-status column (fixed order) then by product name
var shoppingListTable = $('#shoppinglist-table').DataTable({
	'order': [[1, 'asc']],
	"orderFixed": [[3, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 },
		{ 'visible': false, 'targets': 3 },
		{ 'visible': false, 'targets': 5 },
		{ 'visible': false, 'targets': 6 },
		{ 'visible': false, 'targets': 7 },
		{ 'visible': false, 'targets': 8 },
		{ "type": "custom-sort", "targets": 2 },
		{ "type": "html-num-fmt", "targets": 5 },
		{ "type": "html-num-fmt", "targets": 6 }
	].concat($.fn.dataTable.defaults.columnDefs),
	'rowGroup': {
		enable: true,
		dataSrc: 3
	}
});
$('#shoppinglist-table tbody').removeClass("d-none");
shoppingListTable.columns.adjust().draw();

// Hidden shadow table used only to produce a grouped/sorted print layout
// (mirrors the visible table's data, grouped by product group)
var shoppingListPrintShadowTable = $('#shopping-list-print-shadow-table').DataTable({
	'order': [[0, 'asc']],
	'orderFixed': [[2, 'asc']],
	'columnDefs': [
		{ 'visible': false, 'targets': 2 },
		{ 'orderable': false, 'targets': '_all' }
	].concat($.fn.dataTable.defaults.columnDefs),
	'rowGroup': {
		enable: true,
		dataSrc: 2
	}
});
shoppingListPrintShadowTable.columns.adjust().draw();

// Free-text search box, debounced via Delay()
$("#search").on("keyup", Delay(function ()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	shoppingListTable.search(value).draw();
}, Victual.FormFocusDelay));

// Resets the search box and status filter dropdown
$("#clear-filter-button").on("click", function ()
{
	$("#search").val("");
	$("#status-filter").val("all");
	$("#search").trigger("keyup");
	$("#status-filter").trigger("change");
});

// Status filter dropdown ("all"/"below min stock amount"/"expired"/...); filters
// the hidden status-info column (index 4) which encodes the item's status text
$("#status-filter").on("change", function ()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	// Transfer CSS classes of selected element to dropdown element (for background)
	$(this).attr("class", $("#" + $(this).attr("id") + " option[value='" + value + "']").attr("class") + " form-control");

	shoppingListTable.column(shoppingListTable.colReorder.transpose(4)).search(value).draw();
});

// Switching the shopping list dropdown navigates to that list (list id passed as query param)
$("#selected-shopping-list").on("change", function ()
{
	var value = $(this).val();
	window.location.href = U('/shoppinglist?list=' + encodeURIComponent(value));
});

// Clicking a status message (e.g. "N items below min. stock amount") applies that status as the filter
$(".status-filter-message").on("click", function ()
{
	var value = $(this).data("status-filter");
	$("#status-filter").val(value);
	$("#status-filter").trigger("change");
});

// Deletes the currently selected shopping list itself (DELETE objects/shopping_lists/{id}),
// after user confirmation, then redirects back to the (now default) shopping list
$("#delete-selected-shopping-list").on("click", function ()
{
	var objectName = $("#selected-shopping-list option:selected").attr("data-shoppinglist-name");
	var objectId = $("#selected-shopping-list").val();

	bootbox.confirm({
		// objectName came out of an <option>'s data- attribute with .attr(), which returns
		// the decoded string, and bootbox renders its message with .html() (S29)
		message: __t('Are you sure you want to delete shopping list "%s"?', Victual.FrontendHelpers.EscapeHtml(objectName)),
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
				Victual.Api.Delete('objects/shopping_lists/' + objectId, {},
					function (result)
					{
						window.location.href = U('/shoppinglist');
					}
				);
			}
		}
	});
});

// Removes a single item from the list (DELETE objects/shopping_list/{id}), fades the row
// out and refreshes the total value; delegated because rows are re-rendered/removed dynamically
$(document).on('click', '.shoppinglist-delete-button', function (e)
{
	e.preventDefault();

	var shoppingListItemId = $(e.currentTarget).attr('data-shoppinglist-id');
	Victual.FrontendHelpers.BeginUiBusy();

	Victual.Api.Delete('objects/shopping_list/' + shoppingListItemId, {},
		function (result)
		{
			animateCSS("#shoppinglistitem-" + shoppingListItemId + "-row", "fadeOut", function ()
			{
				Victual.FrontendHelpers.EndUiBusy();
				$("#shoppinglistitem-" + shoppingListItemId + "-row").addClass("d-none").remove();
				OnListItemRemoved();
			});
		},
		function (xhr)
		{
			Victual.FrontendHelpers.EndUiBusy();
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});

// Adds all products currently below their minimum stock amount to the selected list
$(document).on('click', '#add-products-below-min-stock-amount', function (e)
{
	Victual.Api.Post('stock/shoppinglist/add-missing-products', { "list_id": $("#selected-shopping-list").val() },
		function (result)
		{
			window.location.href = U('/shoppinglist?list=' + encodeURIComponent($("#selected-shopping-list").val()));
		}
	);
});

// Adds overdue then expired products to the selected list (two sequential API calls)
$(document).on('click', '#add-overdue-expired-products', function (e)
{
	Victual.Api.Post('stock/shoppinglist/add-overdue-products', { "list_id": $("#selected-shopping-list").val() },
		function (result)
		{
			Victual.Api.Post('stock/shoppinglist/add-expired-products', { "list_id": $("#selected-shopping-list").val() },
				function (result)
				{
					window.location.href = U('/shoppinglist?list=' + encodeURIComponent($("#selected-shopping-list").val()));
				}
			);
		}
	);
});

// Empties the selected shopping list entirely (all items removed), after confirmation
$(document).on('click', '#clear-shopping-list', function (e)
{
	// The list name comes out of the <option>'s text, which is the decoded string, and
	// bootbox renders its message with .html() (sweep finding S29)
	var confirmMessage = __t('Are you sure you want to empty shopping list "%s"?', Victual.FrontendHelpers.EscapeHtml($("#selected-shopping-list option:selected").text()));
	if (!BoolVal(Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_SHOPPINGLIST_MULTIPLE_LISTS))
	{
		confirmMessage = __t('Are you sure you want to empty the shopping list?');
	}

	bootbox.confirm({
		message: confirmMessage,
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
				Victual.FrontendHelpers.BeginUiBusy();

				Victual.Api.Post('stock/shoppinglist/clear', { "list_id": $("#selected-shopping-list").val() },
					function (result)
					{
						window.location.reload();
					},
					function (xhr)
					{
						Victual.FrontendHelpers.EndUiBusy();
						Victual.Api.DefaultErrorHandler(xhr);
					}
				);
			}
		}
	});
});

// Removes only the items already marked done (done_only flag) from the selected list
$(document).on("click", "#clear-done-items", function (e)
{
	Victual.Api.Post('stock/shoppinglist/clear', { "list_id": $("#selected-shopping-list").val(), "done_only": true },
		function (result)
		{
			window.location.reload();
		}
	);
});

// "Add to stock" workflow: opens the item's purchase form inside a modal iframe.
// When used via #add-all-items-to-stock-button below, Victual.ShoppingListToStockWorkflow*
// tracks progress so items can be stepped through one after another
$(document).on('click', '.shopping-list-stock-add-workflow-list-item-button', function (e)
{
	e.preventDefault();

	var href = $(e.currentTarget).attr('href');

	$("#shopping-list-stock-add-workflow-purchase-form-frame").attr("src", href);
	$("#shopping-list-stock-add-workflow-modal").modal("show");

	if (Victual.ShoppingListToStockWorkflowAll)
	{
		$("#shopping-list-stock-add-workflow-purchase-item-count").removeClass("d-none");
		$("#shopping-list-stock-add-workflow-purchase-item-count").text(__t("Adding shopping list item %1$s of %2$s", Victual.ShoppingListToStockWorkflowCurrent, Victual.ShoppingListToStockWorkflowCount));
		$("#shopping-list-stock-add-workflow-skip-button").removeClass("d-none");
	}
	else
	{
		$("#shopping-list-stock-add-workflow-purchase-item-count").addClass("d-none");
		$("#shopping-list-stock-add-workflow-skip-button").addClass("d-none");
	}
});

// Global state for the "add all items to stock" bulk workflow (reset on modal close)
Victual.ShoppingListToStockWorkflowAll = false;
Victual.ShoppingListToStockWorkflowCount = 0;
Victual.ShoppingListToStockWorkflowCurrent = 0;
Victual.ShoppingListAddToStockButtonList = [];
// Kicks off the bulk workflow by simulating a click on the first item's add-to-stock button;
// subsequent items are triggered by the "Ready"/"AfterItemAdded" postMessage handler below
$(document).on('click', '#add-all-items-to-stock-button', function (e)
{
	Victual.ShoppingListToStockWorkflowAll = true;
	Victual.ShoppingListAddToStockButtonList = $(".shopping-list-stock-add-workflow-list-item-button");
	Victual.ShoppingListToStockWorkflowCount = Victual.ShoppingListAddToStockButtonList.length;
	Victual.ShoppingListToStockWorkflowCurrent++;
	$("#shopping-list-stock-add-workflow-modal .modal-footer").removeClass("d-none");
	$(".shopping-list-stock-add-workflow-list-item-button").first().click();
});

// Reset bulk-workflow state whenever the modal is closed (manually or after the last item)
$("#shopping-list-stock-add-workflow-modal").on("hidden.bs.modal", function (e)
{
	Victual.ShoppingListToStockWorkflowAll = false;
	Victual.ShoppingListToStockWorkflowCount = 0;
	Victual.ShoppingListToStockWorkflowCurrent = 0;
	Victual.ShoppingListAddToStockButtonList = [];
	$("#shopping-list-stock-add-workflow-modal .modal-footer").addClass("d-none");
})

// Listens for postMessage events sent by the purchase form iframe:
// "AfterItemAdded" -> removes the now-purchased item from the shopping list;
// "Ready" -> either closes the modal (single-item mode) or advances to the next
// item's button click (bulk mode), closing the modal once all items are done
$(window).on("message", function (e)
{
	var data = e.originalEvent.data;

	if (data.Message === "AfterItemAdded")
	{
		$(".shoppinglist-delete-button[data-shoppinglist-id='" + data.Payload + "']").click();
	}
	else if (data.Message === "Ready")
	{
		if (!Victual.ShoppingListToStockWorkflowAll)
		{
			$("#shopping-list-stock-add-workflow-modal").modal("hide");
		}
		else
		{
			Victual.ShoppingListToStockWorkflowCurrent++;
			if (Victual.ShoppingListToStockWorkflowCurrent <= Victual.ShoppingListToStockWorkflowCount)
			{
				Victual.ShoppingListAddToStockButtonList[Victual.ShoppingListToStockWorkflowCurrent - 1].click();
			}
			else
			{
				$("#shopping-list-stock-add-workflow-modal").modal("hide");
			}
		}
	}
});

// Skips the current item in the bulk workflow without purchasing it, by faking a "Ready" message
$(document).on('click', '#shopping-list-stock-add-workflow-skip-button', function (e)
{
	e.preventDefault();

	window.postMessage(WindowMessageBag("Ready"), Victual.BaseUrl);
});

// Toggles an item's done/undone state (checkbox-like button), updates the row styling
// and status text in place, then re-sorts/re-filters the table without a full reload
$(document).on('click', '.order-listitem-button', function (e)
{
	e.preventDefault();

	Victual.FrontendHelpers.BeginUiBusy();

	var listItemId = $(e.currentTarget).attr('data-item-id');

	var done = 1;
	if ($(e.currentTarget).attr('data-item-done') == 1)
	{
		done = 0;
	}

	$(e.currentTarget).attr('data-item-done', done);

	Victual.Api.Put('objects/shopping_list/' + listItemId, { 'done': done },
		function ()
		{
			var statusInfoCell = $("#shoppinglistitem-" + listItemId + "-status-info");

			if (done == 1)
			{
				$('#shoppinglistitem-' + listItemId + '-row').addClass("text-muted");
				$('#shoppinglistitem-' + listItemId + '-row').addClass("text-strike-through");
				statusInfoCell.text(statusInfoCell.text().replace("xxUNDONExx", "xxDONExx"));
			}
			else
			{
				$('#shoppinglistitem-' + listItemId + '-row').removeClass("text-muted");
				$('#shoppinglistitem-' + listItemId + '-row').removeClass("text-strike-through");
				statusInfoCell.text(statusInfoCell.text().replace("xxDONExx", "xxUNDONExx"));
			}

			shoppingListTable.rows().invalidate().draw(false);
			$("#status-filter").trigger("change");

			Victual.FrontendHelpers.EndUiBusy();
		},
		function (xhr)
		{
			Victual.FrontendHelpers.EndUiBusy();
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});

/**
 * Recomputes the "total value" display after an item was removed/purchased, by summing
 * last_price_total across the uihelper_shopping_list view for the selected list; also
 * disables the "add all to stock" button once no purchasable items remain. Called once
 * on load and after every item removal.
 */
function OnListItemRemoved()
{
	if ($(".shopping-list-stock-add-workflow-list-item-button").length === 0)
	{
		$("#add-all-items-to-stock-button").addClass("disabled");
	}

	// Nothing to total when this user may not see prices: shoppinglist.blade.php renders no
	// #total-value element for them, and uihelper_shopping_list comes back without a
	// last_price_total key at all (FieldPolicy removes the field rather than nulling it), so
	// the sum below would be NaN written into an element that is not there. The request is
	// skipped rather than the result guarded, because the response carries nothing else this
	// function wants. Issue #176 item 5.
	if (!Victual.PricesVisible)
	{
		return;
	}

	Victual.Api.Get("objects/uihelper_shopping_list?" + "?query[]=shopping_list_id=" + $("#selected-shopping-list").val(),
		function (items)
		{
			$("#total-value").text(items.reduce((x, { last_price_total }) => x + (last_price_total || 0), 0));
			RefreshLocaleNumberDisplay();
		}
	);
}
OnListItemRemoved();

// Print workflow: builds a bootbox dialog with print options (persisted as user settings
// via the .user-setting-control class), then either triggers window.print() with the
// chosen layout, or calls the thermal-printer API endpoint if that feature flag is enabled
$(document).on("click", "#print-shopping-list-button", function (e)
{
	var checkedPrintShowHeader = "";
	if (BoolVal(Victual.UserSettings.shopping_list_print_show_header))
	{
		checkedPrintShowHeader = "checked";
	}

	var checkedGroupByProductGroup = "";
	if (BoolVal(Victual.UserSettings.shopping_list_print_group_by_product_group))
	{
		checkedGroupByProductGroup = "checked";
	}

	var checkedLayoutTypeTable = "";
	var checkedLayoutTypeList = "";
	if (Victual.UserSettings.shopping_list_print_layout_type == "table")
	{
		checkedLayoutTypeTable = "checked";
		checkedLayoutTypeList = "";
	}
	else
	{
		checkedLayoutTypeTable = "";
		checkedLayoutTypeList = "checked";
	}

	var dialogHtml = ' \
	<div class="text-center"><h5>' + __t('Print options') + '</h5><hr></div> \
	<div class="custom-control custom-checkbox"> \
		<input id="print-show-header" \
			 ' + checkedPrintShowHeader + ' \
			class="form-check-input custom-control-input user-setting-control" \
			data-setting-key="shopping_list_print_show_header" \
			type="checkbox" \
			value="1"> \
		<label class="form-check-label custom-control-label" \
			for="print-show-header">' + __t('Show header') + ' \
		</label> \
	</div> \
	<div class="custom-control custom-checkbox"> \
		<input id="print-group-by-product-group" \
			 ' + checkedGroupByProductGroup + ' \
			class="form-check-input custom-control-input user-setting-control" \
			data-setting-key="shopping_list_print_group_by_product_group" \
			type="checkbox" \
			value="1"> \
		<label class="form-check-label custom-control-label" \
			for="print-group-by-product-group">' + __t('Group by product group') + ' \
		</label> \
	</div> \
	<h5 class="pt-3 pb-0">' + __t('Layout type') + '</h5> \
	<div class="custom-control custom-radio"> \
		<input id="print-layout-type-table" \
			' + checkedLayoutTypeTable + ' \
			class="custom-control-input user-setting-control" \
			data-setting-key="shopping_list_print_layout_type" \
			type="radio" \
			name="print-layout-type" \
			value="table"> \
		<label class="custom-control-label" \
			for="print-layout-type-table">' + __t('Table') + ' \
		</label> \
	</div> \
	<div class="custom-control custom-radio"> \
		<input id="print-layout-type-list" \
		' + checkedLayoutTypeList + ' \
			class="custom-control-input user-setting-control" \
			data-setting-key="shopping_list_print_layout_type" \
			type="radio" \
			name="print-layout-type" \
			value="list"> \
		<label class="custom-control-label" \
			for="print-layout-type-list">' + __t('List') + ' \
		</label> \
	</div>';

	var sizePrintDialog = 'medium';
	var printButtons = {
		cancel: {
			label: __t('Cancel'),
			className: 'btn-secondary',
			callback: function ()
			{
				$(".modal").last().modal("hide");
			}
		},
		printtp: {
			label: __t('Thermal printer'),
			className: 'btn-secondary',
			callback: function ()
			{
				$(".modal").last().modal("hide");
				var printHeader = $("#print-show-header").prop("checked");
				var thermalPrintDialog = bootbox.dialog({
					title: __t('Printing'),
					message: '<p><i class="fa fa-spin fa-spinner"></i> ' + __t('Connecting to printer...') + '</p>'
				});

				// Delaying for one second so that the alert can be closed
				setTimeout(function ()
				{
					// Server renders and sends the print job directly to the configured thermal printer
					Victual.Api.Get('print/shoppinglist/thermal?list=' + $("#selected-shopping-list").val() + '&printHeader=' + printHeader,
						function (result)
						{
							$(".modal").last().modal("hide");
						},
						function (xhr)
						{
							Victual.Api.DefaultErrorHandler(xhr);
							var validResponse = true;

							try
							{
								var jsonError = JSON.parse(xhr.responseText);
							}
							catch (e)
							{
								validResponse = false;
							}

							// Both branches interpolate a server response into .html(), and a
							// <pre> does not neutralise a tag inside it - so the message is
							// escaped as the text it is (sweep finding S29)
							if (validResponse)
							{
								thermalPrintDialog.find('.bootbox-body').html(__t('Unable to print') + '<br><pre><code>' + Victual.FrontendHelpers.EscapeHtml(jsonError.error_message) + '</pre></code>');
							}
							else
							{
								thermalPrintDialog.find('.bootbox-body').html(__t('Unable to print') + '<br><pre><code>' + Victual.FrontendHelpers.EscapeHtml(xhr.responseText) + '</pre></code>');
							}
						}
					);
				}, 1000);
			}
		},
		ok: {
			label: __t('Print'),
			className: 'btn-primary responsive-button',
			callback: function ()
			{
				$(".modal").last().modal("hide");
				$('.modal-backdrop').remove();
				$(".print-timestamp").text(moment().format("l LT"));

				$("#description-for-print").html($("#description").val());
				if (!$("#description").text())
				{
					$("#description-for-print").parent().addClass("d-print-none");
				}

				if (!$("#print-show-header").prop("checked"))
				{
					$("#print-header").addClass("d-none");
				}

				if (!$("#print-group-by-product-group").prop("checked"))
				{
					shoppingListPrintShadowTable.rowGroup().enable(false);
					shoppingListPrintShadowTable.draw();
				}

				$(".print-layout-container").addClass("d-none");
				$(".print-layout-type-" + $("input[name='print-layout-type']:checked").val()).removeClass("d-none");

				window.print();
			}
		}
	}

	if (!Victual.FeatureFlags["VICTUAL_FEATURE_FLAG_THERMAL_PRINTER"])
	{
		delete printButtons['printtp'];
		sizePrintDialog = 'small';
	}

	bootbox.dialog({
		message: dialogHtml,
		size: sizePrintDialog,
		backdrop: true,
		closeButton: false,
		className: "d-print-none",
		buttons: printButtons
	});
});

// Shopping list description is edited via a Summernote rich-text editor; enables/disables
// the save/clear buttons based on whether the content changed and/or is empty
$("#description").on("summernote.change", function ()
{
	$("#save-description-button").removeClass("disabled");

	if ($("#description").summernote("isEmpty"))
	{
		$("#clear-description-button").addClass("disabled");
	}
	else
	{
		$("#clear-description-button").removeClass("disabled");
	}
});

// Persists the description text to the shopping list object
$(document).on("click", "#save-description-button", function (e)
{
	e.preventDefault();

	Victual.Api.Put('objects/shopping_lists/' + $("#selected-shopping-list").val(), { description: $("#description").val() },
		function (result)
		{
			$("#save-description-button").addClass("disabled");
		}
	);
});

// Clears the description editor and immediately saves the (now empty) description
$(document).on("click", "#clear-description-button", function (e)
{
	e.preventDefault();

	$("#description").summernote("reset");
	$("#save-description-button").click();
});

$("#description").trigger("summernote.change");
$("#save-description-button").addClass("disabled");

// Reacts to a "ShoppingListChanged" postMessage (e.g. sent by a stock booking iframe)
// by reloading the page against the affected list
$(window).on("message", function (e)
{
	var data = e.originalEvent.data;

	if (data.Message === "ShoppingListChanged")
	{
		window.location.href = U('/shoppinglist?list=' + data.Payload);
	}
});

// Renders each item's barcode image (data-barcode attribute) client-side via bwip-js,
// auto-detecting EAN-8/EAN-13/Code128 based on digit length
var dummyCanvas = document.createElement("canvas");
$("img.barcode").each(function ()
{
	var img = $(this);
	var barcode = img.attr("data-barcode").replace(/\D/g, "");

	var barcodeType = "code128";
	if (barcode.length == 8)
	{
		barcodeType = "ean8";
	}
	else if (barcode.length == 13)
	{
		barcodeType = "ean13";
	}

	bwipjs.toCanvas(dummyCanvas, {
		bcid: barcodeType,
		text: barcode,
		height: 5,
		includetext: false
	});

	img.attr("src", dummyCanvas.toDataURL("image/png"));
});

// On small screens or when stock tracking is disabled, the "stock" filter group
// isn't shown, so drop the border that would otherwise separate it visually
if ($(window).width() < 768 || !Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK)
{
	$("#filter-container").removeClass("border-bottom");
}
