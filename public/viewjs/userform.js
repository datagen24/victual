// Powers the user create/edit form (userform.blade.php). Victual.EditMode ('create'/'edit')
// and Victual.EditObjectId select POST vs PUT. Handles the optional profile picture upload/
// removal and the password change checkbox in addition to the regular form fields.

// Set once the user exists but the roles step failed. From then on the form is terminal:
// the save handler returns early, so neither the button nor the Enter key can POST the
// same username again.
var UserCreatedRolesFailed = false;

/**
 * Terminal state after the roles step failed: the user already exists, so the form says
 * exactly that - in an alert region that is announced and focused - names a failed
 * picture upload when there was one, and offers only a link onward (the user's permissions
 * page, or the users list when the new user's id is unknown). Every input is disabled.
 * @param {number|null} userId - id of the user that was created, or null when it could not be looked up
 * @param {boolean} pictureFailed - whether the picture upload also failed
 */
function ShowUserRolesNotSet(userId, pictureFailed)
{
	Victual.FrontendHelpers.EndUiBusy("user-form");
	$("#user-form :input").prop("disabled", true);
	var link = $("#user-roles-error-link");
	link.attr("href", userId ? U('/user/' + userId + '/permissions') : U('/users'));
	link.text(link.attr(userId ? "data-text-permissions" : "data-text-list"));
	$("#user-picture-error").toggleClass("d-none", !pictureFailed);
	$("#user-roles-error").removeClass("d-none").trigger("focus");
}

/**
 * Second step of saving a user: after the user object itself was created/updated, saves
 * userfields and then either uploads the newly chosen picture file (if any, and it wasn't
 * also flagged for deletion) or just redirects to the users list. When the roles step
 * failed (rolesFailedFor given) the userfields and picture are still saved, but the end of
 * the flow shows the terminal alert instead of redirecting.
 * @param {object} result - API response from the create/update call (used for created_object_id)
 * @param {object} jsonData - the serialized form data (used for picture_file_name)
 * @param {{userId: (number|null)}} [rolesFailedFor] - present when the roles step failed
 */
function SaveUserPicture(result, jsonData, rolesFailedFor)
{
	var userId = Victual.EditObjectId || result.created_object_id;
	Victual.Components.UserfieldsForm.Save(() =>
	{
		if (jsonData.hasOwnProperty("picture_file_name") && !Victual.DeleteUserPictureOnSave)
		{
			Victual.Api.UploadFile($("#user-picture")[0].files[0], 'userpictures', jsonData.picture_file_name,
				(result) =>
				{
					if (rolesFailedFor)
					{
						ShowUserRolesNotSet(rolesFailedFor.userId, false);
						return;
					}

					window.location.href = U('/users');
				},
				(xhr) =>
				{
					if (rolesFailedFor)
					{
						ShowUserRolesNotSet(rolesFailedFor.userId, true);
						return;
					}

					Victual.FrontendHelpers.EndUiBusy("user-form");
					Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
				}
			);
		}
		else if (rolesFailedFor)
		{
			ShowUserRolesNotSet(rolesFailedFor.userId, false);
		}
		else
		{
			window.location.href = U('/users');
		}
	});
}

/**
 * Create mode, roles step failed: locks the form against a second POST, then still runs
 * the userfields and picture steps (when the new user's id is known) so only the roles are
 * reported as not set.
 * @param {object} jsonData - the serialized form data, passed through to SaveUserPicture()
 * @param {number|null} userId - id of the created user, or null when it could not be looked up
 */
function RolesStepFailed(jsonData, userId)
{
	UserCreatedRolesFailed = true;
	if (userId)
	{
		SaveUserPicture({ created_object_id: userId }, jsonData, { userId: userId });
	}
	else
	{
		ShowUserRolesNotSet(null, false);
	}
}

/**
 * Create mode, step two: when the form offers the Roles picker (the caller may set roles),
 * replaces the roles CreateUser assigned with the chosen ones via PUT /users/{id}/roles,
 * then carries on with SaveUserPicture(). POST /users answers 204 with no body, so the new
 * user's id is looked up by its (unique) username in the unfiltered user list: the query
 * filter's value pattern drops some characters a username may contain, so an exact match
 * is done here instead.
 * @param {object} result - API response from the create call (empty: 204)
 * @param {object} jsonData - the serialized form data (username is used for the lookup), passed through to SaveUserPicture()
 */
function SaveUserRoles(result, jsonData)
{
	var rolesSelect = $("#user-roles");
	if (rolesSelect.length === 0)
	{
		SaveUserPicture(result, jsonData);
		return;
	}

	var roleIds = (rolesSelect.val() || []).map(Number);
	Victual.Api.Get('users',
		function (users)
		{
			var created = (users || []).find((user) => user.username === jsonData.username);
			if (!created)
			{
				RolesStepFailed(jsonData, null);
				return;
			}

			Victual.Api.Put('users/' + created.id + '/roles', { roles: roleIds },
				() => SaveUserPicture({ created_object_id: created.id }, jsonData),
				() => RolesStepFailed(jsonData, created.id)
			);
		},
		() => RolesStepFailed(jsonData, null)
	);
}

// Validates and submits the form: generates a randomized/sanitized file name for a newly
// chosen picture, base64-encodes the password (password_base64, as the API expects) and
// drops the raw password/confirm/change_password fields, then creates or updates the user
// and hands off to SaveUserPicture() for the picture upload and final redirect
$('#save-user-button').on('click', function (e)
{
	e.preventDefault();

	if (UserCreatedRolesFailed)
	{
		return;
	}

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

	// jsonData.password can be missing for two different reasons, and encoding it
	// regardless used to send btoa(undefined) === "dW5kZWZpbmVk" - the base64 of the
	// literal string "undefined" - answered as a real new password by the API (issue
	// #549, in three different modes across rounds 4, 5, 6 and 8):
	//
	// 1. #change_password (edit mode, a backend with a local password a user can change
	//    at all) exists and is unticked. The password inputs are then disabled, and
	//    serializeJSON() - like a real form submit - omits a disabled field entirely. An
	//    admin's edit of someone else's profile with the box left unticked silently set
	//    that account's password to the word "undefined" (round 4).
	//
	// 2. There is no local password to change at all - externally managed (reverse-proxy)
	//    authentication, an embedded install, or authentication disabled - so
	//    userform.blade.php renders no password field and no checkbox in edit mode, and,
	//    as of round 8, none in create mode either: CreateUser() itself now accepts a
	//    missing password under all three of those and stores an unusable one instead
	//    (UsersApiController::CreatedUserPassword()). Checking only the checkbox's
	//    absence - "no checkbox means always encode" - was true for create mode's own
	//    missing checkbox back when every other checkbox-less mode still rendered a
	//    hidden field with a real value; it stopped being true the moment create mode
	//    could be checkbox-less too, and sent the same bogus password there instead
	//    (round 5 for reverse-proxy, round 8 for embedded installs and disabled auth -
	//    issue #554).
	//
	// So neither the checkbox's state nor its mere absence is sufficient on its own:
	// whether the field was serialized at all is what actually decides it.
	var changePasswordCheckbox = $("#change_password");
	var passwordWasSerialized = jsonData.hasOwnProperty("password");

	if (passwordWasSerialized && (changePasswordCheckbox.length === 0 || changePasswordCheckbox.prop("checked")))
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
			(result) => SaveUserRoles(result, jsonData),
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
