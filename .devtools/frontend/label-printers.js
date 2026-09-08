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
