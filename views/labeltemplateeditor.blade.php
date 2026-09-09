@php require_frontend_packages(['fabric']); @endphp

@extends('layout.default')

@section('title', $__t('Design a label'))
@section('viewJsName', 'labeltemplateeditor')

@section('content')
<script>
	Victual.LabelTemplate = {
		Id: {{ $template['id'] }},
		EntityKind: '{{ $template['entity_kind'] }}',
		Name: {!! json_encode($template['name']) !!},
		Assets: {!! json_encode($assets) !!},
		Printers: {!! json_encode($printers) !!}
	};
</script>

<div class="row">
	<div class="col">
		<h1>{{ $__t('Design a label') }} &mdash; {{ $template['name'] }}</h1>
	</div>
</div>

<div class="row">
	<div class="col-12 col-lg-4">
		<div class="form-group">
			<label for="element-kind">{{ $__t('Add an element') }}</label>
			<div class="input-group">
				<select class="form-control" id="element-kind">
					<option value="text">{{ $__t('Text') }}</option>
					<option value="qr">{{ $__t('QR code') }}</option>
					<option value="rect">{{ $__t('Rectangle') }}</option>
					<option value="line">{{ $__t('Line') }}</option>
					<option value="image">{{ $__t('Image') }}</option>
				</select>
				<div class="input-group-append">
					<button class="btn btn-outline-secondary" id="add-element-button">
						<i class="fa-solid fa-plus"></i>
					</button>
				</div>
			</div>
		</div>

		{{-- The properties of whatever is selected. Rendered from the document rather than
		from the canvas: the document is the thing being edited, and the canvas is a view of
		it. --}}
		<div id="element-properties"></div>

		<hr>

		<div class="form-group">
			<label for="canvas-width-mm">{{ $__t('Label width (mm)') }}</label>
			<input type="number" step="0.1" min="1" class="form-control" id="canvas-width-mm">
		</div>
		<div class="form-group">
			<label for="canvas-height-mm">{{ $__t('Label length (mm), empty for automatic') }}</label>
			<input type="number" step="0.1" min="1" class="form-control" id="canvas-height-mm">
		</div>
		<div class="form-group">
			<label for="canvas-max-height-mm">{{ $__t('Maximum length (mm)') }}</label>
			<input type="number" step="0.1" min="1" class="form-control" id="canvas-max-height-mm">
			<small class="form-text text-muted">
				{{ $__t('Automatic length is bounded: continuous tape is not an unbounded canvas, so a design that grows past this is refused rather than cropped.') }}
			</small>
		</div>

		<hr>

		<button class="btn btn-secondary" id="save-draft-button">
			<i class="fa-solid fa-floppy-disk"></i>&nbsp;{{ $__t('Save draft') }}
		</button>
		<button class="btn btn-success" id="publish-button">
			<i class="fa-solid fa-upload"></i>&nbsp;{{ $__t('Publish a version') }}
		</button>

		<hr>

		{{-- Fonts and images. A template pins the font it prints with, so there is nowhere
		else for this to live: without an uploaded font there is no text element to add, and
		telling somebody that in the element panel while giving them no way to fix it would be
		a dead end. --}}
		<h5>{{ $__t('Fonts and images') }}</h5>
		<ul class="list-unstyled small" id="asset-list">
			@foreach($assets as $asset)
			<li>
				<i class="fa-solid @if($asset['asset_kind'] == 'font') fa-font @else fa-image @endif"></i>
				{{ $asset['name'] }}
				@if($asset['font_family']) <span class="text-muted">({{ $asset['font_family'] }})</span> @endif
			</li>
			@endforeach
		</ul>
		<div class="form-group">
			<label for="asset-file">{{ $__t('Upload a font (TTF or OTF) or a PNG image') }}</label>
			<input type="file" class="form-control-file" id="asset-file" accept=".ttf,.otf,.png">
		</div>
		<div class="form-group">
			<label for="asset-licence">{{ $__t('Licence it is used under') }}</label>
			<input type="text" class="form-control" id="asset-licence"
				placeholder="{{ $__t('for example, SIL Open Font License 1.1') }}">
			<small class="form-text text-muted">
				{{ $__t('Recorded beside the bytes: a household printing labels with a font is redistributing its output, and what it was allowed to do belongs next to it.') }}
			</small>
		</div>
		<button class="btn btn-secondary" id="upload-asset-button">
			<i class="fa-solid fa-upload"></i>&nbsp;{{ $__t('Upload') }}
		</button>

		<hr>

		<div class="form-group">
			<label for="preview-printer">{{ $__t('Preview against') }}</label>
			<select class="form-control" id="preview-printer">
				@foreach($printers as $printer)
				<option value="{{ $printer['id'] }}" @if($printer['is_default'] == 1) selected @endif>{{ $printer['name'] }}</option>
				@endforeach
			</select>
			<small class="form-text text-muted">
				{{ $__t('A preview is rendered by the same renderer that produces a printed label, against that printer\'s media profile. The canvas beside it is a design aid and not a fidelity claim.') }}
			</small>
		</div>
		<button class="btn btn-info" id="preview-button" @if(count($printers) === 0) disabled @endif>
			<i class="fa-solid fa-eye"></i>&nbsp;{{ $__t('Render a preview') }}
		</button>

		<div id="template-message"
			class="mt-3"
			role="status"
			aria-live="polite"></div>
	</div>

	<div class="col-12 col-lg-8">
		<div class="border d-inline-block">
			<canvas id="label-canvas"></canvas>
		</div>

		<div class="mt-3">
			<h5>{{ $__t('Authoritative preview') }}</h5>
			<div id="preview-holder"
				class="border d-inline-block p-2">
				<span class="text-muted">{{ $__t('Not rendered yet') }}</span>
			</div>
		</div>
	</div>
</div>
@stop
