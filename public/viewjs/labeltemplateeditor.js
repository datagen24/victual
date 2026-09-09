// The label designer.
//
// **The document is the state; the canvas is a view of it.** Fabric.js draws millimetres at a
// screen scale and reports drags back as millimetres, and every save serializes the document
// this file holds rather than asking Fabric what it thinks the design is. That direction
// matters: the format is Victual's, and an editor that emitted its own object graph would
// make the format whatever the editor happened to produce.
//
// **The canvas is a design aid and not a fidelity claim.** The authoritative preview is the
// render, from the same renderer that produces a printed label against the same media
// profile. ADR-0021 prerequisite 1 is explicit that sharing the editor's engine is not a
// qualification for the headless renderer, and nothing here is shared with it.

(function()
{
	var MM_PER_PX = 4;  // Screen pixels per millimetre. A drawing scale, not a device one.

	var state = { document: null, revisionToken: null, selectedId: null };
	var canvas = null;

	function region(id)
	{
		return document.getElementById(id);
	}

	function report(kind, text)
	{
		var holder = region('template-message');
		while (holder.firstChild !== null)
		{
			holder.removeChild(holder.firstChild);
		}
		var box = document.createElement('div');
		box.className = 'alert alert-' + kind;
		box.appendChild(document.createTextNode(text));
		holder.appendChild(box);
	}

	/**
	 * The server's own words.
	 *
	 * The API wrapper hands an error callback the raw XMLHttpRequest, so the body is
	 * `responseText` and is a string - reading `xhr.response.error_message` finds nothing and
	 * turns every refusal into a generic failure. A refusal here names the element and the
	 * property it is about, which is the whole reason the document format refuses rather than
	 * approximating, and showing that is what makes it actionable.
	 */
	function refusal(xhr)
	{
		var body = {};
		try
		{
			body = JSON.parse((xhr && xhr.responseText) || '{}');
		}
		catch (error)
		{
			// A non-JSON failure is a transport or a crash, and the fallback below says so.
		}

		if (body.error_message)
		{
			return (body.field ? body.field + ': ' : '') + body.error_message;
		}
		return __t('The server did not answer');
	}

	function millimetres(value, fallback)
	{
		var number = parseFloat(value);
		return isNaN(number) ? fallback : number;
	}

	// --- The canvas -----------------------------------------------------------------------

	function draw()
	{
		var width = state.document.canvas.width_mm;
		var height = state.document.canvas.height_mm || state.document.canvas.max_height_mm || 60;

		canvas.setWidth(width * MM_PER_PX);
		canvas.setHeight(height * MM_PER_PX);
		canvas.clear();
		canvas.backgroundColor = '#ffffff';

		state.document.elements.forEach(function(element)
		{
			var shape = shapeFor(element);
			if (shape === null)
			{
				return;
			}
			shape.set({ victualId: element.id, hasRotatingPoint: false, lockRotation: true });
			canvas.add(shape);
		});

		canvas.renderAll();
	}

	function paint(colour)
	{
		return colour === 'red' ? '#dc3545' : (colour === 'white' ? '#ffffff' : '#000000');
	}

	function shapeFor(element)
	{
		var common = {
			left: (element.x_mm || 0) * MM_PER_PX,
			top: (element.y_mm || 0) * MM_PER_PX,
			fill: paint(element.color)
		};

		if (element.type === 'text')
		{
			// Drawn at the point size the document names, converted to screen pixels at the
			// drawing scale. It is an approximation of the printed size on purpose - the
			// device has two resolutions and this screen has one, which is exactly why the
			// authoritative preview exists.
			return new fabric.Textbox(element.field || element.literal || '', Object.assign(common, {
				width: element.width_mm * MM_PER_PX,
				fontSize: element.size_pt * MM_PER_PX * 25.4 / 72,
				textAlign: element.align || 'left'
			}));
		}
		if (element.type === 'qr')
		{
			// A placeholder square at the symbol's real physical size: 21 modules for a
			// version 1 symbol plus its quiet zone. What the modules say is the server's, so
			// there is nothing here to draw them from.
			var side = (21 + 2 * (element.quiet_zone_modules || 4)) * element.module_mm;
			return new fabric.Rect(Object.assign(common, {
				width: side * MM_PER_PX, height: side * MM_PER_PX,
				fill: 'rgba(0,0,0,0.15)', stroke: '#000000', strokeDashArray: [4, 4]
			}));
		}
		if (element.type === 'rect')
		{
			return new fabric.Rect(Object.assign(common, {
				width: element.width_mm * MM_PER_PX, height: element.height_mm * MM_PER_PX,
				fill: element.fill ? paint(element.fill) : 'transparent',
				stroke: paint(element.color), strokeWidth: Math.max(1, element.stroke_mm * MM_PER_PX)
			}));
		}
		if (element.type === 'line')
		{
			return new fabric.Line(
				[element.x1_mm * MM_PER_PX, element.y1_mm * MM_PER_PX, element.x2_mm * MM_PER_PX, element.y2_mm * MM_PER_PX],
				{ stroke: paint(element.color), strokeWidth: Math.max(1, element.stroke_mm * MM_PER_PX) });
		}
		if (element.type === 'image')
		{
			return new fabric.Rect(Object.assign(common, {
				width: element.width_mm * MM_PER_PX, height: element.height_mm * MM_PER_PX,
				fill: 'rgba(0,0,0,0.08)', stroke: '#6c757d', strokeDashArray: [2, 2]
			}));
		}
		return null;
	}

	/** A drag or a resize writes millimetres back into the document, which is the state. */
	function absorb(shape)
	{
		var element = find(shape.victualId);
		if (!element)
		{
			return;
		}

		if (element.type === 'line')
		{
			var dx = shape.left - Math.min(element.x1_mm, element.x2_mm) * MM_PER_PX;
			var dy = shape.top - Math.min(element.y1_mm, element.y2_mm) * MM_PER_PX;
			element.x1_mm = round(element.x1_mm + dx / MM_PER_PX);
			element.x2_mm = round(element.x2_mm + dx / MM_PER_PX);
			element.y1_mm = round(element.y1_mm + dy / MM_PER_PX);
			element.y2_mm = round(element.y2_mm + dy / MM_PER_PX);
			return;
		}

		element.x_mm = round(shape.left / MM_PER_PX);
		element.y_mm = round(shape.top / MM_PER_PX);

		if (element.type === 'qr')
		{
			return;
		}
		if (typeof element.width_mm === 'number')
		{
			element.width_mm = round(shape.getScaledWidth() / MM_PER_PX);
		}
		if (typeof element.height_mm === 'number')
		{
			element.height_mm = round(shape.getScaledHeight() / MM_PER_PX);
		}
	}

	function round(value)
	{
		return Math.max(0, Math.round(value * 10) / 10);
	}

	function find(id)
	{
		for (var i = 0; i < state.document.elements.length; i++)
		{
			if (state.document.elements[i].id === id)
			{
				return state.document.elements[i];
			}
		}
		return null;
	}

	// --- The properties panel --------------------------------------------------------------

	function field(label, value, onChange, options)
	{
		var group = document.createElement('div');
		group.className = 'form-group';

		var caption = document.createElement('label');
		caption.appendChild(document.createTextNode(label));
		group.appendChild(caption);

		var input;
		if (options)
		{
			input = document.createElement('select');
			input.className = 'form-control';
			options.forEach(function(option)
			{
				var node = document.createElement('option');
				node.value = option.value;
				node.appendChild(document.createTextNode(option.label));
				if (option.value === value)
				{
					node.selected = true;
				}
				input.appendChild(node);
			});
		}
		else
		{
			input = document.createElement('input');
			input.className = 'form-control';
			input.type = typeof value === 'number' ? 'number' : 'text';
			input.step = 'any';
			input.value = value === null || value === undefined ? '' : value;
		}

		input.addEventListener('change', function()
		{
			onChange(input.value);
			draw();
			properties();
		});

		group.appendChild(input);
		return group;
	}

	function properties()
	{
		var holder = region('element-properties');
		while (holder.firstChild !== null)
		{
			holder.removeChild(holder.firstChild);
		}

		var element = state.selectedId === null ? null : find(state.selectedId);
		if (element === null)
		{
			var hint = document.createElement('p');
			hint.className = 'text-muted';
			hint.appendChild(document.createTextNode(__t('Select an element to edit it')));
			holder.appendChild(hint);
			return;
		}

		var heading = document.createElement('h5');
		heading.appendChild(document.createTextNode(element.type + ' — ' + element.id));
		holder.appendChild(heading);

		if (element.type === 'text')
		{
			var fields = Victual.LabelTemplate.EntityKind === 'location'
				? ['location.name', 'location.description', 'location.id']
				: [];
			holder.appendChild(field(__t('Field'), element.field, function(v) { element.field = v || null; element.literal = element.field ? null : (element.literal || 'Text'); },
				fields.map(function(f) { return { value: f, label: f }; }).concat([{ value: '', label: __t('a fixed string') }])));
			if (!element.field)
			{
				holder.appendChild(field(__t('Fixed text'), element.literal || '', function(v) { element.literal = v; }));
			}
			holder.appendChild(field(__t('Font'), element.font_asset,
				function(v) { element.font_asset = v; },
				Victual.LabelTemplate.Assets.filter(function(a) { return a.asset_kind === 'font'; })
					.map(function(a) { return { value: a.name, label: a.name + ' (' + a.font_family + ')' }; })));
			holder.appendChild(field(__t('Size (pt)'), element.size_pt, function(v) { element.size_pt = millimetres(v, element.size_pt); }));
			holder.appendChild(field(__t('Alignment'), element.align, function(v) { element.align = v; },
				[{ value: 'left', label: __t('Left') }, { value: 'center', label: __t('Centre') }, { value: 'right', label: __t('Right') }]));
			holder.appendChild(field(__t('When it does not fit'), element.overflow, function(v)
			{
				element.overflow = v;
				element.min_size_pt = v === 'shrink_to_fit' ? (element.min_size_pt || Math.max(3, element.size_pt / 2)) : null;
			}, [
				{ value: 'error', label: __t('Refuse the label') },
				{ value: 'ellipsis', label: __t('Shorten with an ellipsis') },
				{ value: 'shrink_to_fit', label: __t('Shrink, to a floor') }
			]));
			if (element.overflow === 'shrink_to_fit')
			{
				holder.appendChild(field(__t('Smallest size (pt)'), element.min_size_pt, function(v) { element.min_size_pt = millimetres(v, element.min_size_pt); }));
			}
		}

		if (element.type === 'qr')
		{
			holder.appendChild(field(__t('Module size (mm)'), element.module_mm, function(v) { element.module_mm = millimetres(v, element.module_mm); }));
			holder.appendChild(field(__t('Error correction'), element.ec_level, function(v) { element.ec_level = v; },
				['L', 'M', 'Q', 'H'].map(function(l) { return { value: l, label: l }; })));
			var note = document.createElement('p');
			note.className = 'text-muted small';
			note.appendChild(document.createTextNode(__t('A QR always encodes this label\'s own identifier. It cannot be pointed anywhere else.')));
			holder.appendChild(note);
		}

		if (element.type === 'image')
		{
			holder.appendChild(field(__t('Image'), element.asset, function(v) { element.asset = v; },
				Victual.LabelTemplate.Assets.filter(function(a) { return a.asset_kind === 'image'; })
					.map(function(a) { return { value: a.name, label: a.name }; })));
		}

		if (element.type === 'rect' || element.type === 'line')
		{
			holder.appendChild(field(__t('Stroke (mm)'), element.stroke_mm, function(v) { element.stroke_mm = millimetres(v, element.stroke_mm); }));
		}

		holder.appendChild(field(__t('Colour'), element.color, function(v) { element.color = v; },
			[{ value: 'black', label: __t('Black') }, { value: 'red', label: __t('Red') }, { value: 'white', label: __t('White') }]));

		var remove = document.createElement('button');
		remove.className = 'btn btn-danger btn-sm';
		remove.appendChild(document.createTextNode(__t('Remove this element')));
		remove.addEventListener('click', function()
		{
			state.document.elements = state.document.elements.filter(function(e) { return e.id !== element.id; });
			state.selectedId = null;
			draw();
			properties();
		});
		holder.appendChild(remove);
	}

	// --- Adding ---------------------------------------------------------------------------

	function nextId(prefix)
	{
		var n = 1;
		while (find(prefix + n) !== null)
		{
			n++;
		}
		return prefix + n;
	}

	function add(kind)
	{
		var element = { type: kind, id: nextId(kind), x_mm: 2, y_mm: 2, color: 'black' };

		if (kind === 'text')
		{
			var font = Victual.LabelTemplate.Assets.filter(function(a) { return a.asset_kind === 'font'; })[0];
			if (!font)
			{
				report('danger', __t('Upload a font before adding text: a template pins the font it prints with, and there is no default and no substitution.'));
				return;
			}
			Object.assign(element, {
				width_mm: 30, height_mm: 8, field: Victual.LabelTemplate.EntityKind + '.name', literal: null,
				font_asset: font.name, size_pt: 11, align: 'left', valign: 'top', wrap: true,
				line_spacing: 1.2, overflow: 'error', min_size_pt: null
			});
		}
		else if (kind === 'qr')
		{
			Object.assign(element, { module_mm: 0.6, ec_level: 'M', quiet_zone_modules: 4, source: 'label.payload' });
		}
		else if (kind === 'rect')
		{
			Object.assign(element, { width_mm: 20, height_mm: 10, stroke_mm: 0.3, fill: null });
		}
		else if (kind === 'line')
		{
			Object.assign(element, { x1_mm: 2, y1_mm: 2, x2_mm: 30, y2_mm: 2, stroke_mm: 0.3 });
			delete element.x_mm;
			delete element.y_mm;
		}
		else if (kind === 'image')
		{
			var image = Victual.LabelTemplate.Assets.filter(function(a) { return a.asset_kind === 'image'; })[0];
			if (!image)
			{
				report('danger', __t('Upload an image before adding one.'));
				return;
			}
			Object.assign(element, { width_mm: 15, height_mm: 15, asset: image.name, fit: 'contain' });
		}

		state.document.elements.push(element);
		state.selectedId = element.id;
		draw();
		properties();
	}

	// --- Loading and saving ------------------------------------------------------------------

	function absorbCanvasSize()
	{
		state.document.canvas.width_mm = millimetres(region('canvas-width-mm').value, state.document.canvas.width_mm);
		var height = region('canvas-height-mm').value;
		state.document.canvas.height_mm = height === '' ? null : millimetres(height, null);
		var max = region('canvas-max-height-mm').value;
		state.document.canvas.max_height_mm = max === '' ? null : millimetres(max, null);
	}

	function load()
	{
		Victual.Api.Get('labels/templates/' + Victual.LabelTemplate.Id + '/draft',
			function(draft)
			{
				state.document = draft.document;
				state.revisionToken = draft.revision_token;
				region('canvas-width-mm').value = state.document.canvas.width_mm;
				region('canvas-height-mm').value = state.document.canvas.height_mm === null ? '' : state.document.canvas.height_mm;
				region('canvas-max-height-mm').value = state.document.canvas.max_height_mm === null ? '' : state.document.canvas.max_height_mm;
				draw();
				properties();
			},
			function(xhr) { report('danger', refusal(xhr)); });
	}

	function save(onSaved)
	{
		absorbCanvasSize();
		Victual.Api.Put('labels/templates/' + Victual.LabelTemplate.Id + '/draft',
			{ 'document': state.document, 'revision_token': state.revisionToken },
			function(saved)
			{
				// The token moves on every save. Keeping the old one would make the next save
				// a conflict against ourselves.
				state.revisionToken = saved.revision_token;
				state.document = saved.document;
				draw();
				properties();
				if (onSaved)
				{
					onSaved();
				}
				else
				{
					report('success', __t('Draft saved'));
				}
			},
			function(xhr)
			{
				if (xhr.status === 409)
				{
					report('danger', __t('Somebody else changed this draft. Reload to see their version before applying this edit.'));
					return;
				}
				report('danger', refusal(xhr));
			});
	}

	// --- Wiring -----------------------------------------------------------------------------

	$(document).ready(function()
	{
		canvas = new fabric.Canvas('label-canvas', { selection: false, backgroundColor: '#ffffff' });

		canvas.on('selection:created', function(event) { state.selectedId = event.selected[0].victualId; properties(); });
		canvas.on('selection:updated', function(event) { state.selectedId = event.selected[0].victualId; properties(); });
		canvas.on('selection:cleared', function() { state.selectedId = null; properties(); });
		canvas.on('object:modified', function(event) { absorb(event.target); properties(); });

		$(document).find('#add-element-button').on('click', function() { add(region('element-kind').value); });

		$(document).find('#upload-asset-button').on('click', function()
		{
			var input = region('asset-file');
			var file = input.files && input.files[0];
			var licence = region('asset-licence').value;

			if (!file)
			{
				report('danger', __t('Choose a file first'));
				return;
			}
			if (!licence)
			{
				// Refused here rather than by the server, so the reason arrives beside the
				// field it is about. The server refuses it too.
				report('danger', __t('An asset records the licence it is used under'));
				return;
			}

			var kind = /\.png$/i.test(file.name) ? 'image' : 'font';
			var mime = kind === 'image' ? 'image/png' : (/\.otf$/i.test(file.name) ? 'font/otf' : 'font/ttf');

			var reader = new FileReader();
			reader.onload = function()
			{
				// The bytes travel base64 in the JSON envelope, which is one request shape
				// across this whole API. The server validates them by decoding - a declared
				// font that is not one is refused there, not here.
				var base64 = reader.result.substring(reader.result.indexOf(',') + 1);
				Victual.Api.Post('labels/assets',
					{
						'name': file.name.replace(/\.[^.]+$/, ''),
						'asset_kind': kind,
						'mime_type': mime,
						'content_base64': base64,
						'licence': licence
					},
					function(asset)
					{
						Victual.LabelTemplate.Assets.push(asset);
						var item = document.createElement('li');
						item.appendChild(document.createTextNode(asset.name + (asset.font_family ? ' (' + asset.font_family + ')' : '')));
						region('asset-list').appendChild(item);
						report('success', __t('Stored %s', asset.name));
						properties();
					},
					function(xhr) { report('danger', refusal(xhr)); });
			};
			reader.readAsDataURL(file);
		});
		$(document).find('#save-draft-button').on('click', function() { save(null); });

		$(document).find('#publish-button').on('click', function()
		{
			// Saved first, deliberately: publishing takes the *stored* draft, so publishing
			// an unsaved canvas would publish the version before the one on screen.
			save(function()
			{
				Victual.Api.Post('labels/templates/' + Victual.LabelTemplate.Id + '/publish', {},
					function(version) { report('success', __t('Published version %s', version.version)); },
					function(xhr) { report('danger', refusal(xhr)); });
			});
		});

		$(document).find('#preview-button').on('click', function()
		{
			save(function()
			{
				report('info', __t('Rendering...'));
				Victual.Api.Post('labels/templates/' + Victual.LabelTemplate.Id + '/preview',
					{ 'kind': 'draft', 'printer_id': parseInt(region('preview-printer').value, 10) },
					function(request) { poll(request.id, 40); },
					function(xhr) { report('danger', refusal(xhr)); });
			});
		});

		load();
	});

	/** Polls a render request. A pending image reports its status rather than an empty body. */
	function poll(requestId, attemptsLeft)
	{
		Victual.Api.Get('labels/renders/' + requestId, function(status)
		{
			if (status.state === 'ready')
			{
				var holder = region('preview-holder');
				while (holder.firstChild !== null)
				{
					holder.removeChild(holder.firstChild);
				}
				var image = document.createElement('img');
				image.src = U('/api/labels/artifacts/' + status.artifact_id + '/image');
				image.alt = __t('The rendered label');
				image.style.maxWidth = '100%';
				holder.appendChild(image);
				report('success', __t('Rendered'));
				return;
			}
			if (status.state === 'invalid')
			{
				// An input or layout error names the element it is about, which is what makes
				// it something a designer can act on rather than a failed render.
				report('danger', status.error_code + (status.error_element ? ' (' + status.error_element + ')' : '') + ': ' + (status.error_detail || ''));
				return;
			}
			if (status.state === 'failed')
			{
				report('danger', __t('The renderer failed: %s', status.error_detail || status.error_code || ''));
				return;
			}
			if (attemptsLeft <= 0)
			{
				report('warning', __t('The preview is still rendering. No renderer may be running.'));
				return;
			}
			window.setTimeout(function() { poll(requestId, attemptsLeft - 1); }, 1500);
		},
		function(xhr) { report('danger', refusal(xhr)); });
	}
})();
