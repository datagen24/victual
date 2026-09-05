@php require_frontend_packages(['datatables']); @endphp
@extends('layout.default')
@section('title', $__t('Roles'))
@section('content')
<h2>@yield('title')</h2>
@if($canEdit)<a class="btn btn-primary" href="{{ $U('/role/new') }}">{{ $__t('Add') }}</a>@endif
<a href="{{ $U('/users') }}">{{ $__t('Users') }}</a>
@include('components.list_filter_row', ['showDisabled' => false])
<table id="roles-table" class="table table-striped">
<thead><tr><th></th><th>{{ $__t('Name') }}</th><th>{{ $__t('Code') }}</th><th>{{ $__t('Description') }}</th></tr></thead>
<tbody class="d-none">
@foreach($roles as $role)
<tr><td>
<a class="btn btn-info btn-sm" href="{{ $U('/role/' . $role->id) }}">{{ $__t('Permissions') }}</a>
@if($canEdit && !$role->builtin)<button type="button" class="btn btn-danger btn-sm role-delete-button" data-role-id="{{ $role->id }}" data-role-name="{{ $role->name }}">{{ $__t('Delete') }}</button>@endif
</td><td>{{ $role->name }}</td><td>{{ $role->code }}</td><td>{{ $role->description }}</td></tr>
@endforeach
</tbody></table>
@endsection
