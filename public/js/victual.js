// Victual global object core: API wrapper (Victual.Api), translation helpers (__t/__n),
// generic frontend helpers (Victual.FrontendHelpers), locale aware number/date display,
// iframe modal / cross-window messaging and various global UI wiring.
// Loaded on every page (after extensions.js) before the per-view script; the Victual
// object itself (BaseUrl, UserSettings, UserId, Mode, ...) is pre-populated inline
// by views/layout/default.blade.php.

// Thin XMLHttpRequest wrapper around the Victual REST API,
// all apiFunction arguments are paths relative to /api (e.g. "stock/products/1")
Victual.Api = {};

/**
 * How long a request may run before it is abandoned. A request that is answered - even
 * with an error - resolves one of the two callbacks; a request that is never answered
 * used to resolve neither, which left the form it came from disabled forever. This is a
 * plain setting rather than a constant so a console session (or a test) can shorten it.
 * @type {number}
 */
Victual.Api.TimeoutMilliseconds = 30000;

/**
 * The error callback used when a caller passes none, or passes something that is not a
 * function. It renders the same toast the explicit handlers render.
 *
 * Note this is *not* Victual.FrontendHelpers.ShowGenericError itself: that takes
 * (message, exception), and an error callback is called with one argument, so wiring it
 * up directly would put the XMLHttpRequest where the message text belongs and run it
 * through __t(). This adapter is what "defaults to ShowGenericError" means in practice.
 * @param {XMLHttpRequest|Object} xhr The request that failed, or the failure descriptor
 *                                    synthesised for a timeout / transport error
 */
Victual.Api.DefaultErrorHandler = function (xhr)
{
	Victual.FrontendHelpers.ShowGenericError('A server error occured while processing your request', xhr && xhr.response ? xhr.response : xhr);
};

