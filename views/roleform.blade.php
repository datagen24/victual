@extends('layout.default')
@section('title', $__t('Role'))
@push('pageScripts')
<script src="{{ $U('/js/victual_permissions.js') }}"></script>
<script>Victual.EditObjectId = @json($role === null ? null : (int)$role->id); Victual.CanEditPermissions = @json($canEdit);</script>
@endpush
@section('content')
<h2>@yield('title')</h2>
<form id="role-form">
	<label for="role-name">{{ $__t('Name') }}</label>
	<input id="role-name" class="form-control" required value="{{ $role->name ?? '' }}" @if(!$canEdit) disabled @endif>
	<label for="role-code">{{ $__t('Code') }}</label>
	<input id="role-code" class="form-control" required pattern="[A-Z][A-Z0-9_]{0,63}" value="{{ $role->code ?? '' }}" @if($role !== null || !$canEdit) disabled @endif>
	<label for="role-description">{{ $__t('Description') }}</label>
	<textarea id="role-description" class="form-control" @if(!$canEdit) disabled @endif>{{ $role->description ?? '' }}</textarea>
	@include('components.permission_tree', ['parentId' => null])
	@if($canEdit)<button id="role-save" type="button" class="btn btn-success">{{ $__t('Save') }}</button>@endif
</form>
@endsection
