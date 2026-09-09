@php require_frontend_packages(['bootstrap-combobox']); @endphp

@once
@push('componentScripts')
<script src="{{ $U('/viewjs/components/locationpicker.js', true) }}?v={{ $version }}"></script>
@endpush
@endonce

@php if(empty($prefillByName)) { $prefillByName = ''; } @endphp
@php if(empty($prefillById)) { $prefillById = ''; } @endphp
@php if(!isset($isRequired)) { $isRequired = true; } @endphp
@php if(empty($hint)) { $hint = ''; } @endphp
@php if(empty($nextInputSelector)) { $nextInputSelector = ''; } @endphp

<div class="form-group"
	data-next-input-selector="{{ $nextInputSelector }}"
	data-prefill-by-name="{{ $prefillByName }}"
	data-prefill-by-id="{{ $prefillById }}">
	<label for="location_id">{{ $__t('Location') }}
		@if(!empty($hint))
		<i class="fa-solid fa-question-circle text-muted"
			data-toggle="tooltip"
			data-trigger="hover click"
			title="{{ $hint }}"></i>
		@endif
	</label>
	<select class="form-control location-combobox"
		id="location_id"
		name="location_id"
		@if($isRequired)
		required
		@endif>
		<option value=""></option>
		@foreach($locations as $location)
		{{-- The path, not the bare name: "Kitchen / Pantry / Top shelf" is typeable in the
		combobox and tells two "Top shelf" rows apart, which an indent cannot. data-level and
		data-is-freezer are here for callers that need the tree shape or the freezer flag
		without a second request. --}}
		<option value="{{ $location->id }}"
			data-level="{{ $location->level }}"
			data-is-freezer="{{ $location->is_freezer }}">{{ $location->path }}</option>
		@endforeach
	</select>
	<div class="invalid-feedback">{{ $__t('You have to select a location') }}</div>
</div>
