<?php
// Do Victual and the renderer agree about geometry?
//
//   VICTUAL_RENDERER_BIN=/path/to/victual-label-renderer \
//   VICTUAL_RENDERER_FONT=/path/to/NotoSans-Regular.ttf \
//   LABEL_TEST_DSN='pgsql:host=127.0.0.1;port=5432;dbname=victual' PGUSER=... PGPASSWORD=... \
//     php .devtools/labels/renderer-agreement-tests.php
//
// This is the check neither repository can make on its own. The renderer's own tests assert
// that its output has the form it meant to produce; Victual's assert that its verifier
// refuses artifacts that are wrong. Neither establishes the thing that actually matters -
// that the artifact *this* renderer produces is one *this* verifier accepts - because each
// would pass just as happily if both were wrong about the same axis in the same direction,
// which is precisely the defect the 2026-09-07 renderer comparison found.
//
// So the fixture renderer is set aside here and the real binary is run, over a request
// Victual composed, and the bytes it returns are fed to the verifier that will accept or
// refuse them in production.
require __DIR__.'/test-support.php';

use Victual\Services\Labels\{ArtifactService,LabelAssetService,LabelOperationsService,LabelTemplateService,PrintAttemptService,RenderRequestService};

$binary = getenv('VICTUAL_RENDERER_BIN');
$font = getenv('VICTUAL_RENDERER_FONT');

if (!$binary || !is_executable($binary))
{
	// Not skipped. A cross-implementation check that quietly becomes a no-op when the other
	// implementation is absent is a check that will be absent on the day it was needed.
	fwrite(STDERR, "VICTUAL_RENDERER_BIN must point at a built victual-label-renderer.\n");
	fwrite(STDERR, "Build it with: cargo build --release --manifest-path <victual-label-renderer>/Cargo.toml\n");
	exit(1);
}
if (!$font || !is_readable($font))
{
	fwrite(STDERR, "VICTUAL_RENDERER_FONT must point at a TrueType font (the renderer repository ships one under tests/fixtures).\n");
	exit(1);
}