(function ()
{
	/**
	 * Builds the object handed to the error callback when the request never produced an
	 * HTTP response at all. Callers reach into `response`/`responseText` (some of them
	 * JSON.parse it and read error_message) and log the whole thing, so the shape has to
	 * look enough like an XMLHttpRequest for all three of those to keep working - a real
	 * XMLHttpRequest would JSON.stringify to "{}" and tell the reader nothing.
	 * @param {string} kind "timeout" or "error"
	 * @param {string} message Human readable cause
	 * @returns {Object} xhr-like failure descriptor
	 */
	function networkFailure(kind, message)
	{
		var payload = JSON.stringify({ error_message: message });

		return {
			readyState: 4,
			status: 0,
			statusText: kind,
			response: payload,
			responseText: payload,
			error_message: message,
			victualNetworkError: kind
		};
	}

	/**
	 * The one request path. Every Victual.Api.* function below is a wrapper around this.
	 * @param {string} method HTTP method
	 * @param {string} url Absolute URL (the wrappers build it through U())
	 * @param {*} [body] Request body; undefined sends none
	 * @param {Function} [success] Called with the parsed JSON response ({} on HTTP 204)
	 * @param {Function} [error] Called with the XMLHttpRequest on any non 200/204 status,
	 *                           or with a failure descriptor on timeout / transport error;
	 *                           defaults to Victual.Api.DefaultErrorHandler
	 * @param {Object} [opts] { contentType: string } - the Content-Type header to set
	 */
	function request(method, url, body, success, error, opts)
	{
		opts = opts || {};

		if (typeof error !== 'function')
		{
			error = Victual.Api.DefaultErrorHandler;
		}

		var xhr = new XMLHttpRequest();

		// A dropped connection fires readystatechange (status 0) *and* onerror, and a
		// timeout fires readystatechange *and* ontimeout, so without this exactly one
		// failure would call the error callback twice.
		var settled = false;
		var settle = function (callback, argument)
		{
			if (settled)
			{
				return;
			}

			settled = true;

			if (callback)
			{
				callback(argument);
			}
		};

		xhr.onreadystatechange = function ()
		{
			if (xhr.readyState === XMLHttpRequest.DONE)
			{
				// 202 is a success and carries a body. The label print operations answer with
				// it because creation is asynchronous - the job exists, and it becomes
				// printable when a renderer has produced bytes Victual verified - and a
				// wrapper that knew only 200 and 204 reported every accepted request as a
				// failure, with the status text where the error message belongs.
				if (xhr.status === 200 || xhr.status === 202 || xhr.status === 204)
				{
					if (xhr.status === 204)
					{
						settle(success, {});
					}
					else
					{
						settle(success, JSON.parse(xhr.responseText));
					}
				}
				else if (xhr.status === 0)
				{
					// No HTTP response at all - let ontimeout/onerror describe it instead.
					return;
				}
				else
				{
					settle(error, xhr);
				}
			}
		};

		xhr.timeout = Victual.Api.TimeoutMilliseconds;

		xhr.ontimeout = function ()
		{
			settle(error, networkFailure('timeout', 'The request to ' + url + ' timed out after ' + xhr.timeout + ' ms'));
		};

		xhr.onerror = function ()
		{
			settle(error, networkFailure('error', 'The request to ' + url + ' could not be completed (connection lost or blocked)'));
		};

		// Nothing in the tree calls abort() today, but the readystatechange branch above
		// no longer reports status 0, so the third way to get there needs its own exit.
		xhr.onabort = function ()
		{
			settle(error, networkFailure('abort', 'The request to ' + url + ' was aborted'));
		};

		xhr.open(method, url, true);

		if (opts.contentType)
		{
			xhr.setRequestHeader('Content-Type', opts.contentType);
		}

		// Extra request headers, for the one thing that needs them: an idempotency key is a
		// property of the *request* rather than of the resource, so it travels in a header
		// rather than in the body - which also keeps it out of the canonical fingerprint the
		// server takes of the body.
		if (opts.headers)
		{
			for (var name in opts.headers)
			{
				if (Object.prototype.hasOwnProperty.call(opts.headers, name))
				{
					xhr.setRequestHeader(name, opts.headers[name]);
				}
			}
		}

		if (body === undefined)
		{
			xhr.send();
		}
		else
		{
			xhr.send(body);
		}
	}

	var JSON_CONTENT_TYPE = { contentType: 'application/json' };

	/**
	 * Executes a GET request against the Victual API.
	 * @param {string} apiFunction API path relative to /api, e.g. "system/db-changed-time"
	 * @param {Function} [success] Called with the parsed JSON response ({} on HTTP 204)
	 * @param {Function} [error] Called with the XMLHttpRequest on any non 200/204 status
	 */
	Victual.Api.Get = function (apiFunction, success, error)
	{
		request('GET', U('/api/' + apiFunction), undefined, success, error, {});
	};

	/**
	 * Executes a POST request (JSON body) against the Victual API.
	 * @param {string} apiFunction API path relative to /api
	 * @param {Object} jsonData Request body, sent as JSON
	 * @param {Function} [success] Called with the parsed JSON response ({} on HTTP 204)
	 * @param {Function} [error] Called with the XMLHttpRequest on any non 200/204 status
	 * @param {Object} [headers] Extra request headers, e.g. an Idempotency-Key
	 */
	Victual.Api.Post = function (apiFunction, jsonData, success, error, headers)
	{
		var opts = headers ? { contentType: JSON_CONTENT_TYPE.contentType, headers: headers } : JSON_CONTENT_TYPE;
		request('POST', U('/api/' + apiFunction), JSON.stringify(jsonData), success, error, opts);
	};

	/**
	 * Executes a PUT request (JSON body) against the Victual API.
	 * @param {string} apiFunction API path relative to /api
	 * @param {Object} jsonData Request body, sent as JSON
	 * @param {Function} [success] Called with the parsed JSON response ({} on HTTP 204)
	 * @param {Function} [error] Called with the XMLHttpRequest on any non 200/204 status
	 */
	Victual.Api.Put = function (apiFunction, jsonData, success, error)
	{
		request('PUT', U('/api/' + apiFunction), JSON.stringify(jsonData), success, error, JSON_CONTENT_TYPE);
	};

	/**
	 * Executes a DELETE request against the Victual API.
	 * @param {string} apiFunction API path relative to /api
	 * @param {Object} jsonData Request body, sent as JSON (usually {})
	 * @param {Function} [success] Called with the parsed JSON response ({} on HTTP 204)
	 * @param {Function} [error] Called with the XMLHttpRequest on any non 200/204 status
	 */
	Victual.Api.Delete = function (apiFunction, jsonData, success, error)
	{
		request('DELETE', U('/api/' + apiFunction), JSON.stringify(jsonData), success, error, JSON_CONTENT_TYPE);
	};

	/**
	 * Uploads a file via PUT /api/files/{group}/{fileName} (raw octet-stream body).
	 * @param {Blob|File} file The file contents to upload
	 * @param {string} group File group (server side subfolder, e.g. "productpictures", "recipepictures")
	 * @param {string} fileName File name; is BASE64 encoded for the URL
	 * @param {Function} [success] Called with the parsed JSON response ({} on HTTP 204)
	 * @param {Function} [error] Called with the XMLHttpRequest on any non 200/204 status
	 */
	Victual.Api.UploadFile = function (file, group, fileName, success, error)
	{
		request('PUT', U('/api/files/' + group + '/' + btoa(fileName)), file, success, error, { contentType: 'application/octet-stream' });
	};

	/**
	 * Deletes a file via DELETE /api/files/{group}/{fileName}.
	 * Note the argument order is (fileName, group), the mirror image of UploadFile's, and
	 * that this sends no body where Victual.Api.Delete sends "{}" - both are long standing
	 * and both are kept.
	 * @param {string} fileName File name; is BASE64 encoded for the URL
	 * @param {string} group File group (server side subfolder)
	 * @param {Function} [success] Called with the parsed JSON response ({} on HTTP 204)
	 * @param {Function} [error] Called with the XMLHttpRequest on any non 200/204 status
	 */
	Victual.Api.DeleteFile = function (fileName, group, success, error)
	{
		request('DELETE', U('/api/files/' + group + '/' + btoa(fileName)), undefined, success, error, JSON_CONTENT_TYPE);
	};
})();

