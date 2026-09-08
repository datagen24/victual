(function ()
{
 var workers = [], printers = [], drivers = [];
 function Decode(value) { return typeof value === 'string' ? JSON.parse(value) : value; }
 function Message(value) { $('#label-admin-message').text(value); }
 function Failed(xhr)
 {
  var detail = xhr.responseJSON || {};
  Message((detail.field ? detail.field + ': ' : '') + (detail.error_message || __t('Could not save label configuration.')));
 }
 function Options(selector, rows, label, empty)
 {
  var select = $(document).find(selector).empty();
  if (empty) select.append($('<option>').val('').text(empty));
  rows.forEach(function (row, index) { select.append($('<option>').val(index).text(label(row))); });
 }
 var loadGeneration = 0;
 function Read(path) { return new Promise(function (resolve, reject) { Victual.Api.Get(path, resolve, reject); }); }
 function Load(selection)
 {
  selection = selection || {};
  var generation = ++loadGeneration;
  Promise.all([Read('objects/label_workers'), Read('objects/label_printers'), Read('objects/label_drivers')]).then(function (results)
  {
   if (generation !== loadGeneration) return;
   workers = results[0]; printers = results[1];
   drivers = results[2].map(function (d)
   {
    d.settings_schemas = Decode(d.settings_schemas); d.capability_document = Decode(d.capability_document); return d;
   });
   Options('#label-worker-select', workers, function (w) { return w.name; }, __t('New worker'));
   Options('#label-printer-worker', workers, function (w) { return w.name; });
   Options('#label-printer-select', printers, function (p) { return p.name; }, __t('New printer'));
   Options('#label-printer-driver', drivers, function (d) { return d.driver_id + ' / ' + d.schema_version; });
   DriverChanged();
   if (selection.workerId !== undefined) $('#label-worker-select').val(workers.findIndex(function (w) { return w.id === selection.workerId; }));
   if (selection.printerId !== undefined) $('#label-printer-select').val(printers.findIndex(function (p) { return p.id === selection.printerId; }));
   $('#label-worker-select').trigger('change');
   $('#label-printer-select').trigger('change');
  }).catch(Failed);
 }
 function CurrentDriver() { return drivers[Number($('#label-printer-driver').val())]; }
 function DriverChanged()
 {
  var driver = CurrentDriver();
  if (!driver) return;
  Options('#label-printer-combination', driver.settings_schemas, function (s) { return Object.values(s.when).join(' / '); });
  Options('#label-printer-model', driver.capability_document.models, function (s) { return s; });
  Options('#label-printer-connection-type', driver.capability_document.connection_types, function (s) { return s; });
  SettingsChanged();
 }
 function SettingsChanged(values)
 {
  var driver = CurrentDriver(); if (!driver) return;
  var entry = driver.settings_schemas[Number($('#label-printer-combination').val())]; if (!entry) return;
  $('#label-printer-model').prop('disabled', entry.when.model !== undefined);
  if (entry.when.model !== undefined) $('#label-printer-model').val(driver.capability_document.models.indexOf(entry.when.model));
  $('#label-printer-connection-type').prop('disabled', entry.when.connection_type !== undefined);
  if (entry.when.connection_type !== undefined) $('#label-printer-connection-type').val(driver.capability_document.connection_types.indexOf(entry.when.connection_type));
  var container = $('#label-printer-settings').empty();
  Object.keys(entry.schema.properties).forEach(function (key, index)
  {
   var schema = entry.schema.properties[key], input, value = values && values[key] !== undefined ? values[key] : entry.when[key] !== undefined ? entry.when[key] : schema.default;
   if (schema.enum)
   {
    input = $('<select>');
    schema.enum.forEach(function (item, i) { input.append($('<option>').val(i).text(String(item))); });
    if (value !== undefined) input.val(schema.enum.indexOf(value));
   }
   else
   {
    input = $('<input>').attr('type', schema.type === 'boolean' ? 'checkbox' : ['integer','number'].includes(schema.type) ? 'number' : 'text');
    if (schema.type === 'boolean') input.prop('checked', !!value); else if (value !== undefined) input.val(value);
    if (schema.type === 'number') input.attr('step', 'any');
    [['minimum','min'],['maximum','max'],['minLength','minlength'],['maxLength','maxlength']].forEach(function (pair) { if (schema[pair[0]] !== undefined) input.attr(pair[1], schema[pair[0]]); });
   }
   input.attr('id', 'label-setting-' + index).addClass('form-control').data('setting', key).data('schema', schema);
   input.prop('required', schema.type !== 'boolean' && (entry.schema.required || []).includes(key));
   container.append($('<label>').attr('for', 'label-setting-' + index).text(schema.title || key), input);
  });
 }
 function SavePrinter(move)
 {
  if (!document.getElementById('label-printer-form').reportValidity()) return;
  var driver = CurrentDriver(), worker = workers[Number($('#label-printer-worker').val())]; if (!driver || !worker) return;
  var entry = driver.settings_schemas[Number($('#label-printer-combination').val())], settings = {};
  $('#label-printer-settings').find('input, select').each(function ()
  {
   var input = $(this), schema = input.data('schema'), value = input.val();
   if (value === '' && schema.type !== 'boolean' && !(entry.schema.required || []).includes(input.data('setting'))) return;
   if (schema.enum) value = schema.enum[Number(value)];
   else if (schema.type === 'boolean') value = input.prop('checked');
   else if (['integer','number'].includes(schema.type)) value = Number(value);
   settings[input.data('setting')] = value;
  });
  var selected = $('#label-printer-select').val(), printer = selected === '' ? null : printers[Number(selected)];
  var payload = { name: $('#label-printer-name').val(), worker_id: worker.id, driver_id: driver.driver_id, driver_schema_version: driver.schema_version,
   connection: $('#label-printer-connection').val(), connection_type: driver.capability_document.connection_types[Number($('#label-printer-connection-type').val())],
   model: driver.capability_document.models[Number($('#label-printer-model').val())], settings: settings,
   active: $('#label-printer-active').prop('checked') ? 1 : 0, is_default: $('#label-printer-default').prop('checked') ? 1 : 0 };
  var success = function (result) { Message(__t('Printer saved')); Load({ printerId: result.id }); };
  if (move && printer) Victual.Api.Post('labels/printers/' + printer.id + '/schema-version', payload, success, Failed);
  else if (printer) Victual.Api.Put('labels/printers/' + printer.id, payload, success, Failed);
  else Victual.Api.Post('labels/printers', payload, success, Failed);
 }
 $('#label-printer-driver').on('change', DriverChanged);
 $('#label-printer-combination').on('change', function () { SettingsChanged(); });
 $('#label-printer-form').on('submit', function (event) { event.preventDefault(); SavePrinter(false); });
 $('#label-printer-move').on('click', function () { SavePrinter(true); });
 $('#label-printer-select').on('change', function ()
 {
  var value = $(this).val(), printer = value === '' ? null : printers[Number(value)];
  $('#label-printer-name').val(printer ? printer.name : ''); $('#label-printer-connection').val(printer ? printer.connection : '');
  $('#label-printer-active').prop('checked', !printer || !!printer.active); $('#label-printer-default').prop('checked', !!printer && !!printer.is_default);
  if (!printer) return;
  $('#label-printer-worker').val(workers.findIndex(function (w) { return w.id === printer.worker_id; }));
  $('#label-printer-driver').val(drivers.findIndex(function (d) { return d.driver_id === printer.driver_id && d.schema_version === printer.driver_schema_version; }));
  DriverChanged(); var driver = CurrentDriver(), settings = Decode(printer.settings);
  $('#label-printer-combination').val(driver.settings_schemas.findIndex(function (s) { return Object.keys(s.when).every(function (key) { return s.when[key] === (key === 'model' ? printer.model : key === 'connection_type' ? printer.connection_type : settings[key]); }); }));
  $('#label-printer-model').val(driver.capability_document.models.indexOf(printer.model));
  $('#label-printer-connection-type').val(driver.capability_document.connection_types.indexOf(printer.connection_type)); SettingsChanged(settings);
 });
 $('#label-worker-select').on('change', function ()
 {
  var value = $(this).val(), worker = value === '' ? null : workers[Number(value)];
  $('#label-worker-name').val(worker ? worker.name : ''); $('#label-worker-mode').val(worker ? worker.configuration_mode : 'declared').prop('disabled', !!worker);
  $('#label-worker-active').prop('checked', !worker || !!worker.active); $('#label-worker-secret').text('');
 });
 $('#label-worker-form').on('submit', function (event)
 {
  event.preventDefault(); var value = $('#label-worker-select').val(), worker = value === '' ? null : workers[Number(value)];
  var payload = { name: $('#label-worker-name').val(), configuration_mode: $('#label-worker-mode').val(), active: $('#label-worker-active').prop('checked') ? 1 : 0 };
  var success = function (result) { Message(__t('Worker saved')); Load({ workerId: result.id }); };
  if (worker) Victual.Api.Put('labels/workers/' + worker.id, payload, success, Failed); else Victual.Api.Post('labels/workers', payload, success, Failed);
 });
 $('#label-worker-credential').on('click', function ()
 {
  var value = $('#label-worker-select').val(); if (value === '') return;
  var worker = workers[Number(value)];
  Victual.Api.Post('labels/workers/' + worker.id + (worker.configuration_mode === 'paired' ? '/pairing-material' : '/credentials'), {}, function (result) { $('#label-worker-secret').text(result.credential || result.material); }, Failed);
 });
 $('#label-worker-revoke').on('click', function ()
 {
  var value = $('#label-worker-select').val(); if (value === '' || !window.confirm(__t('Revoke all credentials for this worker?'))) return;
  Victual.Api.Delete('labels/workers/' + workers[Number(value)].id + '/credentials', {}, function () { $('#label-worker-secret').text(''); Message(__t('Worker credentials revoked')); }, Failed);
 });
 Load();
})();
