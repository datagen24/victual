// The list and form factories the master data pages are built from.
//
// Loaded on every page from views/layout/default.blade.php, straight after victual.js
// (it uses Victual.FrontendHelpers and Victual.Api) and before the per-view script that
// calls into it. Plan 12 step 3; no bundler, per that plan's Q6 - a second <script> tag
// is the whole cost of sharing this code.
//
// Two entry points, and the pieces they are made of:
//
//   Victual.EntityList({ ... })   the whole of a master data list page: DataTable, the
//                                 search box, the "show disabled" toggle and the delete
//                                 confirmation.
//   Victual.EntityForm({ ... })   the whole of a create/edit form page: the save button,
//                                 live validation, Enter-to-submit, the busy cycle, the
//                                 userfields round trip and the embedded-dialog Reload.
//
// The four list pieces are also exported individually (Victual.EntityList.Table and
// friends) for the partly-cloned pages that want some of the behaviour but bring their
// own page logic - plan 12's Q5 calls those the mixin adopters.
//
// SECURITY (sweep finding S29). Every message these factories build is rendered as HTML:
// bootbox does `.html(options.message)` and toastr ships `escapeHtml: false`. So the
// entity name is taken as *data* and escaped on the way in, here, once - no caller can
// pass markup through it, and the thirty-second list page added to this tree is safe
// because of where the escaping lives rather than because someone remembered. Note the
// name arrives from a `data-*` attribute read back with `.attr()`, which returns the
// *decoded* string: escaping applied when the attribute was written is not in effect by
// then, which is exactly how the tree's one pre-existing `.escapeHTML()` call was
// defeated. Escape at the point of use, which is what ConfirmDelete does.

/**
 * Escapes a value for interpolation into an HTML context (a bootbox message, a toastr
 * message, concatenated markup). Accepts anything: null and undefined become the empty
 * string, numbers are stringified, so callers do not have to guard the read.
 *
 * The function form exists because the value usually comes back from `.attr()` or from
 * an API response, where `String.prototype.escapeHTML` would throw on null.
 * @param {*} value Value to escape
 * @returns {string} The value with &, <, >, ", ', /, ` and = replaced by entities
 */
Victual.FrontendHelpers.EscapeHtml = function (value)
{
	if (value === null || value === undefined)
	{
		return '';
	}

	return String(value).escapeHTML();
};

