@php require_frontend_packages(['datatables']); @endphp

@extends('layout.default')

@section('title', $__t('Users'))

@section('content')
<div class="row">
	<div class="col">
		<div class="title-related-links">
			<h2 class="title">@yield('title')</h2>
			<a class="btn btn-outline-secondary" href="{{ $U('/roles') }}">{{ $__t('Roles') }}</a>
			@include('components.list_collapse_toggles')
			<div class="related-links collapse d-md-flex order-2 width-xs-sm-100 m-1 mt-md-0 mb-md-0 float-right"
				id="related-links">
				@if(!defined('VICTUAL_EXTERNALLY_MANAGED_AUTHENTICATION'))
				<a class="btn btn-primary responsive-button"
					href="{{ $U('/user/new') }}">
					{{ $__t('Add') }}
				</a>
				@endif
				<a class="btn btn-outline-secondary m-1 mt-md-0 mb-md-0 float-right"
					href="{{ $U('/userfields?entity=users') }}">
					{{ $__t('Configure userfields') }}
				</a>
			</div>
		</div>
	</div>
</div>

<hr class="my-2">

@include('components.list_filter_row', array(
	'showDisabled' => false
))

<div class="row">
	<div class="col">
		<table id="users-table"
			class="table table-sm table-striped nowrap w-100">
			<thead>
				<tr>
					<th class="border-right"><a class="text-muted change-table-columns-visibility-button"
							data-toggle="tooltip"
							title="{{ $__t('Table options') }}"
							data-table-selector="#users-table"
							href="#"><i class="fa-solid fa-eye"></i></a>
					</th>
					<th>{{ $__t('Username') }}</th>
					<th>{{ $__t('First name') }}</th>
					<th>{{ $__t('Last name') }}</th>
					<th>{{ $__t('Roles') }}</th>

					@include('components.userfields_thead', array(
					'userfields' => $userfields
					))
				</tr>
			</thead>
			<tbody class="d-none">
				@foreach($users as $user)
				<tr>
					<td class="fit-content border-right">
						<a class="btn btn-info btn-sm"
							href="{{ $U('/user/') }}{{ $user->id }}"
							data-toggle="tooltip"
							title="{{ $__t('Edit this item') }}">
							<i class="fa-solid fa-edit"></i>
						</a>
						@if(!VICTUAL_IS_EMBEDDED_INSTALL && !VICTUAL_DISABLE_AUTH)
						<a class="btn btn-info btn-sm"
							href="{{ $U('/user/' . $user->id . '/permissions') }}"
							data-toggle="tooltip"
							title="{{ $__t('Configure user permissions') }}">
							<i class="fa-solid fa-lock"></i>
						</a>
						@endif
						<a class="btn btn-danger btn-sm user-delete-button @if($user->id == VICTUAL_USER_ID) disabled @endif"
							href="#"
							data-user-id="{{ $user->id }}"
							data-user-username="{{ $user->username }}"
							data-toggle="tooltip"
							title="{{ $__t('Delete this item') }}">
							<i class="fa-solid fa-trash"></i>
						</a>
					</td>
					<td>
						{{ $user->username }}
					</td>
					<td>
						{{ $user->first_name }}
					</td>
					<td>
						{{ $user->last_name }}
					</td>
					<td>@foreach($rolesService->GetUserRoles((int)$user->id) as $role) {{ $role->name }}@if(!$loop->last), @endif @endforeach</td>

					@include('components.userfields_tbody', array(
					'userfields' => $userfields,
					'userfieldValues' => FindAllObjectsInArrayByPropertyValue($userfieldValues, 'object_id', $user->id)
					))
				</tr>
				@endforeach
			</tbody>
		</table>
	</div>
</div>
@stop
