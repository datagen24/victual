// View script for the API key management page (views/manageapikeys.blade.php):
// lists API keys in a DataTable and supports creating, deleting and showing keys as QR code.
//
// A partial clone rather than a pure one (plan 12, Q5): it takes the shared table, search
// and delete-confirmation pieces and keeps the QR dialog and the "add key" modal, which
// nothing else has.

var apiKeysTable = Victual.EntityList.Table('#apikeys-table', {
	order: [[6, 'desc']]
});

Victual.EntityList.SearchFilter(apiKeysTable);

// Delete an API key after confirmation (DELETE /api/objects/api_keys/{id});
// the buttons carry data-apikey-id/-description from the Blade template. The description
// is user-controlled text that ends up in an HTML-rendered bootbox message, so the shared
// confirmation escapes whatever is shown - see sweep finding S29. `nameAttr` picks the
// description when there is one and the key's hint otherwise; it used to be the key
// itself, which is a hash on disk now and no longer readable (plan 11, question 4).
Victual.EntityList.ConfirmDelete({
	button: '.apikey-delete-button',
	idAttr: 'data-apikey-id',
	nameAttr: 'data-apikey-name',
	endpoint: 'objects/api_keys',
	message: 'Are you sure you want to delete API key "%s"?',
	className: 'text-break',
	list: '/manageapikeys'
});

// Rotate a regular API key (issue #130): confirm, then submit a POST form to
// /manageapikeys/{id}/rotate, the same way "add" and "delete" are state changes done as a
// form/AJAX call rather than a GET (sweep finding S8). This only creates the successor -
// the confirmation says so, and retiring the predecessor stays the existing delete button
// on its own row.
$(document).on("click", ".apikey-rotate-button", function (e)
{
	e.preventDefault();

	var button = $(this);
	var apiKeyId = button.data("apikey-id");
	var apiKeyName = button.attr("data-apikey-name");

	bootbox.confirm({
		message: __t('Rotate API key "%s"? A new key will be created; this one keeps working until you delete it.', Victual.FrontendHelpers.EscapeHtml(apiKeyName)),
		closeButton: false,
		buttons: {
			confirm: { label: __t('Yes'), className: 'btn-success' },
			cancel: { label: __t('No'), className: 'btn-danger' }
		},
		callback: function (result)
		{
			if (result !== true)
			{
				return;
			}

			var form = $("<form>").attr({ method: "post", action: U("/manageapikeys/" + apiKeyId + "/rotate") });
			$(document.body).append(form);
			form.trigger("submit");
		}
	});
});

// Show the key as QR code - the reveal block encodes "<api url>|<key>" for a key that has
// just been created, and an iCal special purpose key's row encodes the ready-to-use
// calendar URL. There is deliberately no such button on a regular key's row: what is
// stored is a hash, so there is nothing to encode.
$(".apikey-show-qr-button").on("click", function ()
{
	var button = $(this);
	var apiKey = button.data("apikey-key");
	var apiKeyType = button.data("apikey-type");
	var apiKeyDescription = button.data("apikey-description");

	var content = U("/api") + "|" + apiKey;
	if (apiKeyType === "special-purpose-calendar-ical")
	{
		content = U("/api/calendar/ical?secret=" + apiKey);
	}

	// The description is concatenated into markup handed to bootbox, which renders it as
	// HTML, so it is escaped at the point of use (sweep finding S29). `content` is not:
	// it is the QR payload, encoded into an image rather than into the document, and
	// QrCodeImgHtml emits only its own data URI.
	bootbox.alert({
		message: "<div class='text-center'><h1>" + __t("API key") + "</h1><h2 class='text-muted'>" + Victual.FrontendHelpers.EscapeHtml(apiKeyDescription) + "</h2><p><hr>" + QrCodeImgHtml(content) + "</p></div>",
		closeButton: false
	});
});

// "Add" opens a modal asking for a description; the key itself is then
// generated server side by posting the description to /manageapikeys/new
$("#add-api-key-button").on("click", function (e)
{
	$("#add-api-key-modal").modal("show");
});

$("#add-api-key-modal").on("shown.bs.modal", function (e)
{
	setTimeout(function ()
	{
		$("#description").focus();
	}, Victual.FormFocusDelay);
});

$("#new-api-key-button").on("click", function (e)
{
	// Submitted as a form rather than navigated to. Creating a key is a state change, and
	// as a GET it fired from any page that could get the browser to load a URL - with a
	// description of the attacker's choosing. Sweep finding S8.
	//
	// Built as nodes rather than as a markup string: the description is user input on its
	// way into the DOM, which is the sink rule the frontend-security job checks.
	var form = $("<form>").attr({ method: "post", action: U("/manageapikeys/new") });
	form.append($("<input>").attr({ type: "hidden", name: "description" }).val($("#description").val()));
	form.append($("<input>").attr({ type: "hidden", name: "expires_in_days" }).val($("#expires_in_days").val()));
	// Issue #208: the key type, and - for an MCP key only - whether it is read-only. A
	// regular key never sends the flag, so hiding the checkbox cannot leave it applied.
	var keyType = $("#key_type").val();
	form.append($("<input>").attr({ type: "hidden", name: "key_type" }).val(keyType));
	if (keyType === "mcp" && $("#read_only").is(":checked"))
	{
		form.append($("<input>").attr({ type: "hidden", name: "read_only" }).val("1"));
	}
	$(document.body).append(form);
	form.trigger("submit");
});

// The read-only option belongs to an MCP key (issue #208); shown only while that type is
// selected.
$("#key_type").on("change", function ()
{
	$("#read_only_group").toggleClass("d-none", $(this).val() !== "mcp");
});