(function ()
{
	var escapeHtml = Victual.FrontendHelpers.EscapeHtml;

	/**
	 * The standard master data DataTable: first column is the row menu, so it is neither
	 * orderable nor searchable, and the tbody is revealed once the table has been drawn.
	 * @param {string} selector jQuery selector of the table
	 * @param {Object} [options] { order: [[1, 'asc']], columnDefs: [] } - columnDefs are
	 *                           appended to the standard two, and any other key is passed
	 *                           through to DataTables untouched
	 * @returns {Object} The DataTables api object
	 */
	function Table(selector, options)
	{
		options = options || {};

		var settings = {};

		for (var key in options)
		{
			if (options.hasOwnProperty(key) && key !== 'order' && key !== 'columnDefs')
			{
				settings[key] = options[key];
			}
		}

		settings.order = options.order || [[1, 'asc']];
		settings.columnDefs = [
			{ 'orderable': false, 'targets': 0 },
			{ 'searchable': false, 'targets': 0 }
		].concat(options.columnDefs || []).concat($.fn.dataTable.defaults.columnDefs);

		var table = $(selector).DataTable(settings);
		$(selector + ' tbody').removeClass('d-none');
		table.columns.adjust().draw();

		return table;
	}

	/**
	 * Wires the debounced #search box and the #clear-filter-button to a table.
	 * @param {Object} table The DataTables api object
	 * @param {Object} [options] { onClear: Function, resetShowDisabled: boolean } -
	 *                           onClear runs after the search is reset; resetShowDisabled
	 *                           unchecks #show-disabled the way a few lists do
	 */
	function SearchFilter(table, options)
	{
		options = options || {};

		$('#search').on('keyup', Delay(function ()
		{
			var value = $(this).val();
			if (value === 'all')
			{
				value = '';
			}

			table.search(value).draw();
		}, Victual.FormFocusDelay));

		$('#clear-filter-button').on('click', function ()
		{
			$('#search').val('');
			table.search('').draw();

			if (options.resetShowDisabled)
			{
				$('#show-disabled').prop('checked', false);
			}

			if (typeof options.onClear === 'function')
			{
				options.onClear();
			}
		});
	}

	/**
	 * Wires the "show disabled" checkbox. Filtering is server side, so the toggle simply
	 * reloads the list with or without the include_disabled URI parameter, and the
	 * checkbox reflects that parameter on load.
	 * @param {string} listUrl Root relative list URL, e.g. "/locations"
	 */
	function ShowDisabledToggle(listUrl)
	{
		$('#show-disabled').change(function ()
		{
			if (this.checked)
			{
				window.location.href = U(listUrl + '?include_disabled');
			}
			else
			{
				window.location.href = U(listUrl);
			}
		});

		if (GetUriParam('include_disabled'))
		{
			$('#show-disabled').prop('checked', true);
		}
	}

	/**
	 * The delete-confirm dialog and the DELETE behind it, bound as a delegated handler so
	 * it survives a DataTables redraw.
	 *
	 * `options.message` is a localization key with a single "%s" placeholder for the
	 * entity's name; the name is read from `options.nameAttr` and **escaped here** before
	 * it reaches the message - see the security note at the top of this file. A message
	 * with no placeholder (the userobjects list has none, since a userobject has no name)
	 * simply ignores the name.
	 *
	 * @param {Object} options
	 * @param {string} options.button Selector of the delete buttons, e.g. ".location-delete-button"
	 * @param {string} options.idAttr Attribute holding the object id, e.g. "data-location-id"
	 * @param {string} [options.nameAttr] Attribute holding the display name
	 * @param {string} options.endpoint API path the id is appended to, e.g. "objects/locations"
	 * @param {string} options.message Localization key of the confirmation question
	 * @param {string} [options.extraMessage] Already-translated markup appended to the question
	 * @param {Function} [options.after] Called with (id) on success; defaults to navigating
	 *                                   to options.list
	 * @param {string} [options.list] Root relative list URL used by the default `after`
	 */
	function ConfirmDelete(options)
	{
		$(document).on('click', options.button, function (e)
		{
			e.preventDefault();

			var button = $(e.currentTarget);
			var objectId = button.attr(options.idAttr);
			var objectName = options.nameAttr ? button.attr(options.nameAttr) : null;

			var message = __t(options.message, escapeHtml(objectName));

			if (options.extraMessage)
			{
				message = message + '<br><br>' + options.extraMessage;
			}

			bootbox.confirm({
				message: message,
				closeButton: false,
				className: options.className,
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
					if (result !== true)
					{
						return;
					}

					// A user initiated delete that fails must say so - plan 12, Q2 - and it now
					// says what the server said when the server had something to say. An
					// endpoint that refuses on purpose sends the reason as error_message
					// ("Location has child locations"), which is the whole answer; anything
					// else still lands on the generic wording ShowGenericError has always
					// used. The message is escaped where it is rendered, not here.
					Victual.Api.Delete(options.endpoint + '/' + objectId, {},
						function ()
						{
							if (typeof options.after === 'function')
							{
								options.after(objectId);
							}
							else
							{
								window.location.href = U(options.list);
							}
						},
						function (xhr)
						{
							Victual.FrontendHelpers.ShowApiError('A server error occured while processing your request', xhr);
						}
					);
				}
			});
		});
	}

	/**
	 * A whole master data list page.
	 *
	 * @param {Object} options
	 * @param {string} options.table Table selector, e.g. "#locations-table"
	 * @param {string} [options.list] Root relative list URL; also the default delete target
	 * @param {Array} [options.order] DataTables order, defaults to [[1, 'asc']]
	 * @param {Array} [options.columnDefs] Extra column definitions
	 * @param {boolean} [options.showDisabled] Wire the "show disabled" toggle (needs options.list)
	 * @param {Function} [options.onClear] Extra work when the filters are cleared
	 * @param {boolean} [options.resetShowDisabled] Clearing filters also unchecks "show disabled"
	 * @param {Object} [options.delete] ConfirmDelete options, minus `list` which is filled in
	 * @returns {Object} { Table } - the DataTables api object, for page specific code
	 */
	Victual.EntityList = function (options)
	{
		var table = Table(options.table, { order: options.order, columnDefs: options.columnDefs });

		SearchFilter(table, { onClear: options.onClear, resetShowDisabled: options.resetShowDisabled });

		if (options.showDisabled)
		{
			ShowDisabledToggle(options.list);
		}

		if (options.delete)
		{
			var deleteOptions = {};

			for (var key in options.delete)
			{
				if (options.delete.hasOwnProperty(key))
				{
					deleteOptions[key] = options.delete[key];
				}
			}

			if (deleteOptions.list === undefined)
			{
				deleteOptions.list = options.list;
			}

			ConfirmDelete(deleteOptions);
		}

		return { Table: table };
	};

	Victual.EntityList.Table = Table;
	Victual.EntityList.SearchFilter = SearchFilter;
	Victual.EntityList.ShowDisabledToggle = ShowDisabledToggle;
	Victual.EntityList.ConfirmDelete = ConfirmDelete;

	/**
	 * A whole create/edit form page.
	 *
	 * Owns the save/disable/re-enable cycle, live validation, Enter-to-submit, the
	 * userfields round trip and what happens after a successful save - which is the
	 * embedded-dialog `Reload` message when the form runs in a dialog iframe, and a
	 * navigation back to the list otherwise.
	 *
	 * Enter-to-submit is bound to the *same function* the save button calls, rather than
	 * to a click on a save button selector. That is deliberate: three forms in this tree
	 * had drifted to clicking a button id that does not exist (`#save-quantityunit-button`,
	 * `#save-recipe-button`, `#save-mealplansections-button`), so Enter did nothing and
	 * nothing said so. A factory that cannot name the button wrong cannot repeat that.
	 *
	 * @param {Object} options
	 * @param {string} options.form Form element id, without the "#", e.g. "location-form"
	 * @param {string} options.save Selector of the save button(s)
	 * @param {string} options.endpoint API path, e.g. "objects/locations"
	 * @param {string|Function} [options.list] Root relative URL to return to after saving
	 * @param {boolean} [options.userfields=true] Load and save userfields around the object
	 * @param {string|null} [options.focus='#name'] Element focused on load
	 * @param {boolean} [options.comboboxGuard=true] Ignore a save while a combobox menu is open
	 * @param {boolean} [options.validateOnLoad=true] Run one validation pass on load
	 * @param {boolean} [options.validateOnSelectChange=false] Also re-validate when a select changes
	 * @param {Function} [options.body] (jsonData, context) => body actually sent
	 * @param {Function} [options.afterSave] (context) => void, replaces the default navigation
	 * @returns {Object} { Submit, FormId } - Submit(context) runs the same save the button does
	 */
	Victual.EntityForm = function (options)
	{
		var formId = options.form;
		var formSelector = '#' + formId;
		var useUserfields = options.userfields !== false;
		var focusSelector = options.focus === undefined ? '#name' : options.focus;

		function endUiBusy()
		{
			Victual.FrontendHelpers.EndUiBusy(formId);
		}

		function saveError(xhr)
		{
			endUiBusy();
			Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
		}

		function finish(context)
		{
			if (typeof options.afterSave === 'function')
			{
				options.afterSave(context);
				return;
			}

			if (GetUriParam('embedded') !== undefined)
			{
				window.parent.postMessage(WindowMessageBag('Reload'), Victual.BaseUrl);
			}
			else
			{
				window.location.href = U(typeof options.list === 'function' ? options.list(context) : options.list);
			}
		}

		function afterObjectSaved(context)
		{
			if (useUserfields)
			{
				Victual.Components.UserfieldsForm.Save(function ()
				{
					finish(context);
				});
			}
			else
			{
				finish(context);
			}
		}

		/**
		 * Validates and saves. `context` is handed to the `body` and `afterSave` hooks and
		 * carries whatever the caller put in it (the clicked button, for instance).
		 * @param {Object} [context]
		 * @returns {boolean} false when the save did not start
		 */
		function submit(context)
		{
			context = context || {};

			if (!Victual.FrontendHelpers.ValidateForm(formId, true))
			{
				return false;
			}

			if (options.comboboxGuard !== false && $('.combobox-menu-visible').length)
			{
				return false;
			}

			var jsonData = $(formSelector).serializeJSON();

			if (typeof options.body === 'function')
			{
				jsonData = options.body(jsonData, context);
			}

			Victual.FrontendHelpers.BeginUiBusy(formId);

			if (Victual.EditMode === 'create')
			{
				Victual.Api.Post(options.endpoint, jsonData,
					function (result)
					{
						Victual.EditObjectId = result.created_object_id;
						afterObjectSaved(context);
					},
					saveError
				);
			}
			else
			{
				Victual.Api.Put(options.endpoint + '/' + Victual.EditObjectId, jsonData,
					function ()
					{
						afterObjectSaved(context);
					},
					saveError
				);
			}

			return true;
		}

		$(document).on('click', options.save, function (e)
		{
			e.preventDefault();
			submit({ button: $(e.currentTarget) });
		});

		// Delegated from the form rather than bound to the inputs it has right now: a
		// userobject form's inputs are all userfields, and an entity with none has no
		// inputs at page load at all - which is why that one form never got an
		// Enter-to-submit handler in the first place.
		$(formSelector).on('keyup', 'input', function ()
		{
			Victual.FrontendHelpers.ValidateForm(formId);
		});

		if (options.validateOnSelectChange)
		{
			$(formSelector).on('change', 'select', function ()
			{
				Victual.FrontendHelpers.ValidateForm(formId);
			});
		}

		$(formSelector).on('keydown', 'input', function (event)
		{
			if (event.keyCode !== 13) // Enter
			{
				return;
			}

			event.preventDefault();

			if (!Victual.FrontendHelpers.ValidateForm(formId))
			{
				return false;
			}

			submit({ button: $(options.save).first() });
		});

		if (useUserfields)
		{
			Victual.Components.UserfieldsForm.Load();
		}

		if (options.validateOnLoad !== false)
		{
			Victual.FrontendHelpers.ValidateForm(formId);
		}

		if (focusSelector !== null)
		{
			setTimeout(function ()
			{
				$(focusSelector).focus();
			}, Victual.FormFocusDelay);
		}

		return { Submit: submit, FormId: formId };
	};
})();
