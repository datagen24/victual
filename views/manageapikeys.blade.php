@php require_frontend_packages(['datatables', 'bwipjs']); @endphp

@extends('layout.default')

@section('title', $__t('API keys'))

@section('content')
<div class="row">
	<div class="col">
		<div class="title-related-links">
			<h2 class="title">@yield('title')</h2>
			<div class="float-right @if($embedded) pr-5 @endif">
				<button class="btn btn-outline-dark d-md-none mt-2 order-1 order-md-3"
					type="button"
					data-toggle="collapse"
					data-target="#table-filter-row">
					<i class="fa-solid fa-filter"></i>
				</button>
				<button class="btn btn-outline-dark d-md-none mt-2 order-1 order-md-3"
					type="button"
					data-toggle="collapse"
					data-target="#related-links">
					<i class="fa-solid fa-ellipsis-v"></i>
				</button>
			</div>
			<div class="related-links collapse d-md-flex order-2 width-xs-sm-100"
				id="related-links">
				<a id="add-api-key-button"
					class="btn btn-primary responsive-button m-1 mt-md-0 mb-md-0 float-right"
					href="#">
					{{ $__t('Add') }}
				</a>
			</div>
		</div>
	</div>
</div>

@if(!empty($newApiKey))
{{-- The only moment this string exists. api_keys.api_key holds a SHA-256 hash (plan 11,
     question 4), so nothing - not this page on its next load, not an administrator, not a
     database dump - can produce it again. Hence the wording and the QR code here rather
     than a row action in the table below. --}}
