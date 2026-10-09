@extends('layout.default')
@section('title', $__t('MCP settings'))
@section('content')
<h2>@yield('title')</h2>
<p>{{ $__t('Choose which tools an assistant connected through the MCP server can use. A change applies to the next request, with no restart.') }}</p>
<p>
	{{ $__t('Turning a tool on only lists it. An API key marked read-only can never run a tool that writes, and every call is checked against the permissions of the key\'s user.') }}
	<a href="{{ $U('/manageapikeys') }}">{{ $__t('Manage API keys') }}</a>
</p>
@php
	// Literal $__t() calls, one per tool, so each string is findable by the localization tooling.
	$toolDescriptions = [
		'stock_overview' => $__t('Lists what is in stock now, soonest due first.'),
		'expiring_soon' => $__t('Lists products that are due, overdue or expired.'),
		'missing_products' => $__t('Lists products below their minimum stock.'),
		'find_product' => $__t('Finds a product by name.'),
		'shopping_list' => $__t('Reads a shopping list.'),
		'recipes_i_can_cook' => $__t('Lists the recipes the stock can fulfil.'),
		'add_to_shopping_list' => $__t('Adds a product to a shopping list.'),
		'consume_product' => $__t('Books a consumption from stock.'),
		'purchase_product' => $__t('Books a purchase into stock.'),
	];
@endphp
<p id="mcp-settings-message" role="status"></p>
<div class="row">
	<div class="col-lg-8">
		<table class="table table-striped">
			<thead>
				<tr>
					<th>{{ $__t('Tool') }}</th>
					<th>{{ $__t('What it does') }}</th>
					<th>{{ $__t('Enabled') }}</th>
				</tr>
			</thead>
			<tbody>
				@foreach($tools as $tool => $enabled)
				<tr>
					<td>
						<code>{{ $tool }}</code>
						@if(in_array($tool, $writeTools, true))
						<span class="badge badge-warning">{{ $__t('Writes data') }}</span>
						@else
						<span class="badge badge-secondary">{{ $__t('Read only') }}</span>
						@endif
					</td>
					<td>{{ $toolDescriptions[$tool] ?? '' }}</td>
					<td>
						<div class="custom-control custom-switch">
							<input type="checkbox"
								class="custom-control-input mcp-tool-switch"
								id="mcp-tool-{{ $tool }}"
								data-tool="{{ $tool }}"
								@if($enabled) checked @endif>
							<label class="custom-control-label"
								for="mcp-tool-{{ $tool }}"><span class="sr-only">{{ $tool }}</span></label>
						</div>
					</td>
				</tr>
				@endforeach
			</tbody>
		</table>
	</div>
</div>
@stop