/**
 * Turns a root relative path (e.g. "/api/stock" or "/css/victual.css") into an
 * absolute URL by prepending the configured base URL (Victual.BaseUrl).
 * @param {string} relativePath Path starting with "/"
 * @returns {string} Absolute URL
 */
U = function (relativePath)
{
	return Victual.BaseUrl.replace(/\/$/, '') + relativePath;
}

// Gettext style translators; the localization strings are provided inline by the layout
// (TranslatorQu holds the separate quantity unit plural strings)
Victual.Translator = new window.translator.default(Victual.LocalizationStrings);
Victual.TranslatorQu = new window.translator.default(Victual.LocalizationStringsQu);

/**
 * Translates the given text into the current language,
 * placeholders are filled in sprintf-style (e.g. __t("Removed %1$s of %2$s", amount, name)).
 * In dev mode missing localizations are reported via POST /api/system/log-missing-localization.
 * @param {string} text Source (English) text / localization key
 * @param {...*} placeholderValues Values for sprintf placeholders in the translated text
 * @returns {string} Translated text
 */
__t = function (text, ...placeholderValues)
{
	if (!text)
	{
		return text;
	}

	if (Victual.Mode === "dev")
	{
		var text2 = text;
		if (Victual.LocalizationStrings && !Victual.LocalizationStrings.messages[""].hasOwnProperty(text2))
		{
			Victual.Api.Post('system/log-missing-localization', { "text": text2 },
				function (result)
				{
					// Nothing to do...
				},
				function ()
				{
					// Deliberately silent: this call is made from inside __t()/__n(), and the
					// default error handler renders a toast whose own text goes back through
					// __t() - so a failing localization log could re-enter localization and
					// recurse. Plan 12, Q2.
				}
			);
		}
	}

	// sprintf can fail due to invalid placeholders
	try
	{
		return sprintf(Victual.Translator.__(text, ...placeholderValues), ...placeholderValues);
	} catch (e)
	{
		return Victual.Translator.__(text, ...placeholderValues);
	}
}
/**
 * Translates a singular/plural text based on the given number
 * (the number itself is provided to the "%s"/"%d" placeholder, locale formatted).
 * @param {number} number Amount deciding the plural form (absolute value is used)
 * @param {string} singularForm Source singular text
 * @param {string} pluralForm Source plural text (defaults to singularForm)
 * @param {boolean} [isQu=false] Use the quantity unit translator (TranslatorQu) instead of the general one
 * @returns {string} Translated text with the number filled in
 */