<div class="row mt-2">
	<div class="col">
		<div class="alert alert-success">
			<h4>{{ $__t('Your new API key') }}</h4>
			@if(!empty($newApiKeyDescription))
			<h5 class="text-muted">{{ $newApiKeyDescription }}</h5>
			@endif
			@if(!empty($rotatedFromId))
			{{-- This request was a rotation (issue #130), not a plain "add" - the successor is
			     live now, and the predecessor keeps authenticating until it is explicitly
			     retired. Said here rather than assumed, since retiring it is a separate action
			     on its own row and easy to forget once the new value is copied. --}}
			<p>{{ $__t('This key replaces the one you rotated. It keeps working alongside its predecessor until you delete that key from the table below.') }}</p>
			@endif
			@if($newApiKeyType === \Victual\Services\ApiKeyService::API_KEY_TYPE_MCP)
			{{-- Issue #208. An MCP client presents the key as a bearer token to the sidecar,
			     not in Victual's own header, and that is the one thing about it people get
			     wrong when configuring a client. --}}
			<p>{{ $__t('This is an MCP key: give it to your assistant\'s MCP client as the header "Authorization: Bearer <key>". It works on Victual\'s API as well, as you.') }}</p>
			@endif
			<p>{{ $__t('Copy it now - it cannot be shown again') }}</p>
			<pre class="user-select-all mb-2"><code id="new-api-key-value">{{ $newApiKey }}</code></pre>
			{{-- The description carried here is the one just typed, so that the QR dialog says
			     which key it is showing. It is user input reaching a bootbox message, which
			     renders as HTML - manageapikeys.js escapes it at the point of use, and the
			     s29-payload probe's manageapikeys-qr case is what holds that true. --}}
			<a class="btn btn-info btn-sm apikey-show-qr-button"
				href="#"
				data-apikey-key="{{ $newApiKey }}"
				data-apikey-type="{{ $newApiKeyType }}"
				data-apikey-description="{{ empty($newApiKeyDescription) ? $__t('Your new API key') : $newApiKeyDescription }}">
				<i class="fa-solid fa-qrcode"></i>&nbsp;{{ $__t('Show a QR-Code for this API key') }}
			</a>
		</div>
	</div>
</div>
@endif

<hr class="my-2">

<div class="row collapse d-md-flex"
	id="table-filter-row">
	<div class="col-12 col-md-6 col-xl-3">
		<div class="input-group">
			<div class="input-group-prepend">
				<span class="input-group-text"><i class="fa-solid fa-search"></i></span>
			</div>
			<input type="text"
				id="search"
				class="form-control"
				placeholder="{{ $__t('Search') }}">
		</div>
	</div>
	<div class="col">
		<div class="float-right">
			<button id="clear-filter-button"
				class="btn btn-sm btn-outline-info"
				data-toggle="tooltip"
				title="{{ $__t('Clear filter') }}">
				<i class="fa-solid fa-filter-circle-xmark"></i>
			</button>
		</div>
	</div>
</div>

<div class="row">
	<div class="col">
		<table id="apikeys-table"
			class="table table-sm table-striped nowrap w-100">
			<thead>
				<tr>
					<th class="border-right"><a class="text-muted change-table-columns-visibility-button"
							data-toggle="tooltip"
							title="{{ $__t('Table options') }}"
							data-table-selector="#apikeys-table"
							href="#"><i class="fa-solid fa-eye"></i></a>
					</th>
					<th>{{ $__t('Description') }}</th>
					<th>{{ $__t('API key') }}</th>
					<th class="allow-grouping">{{ $__t('User') }}</th>
					<th>{{ $__t('Expires') }}</th>
					<th>{{ $__t('Last used') }}</th>
					<th>{{ $__t('Created') }}</th>
					<th class="allow-grouping">{{ $__t('Key type') }}</th>
					<th class="allow-grouping">{{ $__t('Read-only') }}</th>
				</tr>
			</thead>
			<tbody class="d-none">
				@foreach($apiKeys as $apiKey)
				<tr class="@if($apiKey->id == $selectedKeyId) table-info @endif">
					<td class="fit-content border-right">
						<a class="btn btn-danger btn-sm apikey-delete-button"
							href="#"
							data-apikey-id="{{ $apiKey->id }}"
							data-apikey-description="{{ $apiKey->description }}"
							{{-- What the delete confirmation names the key: its description when it has
							one, its hint otherwise. Resolved here rather than in the view script so the
							shared delete confirmation can read one attribute like every other list
							does. It used to be the key itself, which is no longer readable. --}}
							data-apikey-name="{{ empty($apiKey->description) ? ApiKeyDisplayValue($apiKey) : $apiKey->description }}"
							data-toggle="tooltip"
							title="{{ $__t('Delete this item') }}">
							<i class="fa-solid fa-trash"></i>
						</a>
						@if(in_array($apiKey->key_type, \Victual\Services\ApiKeyService::USER_ISSUED_KEY_TYPES, true))
						{{-- Rotation (issue #130) is offered for the user-issued types only, regular
						and MCP (issue #208) - the
						special-purpose types (calendar, label worker/verifier/renderer) each already
						have their own rotation story and this must not add a second, conflicting one.
						Creates a successor only; retiring this row stays the "Delete" button above. --}}
						<a class="btn btn-secondary btn-sm apikey-rotate-button"
							href="#"
							data-apikey-id="{{ $apiKey->id }}"
							data-apikey-description="{{ $apiKey->description }}"
							data-apikey-name="{{ empty($apiKey->description) ? ApiKeyDisplayValue($apiKey) : $apiKey->description }}"
							data-toggle="tooltip"
							title="{{ $__t('Rotate this API key') }}">
							<i class="fa-solid fa-rotate"></i>
						</a>
						@endif
						@if(ApiKeyIsReadable($apiKey))
						{{-- Only a special-purpose key can still be shown: it is stored as issued,
						because the sharing dialog has to hand its URL back. A regular key is a hash
						here and there is nothing to encode. --}}
						<a class="btn btn-info btn-sm apikey-show-qr-button"
							href="#"
							data-apikey-key="{{ $apiKey->api_key }}"
							data-apikey-type="{{ $apiKey->key_type }}"
							data-apikey-description="{{ $apiKey->description }}"
							data-toggle="tooltip"
							title="{{ $__t('Show a QR-Code for this API key') }}">
							<i class="fa-solid fa-qrcode"></i>
						</a>
						@endif
					</td>
					<td>
						{{ $apiKey->description }}
					</td>
					<td>
						{{ ApiKeyDisplayValue($apiKey) }}
					</td>
					<td>
						{{ GetUserDisplayName(FindObjectInArrayByPropertyValue($users, 'id', $apiKey->user_id)) }}
					</td>
					<td>
						{{ $apiKey->expires }}
						<time class="timeago timeago-contextual"
							datetime="{{ $apiKey->expires }}"></time>
					</td>
					<td>
						@if(empty($apiKey->last_used)){{ $__t('never') }}@else{{ $apiKey->last_used }}@endif
						<time class="timeago timeago-contextual"
							datetime="{{ $apiKey->last_used }}"></time>
					</td>
					<td>
						{{ $apiKey->row_created_timestamp }}
						<time class="timeago timeago-contextual"
							datetime="{{ $apiKey->row_created_timestamp }}"></time>
					</td>
					<td>
						{{ $apiKey->key_type }}
					</td>
					<td>
						@if($apiKey->read_only == 1){{ $__t('Yes') }}@else{{ $__t('No') }}@endif
					</td>
				</tr>
				@endforeach
			</tbody>
		</table>
	</div>
</div>

<div class="modal fade"
	id="add-api-key-modal"
	tabindex="-1">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title w-100">{{ $__t('Create new API key') }}</h4>
			</div>
			<div class="modal-body">
				<div class="form-group">
					<label for="name">{{ $__t('Description') }}</label>
					<input type="text"
						class="form-control"
						id="description"
						name="description">
				</div>
				<div class="form-group">
					<label for="key_type">{{ $__t('Key type') }}</label>
					<select class="custom-control custom-select"
						id="key_type"
						name="key_type">
						<option value="default">{{ $__t('Regular') }}</option>
						<option value="mcp">{{ $__t('MCP (for an AI assistant)') }}</option>
					</select>
				</div>
				{{-- Offered for an MCP key only, and on by default there (issue #208): the
				     assistant's tools are read-only today, so a key that can do no more is the
				     sensible default, and the restriction is enforced by Victual, not by the
				     sidecar. --}}
				<div class="form-group d-none"
					id="read_only_group">
					<div class="custom-control custom-checkbox">
						<input type="checkbox"
							class="form-check-input custom-control-input"
							id="read_only"
							name="read_only"
							value="1"
							checked>
						<label class="form-check-label custom-control-label"
							for="read_only">{{ $__t('Read-only') }}
							&nbsp;<i class="fa-solid fa-question-circle text-muted"
								data-toggle="tooltip"
								data-trigger="hover click"
								title="{{ $__t('A read-only key can look things up but cannot change anything: Victual refuses every other request made with it') }}"></i>
						</label>
					</div>
				</div>
				<div class="form-group">
					<label for="expires_in_days">{{ $__t('Expires in (days)') }}</label>
					<input type="number"
						class="form-control"
						id="expires_in_days"
						name="expires_in_days"
						min="1"
						max="{{ $maxLifetimeDays }}"
						value="{{ $maxLifetimeDays }}">
				</div>
			</div>
			<div class="modal-footer">
				<button type="button"
					class="btn btn-secondary"
					data-dismiss="modal">{{ $__t('Cancel') }}</button>
				<button id="new-api-key-button"
					class="btn btn-primary">{{ $__t('OK') }}</button>
			</div>
		</div>
	</div>
</div>
@stop
