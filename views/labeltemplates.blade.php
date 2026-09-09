@extends('layout.default')

@section('title', $__t('Label templates'))
@section('viewJsName', 'labeltemplates')

@section('content')
<div class="row">
	<div class="col">
		<h1>{{ $__t('Label templates') }}</h1>
		<p class="text-muted">
			{{ $__t('A template is a design. Printing pins a published version of it, so a change here affects new labels and never one that has already been queued or printed.') }}
		</p>

		<div class="form-inline mb-3">
			<input type="text"
				class="form-control mr-2"
				id="new-template-name"
				placeholder="{{ $__t('Name') }}">
			<button class="btn btn-success"
				id="create-template-button">
				<i class="fa-solid fa-plus"></i>&nbsp;{{ $__t('Create a location label template') }}
			</button>
		</div>

		<div id="label-templates-message"
			role="status"
			aria-live="polite"></div>

		<table class="table table-sm table-striped w-100">
			<thead>
				<tr>
					<th></th>
					<th>{{ $__t('Name') }}</th>
					<th>{{ $__t('Entity kind') }}</th>
					<th>{{ $__t('Published versions') }}</th>
					<th>{{ $__t('Default version') }}</th>
				</tr>
			</thead>
			<tbody id="label-templates-rows">
				@foreach($templates as $template)
				<tr @if($template['archived_at'] !== null) class="text-muted" @endif>
					<td class="fit-content">
						<a class="btn btn-info btn-sm"
							href="{{ $U('/labeltemplate/') }}{{ $template['id'] }}">
							<i class="fa-solid fa-edit"></i>&nbsp;{{ $__t('Design') }}
						</a>
					</td>
					<td>{{ $template['name'] }}</td>
					<td>{{ $template['entity_kind'] }}</td>
					<td>{{ $template['version_count'] }}</td>
					<td>
						@if($template['default_version'] === null)
						<span class="text-warning">{{ $__t('None published yet') }}</span>
						@else
						{{ $template['default_version'] }}
						@endif
					</td>
				</tr>
				@endforeach
			</tbody>
		</table>
	</div>
</div>
@stop
