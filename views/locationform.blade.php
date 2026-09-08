@extends('layout.default')

@if($mode == 'edit')
@section('title', $__t('Edit location'))
@else
@section('title', $__t('Create location'))
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
			Victual.EditObjectId = {{ $location->id }};
		</script>
		@endif

		<form id="location-form"
			novalidate>

			<div class="form-group">
				<label for="name">{{ $__t('Name') }}</label>
				<input type="text"
					class="form-control"
					required
					id="name"
					name="name"
					value="@if($mode == 'edit'){{ $location->name }}@endif">
				<div class="invalid-feedback">{{ $__t('A name is required') }}</div>
			</div>

			<div class="form-group">
				<div class="custom-control custom-checkbox">
					<input @if($mode=='create'
						)
						checked
						@elseif($mode=='edit'
						&&
						$location->active == 1) checked @endif class="form-check-input custom-control-input" type="checkbox" id="active" name="active" value="1">
					<label class="form-check-label custom-control-label"
						for="active">{{ $__t('Active') }}</label>
				</div>
			</div>

			<div class="form-group">
				<label for="description">{{ $__t('Description') }}</label>
				<textarea class="form-control"
					rows="2"
					id="description"
					name="description">@if($mode == 'edit'){{ $location->description }}@endif</textarea>
			</div>

			@if(VICTUAL_FEATURE_FLAG_STOCK_PRODUCT_FREEZING)
			<div class="form-group">
				<div class="custom-control custom-checkbox">
					<input @if($mode=='edit'
						&&
						$location->is_freezer == 1) checked @endif class="form-check-input custom-control-input" type="checkbox" id="is_freezer" name="is_freezer" value="1">
					<label class="form-check-label custom-control-label"
						for="is_freezer">{{ $__t('Is freezer') }}
						&nbsp;<i class="fa-solid fa-question-circle text-muted"
							data-toggle="tooltip"
							data-trigger="hover click"
							title="{{ $__t('When moving products from/to a freezer location, the products due date is automatically adjusted according to the product settings') }}"></i>
					</label>
				</div>
			</div>
			@else
			<input type="hidden"
				name="is_freezer"
				value="0">
			@endif

			@include('components.userfieldsform', array(
			'userfields' => $userfields,
			'entity' => 'locations'
			))

			<button id="save-location-button"
				class="btn btn-success">{{ $__t('Save') }}</button>

		</form>

		@if($mode == 'edit' && VICTUAL_FEATURE_FLAG_LABELS && isset($labelPrinters) && count($labelPrinters) > 0 && Victual\Controllers\Users\User::HasPermissions(Victual\Controllers\Users\User::PERMISSION_MASTER_DATA_EDIT))
		{{-- Outside the form on purpose: printing a label is not saving this record, and a
		button inside the form would submit it. --}}
		<hr>
		<div class="form-group">
			<label for="location-form-label-printer">{{ $__t('Label printer') }}</label>
			<select class="form-control"
				id="location-form-label-printer">
				@foreach($labelPrinters as $printer)
				<option value="{{ $printer->id }}" @if($printer->is_default == 1) selected @endif>{{ $printer->name }}</option>
				@endforeach
			</select>
		</div>
		<button id="location-form-print-button"
			class="btn btn-primary"
			data-location-id="{{ $location->id }}"
			data-location-name="{{ $location->name }}">
			<i class="fa-solid fa-print"></i>&nbsp;{{ $__t('Print a label for this location') }}
		</button>
		<div id="location-form-print-status"
			class="mt-3"
			role="status"
			aria-live="polite"></div>
		@endif
	</div>
</div>
@stop
