@extends('layout.default')

@section('title', $__t('Prescription refills'))

@section('content')
<div class="row">
	<div class="col">
		<h2 class="title">@yield('title')</h2>
		<p>{{ $__t('Estimated reorder dates calculated from the fills you record. An estimate is a date worked out from what you entered. It does not say that a pharmacy or an insurer will allow a refill on that date, and it is separate from the stock you hold. Only you and the users you share a prescription with can see it.') }}</p>

		<section aria-labelledby="refill-notices-title"
			class="mb-4">
			<h3 id="refill-notices-title"
				class="h5">{{ $__t('Notices') }}</h3>
			<ul id="refill-notices"
				class="list-unstyled"></ul>
			<p id="refill-notices-empty"
				class="text-muted">{{ $__t('No notices.') }}</p>
		</section>

		<div class="form-inline mb-3">
			<label class="mr-2"
				for="refill-lead-default">{{ $__t('Days of advance warning') }}</label>
			<input id="refill-lead-default"
				class="form-control form-control-sm mr-2"
				type="number"
				min="0"
				max="60"
				step="1"
				aria-describedby="refill-lead-default-help">
			<button id="refill-lead-default-save"
				class="btn btn-sm btn-outline-primary"
				type="button">{{ $__t('Save') }}</button>
			<small id="refill-lead-default-help"
				class="form-text text-muted ml-3">{{ $__t('Used for every prescription that has no value of its own (0 to 60, 7 if you set none).') }}</small>
		</div>

		<p id="refill-message"
			class="mt-2"
			role="status"></p>
		<p id="refill-error"
			class="mt-2 text-danger"
			role="alert"></p>

		<table id="refill-table"
			class="table table-sm table-striped nowrap w-100">
			<caption class="sr-only">{{ $__t('Prescriptions and their estimated reorder dates') }}</caption>
			<thead>
				<tr>
					<th scope="col">{{ $__t('Prescription') }}</th>
					<th scope="col">{{ $__t('Status') }}</th>
					<th scope="col">{{ $__t('Estimated reorder date') }}</th>
					<th scope="col">{{ $__t('Where the date comes from') }}</th>
					<th scope="col">{{ $__t('Actions') }}</th>
				</tr>
			</thead>
			<tbody id="refill-rows"></tbody>
		</table>
		<p id="refill-empty"
			class="text-muted d-none">{{ $__t('You have no prescriptions. Create one on the consumption recipes page.') }}</p>

		<section id="refill-detail"
			class="d-none mt-4"
			aria-labelledby="refill-detail-title">
			<h3 id="refill-detail-title"
				class="h4"
				tabindex="-1"></h3>
			<dl id="refill-summary"
				class="row"></dl>
			<p id="refill-read-only"
				class="text-muted d-none">{{ $__t('You may read these dates but not change them. Changing them needs the edit right on this prescription.') }}</p>

			<div id="refill-forms">
				<h4 class="h5 mt-4">{{ $__t('Record a fill') }}</h4>
				<form id="refill-fill-form"
					class="form-row align-items-end"
					novalidate>
					<div class="form-group col-md-3">
						<label for="refill-fill-date">{{ $__t('Filled on') }}</label>
						<input id="refill-fill-date"
							class="form-control"
							type="date"
							required>
					</div>
					<div class="form-group col-md-2">
						<label for="refill-fill-days">{{ $__t('Days supplied') }}</label>
						<input id="refill-fill-days"
							class="form-control"
							type="number"
							min="1"
							max="730"
							step="1">
					</div>
					<div class="form-group col-md-4">
						<label for="refill-fill-note">{{ $__t('Note') }}</label>
						<input id="refill-fill-note"
							class="form-control"
							type="text"
							maxlength="2000">
					</div>
					<div class="form-group col-md-3">
						<button id="refill-fill-record"
							class="btn btn-primary"
							type="submit">{{ $__t('Record fill') }}</button>
						<button id="refill-fill-receive"
							class="btn btn-outline-primary d-none"
							type="button">{{ $__t('Receive order') }}</button>
					</div>
				</form>
				<small class="form-text text-muted mb-3">{{ $__t('Enter the date the pharmacy supplied the medication and the number of days it covers. Recording a fill does not change your stock; record the purchase on the purchase page.') }}</small>

				<h4 class="h5 mt-4">{{ $__t('Reorder rule') }}</h4>
				<form id="refill-rule-form"
					class="form-row align-items-end"
					novalidate>
					<div class="form-group col-md-4">
						<label for="refill-rule-kind">{{ $__t('Rule for this prescription') }}</label>
						<select id="refill-rule-kind"
							class="form-control"
							aria-describedby="refill-rule-help">
							<option value="">{{ $__t('None: supply end minus 14 days') }}</option>
							<option value="days_before_end">{{ $__t('Days before the supply ends') }}</option>
							<option value="fixed_interval">{{ $__t('Days after the fill date') }}</option>
							<option value="fraction_elapsed">{{ $__t('Percent of the supply used') }}</option>
						</select>
					</div>
					<div class="form-group col-md-2">
						<label for="refill-rule-parameter">{{ $__t('Value') }}</label>
						<input id="refill-rule-parameter"
							class="form-control"
							type="number"
							step="1">
					</div>
					<div class="form-group col-md-3">
						<button id="refill-rule-save"
							class="btn btn-outline-primary"
							type="submit">{{ $__t('Save rule') }}</button>
					</div>
				</form>
				<small id="refill-rule-help"
					class="form-text text-muted mb-3"></small>

				<h4 class="h5 mt-4">{{ $__t('Reorder date you choose') }}</h4>
				<form id="refill-date-form"
					class="form-row align-items-end"
					novalidate>
					<div class="form-group col-md-3">
						<label for="refill-date-input">{{ $__t('Reorder date') }}</label>
						<input id="refill-date-input"
							class="form-control"
							type="date"
							aria-describedby="refill-date-help">
					</div>
					<div class="form-group col-md-5">
						<button id="refill-date-set"
							class="btn btn-outline-primary"
							type="submit">{{ $__t('Set date') }}</button>
						<button id="refill-date-clear"
							class="btn btn-outline-secondary"
							type="button">{{ $__t('Remove date') }}</button>
					</div>
				</form>
				<small id="refill-date-help"
					class="form-text text-muted mb-3">{{ $__t('This date belongs to the current fill. It stops applying when a newer fill is recorded and does not apply again if that fill is voided.') }}</small>

				<h4 class="h5 mt-4">{{ $__t('Advance warning for this prescription') }}</h4>
				<form id="refill-lead-form"
					class="form-row align-items-end"
					novalidate>
					<div class="form-group col-md-3">
						<label for="refill-lead-input">{{ $__t('Days of advance warning') }}</label>
						<input id="refill-lead-input"
							class="form-control"
							type="number"
							min="0"
							max="60"
							step="1">
					</div>
					<div class="form-group col-md-5">
						<button id="refill-lead-save"
							class="btn btn-outline-primary"
							type="submit">{{ $__t('Save') }}</button>
						<button id="refill-lead-clear"
							class="btn btn-outline-secondary"
							type="button">{{ $__t('Use my setting') }}</button>
					</div>
				</form>

				<h4 class="h5 mt-4">{{ $__t('Order') }}</h4>
				<form id="refill-order-form"
					class="form-row align-items-end"
					novalidate>
					<div class="form-group col-md-3">
						<label for="refill-order-date">{{ $__t('Ordered on') }}</label>
						<input id="refill-order-date"
							class="form-control"
							type="date"
							required>
					</div>
					<div class="form-group col-md-5">
						<button id="refill-order-record"
							class="btn btn-outline-primary"
							type="submit">{{ $__t('Record order') }}</button>
						<button id="refill-order-cancel"
							class="btn btn-outline-secondary d-none"
							type="button">{{ $__t('Cancel order') }}</button>
					</div>
				</form>
				<small class="form-text text-muted mb-3">{{ $__t('An order records that you asked the pharmacy. It adds no stock and does not change the fill history.') }}</small>
			</div>

			<h4 class="h5 mt-4">{{ $__t('Fills') }}</h4>
			<table class="table table-sm">
				<caption class="sr-only">{{ $__t('Recorded fills, newest first') }}</caption>
				<thead>
					<tr>
						<th scope="col">{{ $__t('Filled on') }}</th>
						<th scope="col">{{ $__t('Days supplied') }}</th>
						<th scope="col">{{ $__t('Note') }}</th>
						<th scope="col">{{ $__t('State') }}</th>
						<th scope="col">{{ $__t('Actions') }}</th>
					</tr>
				</thead>
				<tbody id="refill-fills-rows"></tbody>
			</table>
			<p id="refill-fills-empty"
				class="text-muted d-none">{{ $__t('No fills recorded.') }}</p>

			<h4 class="h5 mt-4">{{ $__t('Orders') }}</h4>
			<table class="table table-sm">
				<caption class="sr-only">{{ $__t('Recorded orders, newest first') }}</caption>
				<thead>
					<tr>
						<th scope="col">{{ $__t('Ordered on') }}</th>
						<th scope="col">{{ $__t('State') }}</th>
					</tr>
				</thead>
				<tbody id="refill-orders-rows"></tbody>
			</table>
			<p id="refill-orders-empty"
				class="text-muted d-none">{{ $__t('No orders recorded.') }}</p>
		</section>
	</div>
</div>

<div class="modal fade"
	id="refill-void-modal"
	tabindex="-1"
	role="dialog"
	aria-labelledby="refill-void-title">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<h4 id="refill-void-title"
					class="modal-title"></h4>
			</div>
			<div class="modal-body">
				<p>{{ $__t('A voided fill stays in the history. The previous fill becomes the current one and the estimate is calculated again.') }}</p>
				<div class="form-group">
					<label for="refill-void-reason">{{ $__t('Reason') }}</label>
					<input id="refill-void-reason"
						class="form-control"
						type="text"
						maxlength="500">
				</div>
				<p id="refill-void-error"
					class="text-danger"
					role="alert"></p>
			</div>
			<div class="modal-footer">
				<button id="refill-void-confirm"
					class="btn btn-primary"
					type="button">{{ $__t('Void this fill') }}</button>
				<button class="btn btn-secondary"
					type="button"
					data-dismiss="modal">{{ $__t('Cancel') }}</button>
			</div>
		</div>
	</div>
</div>
@stop
