@extends('layout.default')

@if($mode == 'edit')
@section('title', $__t('Edit user'))
@else
@section('title', $__t('Create user'))
@endif

@section('content')
<div class="row">
	<div class="col">
		<h2 class="title">@yield('title')</h2>
	</div>
</div>

<hr class="my-2">

<div class="row">
	<div class="col-lg-6 col-12">
		<script>
			Victual.EditMode = '{{ $mode }}';
		</script>

		@if($mode == 'edit')
		<script>
			Victual.EditObjectId = {{ $user->id }};

			@if(!empty($user->picture_file_name))
			Victual.UserPictureFileName = '{{ $user->picture_file_name }}';
			@endif
		</script>
		@endif

		<form id="user-form"
			novalidate>

			<div class="form-group">
				<label for="username">{{ $__t('Username') }}</label>
				<input type="text"
					class="form-control"
					required
					id="username"
					name="username"
					value="@if($mode == 'edit'){{ $user->username }}@endif">
				<div class="invalid-feedback">{{ $__t('A username is required') }}</div>
			</div>

			<div class="form-group">
				<label for="first_name">{{ $__t('First name') }}</label>
				<input type="text"
					class="form-control"
					id="first_name"
					name="first_name"
					value="@if($mode == 'edit'){{ $user->first_name }}@endif">
			</div>

			<div class="form-group">
				<label for="last_name">{{ $__t('Last name') }}</label>
				<input type="text"
					class="form-control"
					id="last_name"
					name="last_name"
					value="@if($mode == 'edit'){{ $user->last_name }}@endif">
			</div>

			@if(!VICTUAL_IS_EMBEDDED_INSTALL && !VICTUAL_DISABLE_AUTH)
			@if(!defined('VICTUAL_EXTERNALLY_MANAGED_AUTHENTICATION'))
			@if($mode == 'edit')
			<div class="form-group mb-1">
				<div class="custom-control custom-checkbox">
					<input class="form-check-input custom-control-input"
						type="checkbox"
						id="change_password"
						name="change_password"
						value="1">
					<label class="form-check-label custom-control-label"
						for="change_password">{{ $__t('Change password') }}
					</label>
				</div>
			</div>
			@endif

			@if($mode == 'edit' && $user->id == VICTUAL_USER_ID)
			{{-- Sweep finding S6: changing your own password needs the old one, so that a
			     borrowed session cannot lock the account's owner out of it permanently. --}}
			<div class="form-group">
				<label for="current_password">{{ $__t('Current password') }}</label>
				<input type="password"
					class="form-control"
					required
					id="current_password"
					name="current_password"
					disabled>
				<small class="form-text text-muted">{{ $__t('Your current password is required to set a new one') }}</small>
			</div>
			@endif

			<div class="form-group">
				<label for="password">{{ $__t('Password') }}</label>
				<input type="password"
					class="form-control"
					required
					id="password"
					name="password"
					@if($mode=='edit'
					)
					disabled
					@endif>
			</div>

			<div class="form-group">
				<label for="password_confirm">{{ $__t('Confirm password') }}</label>
				<input type="password"
					class="form-control"
					required
					id="password_confirm"
					name="password_confirm"
					@if($mode=='edit'
					)
					disabled
					@endif>
				<div class="invalid-feedback">{{ $__t('Passwords do not match') }}</div>
			</div>
			@endif
			{{-- Externally managed (reverse-proxy) authentication renders no password
			     field at all here, in either mode - not even a hidden one. A fixed
			     placeholder used to stand in for create mode (round 5), but that gave
			     every account created through this form the same real, guessable
			     password ("x"), dormant only until the deployment's backend ever
			     switches away from reverse-proxy auth (issue #549's own class of
			     defect, round 6). CreateUser() itself now accepts a missing password
			     under this constant and stores unusable random bytes instead
			     (UsersApiController::CreateUser()) - ReverseProxyAuthenticator never
			     checks a local password on login either way. Edit mode keeps deliberately
			     getting no such field for the separate reason already covered in
			     userform.js: EditUser() revokes every other session of the account
			     whenever a password field is present at all (issue #513). --}}
			@else
			{{-- Embedded installs and instances with authentication disabled both fall
			     under DISABLE_AUTH's single-identity bypass (BaseAuthMiddleware): every
			     caller becomes the same default user with no credential at all, so there
			     is no local password to change here either, in either mode - the same
			     situation externally managed authentication is in, just above. A fixed
			     placeholder ("x") used to stand in for both modes, which was issue #549's
			     own class of defect: every account created through this form got the same
			     real, guessable password, dormant only until authentication was ever
			     turned back on. Fixed the same way round 6 fixed it for reverse-proxy
			     authentication - CreateUser() now accepts a missing password under these
			     constants too and stores unusable random bytes instead
			     (UsersApiController::CreatedUserPassword()). Issue #554, round 8. --}}
			@endif

			@include('components.userfieldsform', array(
			'userfields' => $userfields,
			'entity' => 'users'
			))

			<button id="save-user-button"
				class="btn btn-success">{{ $__t('Save') }}</button>

		</form>
	</div>

	<div class="col-lg-6 col-12">
		<div class="title-related-links">
			<h4>
				{{ $__t('Picture') }}
			</h4>
			<div class="form-group w-75 m-0">
				<div class="input-group">
					<div class="custom-file">
						<input type="file"
							class="custom-file-input"
							id="user-picture"
							accept="image/*">
						<label id="user-picture-label"
							class="custom-file-label @if(empty($user->picture_file_name)) d-none @endif"
							for="user-picture">
							{{ $user->picture_file_name ?? '' }}
						</label>
						<label id="user-picture-label-none"
							class="custom-file-label @if(!empty($user->picture_file_name)) d-none @endif"
							for="user-picture">
							{{ $__t('No file selected') }}
						</label>
					</div>
					<div class="input-group-append">
						<span class="input-group-text"><i class="fa-solid fa-trash"
								id="delete-current-user-picture-button"></i></span>
					</div>
				</div>
			</div>
		</div>
		@if(!empty($user->picture_file_name))
		<img id="current-user-picture"
			src="{{ $U('/api/files/userpictures/' . base64_encode($user->picture_file_name) . '?force_serve_as=picture&best_fit_width=400') }}"
			class="img-fluid img-thumbnail mt-2 mb-5"
			loading="lazy">
		<p id="delete-current-user-picture-on-save-hint"
			class="form-text text-muted font-italic d-none mb-5">{{ $__t('The current picture will be deleted on save') }}</p>
		@else
		<p id="no-current-user-picture-hint"
			class="form-text text-muted font-italic mb-5">{{ $__t('No picture available') }}</p>
		@endif
	</div>
</div>
@stop
