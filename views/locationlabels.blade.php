@extends('layout.default')

@section('title', $__t('Scan location label'))

@section('content')
<h2 class="title">@yield('title')</h2>
<hr class="my-2">
<div class="row">
	<div class="col-lg-6 col-12">
		<form id="location-label-form">
			<div class="form-group">
				<label for="location-label-code">{{ $__t('Location label code') }}</label>
				<div class="input-group">
					<input id="location-label-code" type="text"
						class="form-control barcodescanner-input" data-target="#location-label-code"
						required autocomplete="off" autocapitalize="none" spellcheck="false"
						aria-describedby="location-label-help">
				</div>
				<small id="location-label-help" class="form-text text-muted">{{ $__t('Scan or enter the vctl: code on a location label.') }}</small>
			</div>
			<button type="submit" class="btn btn-primary">{{ $__t('Look up label') }}</button>
		</form>
		<div id="location-label-result" class="mt-3" role="status" aria-live="polite" aria-atomic="true">
			<p id="location-label-status"></p>
			<p id="location-label-name" class="font-weight-bold"></p>
		</div>
	</div>
</div>
@include('components.camerabarcodescanner')
@stop
