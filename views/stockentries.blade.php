@php require_frontend_packages(['datatables', 'animatecss']); @endphp

@extends('layout.default')

@section('title', $__t('Stock entries'))

@section('content')
<div class="row">
	<div class="col">
		<h2 class="title">@yield('title')</h2>
		<div class="float-right @if($embedded) pr-5 @endif">
			<button class="btn btn-outline-dark d-md-none mt-2 order-1 order-md-3"
				type="button"
				data-toggle="collapse"
				data-target="#table-filter-row">
				<i class="fa-solid fa-filter"></i>
			</button>
		</div>
	</div>
</div>

<hr class="my-2">

<div class="row collapse d-md-flex"
	id="table-filter-row">
	<div class="col-12 col-md-6 col-xl-3 hide-when-embedded">
		@include('components.productpicker', array(
		'products' => $products,
		'disallowAllProductWorkflows' => true,
		'isRequired' => false,
		'additionalGroupCssClasses' => 'mb-0'
		))
	</div>
	@if(VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
	<div class="col-12 col-md-6 col-xl-3 mt-auto">
		<div class="input-group">
			<div class="input-group-prepend">
				<span class="input-group-text"><i class="fa-solid fa-filter"></i>&nbsp;{{ $__t('Location') }}</span>
			</div>
			<select class="custom-control custom-select"
				id="location-filter">
				<option value="all">{{ $__t('All') }}</option>
				@foreach($locations as $location)
				<option value="{{ $location->id }}">{{ $location->path }}</option>
				@endforeach
			</select>
		</div>
	</div>
	@endif
	<div class="col mt-auto">
		<div class="float-right mt-3">
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
		<table id="stockentries-table"
			class="table table-sm table-striped nowrap w-100">
			<thead>
				<tr>
					<th class="border-right"><a class="text-muted change-table-columns-visibility-button"
							data-toggle="tooltip"
							title="{{ $__t('Table options') }}"
							data-table-selector="#stockentries-table"
							href="#"><i class="fa-solid fa-eye"></i></a>
					</th>
					<th class="d-none">Hidden product_id</th>
					<th class="allow-grouping">{{ $__t('Product') }}</th>
					<th>{{ $__t('Amount') }}</th>
					<th class="@if(!VICTUAL_FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_TRACKING) d-none @endif allow-grouping">{{ $__t('Due date') }}</th>
					<th class="@if(!VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING) d-none @endif allow-grouping">{{ $__t('Location') }}</th>
					<th class="@if(!VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING) d-none @endif allow-grouping">{{ $__t('Store') }}</th>
					<th class="@if(!VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING) d-none @endif">{{ $__t('Price') }}</th>
					<th class="allow-grouping"
						data-shadow-rowgroup-column="9">{{ $__t('Purchased date') }}</th>
					<th class="d-none">Hidden purchased_date</th>
					<th>{{ $__t('Timestamp') }}</th>
					<th>{{ $__t('Note') }}</th>

					@include('components.userfields_thead', array(
					'userfields' => $userfieldsProducts
					))

					@include('components.userfields_thead', array(
					'userfields' => $userfieldsStock
					))
				</tr>
			</thead>
			<tbody class="d-none">
				@foreach($stockEntries as $stockEntry)
				<tr id="stock-{{ $stockEntry->id }}-row"
					data-due-type="{{ FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->due_type }}"
					class="@if(VICTUAL_FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_TRACKING && $stockEntry->best_before_date < date('Y-m-d 23:59:59', strtotime('-1 days')) && $stockEntry->amount > 0) @if(FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->due_type == 1) table-secondary @else table-danger @endif @elseif(VICTUAL_FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_TRACKING && $stockEntry->best_before_date < date('Y-m-d 23:59:59', strtotime('+' . $nextXDays . ' days'))
					&&
					$stockEntry->amount > 0) table-warning @endif">
					<td class="fit-content border-right">
						<a class="btn btn-danger btn-sm stock-consume-button"
							href="#"
							data-toggle="tooltip"
							data-placement="left"
							title="{{ $__t('Consume this stock entry') }}"
							data-product-id="{{ $stockEntry->product_id }}"
							data-stock-id="{{ $stockEntry->stock_id }}"
							data-stockrow-id="{{ $stockEntry->id }}"
							data-location-id="{{ $stockEntry->location_id }}"
							data-product-name="{{ FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->name }}"
							data-product-qu-name="{{ FindObjectInArrayByPropertyValue($quantityunits, 'id', FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->qu_id_stock)->name }}"
							data-consume-amount="{{ $stockEntry->amount }}">
							<i class="fa-solid fa-utensils"></i>
						</a>
						@if(VICTUAL_FEATURE_FLAG_STOCK_PRODUCT_OPENED_TRACKING)
						{{-- Tare-enabled products can be opened directly since ADR-0022 decision 8
						removed OpenProduct()'s refusal - the per-entry measurement below
						supersedes what that mechanism stood in for. --}}
						<a class="btn btn-success btn-sm product-open-button @if($stockEntry->open == 1 || FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->disable_open == 1) disabled @endif"
							href="#"
							data-toggle="tooltip"
							data-placement="left"
							title="{{ $__t('Mark this stock entry as open') }}"
							data-product-id="{{ $stockEntry->product_id }}"
							data-product-name="{{ FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->name }}"
							data-product-qu-name="{{ FindObjectInArrayByPropertyValue($quantityunits, 'id', FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->qu_id_stock)->name }}"
							data-stock-id="{{ $stockEntry->stock_id }}"
							data-stockrow-id="{{ $stockEntry->id }}"
							data-open-amount="{{ $stockEntry->amount }}">
							<i class="fa-solid fa-box-open"></i>
						</a>
						{{-- A measurement describes exactly one container (ADR-0022 decision 8), so
						this is only offered where the entry already is - or opening would leave -
						a single unit: round() mirrors the coherence CHECK's own amount = 1 test. --}}
						@if(round($stockEntry->amount, 2) == 1.0 && FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->disable_open != 1)
						<a class="btn btn-outline-primary btn-sm stock-measure-button"
							href="#"
							data-toggle="tooltip"
							data-placement="left"
							title="{{ $stockEntry->open == 1 ? $__t('Record a measurement of what is left') : $__t('Open and record a measurement') }}"
							data-mode="{{ $stockEntry->open == 1 ? 'remeasure' : 'open' }}"
							data-product-id="{{ $stockEntry->product_id }}"
							data-product-name="{{ FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->name }}"
							data-product-qu-id="{{ FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->qu_id_stock }}"
							data-product-qu-name="{{ FindObjectInArrayByPropertyValue($quantityunits, 'id', FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->qu_id_stock)->name }}"
							data-stock-id="{{ $stockEntry->stock_id }}"
							data-stockrow-id="{{ $stockEntry->id }}">
							<i class="fa-solid fa-weight-scale"></i>
						</a>
						@endif
						@endif
						<a class="btn btn-info btn-sm show-as-dialog-link"
							href="{{ $U('/stockentry/' . $stockEntry->id . '?embedded') }}"
							data-toggle="tooltip"
							data-placement="left"
							title="{{ $__t('Edit stock entry') }}">
							<i class="fa-solid fa-edit"></i>
						</a>
						<div class="dropdown d-inline-block">
							<button class="btn btn-sm btn-light text-secondary"
								type="button"
								data-toggle="dropdown">
								<i class="fa-solid fa-ellipsis-v"></i>
							</button>
							<div class="dropdown-menu">
								@if(VICTUAL_FEATURE_FLAG_SHOPPINGLIST)
								<a class="dropdown-item show-as-dialog-link"
									type="button"
									href="{{ $U('/shoppinglistitem/new?embedded&updateexistingproduct&list=1&product=' . $stockEntry->product_id ) }}">
									<i class="fa-solid fa-shopping-cart"></i> {{ $__t('Add to shopping list') }}
								</a>
								<div class="dropdown-divider"></div>
								@endif
								<a class="dropdown-item show-as-dialog-link"
									type="button"
									href="{{ $U('/purchase?embedded&product=' . $stockEntry->product_id ) }}">
									<i class="fa-solid fa-cart-plus"></i> {{ $__t('Purchase') }}
								</a>
								<a class="dropdown-item show-as-dialog-link"
									type="button"
									href="{{ $U('/consume?embedded&product=' . $stockEntry->product_id . '&locationId=' . $stockEntry->location_id . '&stockId=' . $stockEntry->stock_id) }}">
									<i class="fa-solid fa-utensils"></i> {{ $__t('Consume') }}
								</a>
								@if(VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
								<a class="dropdown-item show-as-dialog-link"
									type="button"
									href="{{ $U('/transfer?embedded&product=' . $stockEntry->product_id . '&locationId=' . $stockEntry->location_id . '&stockId=' . $stockEntry->stock_id) }}">
									<i class="fa-solid fa-exchange-alt"></i> {{ $__t('Transfer') }}
								</a>
								@endif
								<a class="dropdown-item show-as-dialog-link"
									type="button"
									href="{{ $U('/inventory?embedded&product=' . $stockEntry->product_id ) }}">
									<i class="fa-solid fa-list"></i> {{ $__t('Inventory') }}
								</a>
								<a class="dropdown-item stock-consume-button stock-consume-button-spoiled"
									type="button"
									href="#"
									data-product-id="{{ $stockEntry->product_id }}"
									data-product-name="{{ FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->name }}"
									data-product-qu-name="{{ FindObjectInArrayByPropertyValue($quantityunits, 'id', FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->qu_id_stock)->name }}"
									data-stock-id="{{ $stockEntry->stock_id }}"
									data-stockrow-id="{{ $stockEntry->id }}"
									data-location-id="{{ $stockEntry->location_id }}"
									data-consume-amount="{{ $stockEntry->amount }}">
									{{ $__t('Consume this stock entry as spoiled', '1 ' . FindObjectInArrayByPropertyValue($quantityunits, 'id', FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->qu_id_stock)->name, FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->name) }}
								</a>
								@if(VICTUAL_FEATURE_FLAG_RECIPES)
								<div class="dropdown-divider"></div>
								<a class="dropdown-item"
									type="button"
									href="{{ $U('/recipes?search=') }}{{ FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->name }}">
									{{ $__t('Search for recipes containing this product') }}
								</a>
								@endif
								<div class="dropdown-divider"></div>
								<a class="dropdown-item productcard-trigger"
									data-product-id="{{ $stockEntry->product_id }}"
									type="button"
									href="#">
									{{ $__t('Product overview') }}
								</a>
								<a class="dropdown-item show-as-dialog-link"
									type="button"
									href="{{ $U('/stockjournal?embedded&product=') }}{{ $stockEntry->product_id }}"
									data-dialog-type="table">
									{{ $__t('Stock journal') }}
								</a>
								<a class="dropdown-item show-as-dialog-link"
									type="button"
									href="{{ $U('/stockjournal/summary?embedded&product=') }}{{ $stockEntry->product_id }}"
									data-dialog-type="table">
									{{ $__t('Stock journal summary') }}
								</a>
								<a class="dropdown-item link-return"
									type="button"
									data-href="{{ $U('/product/') }}{{ $stockEntry->product_id }}">
									{{ $__t('Edit product') }}
								</a>
								<div class="dropdown-divider"></div>
								<a class="dropdown-item"
									type="button"
									href="{{ $U('/stockentry/' . $stockEntry->id . '/grocycode?download=true') }}">
									{!! str_replace('Grocycode', '<span class="ls-n1">Grocycode</span>', $__t('Download %s Grocycode', $__t('Stock entry'))) !!}
								</a>
								@if(VICTUAL_FEATURE_FLAG_LABEL_PRINTER)
								<a class="dropdown-item stockentry-grocycode-label-print"
									data-stock-id="{{ $stockEntry->id }}"
									type="button"
									href="#">
									{!! str_replace('Grocycode', '<span class="ls-n1">Grocycode</span>', $__t('Print %s Grocycode on label printer', $__t('Stock entry'))) !!}
								</a>
								@endif
								<a class="dropdown-item stockentry-label-link"
									type="button"
									target="_blank"
									href="{{ $U('/stockentry/' . $stockEntry->id . '/label') }}">
									{{ $__t('Open stock entry label in new window') }}
								</a>
							</div>
						</div>
					</td>
					<td class="d-none"
						data-product-id="{{ $stockEntry->product_id }}">
						{{ $stockEntry->product_id }}
					</td>
					<td class="productcard-trigger cursor-link"
						data-product-id="{{ $stockEntry->product_id }}">
						{{ FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->name }}
					</td>
					<td>
						<span class="custom-sort d-none">{{$stockEntry->amount}}</span>
						<span id="stock-{{ $stockEntry->id }}-amount"
							class="locale-number locale-number-quantity-amount">{{ $stockEntry->amount }}</span> <span id="product-{{ $stockEntry->product_id }}-qu-name">{{ $__n($stockEntry->amount, FindObjectInArrayByPropertyValue($quantityunits, 'id', FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->qu_id_stock)->name, FindObjectInArrayByPropertyValue($quantityunits, 'id', FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->qu_id_stock)->name_plural, true) }}</span>
						<span class="small font-italic">
							<span id="stock-{{ $stockEntry->id }}-opened-amount">@if($stockEntry->open == 1) @if($stockEntry->opened_amount !== null) {{ $__t('Opened') }} &ndash; {{ $stockEntry->opened_amount }} {{ $__n($stockEntry->opened_amount, FindObjectInArrayByPropertyValue($quantityunits, 'id', $stockEntry->opened_qu_id)->name, FindObjectInArrayByPropertyValue($quantityunits, 'id', $stockEntry->opened_qu_id)->name_plural, true) }} {{ $__t('left') }} @else {{ $__n($stockEntry->amount, 'Opened', 'Opened') }} @endif @endif</span>
							@if($stockEntry->open == 1 && $stockEntry->opened_amount !== null)
							(<time id="stock-{{ $stockEntry->id }}-opened-measured-at-timeago"
								class="timeago timeago-contextual"
								datetime="{{ $stockEntry->opened_measured_at }}"></time>)
							@endif
						</span>
					</td>
					<td class="@if(!VICTUAL_FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_TRACKING) d-none @endif">
						<span id="stock-{{ $stockEntry->id }}-due-date">{{ $stockEntry->best_before_date }}</span>
						<time id="stock-{{ $stockEntry->id }}-due-date-timeago"
							class="timeago timeago-contextual"
							@if($stockEntry->best_before_date != "") datetime="{{ $stockEntry->best_before_date }} 23:59:59" @endif></time>
					</td>
					{{-- data-location-ancestors is this location's id followed by every
					ancestor's, comma separated. The location filter matches on it rather than
					on the cell text, so selecting "Basement" finds an entry at
					"Basement / StorageRoom / UprightFreezer / Door" and nothing merely named
					like it (plan 08 question 4). --}}
					<td id="stock-{{ $stockEntry->id }}-location"
						class="@if(!VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING) d-none @endif"
						data-location-id="{{ $stockEntry->location_id }}"
						data-location-ancestors="{{ implode(',', $locationAncestors[$stockEntry->location_id] ?? [$stockEntry->location_id]) }}">
						@php $stockEntryLocation = FindObjectInArrayByPropertyValue($locations, 'id', $stockEntry->location_id); @endphp
						{{ $stockEntryLocation === null ? '' : $stockEntryLocation->path }}
					</td>
					<td id="stock-{{ $stockEntry->id }}-shopping-location"
						class="@if(!VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING) d-none @endif"
						data-shopping-location-id="{{ $stockEntry->shopping_location_id }}">
						@if (FindObjectInArrayByPropertyValue($shoppinglocations, 'id', $stockEntry->shopping_location_id) !== null)
						{{ FindObjectInArrayByPropertyValue($shoppinglocations, 'id', $stockEntry->shopping_location_id)->name }}
						@endif
					</td>
					<td class="@if(!VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING) d-none @endif">
						<span class="custom-sort d-none">{{$stockEntry->price}}</span>
						<span id="stock-{{ $stockEntry->id }}-price"
							data-toggle="tooltip"
							data-trigger="hover click"
							data-html="true"
							title="{!! $__t('%1$s per %2$s', '<span class=\'locale-number locale-number-currency\'>' . $stockEntry->price . '</span>', FindObjectInArrayByPropertyValue($quantityunits, 'id', FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->qu_id_stock)->name) !!}">
							{!! $__t('%1$s per %2$s', '<span class="locale-number locale-number-currency">' . $stockEntry->price * $stockEntry->qu_factor_price_to_stock . '</span>', FindObjectInArrayByPropertyValue($quantityunits, 'id', FindObjectInArrayByPropertyValue($products, 'id', $stockEntry->product_id)->qu_id_price)->name) !!}
						</span>
					</td>
					<td>
						<span id="stock-{{ $stockEntry->id }}-purchased-date">{{ $stockEntry->purchased_date }}</span>
						<time id="stock-{{ $stockEntry->id }}-purchased-date-timeago"
							class="timeago timeago-contextual"
							@if(!empty($stockEntry->purchased_date)) datetime="{{ $stockEntry->purchased_date }} 23:59:59" @endif></time>
					</td>
					<td class="d-none">{{ $stockEntry->purchased_date }}</td>
					<td>
						<span>{{ $stockEntry->row_created_timestamp }}</span>
						<time class="timeago timeago-contextual"
							datetime="{{ $stockEntry->row_created_timestamp }}"></time>
					</td>
					<td>
						<span id="stock-{{ $stockEntry->id }}-note">{{ $stockEntry->note }}</span>
					</td>

					@include('components.userfields_tbody', array(
					'userfields' => $userfieldsProducts,
					'userfieldValues' => FindAllObjectsInArrayByPropertyValue($userfieldValuesProducts, 'object_id', $stockEntry->product_id)
					))

					@include('components.userfields_tbody', array(
					'userfields' => $userfieldsStock,
					'userfieldValues' => FindAllObjectsInArrayByPropertyValue($userfieldValuesStock, 'object_id', $stockEntry->stock_id)
					))

				</tr>
				@endforeach
			</tbody>
		</table>
	</div>
</div>

@include('components.productcard', [
'asModal' => true
])

{{-- ADR-0022 / docs/plans/28-open-container-measurement.md. One modal serves two actions,
switched by #stock-measurement-modal-mode: opening a single sealed unit while recording
what it holds, or re-measuring a unit that is already open. Either way the amount typed
here is stock.opened_amount, never stock.amount - the container count never changes. --}}
<div class="modal fade"
	id="stock-measurement-modal"
	tabindex="-1">
	<div class="modal-dialog">
		<div class="modal-content text-center">
			<div class="modal-header d-block">
				<h4 class="modal-title">{{ $__t('Record a measurement') }}</h4>
				<h5 id="stock-measurement-modal-product-name"
					class="text-muted"></h5>
			</div>
			<div class="modal-body">
				<form id="stock-measurement-form"
					novalidate>
					<input type="hidden"
						id="stock-measurement-mode">
					<input type="hidden"
						id="stock-measurement-product-id">
					<input type="hidden"
						id="stock-measurement-stock-id">
					<input type="hidden"
						id="stock-measurement-stockrow-id">
					<div class="form-row">
						<div class="form-group col-8 text-left">
							<label for="stock-measurement-amount">{{ $__t('Amount') }}</label>
							<input type="number"
								step="any"
								min="0"
								class="form-control"
								id="stock-measurement-amount"
								required>
						</div>
						<div class="form-group col-4 text-left">
							<label for="stock-measurement-qu">{{ $__t('Quantity unit') }}</label>
							<select class="custom-select"
								id="stock-measurement-qu">
								@foreach($quantityunits as $qu)
								<option value="{{ $qu->id }}">{{ $qu->name }}</option>
								@endforeach
							</select>
						</div>
					</div>
					<div class="form-group text-left">
						<div class="custom-control custom-checkbox">
							<input type="checkbox"
								class="custom-control-input"
								id="stock-measurement-gross">
							<label class="custom-control-label"
								for="stock-measurement-gross">{{ $__t('This is a gross reading (includes the container)') }}</label>
						</div>
					</div>
					<div class="form-group text-left d-none"
						id="stock-measurement-tare-group">
						<label for="stock-measurement-tare">{{ $__t('Container (tare) weight') }}</label>
						<input type="number"
							step="any"
							min="0"
							class="form-control"
							id="stock-measurement-tare">
					</div>
				</form>
			</div>
			<div class="modal-footer">
				<button id="stock-measurement-skip-button"
					type="button"
					class="btn btn-success mr-auto">{{ $__t('Open without measuring') }}</button>
				<button type="button"
					class="btn btn-secondary"
					data-dismiss="modal">{{ $__t('Cancel') }}</button>
				<button id="stock-measurement-save-button"
					type="button"
					class="btn btn-primary">{{ $__t('OK') }}</button>
			</div>
		</div>
	</div>
</div>
@stop
