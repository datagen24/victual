// Implements the DateTimePicker widget (views/components/datetimepicker.blade.php): wraps a
// Tempus Dominus datetimepicker bound to ".datetimepicker input" with typed shorthand parsing
// (arrow keys to step, "x"/"X" for "never overdue", "+/-n[d/m/y]" relative offsets,
// "MMDD"/"YYYYMMDD"/"YYYYMM[e/+]" quick dates) and an optional "shortcut" checkbox
// (#datetimepicker-shortcut) that snaps to a fixed configured value.
// Public API per instance: GetInputElement/GetValue/SetValue/Clear/ChangeFormat/Init.
//
// The widget is *parameterised by an instance suffix* so that two pickers can live on one
// page without fighting over the same selectors - the meal plan's date range and the stock
// entry form's due/purchased pair are the two places that need it. Every per-instance id and
// class is built from that suffix:
//
//   suffix ""            .datetimepicker            #datetimepicker-shortcut            ...
//   suffix "-secondary"  .datetimepicker-secondary  #datetimepicker-secondary-shortcut  ...
//
// This replaces the second copy of this component, a 373-line file that differed only by a "2"
// appended to every one of those names (plan 12 step 5, and its Q4: a normalized diff of the
// pair, and of the two Blade components, found nothing but the naming and the header comment).
//
// An instance is registered only when its markup is actually on the page, because callers use
// the object's existence as the test for whether the picker was rendered at all - the due date
// picker on the purchase page is behind a feature flag and purchase.js branches on
// `if (Victual.Components.DateTimePicker)`.

/**
 * Builds one independently-scoped DateTimePicker instance.
 * @param {string} suffix Appended to every per-instance id/class - "" for the primary picker,
 *                        "-secondary" for a second one on the same page
 * @returns {Object} The instance's public API
 */
