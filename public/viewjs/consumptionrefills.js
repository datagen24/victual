// Prescription refills (ADR-0042, ADR-0015). Every row comes from /api/refills and
// /api/consumption/recipes/{id}/refill with the user's session; the server renders no refill data into the
// page. Text a person typed (a prescription name, a fill note, a void reason) reaches the page only through
// .text(), and markup is built as nodes, never by concatenating a value into a string (AGENTS.md, frontend
// sinks). The page states the dates the person entered and the estimate calculated from them, and says where
// the estimate comes from. It does not advise, and it never says a refill is allowed.
//
// "Today" is the calendar date on this device, sent as as_of with every read and write; the server runs in
// UTC and a person's calendar does not (ADR-0042 section 4). Victual sends no notification: this page
// lists the notices and lets the person mark them as seen.
(function ()
{
	var LEAD_SETTING = 'refill_warning_lead_days';

	var recipes = {};
	var states = [];
	var selectedId = null;
	var detail = null;
	var voidingFill = null;
	var loadSequence = 0;
	var detailSequence = 0;
	var busy = false;

	function Pad(n) { return (n < 10 ? '0' : '') + n; }

	// The date on this device, written YYYY-MM-DD. It is read at every request so a page left open past midnight
	// asks about the new day.
	function LocalToday()
	{
		var now = new Date();
		return now.getFullYear() + '-' + Pad(now.getMonth() + 1) + '-' + Pad(now.getDate());
	}

	function AsOf() { return 'as_of=' + encodeURIComponent(LocalToday()); }

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

	function Message(text) { $('#refill-message').text(text || ''); }
	function ErrorMessage(text) { $('#refill-error').text(text || ''); }
	function Failed(xhr) { ErrorMessage(xhr && xhr.responseText ? ErrorText(xhr) : __t('The request could not be completed.')); }
	function Clear() { Message(''); ErrorMessage(''); }

	// A refused write: show the server's sentence, then read the list and the open prescription again, because
	// the state may have changed under the page (a share removed, an order closed by another person).
	function Refused(xhr)
	{
		Failed(xhr);
		LoadAll(ReloadDetail);
	}

	function HideDetail()
	{
		selectedId = null;
		detail = null;
		$('#refill-detail').addClass('d-none');
	}

	function IsWholeNumber(text, minimum, maximum)
	{
		if (!/^-?\d+$/.test(text)) return false;
		var value = parseInt(text, 10);
		return value >= minimum && value <= maximum;
	}

	// --- Words (ADR-0015: facts and estimates, never instructions) -----------------------------

	function StatusWords(status, daysOverdue)
	{
		if (status === 'ok') return __t('Reorder date not reached');
		if (status === 'approaching') return __t('Reorder date approaching');
		if (status === 'ordered') return __t('Order recorded');
		if (status === 'unknown') return __t('No estimate');
		if (status !== 'due') return status;
		if (daysOverdue > 0) return __n(daysOverdue, 'Reorder date reached, %s day ago', 'Reorder date reached, %s days ago');
		return __t('Reorder date reached');
	}

	function ReasonWords(reason)
	{
		var words = {
			no_fill: __t('No fill recorded'),
			invalid_rule: __t('The rule is not valid'),
			invalid_supply: __t('Days supplied is needed for this estimate'),
			result_not_after_fill: __t('The calculated date is not after the fill date. Choose a rule or enter a date.')
		};
		return words[reason] || reason;
	}

	function SourceWords(source, rule)
	{
		if (source === 'explicit') return __t('The date you entered');
		if (source === 'fallback') return __t('Fill date plus days supplied, minus 14 days');
		var value = rule ? rule.parameter : null;
		if (source === 'rule:days_before_end') return value === null ? __t('Rule: days before the supply ends') : __t('Rule: %s', __n(value, '%s day before the supply ends', '%s days before the supply ends'));
		if (source === 'rule:fixed_interval') return value === null ? __t('Rule: days after the fill date') : __t('Rule: %s', __n(value, '%s day after the fill date', '%s days after the fill date'));
		if (source === 'rule:fraction_elapsed') return value === null ? __t('Rule: percent of the supply used') : __t('Rule: after %s percent of the supply', value);
		return '';
	}

	function FromWords(source)
	{
		if (source === 'explicit') return __t('from the date you entered');
		if (source === 'fallback') return __t('from your last fill');
		return __t('from the rule you set for this prescription');
	}

	function NoticeWords(notice)
	{
		if (notice.kind === 'due') return __t('Reorder date reached: estimated %1$s (%2$s)', notice.reorder_date, FromWords(notice.source));
		return __t('Estimated reorder date %1$s (%2$s)', notice.reorder_date, FromWords(notice.source));
	}

	function RuleHelp(kind)
	{
		if (kind === 'days_before_end') return __t('The estimate falls this many days before the supply ends (0 to 730).');
		if (kind === 'fixed_interval') return __t('The estimate falls this many days after the fill date, whatever the days supplied (1 to 730).');
		if (kind === 'fraction_elapsed') return __t('The estimate falls after this percent of the days supplied (1 to 99).');
		return __t('Without a rule the estimate is the fill date plus the days supplied, minus 14 days.');
	}

	function RuleRange(kind)
	{
		if (kind === 'days_before_end') return [0, 730];
		if (kind === 'fixed_interval') return [1, 730];
		return [1, 99];
	}

	function BadgeClass(status)
	{
		if (status === 'approaching') return 'badge-warning';
		if (status === 'due') return 'badge-primary';
		if (status === 'ordered') return 'badge-info';
		if (status === 'unknown') return 'badge-light border';
		return 'badge-secondary';
	}

	function Dash() { return '\u2014'; }

	function OrderWords(order)
	{
		var age = order.age_days === 0 ? __t('today') : __n(order.age_days, '%s day ago', '%s days ago');
		return __t('Ordered on %1$s (%2$s)', order.ordered_on, age);
	}

	function RecipeName(id) { return recipes[id] ? recipes[id].name : String(id); }
	function CanEdit(id) { return !!(recipes[id] && recipes[id].rights && recipes[id].rights.edit); }

	// --- The list and the notices --------------------------------------------------------------

	function LoadAll(done)
	{
		var sequence = ++loadSequence;
		Victual.Api.Get('consumption/recipes', function (list)
		{
			Victual.Api.Get('refills?' + AsOf(), function (result)
			{
				Victual.Api.Get('refills/notices?' + AsOf(), function (noticeResult)
				{
					if (sequence !== loadSequence) return;
					recipes = {};
					list.forEach(function (r) { recipes[r.id] = r; });
					states = result.refills || [];
					// A prescription that left the list (its share was removed) leaves the page as well.
					if (selectedId !== null && !recipes[selectedId]) HideDetail();
					RenderList();
					RenderNotices(noticeResult.notices || []);
					if (done) done();
				}, Failed);
			}, Failed);
		}, Failed);
	}

	function RenderList()
	{
		var body = $('#refill-rows').empty();
		states.forEach(function (state)
		{
			var row = $('<tr class="refill-row">').attr('data-recipe-id', state.recipe_id).attr('data-status', state.status);
			row.append($('<td class="refill-name">').text(state.recipe_name));
			var status = $('<td class="refill-status">');
			status.append($('<span class="badge">').addClass(BadgeClass(state.status)).text(StatusWords(state.status, state.days_overdue)));
			row.append(status);
			row.append($('<td class="refill-date">').text(state.estimate.reorder_date || Dash()));
			row.append($('<td class="refill-source">').text(state.estimate.reorder_date ? SourceWords(state.estimate.source, null) : (state.estimate.reason ? ReasonWords(state.estimate.reason) : Dash())));
			var actions = $('<td class="refill-actions">');
			var button = $('<button type="button" class="btn btn-sm btn-outline-primary refill-details">').text(__t('Details'))
				.attr('aria-label', __t('Details of %s', state.recipe_name));
			button.on('click', function () { Select(state.recipe_id, true); });
			actions.append(button);
			row.append(actions);
			if (state.recipe_id === selectedId) row.addClass('table-active');
			body.append(row);
		});
		$('#refill-empty').toggleClass('d-none', states.length > 0);
		$('#refill-table').toggleClass('d-none', states.length === 0);
	}

	function RenderNotices(notices)
	{
		var list = $('#refill-notices').empty();
		notices.forEach(function (notice)
		{
			var item = $('<li class="refill-notice mb-2">').attr('data-notice-key', notice.key).attr('data-kind', notice.kind);
			item.append($('<strong class="refill-notice-name">').text(notice.recipe_name));
			item.append(document.createTextNode(': '));
			item.append($('<span class="refill-notice-text">').text(NoticeWords(notice)));
			var seen = $('<button type="button" class="btn btn-sm btn-outline-secondary ml-2 refill-ack">').text(__t('Mark as seen'))
				.attr('aria-label', __t('Mark as seen: %s', notice.recipe_name));
			seen.on('click', function () { Acknowledge(notice); });
			var open = $('<button type="button" class="btn btn-sm btn-outline-primary ml-1 refill-notice-details">').text(__t('Details'))
				.attr('aria-label', __t('Details of %s', notice.recipe_name));
			open.on('click', function () { Select(notice.recipe_id, true); });
			item.append(seen).append(open);
			list.append(item);
		});
		$('#refill-notices-empty').toggleClass('d-none', notices.length > 0);
	}

	function Acknowledge(notice)
	{
		Clear();
		Victual.Api.Post('refills/notices/ack', { notice_key: notice.key }, function ()
		{
			Message(__t('Notice marked as seen.'));
			LoadAll();
		}, function (xhr) { Failed(xhr); LoadAll(); });
	}

	// --- The default advance warning -------------------------------------------------------------

	function LoadDefaultLead()
	{
		Victual.Api.Get('user/settings/' + LEAD_SETTING, function (result)
		{
			$('#refill-lead-default').val(result && result.value !== undefined && result.value !== null ? result.value : '');
		}, Failed);
	}

	function SaveDefaultLead()
	{
		Clear();
		var text = String($('#refill-lead-default').val() || '').trim();
		if (!IsWholeNumber(text, 0, 60)) { ErrorMessage(__t('Advance warning must be a whole number from 0 to 60.')); return; }
		Victual.Api.Put('user/settings/' + LEAD_SETTING, { value: parseInt(text, 10) }, function ()
		{
			Message(__t('Advance warning saved.'));
			LoadAll(ReloadDetail);
		}, Failed);
	}

	// --- One prescription --------------------------------------------------------------------------

	function Select(id, focus, keepMessages)
	{
		selectedId = id;
		var sequence = ++detailSequence;
		if (!keepMessages) Clear();
		Victual.Api.Get('consumption/recipes/' + encodeURIComponent(id) + '/refill?' + AsOf(), function (state)
		{
			// A slower answer for a prescription the person has since left must not replace the one on screen.
			if (sequence !== detailSequence || selectedId !== id) return;
			detail = state;
			RenderDetail(focus, !keepMessages);
			RenderList();
		}, function (xhr) { Failed(xhr); HideDetail(); LoadAll(); });
	}

	function ReloadDetail() { if (selectedId !== null) Select(selectedId, false, true); }

	function Row(label, value)
	{
		return [$('<dt class="col-sm-4">').text(label), $('<dd class="col-sm-8">').text(value)];
	}

	function RenderDetail(focus, resetForms)
	{
		var d = detail;
		var section = $('#refill-detail').removeClass('d-none');
		$('#refill-detail-title').text(d.recipe_name);

		var rule = d.settings && d.settings.rule ? d.settings.rule : null;
		var estimate = d.estimate;
		var summary = $('#refill-summary').empty();
		var rows = [
			[__t('Status'), StatusWords(d.status, d.days_overdue)],
			[__t('Estimated reorder date'), estimate.reorder_date || Dash()],
			[__t('Where the date comes from'), estimate.reorder_date ? SourceWords(estimate.source, rule) : (estimate.reason ? ReasonWords(estimate.reason) : Dash())],
			[__t('Advance warning starts'), estimate.warning_date ? __t('%1$s (%2$s)', estimate.warning_date, __n(estimate.lead_days, '%s day before', '%s days before')) : Dash()],
			[__t('Current fill'), d.current_fill
				? (d.current_fill.supplied_days === null
					? __t('Filled on %s, days supplied not entered', d.current_fill.filled_on)
					: __t('Filled on %1$s, %2$s', d.current_fill.filled_on, __n(d.current_fill.supplied_days, '%s day supplied', '%s days supplied')))
				: Dash()],
			[__t('Order'), d.open_order ? OrderWords(d.open_order) : Dash()],
			[__t('Compared with the date on this device'), d.as_of]
		];
		rows.forEach(function (r) { Row(r[0], r[1]).forEach(function (cell) { summary.append(cell); }); });

		var editable = CanEdit(d.recipe_id);
		$('#refill-forms').toggleClass('d-none', !editable);
		$('#refill-read-only').toggleClass('d-none', editable);

		var today = LocalToday();
		// The fields are filled from the server's state when a prescription is opened or a write succeeded. A
		// refused write or a background refresh leaves what the person typed.
		if (resetForms)
		{
			$('#refill-fill-date').val(today);
			$('#refill-fill-days').val('');
			$('#refill-fill-note').val('');
			$('#refill-rule-kind').val(rule ? rule.kind : '');
			$('#refill-rule-parameter').val(rule ? rule.parameter : '');
			UpdateRuleHelp();
			$('#refill-date-input').val(d.explicit_date ? d.explicit_date.reorder_on : '');
			$('#refill-lead-input').val(d.settings && d.settings.warning_lead_days !== null ? d.settings.warning_lead_days : '');
			$('#refill-order-date').val(today);
		}
		$('#refill-fill-receive').toggleClass('d-none', !d.open_order);
		$('#refill-order-record').prop('disabled', !!d.open_order);
		$('#refill-order-cancel').toggleClass('d-none', !d.open_order);

		var fills = $('#refill-fills-rows').empty();
		d.fills.forEach(function (fill)
		{
			var row = $('<tr class="refill-fill">').attr('data-fill-id', fill.id).attr('data-voided', fill.voided_at ? '1' : '0');
			row.append($('<td class="refill-fill-date">').text(fill.filled_on));
			row.append($('<td class="refill-fill-days">').text(fill.supplied_days === null ? Dash() : fill.supplied_days));
			row.append($('<td class="refill-fill-note">').text(fill.note || ''));
			var state = fill.voided_at ? __t('Voided: %s', fill.void_reason) : (fill.is_current ? __t('Current fill') : __t('Earlier fill'));
			row.append($('<td class="refill-fill-state">').text(state));
			var actions = $('<td class="refill-fill-actions">');
			if (editable && !fill.voided_at)
			{
				var button = $('<button type="button" class="btn btn-sm btn-outline-secondary refill-void">').text(__t('Void'))
					.attr('aria-label', __t('Void the fill of %s', fill.filled_on));
				button.on('click', function () { OpenVoid(fill); });
				actions.append(button);
			}
			row.append(actions);
			fills.append(row);
		});
		$('#refill-fills-empty').toggleClass('d-none', d.fills.length > 0);

		var orders = $('#refill-orders-rows').empty();
		d.orders.forEach(function (order)
		{
			var row = $('<tr class="refill-order">').attr('data-order-id', order.id).attr('data-state', order.state);
			row.append($('<td class="refill-order-date">').text(order.ordered_on));
			var words = order.state === 'open' ? __t('Open') : (order.state === 'received' ? __t('Received') : __t('Cancelled'));
			row.append($('<td class="refill-order-state">').text(words));
			orders.append(row);
		});
		$('#refill-orders-empty').toggleClass('d-none', d.orders.length > 0);

		if (focus)
		{
			section[0].scrollIntoView({ block: 'start' });
			$('#refill-detail-title').trigger('focus');
		}
	}

	// A write answered with the new state: show it, say what happened and read the list again.
	function Applied(message)
	{
		return function (state)
		{
			detail = state;
			Message(message);
			ErrorMessage('');
			RenderDetail(false, true);
			LoadAll();
		};
	}

	// One write at a time: a double click or a double Enter must not post twice.
	function Write(verb, path, body, success, failure)
	{
		if (busy) return;
		busy = true;
		$('#refill-forms button, #refill-void-confirm').prop('disabled', true);
		Victual.Api[verb](path, body, function (result) { Settled(); success(result); }, function (xhr) { Settled(); failure(xhr); });
	}

	function Settled()
	{
		busy = false;
		$('#refill-forms button, #refill-void-confirm').prop('disabled', false);
		if (detail) $('#refill-order-record').prop('disabled', !!detail.open_order);
		UpdateRuleHelp();
	}

	function Base() { return 'consumption/recipes/' + encodeURIComponent(selectedId) + '/refill'; }

	// --- The forms -------------------------------------------------------------------------------

	function FillBody()
	{
		var date = String($('#refill-fill-date').val() || '').trim();
		var days = String($('#refill-fill-days').val() || '').trim();
		// A number field answers '' for text it cannot read ("1e", "-"); that is a mistake, not an empty field.
		if ($('#refill-fill-days')[0].validity.badInput) { ErrorMessage(__t('Days supplied must be a whole number from 1 to 730, or empty.')); return null; }
		var note = String($('#refill-fill-note').val() || '').trim();
		if (date === '') { ErrorMessage(__t('Enter the date the pharmacy supplied the medication.')); return null; }
		if (days !== '' && !IsWholeNumber(days, 1, 730)) { ErrorMessage(__t('Days supplied must be a whole number from 1 to 730, or empty.')); return null; }
		var body = { filled_on: date };
		if (days !== '') body.supplied_days = parseInt(days, 10);
		if (note !== '') body.note = note;
		return body;
	}

	function RecordFill(event)
	{
		event.preventDefault();
		Clear();
		var body = FillBody();
		if (body === null) return;
		Write('Post', Base() + '/fills?' + AsOf(), body, Applied(__t('Fill recorded.')), Refused);
	}

	function ReceiveOrder()
	{
		Clear();
		var body = FillBody();
		if (body === null || !detail || !detail.open_order) return;
		Write('Post', Base() + '/orders/' + detail.open_order.id + '/receive?' + AsOf(), body, Applied(__t('Order received and fill recorded.')), Refused);
	}

	function SaveRule(event)
	{
		event.preventDefault();
		Clear();
		var kind = $('#refill-rule-kind').val() || '';
		var rule = null;
		if (kind !== '')
		{
			var text = String($('#refill-rule-parameter').val() || '').trim();
			var range = RuleRange(kind);
			if (text === '') { ErrorMessage(__t('Enter a value for the rule.')); return; }
			if (!IsWholeNumber(text, range[0], range[1])) { ErrorMessage(__t('The value must be a whole number from %1$s to %2$s.', range[0], range[1])); return; }
			rule = { kind: kind, parameter: parseInt(text, 10) };
		}
		Write('Put', Base() + '?' + AsOf(), { rule: rule }, Applied(__t('Rule saved.')), Refused);
	}

	function SetDate(event)
	{
		event.preventDefault();
		Clear();
		var date = String($('#refill-date-input').val() || '').trim();
		if (date === '') { ErrorMessage(__t('Enter a reorder date.')); return; }
		Write('Put', Base() + '?' + AsOf(), { explicit_reorder_date: date }, Applied(__t('Reorder date saved.')), Refused);
	}

	function ClearDate()
	{
		Clear();
		Write('Put', Base() + '?' + AsOf(), { explicit_reorder_date: null }, Applied(__t('Reorder date removed.')), Refused);
	}

	function SaveLead(event)
	{
		event.preventDefault();
		Clear();
		var text = String($('#refill-lead-input').val() || '').trim();
		if (!IsWholeNumber(text, 0, 60)) { ErrorMessage(__t('Advance warning must be a whole number from 0 to 60.')); return; }
		Write('Put', Base() + '?' + AsOf(), { warning_lead_days: parseInt(text, 10) }, Applied(__t('Advance warning saved.')), Refused);
	}

	function ClearLead()
	{
		Clear();
		Write('Put', Base() + '?' + AsOf(), { warning_lead_days: null }, Applied(__t('Advance warning saved.')), Refused);
	}

	function RecordOrder(event)
	{
		event.preventDefault();
		Clear();
		var date = String($('#refill-order-date').val() || '').trim();
		if (date === '') { ErrorMessage(__t('Enter the date of the order.')); return; }
		Write('Post', Base() + '/orders?' + AsOf(), { ordered_on: date }, Applied(__t('Order recorded.')), Refused);
	}

	function CancelOrder()
	{
		Clear();
		if (!detail || !detail.open_order) return;
		Write('Post', Base() + '/orders/' + detail.open_order.id + '/cancel?' + AsOf(), {}, Applied(__t('Order cancelled.')), Refused);
	}

	function UpdateRuleHelp()
	{
		var kind = $('#refill-rule-kind').val() || '';
		$('#refill-rule-help').text(RuleHelp(kind));
		$('#refill-rule-parameter').prop('disabled', kind === '');
		if (kind !== '')
		{
			var range = RuleRange(kind);
			$('#refill-rule-parameter').attr('min', range[0]).attr('max', range[1]);
		}
		else
		{
			$('#refill-rule-parameter').removeAttr('min').removeAttr('max').val('');
		}
	}

	// --- Voiding a fill ----------------------------------------------------------------------------

	function OpenVoid(fill)
	{
		voidingFill = fill;
		$('#refill-void-title').text(__t('Void the fill of %s', fill.filled_on));
		$('#refill-void-reason').val('');
		$('#refill-void-error').text('');
		$('#refill-void-modal').modal('show');
	}

	function ConfirmVoid()
	{
		var reason = String($('#refill-void-reason').val() || '').trim();
		if (reason === '') { $('#refill-void-error').text(__t('Enter a reason.')); return; }
		Write('Post', Base() + '/fills/' + voidingFill.id + '/void?' + AsOf(), { reason: reason }, function (state)
		{
			$('#refill-void-modal').modal('hide');
			Applied(__t('Fill voided.'))(state);
		}, function (xhr) { $('#refill-void-error').text(ErrorText(xhr)); LoadAll(ReloadDetail); });
	}

	// --- Wiring --------------------------------------------------------------------------------------

	$('#refill-lead-default-save').on('click', SaveDefaultLead);
	$('#refill-fill-form').on('submit', RecordFill);
	$('#refill-fill-receive').on('click', ReceiveOrder);
	$('#refill-rule-form').on('submit', SaveRule);
	$('#refill-rule-kind').on('change', UpdateRuleHelp);
	$('#refill-date-form').on('submit', SetDate);
	$('#refill-date-clear').on('click', ClearDate);
	$('#refill-lead-form').on('submit', SaveLead);
	$('#refill-lead-clear').on('click', ClearLead);
	$('#refill-order-form').on('submit', RecordOrder);
	$('#refill-order-cancel').on('click', CancelOrder);
	$('#refill-void-confirm').on('click', ConfirmVoid);

	UpdateRuleHelp();
	LoadDefaultLead();
	LoadAll();
})();
