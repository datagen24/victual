// Disposable demo/dev app only. Seeds worker/driver data through the real APIs.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const driver = require('../labels/fixtures/brother-ql.json');
const index = process.argv.indexOf('--url');
const base = index < 0 ? 'http://127.0.0.1:8200' : process.argv[index + 1];
(async () => {
 const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });
 try {
  const page = await browser.newPage();
  page.setDefaultTimeout(30000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  async function post(path, data, headers = {}) {
   const response = await page.request.post(base + '/api/' + path, { data, headers });
   assert.ok(response.ok(), path + ': ' + await response.text()); try { return await response.json(); } catch (error) { throw new Error(path + ': ' + await response.text()); }
  }
  const name = 'Label UI ' + Date.now();
  // A run-unique schema version. Re-registering an existing (driver_id, schema_version) with a
  // different document is refused by design - ADR-0019 decision item 3 - so a probe that reused
  // one would fail against any instance a previous run or a real worker had already registered,
  // which is a fixture collision rather than a finding.
  driver.schema_version = '1.' + (Date.now() % 100000);
  const worker = await post('labels/workers', { name, configuration_mode: 'declared' });
  const credential = await post('labels/workers/' + worker.id + '/credentials', {});
  await post('labels/register', { drivers: [driver] }, { 'VICTUAL-API-KEY': credential.credential });
  await page.goto(base + '/labelprinters');
  await page.locator('#label-printer-worker').selectOption({ label: name });
  await page.locator('#label-printer-driver').selectOption({ label: driver.driver_id + ' / ' + driver.schema_version });
  await page.locator('#label-printer-name').fill(name);
  await page.locator('#label-printer-connection').fill('127.0.0.1:9100');
  await page.getByLabel('resolution_x', { exact: true }).fill('600');
  await page.getByLabel('resolution_y', { exact: true }).fill('300');
  await page.getByRole('button', { name: 'Save printer', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('#label-admin-message').textContent.includes('Unsupported combination')).catch(async error => { throw new Error(error.message + ': ' + await page.locator('#label-admin-message').textContent()); });
  await page.getByLabel('resolution_x', { exact: true }).fill('300');
  await page.getByRole('button', { name: 'Save printer', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('#label-admin-message').textContent === 'Printer saved');
  const response = await page.request.get(base + '/api/objects/label_printers');
  const printer = (await response.json()).find(printer => printer.name === name);
  assert.ok(printer);
  // Printing needs a published template, and that is the design rather than a fixture
  // detail: a job pins a template version, and there is deliberately no fallback to "the
  // latest" or to a built-in. A QR-only document is used here because it needs no font
  // asset - the QR binds to the server-supplied payload, so the whole document is server
  // data and there is nothing to upload first.
  const template = await post('labels/templates', { name: name + ' template', entity_kind: 'location' });
  const draft = await (await page.request.get(base + '/api/labels/templates/' + template.id + '/draft')).json();
  const document_ = {
   schema_version: 1, entity_kind: 'location',
   canvas: { width_mm: 58.9, height_mm: 30.0, max_height_mm: null, margins_mm: { top: 1, right: 1, bottom: 1, left: 1 } },
   elements: [{ type: 'qr', id: 'code', x_mm: 2, y_mm: 2, module_mm: 0.6, ec_level: 'M', quiet_zone_modules: 4, color: 'black', source: 'label.payload' }]
  };
  const saved = await (await page.request.put(base + '/api/labels/templates/' + template.id + '/draft',
   { data: { document: document_, revision_token: draft.revision_token } })).json();
  assert.ok(saved.revision_token);
  await post('labels/templates/' + template.id + '/publish', {});

  const location = await post('objects/locations', { name });
  const context = await (await page.request.get(base + '/api/labels/locations/' + location.created_object_id + '/context')).json();
  const job = await post('labels/locations/' + location.created_object_id + '/print', { printer_id: printer.id, import_epoch: context.import_epoch });
  assert.equal(job.state, 'awaiting_artifact');
  await page.goto(base + '/labelprintjobs');
  await page.waitForFunction(() => document.querySelector('#label-jobs-rows').textContent.includes('Waiting for label rendering'));
  assert.deepEqual(errors, []);
  console.log('PASS schema-generated printer form, 422 combination refusal, saved configuration, queued artifact gate and monitor');
 } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
