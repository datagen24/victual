// The consumption inbox (ADR-0041 rules 8 and 9, ADR-0015). Every row comes from /api/consumption/events
// with the user's session; the server renders no event data into the page. Text from the server (a unit
// label the source sent, a stock error message, a product or location name) reaches the page only through
// .text(), and markup is built as nodes, never by concatenating a value into a string (AGENTS.md, frontend
// sinks). The page states facts about each event and offers the actions the state allows. It does not
// advise, and it does not judge when or whether a dose was taken (ADR-0015).
(function ()
{
	var UNRESOLVED_STATES = 'needs_review,needs_mapping,received';
	var BULK_LOOP_ACTIONS = ['void', 'keep', 'dismiss', 'rebook', 'approve_unit'];
	// A cap on server round trips for one bulk click, so no response shape can make the loop endless.
	var BULK_MAX_ROUNDS = 100;

	var productsById = {};
	var locationsById = {};
	var unitNames = {};
	var events = [];
	var loadSequence = 0;
	var linking = null;
	var linkChoices = [];
	var bulkChoices = [];
	var mappingRefs = [];

	function HasPermission(name)
	{
		return (Victual.UserPermissions || []).some(function (p) { return p.permission_name === name && Number(p.has_permission) === 1; });
	}

	var canResolve = HasPermission('STOCK_CONSUME');

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

	function Message(text) { $('#inbox-message').text(text || ''); }
	function ErrorMessage(text) { $('#inbox-error').text(text || ''); }
	function Failed(xhr) { ErrorMessage(xhr && xhr.responseText ? ErrorText(xhr) : __t('The request could not be completed.')); }

	function Key(event) { return event.source_system + '/' + event.source_event_id; }

	function Instant(value) { return moment(value).format('YYYY-MM-DD HH:mm'); }

	// --- Words -------------------------------------------------------------------------------

	function StateWords(state, reason)
	{
		if (state === 'needs_mapping') return __t('Not booked: no mapping for this medication');
		if (state === 'received') return __t('Not booked yet: the event was received and booking was not attempted');
		if (state === 'undone') return __t('Undone: the booking was reversed and stock was restored');
		if (state === 'booked') return __t('Booked');
		if (state === 'linked') return __t('Linked to an existing consumption');
		if (state === 'voided') return __t('Voided: the booking was undone');
		if (state === 'dismissed') return __t('Dismissed: nothing was booked');
		if (state !== 'needs_review') return state;
		var reasons = {
			ambiguous_location: __t('Not booked: the location is not clear'),
			insufficient_stock: __t('Not booked: not enough stock at the chosen location'),
			unit_unconfirmed: __t('Not booked: the unit has not been confirmed'),
			quantity_missing: __t('Not booked: the event has no quantity and the mapping has no default quantity'),
			recipe_unavailable: __t('Not booked: the mapped consumption recipe is not available'),
			partially_undone: __t('Some of the bookings were undone and some remain'),
			changed_after_undo: __t('The source changed this record after its booking was undone; nothing was booked'),
			undo_refused: __t('Not changed: the stock refused to undo the earlier booking'),
			invalid_mapping: __t('Not booked: the mapping does not fit this event'),
			source_deleted: __t('The source deleted this record; stock unchanged'),
			stock_error: __t('Not booked: the stock refused the booking')
		};
		return reasons[reason] || __t('Needs review');
	}

	function RemovalWords(token)
	{
		var words = {
			entered_in_error: __t('entered in error'), history_cleared: __t('history cleared'), medication_archived: __t('medication archived'),
			access_revoked: __t('access revoked'), unknown: __t('no reason given')
		};
		return words[token] || token;
	}

	function ActionLabel(action)
	{
		var labels = {
			retry: __t('Try booking again'), rebook: __t('Book again'), approve_unit: __t('Confirm the unit and book'),
			void: __t('Undo the booking'), keep: __t('Keep the booking'), dismiss: __t('Dismiss'), link: __t('Link to an existing consumption')
		};
		return labels[action] || action;
	}

	// --- What each state allows (ADR-0041 rule 8) --------------------------------------------

	function AllowedActions(event)
	{
		var state = event.state;
		var reason = event.reason || null;
		var actions = [];
		if (state === 'needs_mapping' || state === 'needs_review') actions.push('retry');
		if (state === 'undone' || (state === 'needs_review' && reason === 'partially_undone')) actions.push('rebook');
		if (state === 'needs_review' && reason === 'unit_unconfirmed') actions.push('approve_unit');
		if (state === 'needs_review' && reason === 'source_deleted') { actions.push('void'); actions.push('keep'); }
		// Dismiss is for an event with no live booking. A needs_review event that still holds a booking (the
		// source deleted it, some bookings were undone, a correction was refused) is refused by the server.
		var unbooked = state === 'received' || state === 'needs_mapping' || state === 'undone'
			|| (state === 'needs_review' && (event.transaction_id === null || event.transaction_id === undefined || reason === 'changed_after_undo'));
		if (unbooked) actions.push('dismiss');
		if (state === 'needs_review' || state === 'received' || state === 'booked') actions.push('link');
		return actions;
	}

	// The same table as the bulk choices: which actions apply to events in a state and reason.
	function BuildBulkChoices()
	{
		var choices = [{ state: 'needs_mapping', reason: null }, { state: 'received', reason: null }, { state: 'undone', reason: null }];
		['unit_unconfirmed', 'source_deleted', 'ambiguous_location', 'insufficient_stock', 'quantity_missing', 'recipe_unavailable', 'invalid_mapping', 'stock_error', 'changed_after_undo', 'undo_refused', 'partially_undone']
			.forEach(function (reason) { choices.push({ state: 'needs_review', reason: reason }); });
		choices.forEach(function (c)
		{
			c.actions = AllowedActions({ state: c.state, reason: c.reason, transaction_id: null })
				.filter(function (a) { return a !== 'link'; });
			// Bulk dismiss has the same live-booking limit as a single one.
			if (c.reason === 'source_deleted' || c.reason === 'partially_undone' || c.reason === 'undo_refused') c.actions = c.actions.filter(function (a) { return a !== 'dismiss'; });
		});
		return choices.filter(function (c) { return c.actions.length > 0; });
	}

	// --- Reference data ----------------------------------------------------------------------

	function LoadReferenceData(done)
	{
		Victual.Api.Get('objects/products', function (rows)
		{
			productsById = {};
			rows.forEach(function (p) { productsById[p.id] = p; });
			Victual.Api.Get('objects/locations', function (rows2)
			{
				locationsById = {};
				rows2.forEach(function (l) { locationsById[l.id] = l; });
				Victual.Api.Get('objects/quantity_units', function (units)
				{
					unitNames = {};
					units.forEach(function (u) { unitNames[u.id] = u.name; });
					done();
				}, Failed);
			}, Failed);
		}, Failed);
	}

	function ProductName(id) { return productsById[id] ? productsById[id].name : String(id); }
	function LocationName(id) { return locationsById[id] ? locationsById[id].name : String(id); }

	function LineText(line)
	{
		var product = productsById[line.product_id];
		var unit = product ? (unitNames[product.qu_id_stock] || '') : '';
		var text = line.amount + (unit ? ' ' + unit : '') + ' × ' + ProductName(line.product_id);
		if (line.location_id !== undefined && line.location_id !== null) text += ' (' + LocationName(line.location_id) + ')';
		return text;
	}

	// --- The list ----------------------------------------------------------------------------

	function ShowUndone() { return $('#inbox-show-undone').prop('checked'); }

	function LoadEvents()
	{
		var sequence = ++loadSequence;
		var states = UNRESOLVED_STATES + (ShowUndone() ? ',undone' : '');
		Victual.Api.Get('consumption/events?limit=500&state=' + encodeURIComponent(states), function (unresolved)
		{
			// Booked events are listed only when something may be the same dose (ADR-0041 rule 9).
			Victual.Api.Get('consumption/events?limit=100&state=booked', function (booked)
			{
				if (sequence !== loadSequence) return;
				var suspect = booked.filter(function (e) { return (e.possible_duplicates || []).length > 0; });
				events = unresolved.concat(suspect).filter(function (e) { return ShowUndone() || e.reason !== 'partially_undone'; });
				Render();
			}, Failed);
		}, Failed);
	}

	function MedicationOf(event) { return event.medication_ref || ''; }

	function RefreshMedicationFilter()
	{
		var select = $('#inbox-medication');
		var chosen = select.val() || '';
		var refs = {};
		events.forEach(function (e) { if (MedicationOf(e) !== '') refs[MedicationOf(e)] = true; });
		var names = Object.keys(refs).sort();
		select.empty().append($('<option>').attr('value', '').text(__t('All medications')));
		names.forEach(function (name) { select.append($('<option>').attr('value', name).text(name)); });
		select.val(names.indexOf(chosen) >= 0 ? chosen : '');
		$('#inbox-medication-label').toggleClass('d-none', names.length === 0);
		// Suggestions for the bulk field: references on the listed events and in the user's mappings.
		var list = $('#inbox-bulk-medications').empty();
		var suggested = {};
		names.concat(mappingRefs).forEach(function (name) { suggested[name] = true; });
		Object.keys(suggested).sort().forEach(function (name) { list.append($('<option>').attr('value', name)); });
	}

	function RefreshBulkSystems()
	{
		var select = $('#inbox-bulk-system');
		var chosen = select.val();
		var systems = {};
		events.forEach(function (e) { systems[e.source_system] = true; });
		select.empty();
		Object.keys(systems).sort().forEach(function (name) { select.append($('<option>').attr('value', name).text(name)); });
		if (chosen && systems[chosen]) select.val(chosen);
	}

	function Detail(row, text) { row.append($('<div class="inbox-detail">').text(text)); }

	function DetailsCell(event)
	{
		var cell = $('<td class="inbox-details">');
		if ((event.lines || []).length > 0)
		{
			var list = $('<ul class="mb-1 pl-3 inbox-lines">');
			event.lines.forEach(function (line) { list.append($('<li>').text(LineText(line))); });
			cell.append(list);
		}
		if (event.reason === 'ambiguous_location' && (event.candidate_location_ids || []).length > 0)
		{
			Detail(cell, __t('Locations that could supply this: %s', event.candidate_location_ids.map(LocationName).join(', ')));
		}
		if (event.reason === 'unit_unconfirmed' && event.unit_label_seen !== undefined && event.unit_label_seen !== null)
		{
			Detail(cell, __t('Unit sent by the source: "%s"', event.unit_label_seen));
		}
		if (event.reason === 'stock_error' && event.message)
		{
			Detail(cell, __t('Message from the stock: %s', event.message));
		}
		if (event.source_removed_reason)
		{
			Detail(cell, __t('Reason given by the source: %s', RemovalWords(event.source_removed_reason)));
		}
		(event.possible_duplicates || []).forEach(function (duplicate)
		{
			Detail(cell, __t('Possible duplicate: consumption %1$s, recorded at %2$s', duplicate.transaction_id, Instant(duplicate.occurred_at)));
		});
		return cell;
	}

	function ActionButton(event, action)
	{
		var button = $('<button type="button">').addClass('btn btn-sm btn-outline-primary mr-1 inbox-action inbox-action-' + action).attr('data-action', action).text(ActionLabel(action));
		if (!canResolve)
		{
			button.prop('disabled', true).attr('title', __t('Resolving needs the permission to record consumption.'));
			return button;
		}
		button.on('click', function ()
		{
			if (action === 'link') OpenLink(event);
			else Resolve(event, action, null);
		});
		return button;
	}

	function Render()
	{
		RefreshMedicationFilter();
		RefreshBulkSystems();
		var medication = $('#inbox-medication').val() || '';
		var shown = events.filter(function (e) { return medication === '' || MedicationOf(e) === medication; });
		shown.sort(function (a, b)
		{
			return MedicationOf(a).localeCompare(MedicationOf(b)) || (a.occurred_at < b.occurred_at ? 1 : a.occurred_at > b.occurred_at ? -1 : 0);
		});

		var body = $('#inbox-rows').empty();
		shown.forEach(function (event)
		{
			var row = $('<tr class="inbox-row">').attr('data-event-key', Key(event)).attr('data-state', event.state).attr('data-reason', event.reason || '');
			row.append($('<td class="inbox-source">').text(Key(event)));
			row.append($('<td class="inbox-medication">').text(MedicationOf(event)));
			row.append($('<td class="inbox-time">').text(Instant(event.occurred_at)));
			var stateText = event.state === 'booked' ? __t('Booked; other consumptions of the same product were recorded near this time') : StateWords(event.state, event.reason);
			row.append($('<td class="inbox-state">').text(stateText));
			row.append(DetailsCell(event));
			var actions = $('<td class="inbox-actions">');
			AllowedActions(event).forEach(function (action) { actions.append(ActionButton(event, action)); });
			row.append(actions);
			body.append(row);
		});
		$('#inbox-empty').toggleClass('d-none', shown.length > 0);
		$('#inbox-table').toggleClass('d-none', shown.length === 0);
	}

	// --- One event ---------------------------------------------------------------------------

	function Resolve(event, action, transactionId, onFailure)
	{
		Message('');
		ErrorMessage('');
		var body = { action: action };
		if (transactionId !== null) body.transaction_id = transactionId;
		Victual.Api.Post('consumption/events/' + encodeURIComponent(event.source_system) + '/' + encodeURIComponent(event.source_event_id) + '/resolve', body, function (result)
		{
			$('#inbox-link-modal').modal('hide');
			Message(__t('Event %1$s is now: %2$s', Key(event), StateWords(result.state, result.reason)));
			LoadEvents();
		}, function (xhr)
		{
			// invalid_transition (409), invalid_link (422) and every other refusal show the server's own sentence;
			// the state may have changed under the page, so the list is read again either way.
			if (onFailure) onFailure(ErrorText(xhr)); else ErrorMessage(ErrorText(xhr));
			LoadEvents();
		});
	}

	function OpenLink(event)
	{
		linking = event;
		$('#inbox-link-error').text('');
		$('#inbox-link-other').val('');
		$('#inbox-link-title').text(__t('Link %s to an existing consumption', Key(event)));
		var holder = $('#inbox-link-choices').empty();
		linkChoices = [];
		(event.possible_duplicates || []).forEach(function (duplicate)
		{
			var radio = $('<input type="radio" name="inbox-link-choice" class="inbox-link-choice">').val(duplicate.transaction_id);
			linkChoices.push(radio);
			holder.append($('<div class="form-check">').append($('<label class="form-check-label">').append(radio).append(' ').append($('<span>').text(__t('Consumption %1$s, recorded at %2$s', duplicate.transaction_id, Instant(duplicate.occurred_at))))));
		});
		$('#inbox-link-modal').modal('show');
	}

	function ConfirmLink()
	{
		var other = String($('#inbox-link-other').val() || '').trim();
		var chosen = linkChoices.filter(function (radio) { return radio.prop('checked'); })[0];
		var transaction = other !== '' ? other : (chosen ? chosen.val() : '');
		if (transaction === '') { $('#inbox-link-error').text(__t('Choose a consumption or enter a transaction id.')); return; }
		Resolve(linking, 'link', transaction, function (text) { $('#inbox-link-error').text(text); });
	}

	// --- Many events -------------------------------------------------------------------------

	function FillBulkStates()
	{
		bulkChoices = BuildBulkChoices();
		var select = $('#inbox-bulk-state').empty();
		bulkChoices.forEach(function (choice, index) { select.append($('<option>').attr('value', index).text(StateWords(choice.state, choice.reason))); });
		FillBulkActions();
	}

	function FillBulkActions()
	{
		var choice = bulkChoices[Number($('#inbox-bulk-state').val())];
		var select = $('#inbox-bulk-action').empty();
		if (!choice) return;
		choice.actions.forEach(function (action) { select.append($('<option>').attr('value', action).text(ActionLabel(action))); });
	}

	function RunBulk()
	{
		var choice = bulkChoices[Number($('#inbox-bulk-state').val())];
		var action = $('#inbox-bulk-action').val();
		var system = $('#inbox-bulk-system').val();
		var medication = String($('#inbox-bulk-medication').val() || '').trim();
		$('#inbox-bulk-failures').empty();
		ErrorMessage('');
		if (!choice || !action) return;
		if (!system || medication === '') { $('#inbox-bulk-result').text(__t('Choose a source and enter a medication reference.')); return; }
		if (!window.confirm(__t('Apply "%1$s" to every "%2$s" event of medication %3$s?', ActionLabel(action), StateWords(choice.state, choice.reason), medication))) return;

		var filter = { source_system: system, medication_ref: medication, state: choice.state };
		if (choice.reason) filter.reason = choice.reason;
		var tally = { handled: 0, refused: 0, previousRemaining: null };
		$('#inbox-bulk-result').text('');

		var finish = function (remaining, note)
		{
			var text = __t('Handled %1$s events; %2$s refused; %3$s still match.', tally.handled, tally.refused, remaining);
			$('#inbox-bulk-result').text(note ? text + ' ' + note : text);
			LoadEvents();
		};

		var round = function (rounds)
		{
			Victual.Api.Post('consumption/events/resolve', { action: action, filter: filter }, function (result)
			{
				var progressed = 0;
				(result.results || []).forEach(function (item)
				{
					if (item.http_status === 200) { tally.handled++; progressed++; }
					else
					{
						tally.refused++;
						if ($('#inbox-bulk-failures').children().length < 10)
						{
							$('#inbox-bulk-failures').append($('<li>').text(item.source_system + '/' + item.source_event_id + ': ' + ((item.error && item.error.error_message) || item.http_status)));
						}
					}
				});
				var remaining = result.remaining || 0;
				// `retry` is one call per click. A retry that does not clear the cause leaves the event in the
				// same state, so it would match the filter again and a loop would book-attempt the same events
				// until the cap; a person who wants another attempt clicks again. The other actions end an event's
				// match (void, keep, dismiss, approve_unit and rebook move it to another state), so a loop on
				// `remaining` ends, and it also stops when a round did not reduce the count.
				var loops = BULK_LOOP_ACTIONS.indexOf(action) >= 0;
				if (loops && remaining > 0)
				{
					if (progressed === 0 || (tally.previousRemaining !== null && remaining >= tally.previousRemaining) || rounds + 1 >= BULK_MAX_ROUNDS)
					{
						finish(remaining, __t('The remaining events did not change, so the requests stopped.'));
						return;
					}
					tally.previousRemaining = remaining;
					round(rounds + 1);
					return;
				}
				finish(remaining, null);
			}, function (xhr) { $('#inbox-bulk-result').text(ErrorText(xhr)); LoadEvents(); });
		};
		round(0);
	}

	// --- Wiring ------------------------------------------------------------------------------

	$('#inbox-show-undone').on('change', LoadEvents);
	$('#inbox-reload').on('click', LoadEvents);
	$('#inbox-medication').on('change', Render);
	$('#inbox-bulk-state').on('change', FillBulkActions);
	$('#inbox-bulk-run').on('click', RunBulk);
	$('#inbox-link-confirm').on('click', ConfirmLink);

	FillBulkStates();
	if (!canResolve)
	{
		$('#inbox-permission-note').removeClass('d-none');
		$('#inbox-bulk-run').prop('disabled', true).attr('title', __t('Resolving needs the permission to record consumption.'));
	}

	LoadReferenceData(function ()
	{
		LoadEvents();
		// The medication references that have a mapping complete the bulk field's suggestions.
		Victual.Api.Get('consumption/mappings', function (mappings)
		{
			mappingRefs = mappings.map(function (m) { return m.medication_ref; });
			RefreshMedicationFilter();
		}, function () { });
	});
})();