Victual.Components.CreateDateTimePicker = function (suffix)
{
	var pickerSelector = '.datetimepicker' + suffix;
	var wrapperSelector = '.datetimepicker' + suffix + '-wrapper';
	var shortcutSelector = '#datetimepicker' + suffix + '-shortcut';
	var timeagoSelector = '#datetimepicker' + suffix + '-timeago';
	var earlierThanInfoSelector = '#datetimepicker' + suffix + '-earlier-than-info';
	var dateOnlyCssClass = 'date-only-datetimepicker' + suffix;

	var picker = {};

	/** @returns {jQuery} The picker's underlying date/time text input */
	picker.GetInputElement = function ()
	{
		return $(pickerSelector).find('input').not(".form-check-input");
	}

	/** @returns {string} The current input value, formatted per the input's configured "format" */
	picker.GetValue = function ()
	{
		return picker.GetInputElement().val();
	}

	/**
	 * The current value as the API should receive it (ADR-0027 decision 2). A date-only
	 * picker sends its calendar date unchanged - a date has no zone to convert. A date and
	 * time is the device's wall clock, so it is sent as an instant with the device's offset;
	 * null when the device's zone skipped that wall clock (the input's validity says so too).
	 * @returns {string|null}
	 */
	picker.GetInstant = function ()
	{
		var format = picker.GetInputElement().data("format");
		var value = picker.GetValue();
		if (format == "YYYY-MM-DD")
		{
			return value;
		}

		return Victual.Instant.FromDevice(value, format);
	}

	/** Sets the input value directly and un-checks the shortcut checkbox if it no longer applies */
	picker.SetValue = function (value, inputElement = picker.GetInputElement())
	{
		// "Click" the shortcut checkbox when the desired value is
		// not the shortcut value and it is currently set
		var shortcutValue = $(shortcutSelector).data("datetimepicker-shortcut-value");
		if (value != shortcutValue && $(shortcutSelector).is(":checked"))
		{
			$(shortcutSelector).click();
		}
		inputElement.val(value);
		inputElement.keyup();
	}

	/** Re-inits the underlying picker widget and empties the input/shortcut/timeago display */
	picker.Clear = function ()
	{
		picker.Init(true);

		picker.GetInputElement().val("");

		// "Click" the shortcut checkbox when the desired value is
		// not the shortcut value and it is currently set
		var value = "";
		var shortcutValue = $(shortcutSelector).data("datetimepicker-shortcut-value");
		if (value != shortcutValue && $(shortcutSelector).is(":checked"))
		{
			$(shortcutSelector).click();
		}

		$(timeagoSelector).text('');
	}

	/** Destroys and re-inits the picker with a new moment.js display/parse format (e.g. date-only vs date+time) */
	picker.ChangeFormat = function (format)
	{
		$(pickerSelector).datetimepicker("destroy");
		picker.GetInputElement().data("format", format);
		picker.Init();

		if (format == "YYYY-MM-DD")
		{
			picker.GetInputElement().addClass(dateOnlyCssClass);
		}
		else
		{
			picker.GetInputElement().removeClass(dateOnlyCssClass);
		}
	}

	// Determine the initial date shown when opening the picker, from the template's
	// data-init-with-now / data-init-value attributes on the input
	var startDate = null;
	if (picker.GetInputElement().data('init-with-now') === true)
	{
		startDate = moment().format(picker.GetInputElement().data('format'));
	}
	if (picker.GetInputElement().data('init-value').length > 0)
	{
		startDate = moment(picker.GetInputElement().data('init-value')).format(picker.GetInputElement().data('format'));
	}

	// data-limit-end-to-now caps the calendar (and later validation) to not go beyond "now"
	var limitDate = moment('2999-12-31 23:59:59');
	if (picker.GetInputElement().data('limit-end-to-now') === true)
	{
		limitDate = moment();
	}

	/**
	 * (Re-)initializes the Tempus Dominus widget on every input of this instance with the
	 * format/limits/icons configured via data attributes. Idempotent unless reInit is set,
	 * which first destroys any existing instance (used by ChangeFormat/Clear).
	 * @param {boolean} [reInit=false] Destroy an existing picker instance before initializing.
	 */
	picker.Init = function (reInit = false)
	{
		if (reInit)
		{
			$(pickerSelector).datetimepicker("destroy");
		}

		$(pickerSelector).each(function ()
		{
			$(this).datetimepicker(
				{
					format: $(this).find("input").data('format'),
					buttons: {
						showToday: picker.GetInputElement().data('limit-end-to-now') !== true,
						showClose: true
					},
					calendarWeeks: Victual.CalendarShowWeekNumbers,
					maxDate: limitDate,
					locale: moment.locale(),
					defaultDate: startDate,
					useCurrent: false,
					icons: {
						time: 'fa-solid fa-clock',
						date: 'fa-solid fa-calendar',
						up: 'fa-solid fa-arrow-up',
						down: 'fa-solid fa-arrow-down',
						previous: 'fa-solid fa-chevron-left',
						next: 'fa-solid fa-chevron-right',
						today: 'fa-solid fa-calendar-day',
						clear: 'fa-solid fa-trash-can',
						close: 'fa-solid fa-check'
					},
					sideBySide: true,
					keyBinds: {
						up: function (widget) { },
						down: function (widget) { },
						'control up': function (widget) { },
						'control down': function (widget) { },
						left: function (widget) { },
						right: function (widget) { },
						pageUp: function (widget) { },
						pageDown: function (widget) { },
						enter: function (widget) { },
						escape: function (widget) { },
						'control space': function (widget) { },
						t: function (widget) { },
						'delete': function (widget) { }
					}
				});
		});
	}
	picker.Init();

	// Core typed-shorthand handling: interprets special input values/arrow-key combos as relative
	// date edits, then re-validates the resulting value (required/limit-end-to-now/limit-start-to-now)
	picker.GetInputElement().on('keyup', function (e)
	{
		$(pickerSelector).datetimepicker('hide');

		var inputElement = $(e.currentTarget)
		var value = inputElement.val();
		var format = inputElement.data('format');
		var nextInputElement = $(document).find(inputElement.data('next-input-selector'));

		if (!nextInputElement.is("input"))
		{
			nextInputElement = nextInputElement.find("input");
		}

		// If input is empty and any arrow key is pressed, set date to today
		if (value.length === 0 && (e.keyCode === 38 || e.keyCode === 40 || e.keyCode === 37 || e.keyCode === 39))
		{
			picker.SetValue(moment(new Date(), format, true).format(format), inputElement);
			nextInputElement.focus();
		}
		else if (value === 'x' || value === 'X') // Shorthand for never overdue
		{
			picker.SetValue(moment('2999-12-31 23:59:59').format(format), inputElement);
			nextInputElement.focus();
		}
		else if ((value.startsWith("+") || value.startsWith("-"))) // Shorthand for [+/-]n[d/m/y]
		{
			var lastCharacter = value.slice(-1).toLowerCase();

			if (lastCharacter == "d" || lastCharacter == "m" || lastCharacter == "y")
			{
				var n = Number.parseInt(value.substring(1, value.length - 1));
				if (value.startsWith("-"))
				{
					n = n * -1;
				}

				if (lastCharacter == "d")
				{
					picker.SetValue(moment().add(n, "days").format(format));
					nextInputElement.focus();
				}
				else if (lastCharacter == "m")
				{
					picker.SetValue(moment().add(n, "months").format(format));
					nextInputElement.focus();
				}
				else if (lastCharacter == "y")
				{
					picker.SetValue(moment().add(n, "years").format(format));
					nextInputElement.focus();
				}
			}
		}
		else if (value.length === 4 && $.isNumeric(value) && Number.parseInt(value.substring(0, 2)) >= 1 && Number.parseInt(value.substring(0, 2)) <= 12) // Shorthand for MMDD
		{
			var date = moment((new Date()).getFullYear().toString() + value);
			if (date.isBefore(moment()))
			{
				date.add(1, "year");
			}
			picker.SetValue(date.format(format), inputElement);
			nextInputElement.focus();
		}
		else if (value.length === 8 && $.isNumeric(value)) // Shorthand for YYYYMMDD
		{
			picker.SetValue(value.replace(/(\d{4})(\d{2})(\d{2})/, '$1-$2-$3'), inputElement);
			nextInputElement.focus();
		}
		else if (value.length === 7 && $.isNumeric(value.substring(0, 6)) && (value.substring(6, 7).toLowerCase() === "e" || value.substring(6, 7) === "+")) // Shorthand for YYYYMM[e/+]
		{
			var date = moment(value.substring(0, 4) + "-" + value.substring(4, 6) + "-01").endOf("month");
			picker.SetValue(date.format(format), inputElement);
			nextInputElement.focus();
		}
		else
		{
			var dateObj = moment(value, format, true);
			if (dateObj.isValid())
			{
				if (e.shiftKey)
				{
					// WITH shift modifier key

					if (e.keyCode === 38) // Up
					{
						picker.SetValue(dateObj.add(-1, 'months').format(format), inputElement);
					}
					else if (e.keyCode === 40) // Down
					{
						picker.SetValue(dateObj.add(1, 'months').format(format), inputElement);
					}
					else if (e.keyCode === 37) // Left
					{
						picker.SetValue(dateObj.add(-1, 'years').format(format), inputElement);
					}
					else if (e.keyCode === 39) // Right
					{
						picker.SetValue(dateObj.add(1, 'years').format(format), inputElement);
					}
				}
				else
				{
					// WITHOUT shift modifier key

					if (e.keyCode === 38) // Up
					{
						picker.SetValue(dateObj.add(-1, 'days').format(format), inputElement);
					}
					else if (e.keyCode === 40) // Down
					{
						picker.SetValue(dateObj.add(1, 'days').format(format), inputElement);
					}
					else if (e.keyCode === 37) // Left
					{
						picker.SetValue(dateObj.add(-1, 'weeks').format(format), inputElement);
					}
					else if (e.keyCode === 39) // Right
					{
						picker.SetValue(dateObj.add(1, 'weeks').format(format), inputElement);
					}
				}
			}
		}

		$(timeagoSelector).attr("datetime", picker.GetValue());
		RefreshContextualTimeago(wrapperSelector);

		// Custom validation: invalid/unparsable dates, and dates violating limit-end-to-now /
		// limit-start-to-now are flagged via the input's native validity API
		value = picker.GetValue();
		dateObj = moment(value, format, true);
		var element = picker.GetInputElement()[0];
		if (!dateObj.isValid())
		{
			if ($(element).hasAttr("required"))
			{
				element.setCustomValidity("error");
			}
		}
		else
		{
			// A wall clock the device's zone skipped when daylight saving began parses, and
			// moment moves it forward an hour; it is not a time there, so it is refused
			// rather than booked an hour later than typed (ADR-0028's rule, applied in the
			// zone the viewer typed in).
			if (dateObj.format(format) !== value)
			{
				element.setCustomValidity("error");
			}
			else if (picker.GetInputElement().data('limit-end-to-now') === true && dateObj.isAfter(moment()))
			{
				element.setCustomValidity("error");
			}
			else if (picker.GetInputElement().data('limit-start-to-now') === true && dateObj.isBefore(moment()))
			{
				element.setCustomValidity("error");
			}
			else
			{
				element.setCustomValidity("");
			}

			// data-earlier-than-limit shows an informational (non-blocking) hint when the chosen
			// date is earlier than a given reference date (e.g. a related field's value)
			var earlierThanLimit = picker.GetInputElement().data("earlier-than-limit");
			if (earlierThanLimit)
			{
				if (moment(value).isBefore(moment(earlierThanLimit)))
				{
					$(earlierThanInfoSelector).removeClass("d-none");
				}
				else
				{
					$(earlierThanInfoSelector).addClass("d-none");
				}
			}
		}

		// "Click" the shortcut checkbox when the shortcut value was
		// entered manually and it is currently not set
		var shortcutValue = $(shortcutSelector).data("datetimepicker-shortcut-value");
		if (value == shortcutValue && !$(shortcutSelector).is(":checked"))
		{
			$(shortcutSelector).click();
		}
	});

	// Keeps the contextual "timeago" display in sync with manual edits
	picker.GetInputElement().on('input', function (e)
	{
		$(timeagoSelector).attr("datetime", picker.GetValue());
		RefreshContextualTimeago(wrapperSelector);
	});

	// Calendar widget updated its value programmatically -> propagate to our own 'input' handling
	$(pickerSelector).on('update.datetimepicker', function (e)
	{
		picker.GetInputElement().trigger('input');
	});

	// Calendar closed -> re-run all of our change handling (input/change/keypress/keyup) once more
	$(pickerSelector).on('hide.datetimepicker', function (e)
	{
		picker.GetInputElement().trigger('input');
		picker.GetInputElement().trigger('change');
		picker.GetInputElement().trigger('keypress');
		picker.GetInputElement().trigger('keyup');
	});

	// The "shortcut" checkbox snaps the picker to (and locks it at) a preconfigured value
	// (data-datetimepicker-shortcut-value), e.g. "never expires"
	$(shortcutSelector).on("click", function ()
	{
		if (this.checked)
		{
			var value = $(shortcutSelector).data("datetimepicker-shortcut-value");
			picker.SetValue(value);
			picker.GetInputElement().attr("readonly", "");
			$(document).find(picker.GetInputElement().data('next-input-selector')).focus();
		}
		else
		{
			picker.SetValue("");
			picker.GetInputElement().removeAttr("readonly");
			picker.GetInputElement().focus();
		}

		picker.GetInputElement().trigger('input');
		picker.GetInputElement().trigger('change');
		picker.GetInputElement().trigger('keypress');
	});

	return picker;
}

// Register only the instances whose markup is on this page: the component script is loaded
// once for either instance, but callers test the object's existence to find out whether the
// picker was rendered (see purchase.js's feature-flagged due date field).
if ($('.datetimepicker').length > 0)
{
	Victual.Components.DateTimePicker = Victual.Components.CreateDateTimePicker('');
}

if ($('.datetimepicker-secondary').length > 0)
{
	Victual.Components.SecondaryDateTimePicker = Victual.Components.CreateDateTimePicker('-secondary');
}