__n = function (number, singularForm, pluralForm, isQu = false)
{
	if (!singularForm)
	{
		return singularForm;
	}

	if (Victual.Mode === "dev")
	{
		var singularForm2 = singularForm;
		if (Victual.LocalizationStrings && !Victual.LocalizationStrings.messages[""].hasOwnProperty(singularForm2))
		{
			Victual.Api.Post('system/log-missing-localization', { "text": singularForm2 },
				function (result)
				{
					// Nothing to do...
				},
				function ()
				{
					// Deliberately silent: this call is made from inside __t()/__n(), and the
					// default error handler renders a toast whose own text goes back through
					// __t() - so a failing localization log could re-enter localization and
					// recurse. Plan 12, Q2.
				}
			);
		}
	}

	if (!pluralForm)
	{
		pluralForm = singularForm;
	}

	number = Math.abs(number);

	if (isQu)
	{
		return sprintf(Victual.TranslatorQu.n__(singularForm, pluralForm, number, number), number.toLocaleString());
	}
	else
	{
		return sprintf(Victual.Translator.n__(singularForm, pluralForm, number, number), number.toLocaleString());
	}
}

/**
 * Renders all <time class="timeago" datetime="..."> elements below rootSelector
 * as relative time ("x days ago" via moment.fromNow()), "Today", or the special
 * labels "Never"/"Unknown" for the sentinel dates 2999-12-31 / 2888-12-31;
 * for elements with class "timeago-date-only" the preceding element's text is
 * truncated to the date part (first 10 characters, YYYY-MM-DD).
 * @param {string} [rootSelector="#page-content"] Selector to limit the processed subtree
 */
RefreshContextualTimeago = function (rootSelector = "#page-content")
{
	$(rootSelector + " time.timeago").each(function ()
	{
		var element = $(this);

		if (!element.hasAttr("datetime"))
		{
			element.text("");
			return;
		}

		var timestamp = element.attr("datetime");

		if (!timestamp || timestamp.length < 10)
		{
			element.text("");
			return;
		}

		if (!moment(timestamp).isValid())
		{
			element.text("");
			return;
		}

		var isNever = timestamp && timestamp.substring(0, 10) == "2999-12-31";
		var isUnknown = timestamp && timestamp.substring(0, 10) == "2888-12-31";
		var isToday = timestamp && timestamp.substring(0, 10) == moment().format("YYYY-MM-DD");
		var isDateWithoutTime = element.hasClass("timeago-date-only");

		if (isNever)
		{
			element.prev().text(__t("Never"));
			element.text("");
		}
		else if (isUnknown)
		{
			element.prev().text(__t("Unknown"));
			element.text("");
		}
		else if (isToday)
		{
			element.text(__t("Today"));
		}
		else
		{
			element.text(moment(timestamp).fromNow());
		}

		if (isDateWithoutTime)
		{
			element.prev().text(element.prev().text().substring(0, 10));
		}
	});
}
RefreshContextualTimeago();

// Global defaults for toastr notifications (used for all success/error toasts)
toastr.options = {
	toastClass: 'alert',
	closeButton: true,
	timeOut: 20000,
	extendedTimeOut: 5000
};

Victual.FrontendHelpers = {};
Victual.FrontendHelpers.ValidateForm = function (formId, reportValidity = false)
{
	var form = document.getElementById(formId);
	if (form === null || form === undefined)
	{
		return;
	}

	$(form).addClass('was-validated');

	if (reportValidity)
	{
		form.reportValidity();
	}

	return form.checkValidity();
}

Victual.FrontendHelpers.BeginUiBusy = function (formId = null)
{
	$("body").addClass("cursor-busy");

	if (formId !== null)
	{
		$("#" + formId + " :input").attr("disabled", true);
	}
}

Victual.FrontendHelpers.EndUiBusy = function (formId = null)
{
	$("body").removeClass("cursor-busy");

	if (formId !== null)
	{
		$("#" + formId + " :input").attr("disabled", false);
	}
}

