@extends('layout.default')
@section('title', $__t('Label printers'))
@section('content')
<h2>@yield('title')</h2>
<a href="{{ $U('/labelprintjobs') }}">{{ $__t('Label print jobs') }}</a>
<p id="label-admin-message" role="status"></p>
<div class="row">
 <div class="col-lg-6">
  <h3>{{ $__t('Workers') }}</h3>
  <form id="label-worker-form">
   <label for="label-worker-select">{{ $__t('Worker') }}</label><select id="label-worker-select" class="form-control"><option value="">{{ $__t('New worker') }}</option></select>
   <label for="label-worker-name">{{ $__t('Name') }}</label><input id="label-worker-name" class="form-control" required>
   <label for="label-worker-mode">{{ $__t('Configuration mode') }}</label><select id="label-worker-mode" class="form-control"><option value="declared">{{ $__t('Declared') }}</option><option value="paired">{{ $__t('Paired') }}</option></select>
   <label><input type="checkbox" id="label-worker-active" checked> {{ $__t('Active') }}</label>
   <button class="btn btn-primary" type="submit">{{ $__t('Save worker') }}</button>
  </form>
  <button id="label-worker-credential" class="btn btn-secondary mt-2">{{ $__t('Issue credential or pairing material') }}</button>
  <button id="label-worker-revoke" class="btn btn-danger mt-2">{{ $__t('Revoke worker credentials') }}</button>
  <p>{{ $__t('New credentials and pairing material are shown once. Store them before leaving this page.') }}</p>
  <pre id="label-worker-secret" class="text-wrap"></pre>
 </div>
 <div class="col-lg-6">
  <h3>{{ $__t('Printers') }}</h3>
  <form id="label-printer-form">
   <label for="label-printer-select">{{ $__t('Printer') }}</label><select id="label-printer-select" class="form-control"><option value="">{{ $__t('New printer') }}</option></select>
   <label for="label-printer-name">{{ $__t('Name') }}</label><input id="label-printer-name" class="form-control" required>
   <label for="label-printer-worker">{{ $__t('Assigned worker') }}</label><select id="label-printer-worker" class="form-control" required></select>
   <label for="label-printer-driver">{{ $__t('Driver version') }}</label><select id="label-printer-driver" class="form-control" required></select>
   <label for="label-printer-combination">{{ $__t('Model and media') }}</label><select id="label-printer-combination" class="form-control" required></select>
   <label for="label-printer-connection-type">{{ $__t('Connection type') }}</label><select id="label-printer-connection-type" class="form-control" required></select>
   <label for="label-printer-connection">{{ $__t('Connection') }}</label><input id="label-printer-connection" class="form-control" required>
   <div id="label-printer-settings"></div>
   <label><input type="checkbox" id="label-printer-active" checked> {{ $__t('Active') }}</label>
   <label><input type="checkbox" id="label-printer-default"> {{ $__t('Default printer') }}</label>
   <button class="btn btn-primary" type="submit">{{ $__t('Save printer') }}</button>
   <button id="label-printer-move" class="btn btn-warning" type="button">{{ $__t('Move to selected driver version') }}</button>
  </form>
 </div>
</div>
@stop
