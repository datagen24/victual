@extends('layout.default')
@section('title', $__t('Label print jobs'))
@section('content')
<h2>@yield('title')</h2>
<a href="{{ $U('/labelprinters') }}">{{ $__t('Configure label printers') }}</a>
<p>{{ $__t('Jobs waiting for label rendering cannot be printed yet. A failed or uncertain attempt needs explicit review before another attempt is authorized.') }}</p>
<button id="label-jobs-refresh" class="btn btn-primary">{{ $__t('Refresh') }}</button>
<p id="label-jobs-error" role="alert"></p>
<table class="table table-striped mt-3">
 <thead><tr><th>{{ $__t('Job') }}</th><th>{{ $__t('Printer') }}</th><th>{{ $__t('State') }}</th><th>{{ $__t('Error') }}</th><th>{{ $__t('Last status age (seconds)') }}</th><th>{{ $__t('Action') }}</th></tr></thead>
 <tbody id="label-jobs-rows"></tbody>
</table>
@stop