Victual.FrontendHelpers.ShowGenericError = function (message, exception)
{
	toastr.error(__t(message) + '<br><br>' + __t('Click to show technical details'), '', {
		onclick: function ()
		{
			var errorDetails = JSON.stringify(exception, null, 4);
			if (typeof exception === "object" && exception !== null && exception.hasOwnProperty("error_message"))
			{
				errorDetails = exception.error_message;
			}

			// The details are a server error message, which is not markup and is not
			// trusted: a uniqueness violation on PostgreSQL quotes the offending value
			// back ("Key (name)=(...) already exists"), so a stored payload reaches this
			// bootbox message, and bootbox renders its message with .html()
			// (sweep finding S29).
			bootbox.alert({
				title: __t('Error details'),
				message: '<p class="text-monospace my-0">' + Victual.FrontendHelpers.EscapeHtml(errorDetails) + '</p>',
				closeButton: false,
				className: "wider"
			});
		}
	});

	console.error(exception);
}

/**
 * Reports a failed API call, preferring the server's own message to a generic one when the
 * server meant the message to be read.
 *
 * ShowGenericError always says the same sentence and hides the server's words behind "Click
 * to show technical details". That is right for a failure nobody planned - a 500, a dropped
 * connection - where the message is a diagnostic and the sentence is the answer. It is wrong
 * for a refusal the endpoint raised on purpose: "Location has child locations" IS the answer,
 * and burying it makes a refusal look like a fault.
 *
 * The line between the two is the status. 4xx is "your request was refused, here is why" and
 * its message is shown; everything else - 5xx, a transport failure, a response with no
 * readable message - goes to ShowGenericError exactly as before, technical-details dialog
 * included. That dialog is a rendering sink the S29 probe drives through this path, and this
 * split is what keeps it reachable.
 *
 * @param {string} fallbackMessage Localization key used for anything that is not a 4xx refusal
 * @param {Object} xhr The XMLHttpRequest (or failure descriptor) the error callback was given
 */
Victual.FrontendHelpers.ShowApiError = function (fallbackMessage, xhr)
{
	var response = xhr && xhr.response ? xhr.response : xhr;
	var status = xhr && xhr.status ? xhr.status : 0;
	var message = null;

	if (status >= 400 && status < 500)
	{
		try
		{
			var parsed = typeof response === 'string' ? JSON.parse(response) : response;

			if (parsed && typeof parsed.error_message === 'string' && parsed.error_message !== '')
			{
				message = parsed.error_message;
			}
		}
		catch (parseError)
		{
			message = null;
		}
	}

	if (message === null)
	{
		Victual.FrontendHelpers.ShowGenericError(fallbackMessage, response);
		return;
	}

	// toastr ships escapeHtml: false and this string came off the wire, so it is escaped here
	// rather than trusted (sweep finding S29). __t() leaves an untranslated message as it is,
	// so a server message with no catalogue entry still reads correctly.
	toastr.error(Victual.FrontendHelpers.EscapeHtml(__t(message)));

	console.error(response);
}

Victual.FrontendHelpers.SaveUserSetting = function (settingsKey, value, force = false)
{
	if (Victual.UserSettings[settingsKey] == value && !force)
	{
		return;
	}

	Victual.UserSettings[settingsKey] = value;

	jsonData = {};
	jsonData.value = value;
	Victual.Api.Put('user/settings/' + settingsKey, jsonData,
		function (result)
		{
			// Nothing to do...
		},
		function (xhr)
		{
			console.error(xhr);
		}
	);
}

Victual.FrontendHelpers.DeleteUserSetting = function (settingsKey, reloadPageOnSuccess = false)
{
	delete Victual.UserSettings[settingsKey];

	Victual.Api.Delete('user/settings/' + settingsKey, {},
		function (result)
		{
			if (reloadPageOnSuccess)
			{
				location.reload();
			}
		},
		function (xhr)
		{
			if (xhr.statusText)
			{
				Victual.FrontendHelpers.ShowGenericError('Error while deleting, please retry', xhr.response)
			}
		}
	);
}

