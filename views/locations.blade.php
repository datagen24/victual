@php require_frontend_packages(['datatables']); @endphp

@extends('layout.default')

@section('title', $__t('Locations'))

@section('content')
<div class="row">
	<div class="col">
		<div class="title-related-links">
			<h2 class="title">@yield('title')</h2>
			@include('components.list_collapse_toggles')
			<div class="related-links collapse d-md-flex order-2 width-xs-sm-100"
				id="related-links">
				<a class="btn btn-outline-secondary m-1 mt-md-0 mb-md-0"
					href="{{ $U('/locationlabels') }}">
					{{ $__t('Scan location label') }}
				</a>
				<a class="btn btn-primary responsive-button m-1 mt-md-0 mb-md-0 float-right show-as-dialog-link"
					href="{{ $U('/location/new?embedded') }}">
					{{ $__t('Add') }}
				</a>
				<a class="btn btn-outline-secondary m-1 mt-md-0 mb-md-0 float-right"
					href="{{ $U('/userfields?entity=locations') }}">
					{{ $__t('Configure userfields') }}
				</a>
			</div>
		</div>
	</div>
</div>

<hr class="my-2">

@if(VICTUAL_FEATURE_FLAG_LABELS && count($labelPrinters) > 0)
<div class="row">
	<div class="col-12 col-md-6 col-xl-4">
		<div class="form-group">
			<label for="location-label-printer">{{ $__t('Label printer') }}</label>
			<select class="form-control" id="location-label-printer">
				@foreach($labelPrinters as $printer)
				<option value="{{ $printer->id }}" @if($printer->is_default == 1) selected @endif>{{ $printer->name }}</option>
				@endforeach
			</select>
		</div>
	</div>
	<div class="col-12">
		{{-- A live region rather than a toast: the outcome of a print is something a person
		comes back to, and a job that is waiting for its render has a state worth reading. --}}
		<div id="location-print-status"
			class="mb-3"
			role="status"
			aria-live="polite"></div>
	</div>
</div>
@endif

@include('components.list_filter_row')

<div class="row">
	<div class="col">
		<table id="locations-table"
			class="table table-sm table-striped nowrap w-100">
			<thead>
				<tr>
					<th class="border-right"><a class="text-muted change-table-columns-visibility-button"
							data-toggle="tooltip"
							title="{{ $__t('Table options') }}"
							data-table-selector="#locations-table"
							href="#"><i class="fa-solid fa-eye"></i></a>
					</th>
					<th>{{ $__t('Name') }}</th>
					<th>{{ $__t('Path') }}</th>
					<th>{{ $__t('Description') }}</th>

					@include('components.userfields_thead', array(
					'userfields' => $userfields
					))

				</tr>
			</thead>
			<tbody class="d-none">
				@foreach($locations as $location)
				<tr class="@if($location->active == 0) text-muted @endif">
					<td class="fit-content border-right">
						<a class="btn btn-info btn-sm show-as-dialog-link"
							href="{{ $U('/location/') }}{{ $location->id }}?embedded"
							data-toggle="tooltip"
							title="{{ $__t('Edit this item') }}">
							<i class="fa-solid fa-edit"></i>
						</a>
						<a class="btn btn-danger btn-sm location-delete-button"
							href="#"
							data-location-id="{{ $location->id }}"
							data-location-name="{{ $location->name }}"
							data-toggle="tooltip"
							title="{{ $__t('Delete this item') }}">
							<i class="fa-solid fa-trash"></i>
						</a>
						{{-- The print action, gated on FEATURE_FLAG_LABELS and on a printer
						existing. It is deliberately not the webhook the five other entity
						types still use: ADR-0019 item 7 leaves those alone through this wave,
						and new location printing extends neither the webhook nor Grocycode. --}}
						@if(VICTUAL_FEATURE_FLAG_LABELS && count($labelPrinters) > 0 && VICTUAL_AUTHENTICATED && Victual\Controllers\Users\User::HasPermissions(Victual\Controllers\Users\User::PERMISSION_MASTER_DATA_EDIT))
						<a class="btn btn-primary btn-sm location-print-button"
							href="#"
							data-location-id="{{ $location->id }}"
							data-location-name="{{ $location->name }}"
							data-toggle="tooltip"
							title="{{ $__t('Print a label for this location') }}">
							<i class="fa-solid fa-print"></i>
						</a>
						@endif
					</td>
					<td data-location-level="{{ $location->level }}">
						{{ $location->name }}
					</td>
					{{-- Where this location sits, spelled out. The name column stays the bare
					name because that is what the label actions key off and what a label says
					is plan 06's question, not this one's. --}}
					<td>
						{{ $location->path }}
					</td>
					<td>
						{{ $location->description }}
					</td>

					@include('components.userfields_tbody', array(
					'userfields' => $userfields,
					'userfieldValues' => FindAllObjectsInArrayByPropertyValue($userfieldValues, 'object_id', $location->id)
					))

				</tr>
				@endforeach
			</tbody>
		</table>
	</div>
</div>
@stop
