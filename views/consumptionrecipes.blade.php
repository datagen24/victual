@extends('layout.default')

@section('title', $__t('Consumption recipes'))

@section('content')
<div class="row">
	<div class="col">
		<h2 class="title">@yield('title')</h2>
		<p>{{ $__t('A consumption recipe lists product quantities that are consumed together. Only you and the users you share it with can see it. Stock, and every consumption booking, stays visible to everyone who can see stock.') }}</p>
		<button id="consumption-new"
			class="btn btn-primary"
			type="button">{{ $__t('New consumption recipe') }}</button>
		<p id="consumption-message"
			class="mt-2"
			role="status"></p>
		<table id="consumption-table"
			class="table table-sm table-striped nowrap w-100">
			<thead>
				<tr>
					<th>{{ $__t('Name') }}</th>
					<th>{{ $__t('Lines') }}</th>
					<th>{{ $__t('Your rights') }}</th>
					<th>{{ $__t('Actions') }}</th>
				</tr>
			</thead>
			<tbody id="consumption-rows"></tbody>
		</table>
	</div>
</div>

<div class="modal fade"
	id="consumption-edit-modal"
	tabindex="-1">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header">
				<h4 id="consumption-edit-title"
					class="modal-title"></h4>
			</div>
			<div class="modal-body">
				<div class="form-group">
					<label for="consumption-name">{{ $__t('Name') }}</label>
					<input id="consumption-name"
						class="form-control"
						type="text"
						maxlength="200">
				</div>
				<div class="form-group">
					<label for="consumption-note">{{ $__t('Note') }}</label>
					<textarea id="consumption-note"
						class="form-control"
						rows="2"
						maxlength="2000"></textarea>
				</div>
				<h5>{{ $__t('Lines') }}</h5>
				<p class="text-muted">{{ $__t('Enter each quantity in a unit that has a conversion to the stock unit of its product.') }}</p>
				<div id="consumption-lines"></div>
				<button id="consumption-add-line"
					class="btn btn-sm btn-outline-secondary mt-2"
					type="button">{{ $__t('Add line') }}</button>
				<p id="consumption-edit-error"
					class="mt-2"
					role="alert"></p>
			</div>
			<div class="modal-footer">
				<button id="consumption-save"
					class="btn btn-success"
					type="button">{{ $__t('Save') }}</button>
				<button class="btn btn-secondary"
					type="button"
					data-dismiss="modal">{{ $__t('Cancel') }}</button>
			</div>
		</div>
	</div>
</div>

<div class="modal fade"
	id="consumption-consume-modal"
	tabindex="-1">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<h4 id="consumption-consume-title"
					class="modal-title"></h4>
			</div>
			<div class="modal-body">
				<ul id="consumption-consume-lines"></ul>
				<div class="form-group">
					<label for="consumption-location">{{ $__t('Take from') }}</label>
					<select id="consumption-location"
						class="form-control"></select>
					<small class="form-text text-muted">{{ $__t('When a location is chosen, only stock at that location is used.') }}</small>
				</div>
				<p id="consumption-consume-error"
					role="alert"></p>
			</div>
			<div class="modal-footer">
				<button id="consumption-consume"
					class="btn btn-success"
					type="button">{{ $__t('Record consumption') }}</button>
				<button class="btn btn-secondary"
					type="button"
					data-dismiss="modal">{{ $__t('Cancel') }}</button>
			</div>
		</div>
	</div>
</div>

<div class="modal fade"
	id="consumption-share-modal"
	tabindex="-1">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header">
				<h4 id="consumption-share-title"
					class="modal-title"></h4>
			</div>
			<div class="modal-body">
				<p>{{ $__t('A share lets a user see this recipe and use the rights you give. It does not change what that user may do elsewhere.') }}</p>
				<table class="table table-sm">
					<thead>
						<tr>
							<th>{{ $__t('User') }}</th>
							<th>{{ $__t('Record consumption') }}</th>
							<th>{{ $__t('Edit') }}</th>
							<th>{{ $__t('Undo') }}</th>
							<th>{{ $__t('Share') }}</th>
							<th>{{ $__t('Actions') }}</th>
						</tr>
					</thead>
					<tbody id="consumption-share-rows"></tbody>
				</table>
				<h5>{{ $__t('Share with a user') }}</h5>
				<div class="form-inline">
					<input id="consumption-share-username"
						class="form-control mr-2"
						type="text"
						placeholder="{{ $__t('Username') }}">
					<label class="mr-2"><input id="consumption-share-consume"
							type="checkbox"> {{ $__t('Record consumption') }}</label>
					<label class="mr-2"><input id="consumption-share-edit"
							type="checkbox"> {{ $__t('Edit') }}</label>
					<label class="mr-2"><input id="consumption-share-undo"
							type="checkbox"> {{ $__t('Undo') }}</label>
					<label class="mr-2"><input id="consumption-share-share"
							type="checkbox"> {{ $__t('Share') }}</label>
					<button id="consumption-share-add"
						class="btn btn-primary"
						type="button">{{ $__t('Share') }}</button>
				</div>
				<p id="consumption-share-error"
					class="mt-2"
					role="alert"></p>
			</div>
			<div class="modal-footer">
				<button class="btn btn-secondary"
					type="button"
					data-dismiss="modal">{{ $__t('Close') }}</button>
			</div>
		</div>
	</div>
</div>

<div class="modal fade"
	id="consumption-history-modal"
	tabindex="-1">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header">
				<h4 id="consumption-history-title"
					class="modal-title"></h4>
			</div>
			<div class="modal-body">
				<p>{{ $__t('Your own recorded consumptions of this recipe. What each one booked does not change when the recipe changes.') }}</p>
				<table class="table table-sm">
					<thead>
						<tr>
							<th>{{ $__t('Time') }}</th>
							<th>{{ $__t('Booked') }}</th>
							<th>{{ $__t('State') }}</th>
							<th>{{ $__t('Actions') }}</th>
						</tr>
					</thead>
					<tbody id="consumption-history-rows"></tbody>
				</table>
				<p id="consumption-history-error"
					role="alert"></p>
			</div>
			<div class="modal-footer">
				<button class="btn btn-secondary"
					type="button"
					data-dismiss="modal">{{ $__t('Close') }}</button>
			</div>
		</div>
	</div>
</div>
@stop