Victual.FrontendHelpers.RunWebhook = function (webhook, data, repetitions = 1)
{
	Object.assign(data, webhook.extra_data);
	var hasAlreadyFailed = false;

	for (i = 0; i < repetitions; i++)
	{
		if (webhook.json)
		{
			$.ajax(webhook.hook, { "data": JSON.stringify(data), "contentType": "application/json", "type": "POST" }).fail(function (req, status, errorThrown)
			{
				if (!hasAlreadyFailed)
				{
					hasAlreadyFailed = true;
					Victual.FrontendHelpers.ShowGenericError(__t("Error while executing WebHook", { "status": status, "errorThrown": errorThrown }));
				}
			});
		}
		else
		{
			$.post(webhook.hook, data).fail(function (req, status, errorThrown)
			{
				if (!hasAlreadyFailed)
				{
					hasAlreadyFailed = true;
					Victual.FrontendHelpers.ShowGenericError(__t("Error while executing WebHook", { "status": status, "errorThrown": errorThrown }));
				}
			});
		}
	}
}

$(document).on("keyup paste change click", "input, select, textarea", function ()
{
	$(this).addClass("is-dirty").closest("form").addClass("is-dirty");
});

// Auto saving user setting controls
$(document).on("change", ".user-setting-control", function ()
{
	var element = $(this);
	var settingKey = element.attr("data-setting-key");

	if (!element[0].checkValidity())
	{
		return;
	}

	var inputType = "unknown";
	if (typeof element.attr("type") !== typeof undefined && element.attr("type") !== false)
	{
		inputType = element.attr("type").toLowerCase();
	}

	if (inputType === "checkbox")
	{
		value = element.is(":checked");
	}
	else
	{
		var value = element.val();
	}

	Victual.FrontendHelpers.SaveUserSetting(settingKey, value);
});

// Show file name Bootstrap custom file input
$('input.custom-file-input').on('change', function ()
{
	// .text(), not .html(): the file name is chosen by the user and would otherwise be markup
	$(this).next('.custom-file-label').text(GetFileNameFromPath($(this).val()));
});

// Translation of "Browse"-button of Bootstrap custom file input
if ($(".custom-file-label").length > 0)
{
	$("<style>").html('.custom-file-label::after { content: "' + __t("Select file") + '"; }').appendTo("head");
}

ResizeResponsiveEmbeds = function ()
{
	$("iframe.embed-responsive").each(function ()
	{
		var iframeBody = $(this)[0].contentWindow.document.body;
		if (iframeBody)
		{
			$(this).attr("height", iframeBody.scrollHeight.toString() + "px");
		}

		if ($("body").hasClass("fullscreen-card"))
		{
			$(this).attr("height", $("body").height().toString() + "px");
		}
	});

	var maxHeight = $("body").height() - $("#mainNav").outerHeight() - 62;
	if ($("body").hasClass("fullscreen-card"))
	{
		maxHeight = $("body").height();
	}
	$("embed.embed-responsive:not(.resize-done)").attr("height", maxHeight.toString() + "px").addClass("resize-done");
}
$(window).on("resize", function ()
{
	ResizeResponsiveEmbeds();
});
$("iframe").on("load", function ()
{
	ResizeResponsiveEmbeds();
});
$(document).on("shown.bs.modal", function (e)
{
	ResizeResponsiveEmbeds();
});
$(document).on("hidden.bs.modal", function (e)
{
	$("body").removeClass("fullscreen-card");
});
$("body").children().each(function (index, child)
{
	new ResizeObserver(function ()
	{
		window.parent.postMessage(WindowMessageBag("ResizeResponsiveEmbeds"), Victual.BaseUrl);
	}).observe(child);
});

function WindowMessageBag(message, payload = null)
{
	var obj = {};
	obj.Message = message;
	obj.Payload = payload;
	return obj;
}

// Add border around anchor link section
if (window.location.hash)
{
	$(window.location.hash).addClass("p-2 border border-info rounded");
}

