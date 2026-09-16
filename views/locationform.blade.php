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

			{{-- The parent picker. A plain select rather than the combobox component: this is
			master data edited once, the option text is the full path, and the edit-mode list
			has already had this location and its whole subtree removed server-side, so there
			is no option here the database would refuse. data-is-freezer is what lets create
			mode default the checkbox below from the chosen parent (plan 08 question 3). --}}
			<div class="form-group">
				<label for="parent_location_id">{{ $__t('Parent location') }}
					&nbsp;<i class="fa-solid fa-question-circle text-muted"
						data-toggle="tooltip"
						data-trigger="hover click"
						title="{{ $__t('Leave empty to make this a top level location') }}"></i>
				</label>
				<select class="custom-control custom-select"
					id="parent_location_id"
					name="parent_location_id">
					<option value=""></option>
					@foreach($possibleParents as $possibleParent)
					<option value="{{ $possibleParent->id }}"
						data-level="{{ $possibleParent->level }}"
						data-is-freezer="{{ $possibleParent->is_freezer }}"
						data-storage-class-id="{{ $possibleParent->storage_class_id }}"
						@if($mode=='edit' && $location->parent_location_id == $possibleParent->id) selected="selected" @endif>{{ $possibleParent->path }}</option>
					@endforeach
				</select>
			</div>

			{{-- How cold this location is kept. data-treats-as-freezer is what lets the
			freezer checkbox below become a derived display whenever a class is chosen
			(plan 23 questions 1 and 2) - the server derives is_freezer from the same
			column on save regardless, so this is a preview of what the save will do rather
			than the source of truth. --}}
			<div class="form-group">
				<label for="storage_class_id">{{ $__t('Storage class') }}
					&nbsp;<i class="fa-solid fa-question-circle text-muted"
						data-toggle="tooltip"
						data-trigger="hover click"
						title="{{ $__t('How cold this location is kept. Leave unclassified to set the freezer flag directly') }}"></i>
				</label>
				<select class="custom-control custom-select"
					id="storage_class_id"
					name="storage_class_id">
					<option value=""></option>
					@foreach($storageClasses as $storageClass)
					<option value="{{ $storageClass->id }}"
						data-treats-as-freezer="{{ $storageClass->treats_as_freezer }}"
						@if($mode=='edit' && $location->storage_class_id == $storageClass->id) selected="selected" @endif>{{ $storageClass->name }}</option>
					@endforeach
				</select>
			</div>

			{{-- The vessel tare (ADR-0022 decision 4, plan 29): a bin or a spice jar is a place
			stock passes through, not a container stock arrived in, so its tare is set once
			here rather than on every stock entry a refill mints. Both null means "not a
			vessel", which is what every location means today - leaving both blank changes
			nothing on save. The unit is the location's own, since a location holds no stock
			unit to borrow; StockService::WeighLocation() converts it into whichever product
			ends up stocked here and refuses rather than assumes when no conversion exists. --}}
			@php if($mode == 'edit' && $location->tare_weight !== null) { $value = $location->tare_weight; } else { $value = ''; } @endphp
			@include('components.numberpicker', array(
			'id' => 'tare_weight',
			'label' => 'Tare weight',
			'min' => '0.',
			'decimals' => $userSettings['stock_decimal_places_amounts'] ?? 2,
			'value' => $value,
			'isRequired' => false,
			'hint' => $__t('The empty weight of this location\'s own container. Leave blank unless this location is weighed as a vessel'),
			'additionalCssClasses' => 'locale-number-input locale-number-quantity-amount'
			))

			<div class="form-group">
				<label for="tare_qu_id">{{ $__t('Tare unit') }}
					&nbsp;<i class="fa-solid fa-question-circle text-muted"
						data-toggle="tooltip"
						data-trigger="hover click"
						title="{{ $__t('The quantity unit the tare weight and a gross weighing are given in') }}"></i>
				</label>
				<select class="custom-control custom-select"
					id="tare_qu_id"
					name="tare_qu_id">
					<option value=""></option>
					@foreach($quantityUnits as $quantityUnit)
					<option value="{{ $quantityUnit->id }}"
						@if($mode=='edit' && $location->tare_qu_id == $quantityUnit->id) selected="selected" @endif>{{ $quantityUnit->name }}</option>
					@endforeach
				</select>
			</div>

			<div class="form-group">
				<label for="description">{{ $__t('Description') }}</label>
				<textarea class="form-control"
					rows="2"
					id="description"
					name="description">@if($mode == 'edit'){{ $location->description }}@endif</textarea>
			</div>

			@if(VICTUAL_FEATURE_FLAG_STOCK_PRODUCT_FREEZING)
			{{-- Derived, not editable, wherever a class is set (plan 23 question 1): the
			checkbox becomes a display of what the chosen storage class's
			treats_as_freezer already says, disabled server-side on load so there is no
			flash of an editable control, and kept in sync by the storage_class_id change
			handler below. The server derives is_freezer from the class on save
			regardless of what a disabled, unsubmitted checkbox would have sent. --}}
			<div class="form-group">
				<div class="custom-control custom-checkbox">
					<input @if($mode=='edit'
						&&
						$location->is_freezer == 1) checked @endif @if($mode=='edit'
						&&
						$location->storage_class_id !== null) disabled @endif class="form-check-input custom-control-input" type="checkbox" id="is_freezer" name="is_freezer" value="1">
					<label class="form-check-label custom-control-label"
						for="is_freezer">{{ $__t('Is freezer') }}
						&nbsp;<i class="fa-solid fa-question-circle text-muted"
							data-toggle="tooltip"
							data-trigger="hover click"
							title="{{ $__t('When moving products from/to a freezer location, the products due date is automatically adjusted according to the product settings') }}"></i>
					</label>
				</div>
				<small id="is-freezer-derived-note"
					class="form-text text-muted @if(!($mode=='edit' && $location->storage_class_id !== null)) d-none @endif">{{ $__t('Set by the storage class above') }}</small>
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

		@if($mode == 'edit')
		@include('components.label_print_widget', [
			'idPrefix' => 'location-form',
			'targetId' => $location->id,
			'targetName' => $location->name,
			'printLabel' => $__t('Print a label for this location'),
		])
		@endif
	</div>
</div>
@stop