runLabelTests(function (PDO $db, string $schema) use ($binary, $font) {
	[$worker, $printer] = seed($db);
	$templates = new LabelTemplateService($db);

	// A font asset, so the template can carry text as well as a QR - the case where the two
	// implementations could disagree about anisotropy without either noticing.
	$asset = tx($db, fn () => (new LabelAssetService($db))->Store('agreement-font', 'font', 'font/ttf', file_get_contents($font), 'SIL Open Font License 1.1', 'Test fixture only'));
	check($asset['font_family'] !== null, 'The font decodes to a family rather than a declared type');

	// Schema 1, then every canvas turn schema 2 allows, then the QR turned on its own and
	// inside a turned canvas (#1033). A quarter turn is the case where the two
	// implementations could disagree about direction, or about which dpi each axis takes, and
	// both still pass their own tests.
	foreach ([[null, 0], [0, 0], [90, 0], [180, 0], [270, 0], [0, 90], [90, 270], [180, 180]] as [$orientation, $qrTurn])
	{
		$quarter = $orientation === 90 || $orientation === 270;
		$label = ($orientation === null ? 'schema 1' : 'orientation ' . $orientation) . ($qrTurn ? ', QR turned ' . $qrTurn : '');
		$template = tx($db, fn () => $templates->Create('Agreement label ' . $label, null, 'location', null));
		$draft = $templates->GetDraft((int)$template['id']);
		$canvas = ['width_mm' => 58.9, 'height_mm' => 30.0, 'max_height_mm' => null,
			'margins_mm' => ['top' => 1.0, 'right' => 1.0, 'bottom' => 1.0, 'left' => 1.0]];
		if ($orientation !== null)
		{
			$canvas['orientation_deg'] = $orientation;
		}
		$document = ['schema_version' => $orientation === null ? 1 : 2, 'entity_kind' => 'location',
			'canvas' => $canvas,
			'elements' => [
				['type' => 'qr', 'id' => 'code', 'x_mm' => 2.0, 'y_mm' => 2.0, 'module_mm' => 0.6,
					'ec_level' => 'M', 'quiet_zone_modules' => 4, 'color' => 'black', 'source' => 'label.payload']
					+ ($qrTurn ? ['rotation_deg' => $qrTurn] : []),
				['type' => 'text', 'id' => 'name', 'x_mm' => $quarter ? 2.0 : 24.0, 'y_mm' => $quarter ? 22.0 : 4.0, 'width_mm' => $quarter ? 26.0 : 32.0, 'height_mm' => 12.0,
					'field' => 'location.name', 'font_asset' => 'agreement-font', 'size_pt' => 11.0,
					'align' => 'left', 'valign' => 'top', 'wrap' => true, 'line_spacing' => 1.2, 'overflow' => 'error', 'color' => 'black'],
			]];
		tx($db, fn () => $templates->SaveDraft((int)$template['id'], $document, $draft['revision_token'], null));
		tx($db, fn () => $templates->Publish((int)$template['id'], null));

		$job = tx($db, fn () => (new LabelOperationsService($db))->IssueLocation('location', 1, 0, $printer, (int)$template['id'], null, 'en', 'UTC'));
		$input = tx($db, fn () => (new RenderRequestService($db))->Claim());
		check($input !== null, 'The render request is claimable');

		// The renderer fetches assets over its own authorized route in production; offline it is
		// handed paths, which is the only difference between the two modes.
		$directory = sys_get_temp_dir() . '/' . $schema . '-' . ($orientation ?? 'v1') . '-' . $qrTurn;
		mkdir($directory, 0700, true);
		foreach ($input['assets'] as $index => $stored)
		{
			$path = $directory . '/' . $stored['id'] . '.bin';
			file_put_contents($path, (new LabelAssetService($db))->Bytes((int)$stored['id']));
			$input['assets'][$index]['file'] = $path;
		}

		$inputPath = $directory . '/input.json';
		$outputPath = $directory . '/artifact.png';
		file_put_contents($inputPath, json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

		$command = escapeshellarg($binary) . ' --input ' . escapeshellarg($inputPath) . ' --out ' . escapeshellarg($outputPath) . ' 2>&1';
		$lines = [];
		$status = 0;
		exec($command, $lines, $status);
		$report = json_decode(implode('', $lines), true) ?? [];
		check($status === 0, $label . ': the renderer produced an artifact: ' . implode('', $lines));

		$bytes = file_get_contents($outputPath);
		check(is_string($bytes) && strlen($bytes) > 0, 'The renderer wrote bytes');
		check((int)$report['width_px'] === (int)$input['profile']['raster_width_px'], 'The renderer used the profile grid');

		// The whole point. Victual verifies the form, the geometry, the palette and the QR
		// against the payload it pinned - and it is verifying the real renderer's output.
		$artifact = tx($db, fn () => (new ArtifactService($db))->Accept(
			(int)$input['render_request_id'], $input['generation_token'], $bytes, 'victual-label-renderer', (string)($report['version'] ?? 'test')));

		check($artifact['byte_digest'] === hash('sha256', $bytes), 'The digest covers the bytes that arrived');
		check((int)$artifact['width_px'] === (int)$input['profile']['raster_width_px'], 'Accepted at the profile grid');
		check($artifact['manifest']['validation']['qr_checked'] === true, 'The QR was verified against the pinned payload');

		tx($db, fn () => (new LabelOperationsService($db))->AttachArtifact((int)$input['render_request_id'], (int)$artifact['id']));
		$claim = tx($db, fn () => (new PrintAttemptService($db))->Claim($worker));
		check(count($claim) >= 1, $label . ': the job the real renderer served is claimable');
		check(in_array($artifact['byte_digest'], array_map(static fn ($c) => $c['artifact']['byte_digest'], $claim), true), $label . ': the worker is handed the manifest for those bytes');

		array_map('unlink', glob($directory . '/*'));
		rmdir($directory);
	}

	// A text box too short for its text is refused by the real renderer, naming the element,
	// and the refusal settles the request as invalid with that element - which is what the
	// designer shows. The box is the 2026-10-23 stock label's: 9 pt at 1.2 spacing advances
	// 3.81 mm a line, so a 3.2 mm box holds none, and before the renderer bounded text down
	// it drew the lines over whatever sat below.
	$template = tx($db, fn () => $templates->Create('Agreement label, text taller than its box', null, 'location', null));
	$draft = $templates->GetDraft((int)$template['id']);
	$document = ['schema_version' => 1, 'entity_kind' => 'location',
		'canvas' => ['width_mm' => 58.9, 'height_mm' => 30.0, 'max_height_mm' => null,
			'margins_mm' => ['top' => 1.0, 'right' => 1.0, 'bottom' => 1.0, 'left' => 1.0]],
		'elements' => [
			['type' => 'qr', 'id' => 'code', 'x_mm' => 2.0, 'y_mm' => 2.0, 'module_mm' => 0.6,
				'ec_level' => 'M', 'quiet_zone_modules' => 4, 'color' => 'black', 'source' => 'label.payload'],
			['type' => 'text', 'id' => 'name', 'x_mm' => 24.0, 'y_mm' => 4.0, 'width_mm' => 32.0, 'height_mm' => 3.2,
				'field' => 'location.name', 'font_asset' => 'agreement-font', 'size_pt' => 9.0,
				'align' => 'left', 'valign' => 'top', 'wrap' => true, 'line_spacing' => 1.2, 'overflow' => 'error', 'color' => 'black'],
		]];
	tx($db, fn () => $templates->SaveDraft((int)$template['id'], $document, $draft['revision_token'], null));
	tx($db, fn () => $templates->Publish((int)$template['id'], null));
	tx($db, fn () => (new LabelOperationsService($db))->IssueLocation('location', 1, 0, $printer, (int)$template['id'], null, 'en', 'UTC'));
	$input = tx($db, fn () => (new RenderRequestService($db))->Claim());
	check($input !== null, 'The overflowing render request is claimable');

	$directory = sys_get_temp_dir() . '/' . $schema . '-overflow';
	mkdir($directory, 0700, true);
	foreach ($input['assets'] as $index => $stored)
	{
		$path = $directory . '/' . $stored['id'] . '.bin';
		file_put_contents($path, (new LabelAssetService($db))->Bytes((int)$stored['id']));
		$input['assets'][$index]['file'] = $path;
	}
	file_put_contents($directory . '/input.json', json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	$lines = [];
	$status = 0;
	exec(escapeshellarg($binary) . ' --input ' . escapeshellarg($directory . '/input.json') . ' --out ' . escapeshellarg($directory . '/artifact.png') . ' 2>&1', $lines, $status);
	$report = json_decode(implode('', $lines), true) ?? [];
	check($status === 3, 'A text box too short for its text is refused, not drawn: ' . implode('', $lines));
	check(($report['code'] ?? null) === 'TEXT_OVERFLOW', 'The refusal is TEXT_OVERFLOW');
	check(($report['element'] ?? null) === 'name', 'The refusal names the element');
	check(!file_exists($directory . '/artifact.png'), 'A refused render writes no artifact');

	$settled = tx($db, fn () => (new RenderRequestService($db))->RecordInvalid(
		(int)$input['render_request_id'], $input['generation_token'], $report['code'], $report['element'], (string)($report['detail'] ?? '')));
	check($settled['state'] === 'invalid' && $settled['error_element'] === 'name', 'The request settles as invalid, naming the element the designer shows');

	array_map('unlink', glob($directory . '/*'));
	rmdir($directory);
});