function RefreshLocaleNumberDisplay(rootSelector = "#page-content")
{
	$(rootSelector + " .locale-number.locale-number-currency:not('.number-parsing-done')").each(function ()
	{
		var element = $(this);
		var text = element.text();
		if (!text || Number.isNaN(text))
		{
			return;
		}

		var value = Number.parseFloat(text);
		element.text(value.toLocaleString(undefined, { style: "currency", currency: Victual.Currency, minimumFractionDigits: Victual.UserSettings.stock_decimal_places_prices_display, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_prices_display }));
		element.addClass("number-parsing-done");
	});

	$(rootSelector + " .locale-number.locale-number-quantity-amount:not('.number-parsing-done')").each(function ()
	{
		var element = $(this);
		var text = element.text();
		if (!text || Number.isNaN(text))
		{
			return;
		}

		var value = Number.parseFloat(text);
		element.text(value.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_amounts }));
		element.addClass("number-parsing-done");
	});

	$(rootSelector + " .locale-number.locale-number-generic:not('.number-parsing-done')").each(function ()
	{
		var element = $(this);
		var text = element.text();
		if (!text || Number.isNaN(text))
		{
			return;
		}

		var value = Number.parseFloat(text);
		element.text(value.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 }));
		element.addClass("number-parsing-done");
	});
}
RefreshLocaleNumberDisplay();
$(".locale-number").each(function ()
{
	new MutationObserver(function (mutations)
	{
		mutations.forEach(mutation =>
		{
			if (mutation.type == "childList" || mutation.type == "attributes")
			{
				$(mutation.target).removeClass("number-parsing-done");
			}
		});
	}).observe(this, {
		attributes: true,
		childList: true,
		subtree: true
	});
});

function RefreshLocaleNumberInput(rootSelector = "#page-content")
{
	$(rootSelector + " .locale-number-input.locale-number-currency").each(function ()
	{
		var element = $(this);
		var value = element.val();
		if (!value || Number.isNaN(value))
		{
			return;
		}

		element.val(Number.parseFloat(value).toLocaleString("en", { minimumFractionDigits: Victual.UserSettings.stock_decimal_places_prices_input, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_prices_input, useGrouping: false }));
	});

	$(rootSelector + " .locale-number-input.locale-number-quantity-amount").each(function ()
	{
		var element = $(this);
		var value = element.val();
		if (!value || Number.isNaN(value))
		{
			return;
		}

		element.val(Number.parseFloat(value).toLocaleString("en", { minimumFractionDigits: 0, maximumFractionDigits: Victual.UserSettings.stock_decimal_places_amounts, useGrouping: false }));
	});

	$(rootSelector + " .locale-number-input.locale-number-generic").each(function ()
	{
		var element = $(this);
		var value = element.val();
		if (!value || Number.isNaN(value))
		{
			return;
		}

		element.val(value.toLocaleString("en", { minimumFractionDigits: 0, maximumFractionDigits: 2, useGrouping: false }));
	});
}
RefreshLocaleNumberInput();

$(document).on("click", ".easy-link-copy-textbox", function ()
{
	$(this).select();
});

if (Victual.CalendarFirstDayOfWeek)
{
	moment.updateLocale(moment.locale(), {
		"week": {
			"dow": Number.parseInt(Victual.CalendarFirstDayOfWeek)
		}
	});
}

if (GetUriParam("embedded"))
{
	$("body").append('<div class="fixed-top" style="left: unset;"> \
		<button class="btn btn-light btn-sm close-last-modal-button" \
			type="button" \> \
			<i class="fa-solid fa-xmark"></i> \
		</button> \
	</div>');
}

$(document).on("click", ".close-last-modal-button", function ()
{
	window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
});

$("body").on("keydown", function (e)
{
	if (e.key == "Escape")
	{
		window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
	}
});


$(window).on("message", function (e)
{
	var data = e.originalEvent.data;

	if (data.Message == "ShowSuccessMessage")
	{
		toastr.success(data.Payload);
	}
	else if (data.Message == "CloseLastModal")
	{
		$(".modal:visible").not(".custom-escape-key-handling").last().modal("hide");
	}
	else if (data.Message == "ResizeResponsiveEmbeds")
	{
		ResizeResponsiveEmbeds();
	}
	else if (data.Message == "IframeModal")
	{
		IframeModal(data.Payload.Link, data.Payload.DialogType);
	}
	else if (data.Message == "Reload")
	{
		window.location.reload();
	}
	else if (data.Message == "BroadcastMessage")
	{
		// data.Payload is the original WindowMessageBag

		// => Send the original message to this window
		window.postMessage(data.Payload, Victual.BaseUrl);

		// => Bubble the broadcast message down to all child iframes
		$("iframe.embed-responsive").each(function ()
		{
			$(this)[0].contentWindow.postMessage(data, Victual.BaseUrl);
		});
	}
});

