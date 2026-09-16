@php
if (!isset($idPrefix)) { $idPrefix = 'label-print'; }
@endphp
@if(VICTUAL_FEATURE_FLAG_LABELS && isset($labelPrinters) && count($labelPrinters) > 0)
{{-- The printer picker and live status region for a list page whose rows each carry their
own print button (data-target-id/data-target-name), wired by
Victual.LabelPrinting.Wire() with this page's own `kind`. Mirrors views/locations.blade.php,
generalised by plan 32. --}}
<div class="row">
	<div class="col-12 col-md-6 col-xl-4">
		<div class="form-group">
			<label for="{{ $idPrefix }}-printer">{{ $__t('Label printer') }}</label>
			<select class="form-control" id="{{ $idPrefix }}-printer">
				@foreach($labelPrinters as $printer)
				<option value="{{ $printer->id }}" @if($printer->is_default == 1) selected @endif>{{ $printer->name }}</option>
				@endforeach
			</select>
		</div>
	</div>
	<div class="col-12">
		{{-- A live region rather than a toast: the outcome of a print is something a person
		comes back to, and a job that is waiting for its render has a state worth reading. --}}
		<div id="{{ $idPrefix }}-status"
			class="mb-3"
			role="status"
			aria-live="polite"></div>
	</div>
</div>
@endif
