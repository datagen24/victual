// Powers the user create/edit form (userform.blade.php). Victual.EditMode ('create'/'edit')
// and Victual.EditObjectId select POST vs PUT. Handles the optional profile picture upload/
// removal and the password change checkbox in addition to the regular form fields.

/**
 * Second step of saving a user: after the user object itself was created/updated, saves
 * userfields and then either uploads the newly chosen picture file (if any, and it wasn't
 * also flagged for deletion) or just redirects to the users list.
 * @param {object} result - API response from the create/update call (used for created_object_id)
 * @param {object} jsonData - the serialized form data (used for picture_file_name)
 */
function SaveUserPicture(result, jsonData)
{
	var userId = Victual.EditObjectId || result.created_object_id;
	Victual.Components.UserfieldsForm.Save(() =>
	{
		if (jsonData.hasOwnProperty("picture_file_name") && !Victual.DeleteUserPictureOnSave)
		{
			Victual.Api.UploadFile($("#user-picture")[0].files[0], 'userpictures', jsonData.picture_file_name,
				(result) =>
				{
					window.location.href = U('/users');
				},
				(xhr) =>
				{
					Victual.FrontendHelpers.EndUiBusy("user-form");
					Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
				}
			);
		}
		else
		{
			window.location.href = U('/users');
		}
	});
}

// Validates and submits the form: generates a randomized/sanitized file name for a newly
// chosen picture, base64-encodes the password (password_base64, as the API expects) and
// drops the raw password/confirm/change_password fields, then creates or updates the user
// and hands off to SaveUserPicture() for the picture upload and final redirect
$('#save-user-button').on('click', function (e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("user-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#user-form').serializeJSON();
	Victual.FrontendHelpers.BeginUiBusy("user-form");

	if ($("#user-picture")[0].files.length > 0)
	{
		jsonData.picture_file_name = RandomString() + CleanFileName($("#user-picture")[0].files[0].name);
	}

	// #change_password exists only in edit mode with an authentication backend that has a
	// password to change at all (userform.blade.php); when it does and is unticked, the
	// password inputs are disabled and serializeJSON() - like a real form submit - omits a
	// disabled field entirely, so jsonData.password is undefined here. Encoding it
	// regardless used to send btoa(undefined) === "dW5kZWZpbmVk", the base64 of the literal
	// string "undefined" - answered as a real new password by the API, so an admin's edit
	// of someone else's profile with the box left unticked silently set that account's
	// password to the word "undefined" (issue #549). Create mode and the externally-managed/
	// embedded/disabled-auth branch have no such checkbox at all - .length is 0 - and must
	// keep encoding unconditionally: a new account needs the password it was given, and
	// those other modes render a hidden password field that always carries a value.
	var changePasswordCheckbox = $("#change_password");

	if (changePasswordCheckbox.length === 0 || changePasswordCheckbox.prop("checked"))
	{
		jsonData.password_base64 = btoa(jsonData.password);
	}

	delete jsonData.password;
	delete jsonData.password_confirm;
	delete jsonData.change_password;

	// Only present when editing your own account, and only filled in when the password is
	// actually being changed - the API requires it in exactly that case (sweep finding S6).
	// Already safe against the same bug: current_password is disabled (and so omitted by
	// serializeJSON()) exactly when password is, and the truthy check below additionally
	// refuses to encode a present-but-empty value - there is no path here that can send
	// btoa(undefined) or btoa("") disguised as a real current password (issue #549 review).
	if (jsonData.hasOwnProperty("current_password"))
	{
		if (jsonData.current_password)
		{
			jsonData.current_password_base64 = btoa(jsonData.current_password);
		}

		delete jsonData.current_password;
	}

	if (Victual.EditMode === 'create')
	{
		Victual.Api.Post('users', jsonData,
			(result) => SaveUserPicture(result, jsonData),
			function (xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("user-form");
				Victual.Api.DefaultErrorHandler(xhr);
			}
		);
	}
	else
	{
		// If the existing picture was flagged for removal, delete the old file server-side
		if (Victual.DeleteUserPictureOnSave)
		{
			jsonData.picture_file_name = null;

			Victual.Api.DeleteFile(Victual.UserPictureFileName, 'userpictures',
				function (result)
				{
					// Nothing to do
				},
				function (xhr)
				{
					Victual.FrontendHelpers.EndUiBusy("user-form");
					Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
				}
			);
		}

		Victual.Api.Put('users/' + Victual.EditObjectId, jsonData,
			(result) => SaveUserPicture(result, jsonData),
			function (xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("user-form");
				Victual.Api.DefaultErrorHandler(xhr);
			}
		);
	}
});

// Live-validates on every keystroke, plus a custom-validity check that the password
// confirmation matches the password
$('#user-form input').keyup(function (event)
{
	var element = document.getElementById("password_confirm");
	if ($("#password").val() !== $("#password_confirm").val())
	{
		element.setCustomValidity("error");
	}
	else
	{
		element.setCustomValidity("");
	}

	Victual.FrontendHelpers.ValidateForm('user-form');
});

// Enter key submits the form (if valid) instead of doing a default form submit
$('#user-form input').keydown(function (event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('user-form'))
		{
			return false;
		}
		else
		{
			$('#save-user-button').click();
		}
	}
});

// A newly chosen picture file replaces the "current picture" preview with the
// file-chosen label and cancels a pending "delete current picture" flag, so choosing a
// new file after clicking delete keeps the newly uploaded picture.
$("#user-picture").on("change", function (e)
{
	$("#user-picture-label").removeClass("d-none");
	$("#user-picture-label-none").addClass("d-none");
	$("#delete-current-user-picture-on-save-hint").addClass("d-none");
	$("#current-user-picture").addClass("d-none");
	Victual.DeleteUserPictureOnSave = false;
});

Victual.DeleteUserPictureOnSave = false;
// Flags the existing picture for deletion on save (actual deletion happens in the submit
// handler above)
$("#delete-current-user-picture-button").on("click", function (e)
{
	Victual.DeleteUserPictureOnSave = true;
	$("#current-user-picture").addClass("d-none");
	$("#delete-current-user-picture-on-save-hint").removeClass("d-none");
	$("#user-picture-label").addClass("d-none");
	$("#user-picture-label-none").removeClass("d-none");
});

// Enables the password fields only once "change password" is checked
$("#change_password").click(function ()
{
	$("#password").attr("disabled", !this.checked);
	$("#password_confirm").attr("disabled", !this.checked);
	$("#current_password").attr("disabled", !this.checked);

	setTimeout(function ()
	{
		$("#password").focus();
	}, Victual.FormFocusDelay);
});

// If opened with the "changepw" URI param, immediately reveal the password fields;
// otherwise focus the username field
if (GetUriParam("changepw") === "true")
{
	$("#change_password").click();
}
else
{
	setTimeout(function ()
	{
		$('#username').focus();
	}, Victual.FormFocusDelay);
}

// Initial setup: load userfields, run initial validation
Victual.Components.UserfieldsForm.Load();
Victual.FrontendHelpers.ValidateForm('user-form');