window.IsVictual = true;
Victual.GetTopmostWindow = function ()
{
	if (window.top.IsVictual)
	{
		// If the top window is Victual (so when we're currently not running in an iframe) return that immediately
		return window.top;
	}
	else
	{
		// Otherwise, so when we're currently running in an iframe, climb up the window chain and check for the top most Victual window
		var topmostVictualWindow = window;

		var currentWindow = window;
		while (currentWindow != window.top)
		{
			if (currentWindow.IsVictual)
			{
				topmostVictualWindow = currentWindow;
			}

			currentWindow = currentWindow.parent;
		}

		return topmostVictualWindow;
	}
}

$(document).on("click", ".show-as-dialog-link", function (e)
{
	e.preventDefault();

	var element = $(e.currentTarget);
	var link = element.attr("href");

	var dialogType = "form";
	if (element.hasAttr("data-dialog-type"))
	{
		dialogType = element.attr("data-dialog-type")
	}

	if (Victual.GetTopmostWindow() != window.self)
	{
		Victual.GetTopmostWindow().postMessage(WindowMessageBag("IframeModal", { "Link": link, "DialogType": dialogType }), Victual.BaseUrl);
	}
	else
	{
		IframeModal(link, dialogType);
	}
});

function IframeModal(link, dialogClass = "form")
{
	bootbox.dialog({
		message: '<iframe class="embed-responsive" src="' + link + '"></iframe>',
		size: 'large',
		backdrop: true,
		closeButton: false,
		className: dialogClass
	});
}

// Init Bootstrap tooltips
$('[data-toggle="tooltip"]').tooltip();

// serializeJSON defaults
$.serializeJSON.defaultOptions.checkboxUncheckedValue = "0";

// bootstrap-combobox defaults
BootstrapComboboxDefaults = {
	"appendId": "_text_input",
	"iconCaret": "fa-solid fa-caret-down",
	"iconRemove": "fa-solid fa-xmark",
	"matcher": function (item)
	{
		return ~item.accentNeutralise().toLowerCase().indexOf(this.query.accentNeutralise().toLowerCase());
	}
};

$(Victual.UserPermissions).each(function (index, item)
{
	if (item.has_permission == 0)
	{
		$('.permission-' + item.permission_name).addClass('disabled').addClass('not-allowed');
	}
});

$('a.link-return').not(".btn").each(function ()
{
	var base = $(this).data('href');
	if (base.contains('?'))
	{
		$(this).attr('href', base + '&returnto' + encodeURIComponent(Victual.CurrentUrlRelative));
	}
	else
	{
		$(this).attr('href', base + '?returnto=' + encodeURIComponent(Victual.CurrentUrlRelative));
	}

});
$(document).on("click", "a.btn.link-return", function (e)
{
	e.preventDefault();

	var link = GetUriParam("returnto");
	if (!link || !link.length > 0)
	{
		location.href = $(e.currentTarget).attr("href");
	}
	else
	{
		location.href = U(link);
	}
});

$('.dropdown-item').has('.form-check input[type=checkbox]').on('click', function (e)
{
	if ($(e.target).is('div.form-check') || $(e.target).is('div.dropdown-item'))
	{
		$(e.target).find('input[type=checkbox]').click();
	}
});

$('[data-toggle="tooltip"][data-html="true"]').on("shown.bs.tooltip", function ()
{
	RefreshLocaleNumberDisplay(".tooltip");
})

$(document).on("click", '.btn, a, button', function (e)
{
	// Remove focus and hide any tooltips after click
	document.activeElement.blur();
	$(".tooltip").tooltip("hide");
});

// Delay only initial field focus
Victual.FormFocusDelay = 500;
setTimeout(function ()
{
	Victual.FormFocusDelay = 0;
}, 1000);
