@extends('layout.default')
@section('title', $__t('Permissions for user %s', GetUserDisplayName($user)))
@push('pageScripts')
<script src="{{ $U('/js/victual_permissions.js') }}"></script>
<script>Victual.EditObjectId = {{ $user->id }}; Victual.CanEditPermissions = @json($canEdit);</script>
@endpush
@section('content')
<h2>@yield('title')</h2>
<form id="permissions-form">
	<label for="user-roles">{{ $__t('Roles') }}</label>
	<select id="user-roles" multiple class="form-control" @if(!$canEdit) disabled @endif>
		@foreach($roles as $role)
		<option value="{{ $role->id }}" @if(in_array((int)$role->id, $assignedIds)) selected @endif>{{ $role->name }}</option>
		@endforeach
	</select>
	@if($canEdit)<button id="roles-save" type="button" class="btn btn-primary my-2">{{ $__t('Save roles') }}</button>@endif
	<h3>{{ $__t('Permissions') }}</h3>
	<p>{{ $__t('Role permissions are inherited. Direct grants remain when a role is removed.') }}</p>
	@include('components.permission_tree', ['parentId' => null])
	@if($canEdit)<button id="permission-save" type="button" class="btn btn-success">{{ $__t('Save permissions') }}</button>@endif
</form>
@endsection
