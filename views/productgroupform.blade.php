@extends('layout.default')

@if($mode == 'edit')
@section('title', $__t('Edit product group'))
@else
@section('title', $__t('Create product group'))
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
			Victual.EditObjectId = {{ $group->id }};
		</script>
		@endif

		<form id="product-group-form"
			novalidate>

			<div class="form-group">
				<label for="name">{{ $__t('Name') }}</label>
				<input type="text"
					class="form-control"
					required
					id="name"
					name="name"
					value="@if($mode == 'edit'){{ $group->name }}@endif">
				<div class="invalid-feedback">{{ $__t('A name is required') }}</div>
			</div>

			<div class="form-group">
				<div class="custom-control custom-checkbox">
					<input @if($mode=='create'
						)
						checked
						@elseif($mode=='edit'
						&&
						$group->active == 1) checked @endif class="form-check-input custom-control-input" type="checkbox" id="active" name="active" value="1">
					<label class="form-check-label custom-control-label"
						for="active">{{ $__t('Active') }}</label>
				</div>
			</div>

			{{-- The parent picker. A plain select rather than the combobox component, matching
			locationform.blade.php: the option text is the full path, and the edit-mode list has
			already had this group and its whole subtree removed server-side, so there is no
			option here the database would refuse. --}}
			<div class="form-group">
				<label for="parent_product_group_id">{{ $__t('Parent product group') }}
					&nbsp;<i class="fa-solid fa-question-circle text-muted"
						data-toggle="tooltip"
						data-trigger="hover click"
						title="{{ $__t('Leave empty to make this a top level product group') }}"></i>
				</label>
				<select class="custom-control custom-select"
					id="parent_product_group_id"
					name="parent_product_group_id">
					<option value=""></option>
					@foreach($possibleParents as $possibleParent)
					<option value="{{ $possibleParent->id }}"
						data-level="{{ $possibleParent->level }}"
						@if($mode=='edit' && $group->parent_product_group_id == $possibleParent->id) selected="selected" @endif>{{ $possibleParent->path }}</option>
					@endforeach
				</select>
			</div>

			<div class="form-group">
				<label for="description">{{ $__t('Description') }}</label>
				<textarea class="form-control"
					rows="2"
					id="description"
					name="description">@if($mode == 'edit'){{ $group->description }}@endif</textarea>
			</div>

			@php if($mode == 'edit') { $value = $group->min_stock_amount; } else { $value = 0; } @endphp
			@include('components.numberpicker', array(
			'id' => 'min_stock_amount',
			'label' => 'Minimum stock amount',
			'min' => '0.',
			'decimals' => $userSettings['stock_decimal_places_amounts'],
			'value' => $value,
			'additionalGroupCssClasses' => 'mb-1',
			'additionalCssClasses' => 'locale-number-input locale-number-quantity-amount',
			'hint' => $__t('The summed stock of this group\'s products, in each product\'s own stock quantity unit - amounts are not converted, so a group minimum is only meaningful when its products are measured comparably')
			))

			@include('components.userfieldsform', array(
			'userfields' => $userfields,
			'entity' => 'product_groups'
			))

			<button id="save-product-group-button"
				class="btn btn-success">{{ $__t('Save') }}</button>

		</form>
	</div>
</div>
@stop
