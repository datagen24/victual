@extends('layout.default')

@section('title', $__t('Consumption inbox'))

@section('content')
<div class="row">
	<div class="col">
		<h2 class="title">@yield('title')</h2>
		<p>{{ $__t('Consumption events that other apps sent and that need a decision. Only you can see them. An event is booked only when an action below is chosen or when its mapping allows it.') }}</p>
		<p id="inbox-permission-note"
			class="text-muted d-none">{{ $__t('You may view these events but not resolve them. Resolving needs the permission to record consumption.') }}</p>

		<div class="form-inline mb-2">
			<label class="mr-3"><input id="inbox-show-undone"
					type="checkbox"> {{ $__t('Also show undone events') }}</label>
			<label id="inbox-medication-label"
				class="mr-3 d-none"
				for="inbox-medication">{{ $__t('Medication') }}
				<select id="inbox-medication"
					class="form-control form-control-sm ml-2"></select></label>
			<button id="inbox-reload"
				class="btn btn-sm btn-outline-secondary"
				type="button">{{ $__t('Reload') }}</button>
		</div>

		<p id="inbox-message"
			class="mt-2"
			role="status"></p>
		<p id="inbox-error"
			class="mt-2 text-danger"
			role="alert"></p>

		<table id="inbox-table"
			class="table table-sm table-striped nowrap w-100">
			<thead>
				<tr>
					<th>{{ $__t('Source') }}</th>
					<th>{{ $__t('Medication') }}</th>
					<th>{{ $__t('Time') }}</th>
					<th>{{ $__t('State') }}</th>
					<th>{{ $__t('Details') }}</th>
					<th>{{ $__t('Actions') }}</th>
				</tr>
			</thead>
			<tbody id="inbox-rows"></tbody>
		</table>
		<p id="inbox-empty"
			class="text-muted d-none">{{ $__t('No events need a decision.') }}</p>

		<h4 class="mt-4">{{ $__t('Resolve many at once') }}</h4>
		<p>{{ $__t('One action is applied to every event of one medication that is in the chosen state. The server handles up to 50 events per request.') }}</p>
		<div class="form-inline">
			<label class="mr-2"
				for="inbox-bulk-system">{{ $__t('Source') }}</label>
			<select id="inbox-bulk-system"
				class="form-control form-control-sm mr-3"></select>
			<label class="mr-2"
				for="inbox-bulk-medication">{{ $__t('Medication reference') }}</label>
			<input id="inbox-bulk-medication"
				class="form-control form-control-sm mr-3"
				type="text"
				list="inbox-bulk-medications"
				maxlength="128">
			<datalist id="inbox-bulk-medications"></datalist>
			<label class="mr-2"
				for="inbox-bulk-state">{{ $__t('State') }}</label>
			<select id="inbox-bulk-state"
				class="form-control form-control-sm mr-3"></select>
			<label class="mr-2"
				for="inbox-bulk-action">{{ $__t('Action') }}</label>
			<select id="inbox-bulk-action"
				class="form-control form-control-sm mr-3"></select>
			<button id="inbox-bulk-run"
				class="btn btn-sm btn-primary"
				type="button">{{ $__t('Apply to all matching') }}</button>
		</div>
		<p id="inbox-bulk-result"
			class="mt-2"
			role="status"></p>
		<ul id="inbox-bulk-failures"></ul>
	</div>
</div>

<div class="modal fade"
	id="inbox-link-modal"
	tabindex="-1">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<h4 id="inbox-link-title"
					class="modal-title"></h4>
			</div>
			<div class="modal-body">
				<p>{{ $__t('Linking says this event and an existing consumption are the same dose. If the event is booked, its own booking is undone, so one deduction remains.') }}</p>
				<div id="inbox-link-choices"></div>
				<div class="form-group">
					<label for="inbox-link-other">{{ $__t('Another transaction id') }}</label>
					<input id="inbox-link-other"
						class="form-control"
						type="text"
						maxlength="128">
				</div>
				<p id="inbox-link-error"
					class="text-danger"
					role="alert"></p>
			</div>
			<div class="modal-footer">
				<button id="inbox-link-confirm"
					class="btn btn-success"
					type="button">{{ $__t('Link') }}</button>
				<button class="btn btn-secondary"
					type="button"
					data-dismiss="modal">{{ $__t('Cancel') }}</button>
			</div>
		</div>
	</div>
</div>
@stop
