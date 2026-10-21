// The medication presets page (ADR-0040, ADR-0041). Every row comes from the JSON API, which
// applies the share and ownership rules; a recipe the user holds no share on is not in any
// response. Text from the server reaches the page only through .text(), never as markup.
(function ()
{
	var products = [];
	var productsById = {};
	var locations = [];
	var unitNames = {};
	// Conversion rows by product id, completed or in flight, so lines for one product share one request.
	// Cleared whenever the edit dialog opens, because conversions can change between openings.
	var conversionRequests = {};
	var recipes = [];
	var editing = null;
	var consuming = null;
	var sharing = null;
	var viewing = null;

	/** Returns the server's error_message from a failed request, or a generic sentence when the body has none. */
	function ErrorText(xhr)
	{
		try
		{
			var body = JSON.parse(xhr.responseText);
			if (body && body.error_message) return body.error_message;
		}
		catch (e) { }
		return __t('The request could not be completed.');
	}

	/** Shows a status message in the page's live region; no argument clears it. */
	function Message(text) { $('#consumption-message').text(text || ''); }

	// The browser's own offset, so the booked date is the user's local date. The server runs in UTC
	// and uses the date as written in the offset it receives.
	function LocalInstant(date)
	{
		/** Left-pads a number to two digits. */
		var pad = function (n) { return String(n).padStart(2, '0'); };
		var offset = -date.getTimezoneOffset();
		var sign = offset >= 0 ? '+' : '-';
		offset = Math.abs(offset);
		return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds())
			+ sign + pad(Math.floor(offset / 60)) + ':' + pad(offset % 60);
	}

	/** Returns a fresh idempotency key for one consumption request. */
	function RequestId()
	{
		if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
		return 'r' + Date.now().toString(36) + Math.random().toString(36).slice(2);
	}

	/** Lists the rights a user holds on a preset as a comma-separated sentence fragment. */
	function RightsText(rights)
	{
		var parts = [__t('View')];
		if (rights.consume) parts.push(__t('Record consumption'));
		if (rights.edit) parts.push(__t('Edit'));
		if (rights.undo) parts.push(__t('Undo'));
		if (rights.share) parts.push(__t('Share'));
		return parts.join(', ');
	}

	/** Builds a small button with a text label, a style class and a click handler. */
	function Button(label, className, handler)
	{
		return $('<button type="button">').addClass('btn btn-sm ' + className + ' mr-1').text(label).on('click', handler);
	}

	// --- Reference data ----------------------------------------------------------------------

	/** Loads the active products, active locations and quantity units that every dialog needs, then calls back. */
	function LoadReferenceData(done)
	{
		Victual.Api.Get('objects/products', function (rows)
		{
			products = rows.filter(function (p) { return Number(p.active) === 1; }).sort(function (a, b) { return a.name.localeCompare(b.name); });
			productsById = {};
			rows.forEach(function (p) { productsById[p.id] = p; });
			Victual.Api.Get('objects/locations', function (rows2)
			{
				locations = rows2.filter(function (l) { return Number(l.active) === 1; }).sort(function (a, b) { return a.name.localeCompare(b.name); });
				Victual.Api.Get('objects/quantity_units', function (units)
				{
					unitNames = {};
					units.forEach(function (u) { unitNames[u.id] = u.name; });
					done();
				}, Failed);
			}, Failed);
		}, Failed);
	}

	/** Shows the server's error text for a failed list or reference-data request. */
	function Failed(xhr) { Message(xhr && xhr.responseText ? ErrorText(xhr) : __t('The request could not be completed.')); }

	// --- The list ----------------------------------------------------------------------------

	/** Loads the presets the user can see and renders one row per preset with the actions the user's rights allow. */
	function LoadRecipes()
	{
		Victual.Api.Get('consumption/recipes', function (rows)
		{
			recipes = rows;
			var body = $('#consumption-rows').empty();
			rows.forEach(function (recipe)
			{
				var row = $('<tr>').attr('data-recipe-id', recipe.id);
				row.append($('<td>').text(recipe.name));
				row.append($('<td>').text(recipe.line_count));
				row.append($('<td>').text(recipe.is_owner ? __t('Owner') : RightsText(recipe.rights)));
				var actions = $('<td>');
				if (recipe.rights.consume) actions.append(Button(__t('Record consumption'), 'btn-success consumption-consume-button', function () { OpenConsume(recipe.id); }));
				if (recipe.rights.edit) actions.append(Button(__t('Edit'), 'btn-outline-primary consumption-edit-button', function () { OpenEdit(recipe.id); }));
				if (recipe.rights.share) actions.append(Button(__t('Share'), 'btn-outline-secondary consumption-share-button', function () { OpenShare(recipe.id); }));
				actions.append(Button(__t('History'), 'btn-outline-secondary consumption-history-button', function () { OpenHistory(recipe.id); }));
				if (recipe.is_owner) actions.append(Button(__t('Delete'), 'btn-outline-danger consumption-delete-button', function () { DeleteRecipe(recipe); }));
				else actions.append(Button(__t('Remove my access'), 'btn-outline-danger consumption-leave-button', function () { LeaveRecipe(recipe); }));
				row.append(actions);
				body.append(row);
			});
		}, Failed);
	}

	/** Asks for confirmation, then deletes a preset the user owns and reloads the list. */
	function DeleteRecipe(recipe)
	{
		if (!window.confirm(__t('Delete this medication preset and its shares? Past consumptions stay in the stock history.'))) return;
		Victual.Api.Delete('consumption/recipes/' + recipe.id, {}, function () { Message(__t('Medication preset deleted.')); LoadRecipes(); }, Failed);
	}

	/** Asks for confirmation, then removes the current user's own share of a preset and reloads the list. */
	function LeaveRecipe(recipe)
	{
		if (!window.confirm(__t('Remove your own access to this medication preset?'))) return;
		Victual.Api.Delete('consumption/recipes/' + recipe.id + '/shares/' + Victual.UserId, {}, function () { Message(__t('Your access was removed.')); LoadRecipes(); }, Failed);
	}

	// --- Create and edit ---------------------------------------------------------------------

	/** Builds a product drop-down for one line, with the given product selected. */
	function ProductSelect(selected)
	{
		var select = $('<select class="form-control consumption-line-product">');
		select.append($('<option>').attr('value', '').text(__t('Choose a product')));
		products.forEach(function (p) { select.append($('<option>').attr('value', p.id).text(p.name)); });
		if (selected) select.val(String(selected));
		return select;
	}

	// The units a line can use for a product: its stock unit and every unit with an entered
	// conversion to it. Nothing is inferred; a unit without a conversion is not offered.
	function FillUnits(unitSelect, productId, selectedUnit)
	{
		unitSelect.empty();
		var product = productsById[productId];
		if (!product) return;
		ConversionsOf(productId, function (rows)
		{
			var seen = {};
			/** Appends a unit option once per unit id. */
			var add = function (id, name)
			{
				if (seen[id]) return;
				seen[id] = true;
				unitSelect.append($('<option>').attr('value', id).text(name));
			};
			add(product.qu_id_stock, unitNames[product.qu_id_stock] || '');
			rows.forEach(function (c) { if (String(c.to_qu_id) === String(product.qu_id_stock)) add(c.from_qu_id, c.from_qu_name || unitNames[c.from_qu_id] || ''); });
			if (selectedUnit) unitSelect.val(String(selectedUnit));
		});
	}

	/** Calls back with the unit conversions of one product, sharing one request among all lines for that product. */
	function ConversionsOf(productId, done)
	{
		var request = conversionRequests[productId];
		if (request && request.rows) { done(request.rows); return; }
		if (request) { request.waiting.push(done); return; }
		request = conversionRequests[productId] = { rows: null, waiting: [done] };
		Victual.Api.Get('objects/quantity_unit_conversions_resolved?query[]=product_id=' + encodeURIComponent(productId), function (rows)
		{
			request.rows = rows;
			request.waiting.splice(0).forEach(function (callback) { callback(rows); });
		}, function (xhr)
		{
			delete conversionRequests[productId];
			Failed(xhr);
		});
	}

	/** Appends one product, amount and unit row to the edit dialog, filled from a saved line when one is given. */
	function AddLine(line)
	{
		var row = $('<div class="form-row align-items-end mb-2 consumption-line">');
		var product = ProductSelect(line ? line.product_id : null);
		var amount = $('<input type="number" class="form-control consumption-line-amount" min="0" step="any">');
		var unit = $('<select class="form-control consumption-line-unit">');
		if (line) { amount.val(line.amount); FillUnits(unit, line.product_id, line.qu_id); }
		product.on('change', function () { FillUnits(unit, product.val(), null); });
		row.append($('<div class="col-5">').append(product));
		row.append($('<div class="col-3">').append(amount));
		row.append($('<div class="col-3">').append(unit));
		row.append($('<div class="col-1">').append($('<button type="button" class="btn btn-sm btn-outline-danger">').text('×').attr('title', __t('Remove line')).on('click', function () { row.remove(); })));
		$('#consumption-lines').append(row);
	}

	/** Opens the edit dialog empty for a new preset when id is null, or filled from the stored preset. */
	function OpenEdit(id)
	{
		conversionRequests = {};
		$('#consumption-edit-error').text('');
		$('#consumption-lines').empty();
		editing = id;
		if (id === null)
		{
			$('#consumption-edit-title').text(__t('New medication preset'));
			$('#consumption-name').val('');
			$('#consumption-note').val('');
			AddLine(null);
			$('#consumption-edit-modal').modal('show');
			return;
		}

		Victual.Api.Get('consumption/recipes/' + id, function (recipe)
		{
			$('#consumption-edit-title').text(__t('Edit medication preset'));
			$('#consumption-name').val(recipe.name);
			$('#consumption-note').val(recipe.note || '');
			recipe.lines.forEach(function (line) { AddLine(line); });
			$('#consumption-edit-modal').modal('show');
		}, Failed);
	}

	/** Reads the dialog's line rows into the API's line objects. */
	function CollectLines()
	{
		var lines = [];
		$('#consumption-lines').children('.consumption-line').each(function ()
		{
			var row = $(this);
			lines.push({ product_id: Number(row.find('.consumption-line-product').val()), amount: Number(row.find('.consumption-line-amount').val()), qu_id: Number(row.find('.consumption-line-unit').val()) });
		});
		return lines;
	}

	/** Creates or updates the preset from the edit dialog and reloads the list. */
	function Save()
	{
		var body = { name: $('#consumption-name').val(), note: $('#consumption-note').val(), lines: CollectLines() };
		/** Shows the server's error text inside the edit dialog. */
		var failed = function (xhr) { $('#consumption-edit-error').text(ErrorText(xhr)); };
		/** Closes the edit dialog, reports the save and reloads the list. */
		var done = function () { $('#consumption-edit-modal').modal('hide'); Message(__t('Medication preset saved.')); LoadRecipes(); };
		if (editing === null) Victual.Api.Post('consumption/recipes', body, done, failed);
		else Victual.Api.Put('consumption/recipes/' + editing, body, done, failed);
	}

	// --- Recording a consumption -------------------------------------------------------------

	/** Opens the dialog that records a consumption of one preset, listing its lines and the locations to take from. */
	function OpenConsume(id)
	{
		$('#consumption-consume-error').text('');
		consuming = id;
		Victual.Api.Get('consumption/recipes/' + id, function (recipe)
		{
			$('#consumption-consume-title').text(recipe.name);
			var list = $('#consumption-consume-lines').empty();
			recipe.lines.forEach(function (line)
			{
				var product = productsById[line.product_id];
				list.append($('<li>').text(line.amount + ' × ' + (line.product_name || (product ? product.name : line.product_id))));
			});
			var select = $('#consumption-location').empty();
			select.append($('<option>').attr('value', '').text(__t('Any location')));
			locations.forEach(function (l) { select.append($('<option>').attr('value', l.id).text(l.name)); });
			$('#consumption-consume-modal').modal('show');
		}, Failed);
	}

	/** Records one consumption of the chosen preset at the browser's local time, optionally from one location. */
	function Consume()
	{
		var body = { request_id: RequestId(), occurred_at: LocalInstant(new Date()) };
		var location = $('#consumption-location').val();
		if (location) body.location_id = Number(location);
		Victual.Api.Post('consumption/recipes/' + consuming + '/consume', body, function ()
		{
			$('#consumption-consume-modal').modal('hide');
			Message(__t('Consumption recorded.'));
		}, function (xhr) { $('#consumption-consume-error').text(ErrorText(xhr)); });
	}

	// --- Sharing -----------------------------------------------------------------------------

	/** Opens the share dialog for one preset and loads its shares. */
	function OpenShare(id)
	{
		sharing = id;
		$('#consumption-share-error').text('');
		$('#consumption-share-notice').text('');
		var recipe = recipes.filter(function (r) { return r.id === id; })[0];
		$('#consumption-share-title').text(recipe ? recipe.name : '');
		$('#consumption-share-username').val('');
		$('#consumption-share-modal input[type=checkbox]').prop('checked', false);
		$('#consumption-share-share').closest('label').toggle(!!(recipe && recipe.is_owner));
		LoadShares();
		$('#consumption-share-modal').modal('show');
	}

	/** Loads and renders the share rows of the preset open in the share dialog. */
	function LoadShares()
	{
		var recipe = recipes.filter(function (r) { return r.id === sharing; })[0];
		Victual.Api.Get('consumption/recipes/' + sharing + '/shares', function (shares)
		{
			var body = $('#consumption-share-rows').empty();
			shares.forEach(function (share)
			{
				var row = $('<tr>').attr('data-user-id', share.user_id);
				row.append($('<td>').text(share.username));
				var boxes = {};
				['consume', 'edit', 'undo', 'share'].forEach(function (right)
				{
					boxes[right] = $('<input type="checkbox">').prop('checked', !!share.rights[right]).prop('disabled', right === 'share' && !(recipe && recipe.is_owner));
					row.append($('<td>').append(boxes[right]));
				});
				var actions = $('<td>');
				actions.append(Button(__t('Save'), 'btn-outline-primary consumption-share-save', function ()
				{
					var rights = {};
					Object.keys(boxes).forEach(function (r) { rights[r] = boxes[r].prop('checked'); });
					Victual.Api.Put('consumption/recipes/' + sharing + '/shares/' + share.user_id, rights, function () { $('#consumption-share-error').text(''); LoadShares(); }, ShareFailed);
				}));
				actions.append(Button(__t('Remove'), 'btn-outline-danger consumption-share-remove', function ()
				{
					Victual.Api.Delete('consumption/recipes/' + sharing + '/shares/' + share.user_id, {}, function () { LoadShares(); }, ShareFailed);
				}));
				if (recipe && recipe.is_owner)
				{
					actions.append(Button(__t('Make owner'), 'btn-outline-secondary consumption-share-transfer', function ()
					{
						if (!window.confirm(__t('Transfer ownership to this user? You keep a share with every right, which the new owner can remove.'))) return;
						Victual.Api.Post('consumption/recipes/' + sharing + '/transfer', { user_id: share.user_id }, function () { $('#consumption-share-modal').modal('hide'); LoadRecipes(); }, ShareFailed);
					}));
				}
				row.append(actions);
				body.append(row);
			});
		}, ShareFailed);
	}

	/** Shows the server's error text inside the share dialog. */
	function ShareFailed(xhr) { $('#consumption-share-notice').text(''); $('#consumption-share-error').text(ErrorText(xhr)); }

	/** Shares the open preset with the named user and the ticked rights, then reloads the share rows. */
	function AddShare()
	{
		var body = {
			username: $('#consumption-share-username').val(),
			consume: $('#consumption-share-consume').prop('checked'), edit: $('#consumption-share-edit').prop('checked'),
			undo: $('#consumption-share-undo').prop('checked'), share: $('#consumption-share-share').prop('checked')
		};
		Victual.Api.Post('consumption/recipes/' + sharing + '/shares', body, function ()
		{
			// The server answers 204 whether or not the user exists, so a typo is only visible as a row that does not appear in the list below.
			$('#consumption-share-error').text('');
			$('#consumption-share-notice').text(__t('If that user exists, the preset is now shared with them. Check the list below.'));
			$('#consumption-share-username').val('');
			LoadShares();
		}, ShareFailed);
	}

	// --- History -----------------------------------------------------------------------------

	/** Opens the history dialog for one preset and loads its recorded consumptions. */
	function OpenHistory(id)
	{
		viewing = id;
		$('#consumption-history-error').text('');
		var recipe = recipes.filter(function (r) { return r.id === id; })[0];
		$('#consumption-history-title').text(recipe ? recipe.name : '');
		LoadHistory();
		$('#consumption-history-modal').modal('show');
	}

	/** Loads and renders the recorded consumptions of the preset open in the history dialog. */
	function LoadHistory()
	{
		var recipe = recipes.filter(function (r) { return r.id === viewing; })[0];
		Victual.Api.Get('consumption/recipes/' + viewing + '/events', function (events)
		{
			var body = $('#consumption-history-rows').empty();
			var states = { booked: __t('Recorded'), undone: __t('Undone'), needs_review: __t('Needs review') };
			events.forEach(function (event)
			{
				var row = $('<tr>').attr('data-event-id', event.id);
				row.append($('<td>').text(moment(event.occurred_at).format('YYYY-MM-DD HH:mm')));
				var booked = event.lines.map(function (l) { var p = productsById[l.product_id]; return l.amount + ' × ' + (p ? p.name : l.product_id); }).join(', ');
				row.append($('<td>').text(booked));
				row.append($('<td>').text(states[event.state] || event.state));
				var actions = $('<td>');
				if (event.state === 'booked' && recipe && recipe.rights.undo)
				{
					actions.append(Button(__t('Undo'), 'btn-outline-danger consumption-undo', function ()
					{
						Victual.Api.Post('consumption/recipes/' + viewing + '/events/' + event.id + '/undo', {}, function () { LoadHistory(); }, function (xhr) { $('#consumption-history-error').text(ErrorText(xhr)); });
					}));
				}
				row.append(actions);
				body.append(row);
			});
		}, function (xhr) { $('#consumption-history-error').text(ErrorText(xhr)); });
	}

	$('#consumption-new').on('click', function () { OpenEdit(null); });
	$('#consumption-add-line').on('click', function () { AddLine(null); });
	$('#consumption-save').on('click', Save);
	$('#consumption-consume').on('click', Consume);
	$('#consumption-share-add').on('click', AddShare);

	// The product and location lists feed every dialog, so creating is offered once they are loaded.
	$('#consumption-new').prop('disabled', true);
	LoadReferenceData(function ()
	{
		$('#consumption-new').prop('disabled', false);
		LoadRecipes();
	});
})();
