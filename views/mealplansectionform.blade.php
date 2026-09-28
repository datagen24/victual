@extends('layout.default')

@if($mode == 'edit')
@section('title', $__t('Edit meal plan section'))
@else
@section('title', $__t('Create meal plan section'))
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
			Victual.EditObjectId = {{ $mealplanSection->id }};
		</script>
		@endif

		<form id="mealplansection-form"
			novalidate>

			<div class="form-group">
				<label for="name">{{ $__t('Name') }}</label>
				<input type="text"
					class="form-control"
					required
					id="name"
					name="name"
					value="@if($mode == 'edit'){{ $mealplanSection->name }}@endif">
				<div class="invalid-feedback">{{ $__t('A name is required') }}</div>
			</div>

			{{-- Not empty(): '0' is empty to PHP the same way 0.0 is (issue #545's stock entry
			price), and a section can genuinely sort at position 0 - a real, meaningful value,
			unlike a NULL sort_number, which means "unordered". empty() rendered a stored 0 as
			a blank field, and saving the untouched form then sent "" for this nullable
			INTEGER column - not the price view's silent-NULLing, but a 400 ("The database
			rejected this request"), because the generic entity endpoint has no clearing idiom
			for this column the way PUT /api/stock/entry/{id} does for price. Only an actual
			NULL should render blank (issue #574). --}}
			@php if($mode == 'edit' && $mealplanSection->sort_number !== null) { $value = $mealplanSection->sort_number; } else { $value = ''; } @endphp
			@include('components.numberpicker', array(
			'id' => 'sort_number',
			'label' => 'Sort number',
			'min' => 0,
			'value' => $value,
			'isRequired' => false,
			'hint' => $__t('Sections will be ordered by that number on the meal plan')
			))

			<div class="form-group">
				<label for="time_info">{{ $__t('Time') }}</label>
				<input type="time"
					class="form-control"
					id="time_info"
					name="time_info"
					value="@if($mode == 'edit'){{ $mealplanSection->time_info }}@endif">
			</div>

			<button id="save-mealplansection-button"
				class="btn btn-success">{{ $__t('Save') }}</button>

		</form>
	</div>
</div>
@stop
