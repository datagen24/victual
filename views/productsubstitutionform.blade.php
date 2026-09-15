@extends('layout.default')

@section('title', $__t('Create substitution'))

@section('content')
<div class="row">
	<div class="col">
		<div class="title-related-links">
			<h2 class="title">
				@yield('title')<br>
				<span class="text-muted small">{{ $__t('Substitution for product') }} <strong>{{ $product->name }}</strong></span>
			</h2>
		</div>
	</div>
</div>

<hr class="my-2">

<div class="row">
	<div class="col-lg-6 col-12">

		<script>
			Victual.EditObjectProduct = {!! json_encode($product) !!};
		</script>

		<form id="product-substitution-form"
			novalidate>

			<input type="hidden"
				name="this_product_id"
				value="{{ $product->id }}">

			<div class="form-group">
				<label>{{ $__t('Direction') }}</label>
				<div class="custom-control custom-radio">
					<input type="radio"
						class="custom-control-input"
						id="direction-this"
						name="direction"
						value="this"
						@if($direction == 'this') checked @endif>
					<label class="custom-control-label"
						for="direction-this">{{ $__t('This product can be used instead of the other one') }}</label>
				</div>
				<div class="custom-control custom-radio">
					<input type="radio"
						class="custom-control-input"
						id="direction-other"
						name="direction"
						value="other"
						@if($direction == 'other') checked @endif>
					<label class="custom-control-label"
						for="direction-other">{{ $__t('The other product can be used instead of this one') }}</label>
				</div>
			</div>

			@include('components.productpicker', array(
			'products' => $otherProducts,
			'disallowAllProductWorkflows' => true,
			'isRequired' => true,
			'label' => 'Other product'
			))

			<button id="save-product-substitution-button"
				class="btn btn-success">{{ $__t('Save') }}</button>

		</form>
	</div>
</div>
@stop
