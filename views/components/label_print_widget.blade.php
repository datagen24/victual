@php
if (!isset($idPrefix)) { $idPrefix = 'label-print'; }
@endphp
@if(VICTUAL_FEATURE_FLAG_LABELS && isset($labelPrinters) && count($labelPrinters) > 0 && Victual\Controllers\Users\User::HasPermissions(Victual\Controllers\Users\User::PERMISSION_MASTER_DATA_EDIT))
{{-- Outside the entity's own form on purpose: printing a label is not saving that record,
and a button inside the form would submit it - plan 25's original reasoning for locations,
generalised by plan 32 to every kind this widget is included for. Wired by
Victual.LabelPrinting.Wire() (public/js/victual_label_print.js) with this page's own
`kind`. --}}
<hr>
<div class="form-group">
	<label for="{{ $idPrefix }}-printer">{{ $__t('Label printer') }}</label>
	<select class="form-control" id="{{ $idPrefix }}-printer">
		@foreach($labelPrinters as $printer)
		<option value="{{ $printer->id }}" @if($printer->is_default == 1) selected @endif>{{ $printer->name }}</option>
		@endforeach
	</select>
</div>
<button id="{{ $idPrefix }}-button"
	type="button"
	class="btn btn-primary"
	data-target-id="{{ $targetId }}"
	data-target-name="{{ $targetName }}">
	<i class="fa-solid fa-print"></i>&nbsp;{{ $printLabel }}
</button>
<div id="{{ $idPrefix }}-status"
	class="mt-3"
	role="status"
	aria-live="polite"></div>
@endif
