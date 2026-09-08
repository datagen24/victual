<?php
// Plan 27: templates, captures, renders, artifacts, idempotency and the operations.
//
//   LABEL_TEST_DSN='pgsql:host=127.0.0.1;port=5432;dbname=victual' PGUSER=... PGPASSWORD=... \
//     php .devtools/labels/artifact-tests.php
//
// The renderer here is a fixture that does the real thing - claims a durable request, paints
// the module matrix Victual computed, submits indexed PNG bytes - and every artifact it
// produces goes through the same verification the Rust renderer's will. Nothing in this file
// reaches past ArtifactService to write a row it did not earn.
require __DIR__.'/test-support.php';

use Victual\Services\Labels\{ArtifactService,IdempotencyService,LabelCaptureService,LabelOperationsService,
    LabelTemplateService,PrintAttemptService,RenderRequestService,TemplateDocument};

runLabelTests(function (PDO $db, string $schema) {
    [$worker, $printer, $template] = seed($db);
    $templates = new LabelTemplateService($db);
    $operations = fn () => new LabelOperationsService($db);

    // --- The document format refuses rather than approximating -----------------------------
    $good = $templates->GetDraft($template)['document'];
    refused(fn () => TemplateDocument::Validate(['schema_version' => 99] + $good, 'location'), 'unsupported_version');
    refused(fn () => TemplateDocument::Validate($good + ['nonsense' => 1], 'location'), 'unknown_property');
    $literalQr = $good;
    $literalQr['elements'][0]['source'] = 'https://example.invalid';
    refused(fn () => TemplateDocument::Validate($literalQr, 'location'), 'invalid_value');
    $unbounded = $good;
    $unbounded['canvas']['height_mm'] = null;
    $unbounded['canvas']['max_height_mm'] = null;
    refused(fn () => TemplateDocument::Validate($unbounded, 'location'), 'unbounded_automatic_height');
    $unknownField = $good;
    $unknownField['elements'][] = ['type' => 'text', 'id' => 'x', 'x_mm' => 1.0, 'y_mm' => 20.0, 'width_mm' => 10.0, 'height_mm' => 5.0,
        'field' => 'location.invented', 'font_asset' => 'f', 'size_pt' => 8.0];
    refused(fn () => TemplateDocument::Validate($unknownField, 'location'), 'unknown_field');

    // A stale draft token is a conflict, not a silent overwrite.
    $draft = $templates->GetDraft($template);
    tx($db, fn () => $templates->SaveDraft($template, $draft['document'], $draft['revision_token'], null));
    refused(fn () => tx($db, fn () => $templates->SaveDraft($template, $draft['document'], $draft['revision_token'], null)), 'stale_revision');

    // The digest is over the document, not over a serialization of it.
    $version = $templates->ResolveVersion($template, null);
    $shuffled = array_reverse($version['document'], true);
    check(\Victual\Helpers\CanonicalJson::Digest($shuffled) === $version['document_digest'], 'Template digest is key-order independent');

    // --- Issuance: a job exists, and is deliberately not claimable yet ---------------------
    $job = tx($db, fn () => $operations()->IssueLocation(1, 0, $printer, $template, null, 'en', 'UTC'));
    check($job['artifact_id'] === null, 'A job exists while its render is pending');
    check(tx($db, fn () => (new PrintAttemptService($db))->Claim($worker)) === [], 'A job with no artifact is not claimable');
    check((new \Victual\Services\Labels\LabelPrintJobService($db))->Monitor()[0]['state'] === 'awaiting_artifact', 'The monitor says what it is waiting for');

    $capture = (new LabelCaptureService($db))->Get((int)$job['capture_id']);
    check($capture['captured_fields']['location.name'] === 'Pantry', 'Capture reads the value rather than accepting one');

    // --- Verification refuses a renderer that got it wrong ---------------------------------
    $requests = new RenderRequestService($db);
    $input = tx($db, fn () => $requests->Claim());
    check($input !== null && $input['render_request_id'] === (int)$job['render_request_id'], 'The render request is claimable');
    check($input['qr']['code']['payload'] === 'VCTL:' . $job['label_uid'], 'The QR payload is the server-supplied one');

    $token = $input['generation_token'];
    $artifacts = new ArtifactService($db);

    // An RGBA PNG is a different form, not a smaller detail.
    $rgba = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($rgba);
    $rgbaBytes = ob_get_clean();
    refused(fn () => tx($db, fn () => $artifacts->Accept($input['render_request_id'], $token, $rgbaBytes, 'r', '1')), 'invalid_artifact');

    // Right form, wrong grid: fixed_grid means a mismatch is a refusal rather than a resize.
    $narrow = paintArtifact(['profile' => ['raster_width_px' => 100] + $input['profile'], 'document' => $input['document'], 'qr' => $input['qr']]);
    refused(fn () => tx($db, fn () => $artifacts->Accept($input['render_request_id'], $token, $narrow, 'r', '1')), 'geometry_mismatch');

    // Right grid, wrong QR: one flipped module is caught, because verification re-encodes
    // the pinned payload rather than trusting the renderer's own account of it.
    $flipped = paintArtifact($input, function (array &$pixels) use ($input) {
        $mx = (int)round(0.6 * $input['profile']['dpi_x'] / 25.4);
        $my = (int)round(0.6 * $input['profile']['dpi_y'] / 25.4);
        $ox = (int)round(2.0 * $input['profile']['dpi_x'] / 25.4) + 4 * $mx;
        $oy = (int)round(2.0 * $input['profile']['dpi_y'] / 25.4) + 4 * $my;
        for ($dy = 0; $dy < $my; $dy++) {
            for ($dx = 0; $dx < $mx; $dx++) {
                $pixels[$oy + $dy][$ox + $dx] = 0;
            }
        }
    });
    refused(fn () => tx($db, fn () => $artifacts->Accept($input['render_request_id'], $token, $flipped, 'r', '1')), 'qr_payload_mismatch');

    // --- A conforming artifact, and the job becomes claimable ------------------------------
    $bytes = paintArtifact($input);
    $artifact = tx($db, fn () => $artifacts->Accept($input['render_request_id'], $token, $bytes, 'fixture-renderer', '0.0.1'));
    check($artifact['byte_digest'] === hash('sha256', $bytes), 'The digest is computed over the bytes that arrived');
    check($artifact['manifest']['validation']['qr_checked'] === true, 'The manifest records that the QR was checked');

    // A stale result cannot replace a committed artifact.
    refused(fn () => tx($db, fn () => $artifacts->Accept($input['render_request_id'], $token, $bytes, 'r', '1')), 'already_committed');

    check(tx($db, fn () => (new PrintAttemptService($db))->Claim($worker)) === [], 'A ready artifact that is not attached still does not dispatch');
    $attached = tx($db, fn () => $operations()->AttachArtifact((int)$artifact['render_request_id'], (int)$artifact['id']));
    check($attached === 1, 'The artifact attaches to the waiting job');

    $claim = tx($db, fn () => (new PrintAttemptService($db))->Claim($worker));
    check(count($claim) === 1, 'The job is claimable once its artifact is attached');
    check($claim[0]['artifact']['byte_digest'] === $artifact['byte_digest'], 'The claim carries the manifest');
    check($claim[0]['payload']['artifact']['id'] === (int)$artifact['id'], 'The payload names the artifact');
    tx($db, fn () => (new PrintAttemptService($db))->Result($worker, (int)$claim[0]['attempt']['id'], 'printed', ['device' => 'complete']));

    // --- An exact reprint is renderer-independent ------------------------------------------
    $before = (int)$db->query('SELECT COUNT(*) FROM label_render_requests')->fetchColumn();
    $reprint = tx($db, fn () => $operations()->Reprint((int)$job['id'], null));
    check((int)$db->query('SELECT COUNT(*) FROM label_render_requests')->fetchColumn() === $before, 'A reprint creates no render request');
    check((int)$reprint['artifact_id'] === (int)$artifact['id'], 'A reprint replays the stored artifact');
    check($reprint['operation'] === 'reprint' && (int)$reprint['source_job_id'] === (int)$job['id'], 'A reprint names the job it replays');
    $reprintClaim = tx($db, fn () => (new PrintAttemptService($db))->Claim($worker));
    check(count($reprintClaim) === 1, 'A reprint is immediately claimable, with no renderer running');
    tx($db, fn () => (new PrintAttemptService($db))->Result($worker, (int)$reprintClaim[0]['attempt']['id'], 'printed', []));

    // ...and with the bytes gone it refuses rather than rerendering.
    $db->exec('DELETE FROM files WHERE file_group=' . $db->quote(\Victual\Services\Labels\LabelByteStore::GROUP_ARTIFACTS));
    refused(fn () => tx($db, fn () => $operations()->Reprint((int)$job['id'], null)), 'artifact_collected');
    check((int)$db->query('SELECT COUNT(*) FROM label_render_requests')->fetchColumn() === $before, 'A refused reprint renders nothing in its place');

    // --- A revised print keeps the uid and captures again ----------------------------------
    $db->exec("UPDATE locations SET name='Larder' WHERE id=1");
    $revised = tx($db, fn () => $operations()->RevisedPrint(1, 0, $printer, $template, null, 'en', 'UTC'));
    check($revised['label_uid'] === $job['label_uid'], 'A revised print keeps the identity');
    check((int)$revised['capture_id'] !== (int)$job['capture_id'], 'A revised print captures again');
    $revisedCapture = (new LabelCaptureService($db))->Get((int)$revised['capture_id']);
    check($revisedCapture['captured_fields']['location.name'] === 'Larder', 'A revised print carries the current value');
    $oldCapture = (new LabelCaptureService($db))->Get((int)$job['capture_id']);
    check($oldCapture['captured_fields']['location.name'] === 'Pantry', 'The earlier capture is untouched');

    $revisedArtifact = renderAndAttach($db, (int)$revised['id']);
    check((int)$revisedArtifact['id'] !== (int)$artifact['id'], 'A revised print produces its own artifact');

    // --- A renderer crash retries without printing -----------------------------------------
    $crashJob = tx($db, fn () => $operations()->IssueLocation(1, 0, $printer, $template, null, 'en', 'UTC'));
    $crashed = tx($db, fn () => $requests->Claim());
    $db->exec("UPDATE label_render_requests SET lease_expires_at=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE id=" . (int)$crashed['render_request_id']);
    check(tx($db, fn () => $requests->ReapExpired()) === 1, 'An expired render lease returns to the queue');
    // Asserted about this job rather than about the queue, because an earlier job in this
    // fixture is legitimately claimable: what a crashed render must not do is print *its own*
    // label, and an empty queue would also pass against a suite that had nothing in it.
    check((int)$db->query('SELECT COUNT(*) FROM print_attempts WHERE job_id=' . (int)$crashJob['id'])->fetchColumn() === 0, 'A crashed render prints nothing');
    check($db->query('SELECT artifact_id FROM print_jobs WHERE id=' . (int)$crashJob['id'])->fetchColumn() === null, 'A crashed render attaches nothing');
    $retried = tx($db, fn () => $requests->Claim());
    check((int)$retried['render_request_id'] === (int)$crashed['render_request_id'], 'The same request is retried');
    refused(fn () => tx($db, fn () => $artifacts->Accept((int)$crashed['render_request_id'], $crashed['generation_token'], paintArtifact($crashed), 'r', '1')), 'stale_generation');
    tx($db, fn () => $artifacts->Accept((int)$retried['render_request_id'], $retried['generation_token'], paintArtifact($retried), 'fixture-renderer', '0.0.1'));

    // --- Cancellation racing a claim produces one truthful outcome -------------------------
    $cancelJob = tx($db, fn () => $operations()->IssueLocation(1, 0, $printer, $template, null, 'en', 'UTC'));
    tx($db, fn () => $operations()->Cancel((int)$cancelJob['id'], 'Asked for the wrong shelf'));
    $cancelArtifact = renderPending($db);
    check(tx($db, fn () => $operations()->AttachArtifact((int)$cancelArtifact['render_request_id'], (int)$cancelArtifact['id'])) === 0, 'A cancelled job takes no artifact');
    // Drain whatever else is claimable first, then assert the cancelled job is still not:
    // an empty answer from a queue that had nothing in it proves nothing.
    while (($drain = tx($db, fn () => (new PrintAttemptService($db))->Claim($worker))) !== []) {
        check((int)$drain[0]['attempt']['job_id'] !== (int)$cancelJob['id'], 'A cancelled job is never dispatched');
        tx($db, fn () => (new PrintAttemptService($db))->Result($worker, (int)$drain[0]['attempt']['id'], 'printed', []));
    }
    check($db->query('SELECT cancelled_at FROM print_jobs WHERE id=' . (int)$cancelJob['id'])->fetchColumn() !== null, 'The cancelled job stays cancelled');

    $liveJob = tx($db, fn () => $operations()->IssueLocation(1, 0, $printer, $template, null, 'en', 'UTC'));
    renderAndAttach($db, (int)$liveJob['id']);
    $liveClaim = tx($db, fn () => (new PrintAttemptService($db))->Claim($worker));
    check(count($liveClaim) === 1, 'A live job claims');
    refused(fn () => tx($db, fn () => $operations()->Cancel((int)$liveJob['id'], 'too late')), 'already_claimed');

    // --- Idempotency ----------------------------------------------------------------------
    $keys = new IdempotencyService($db);
    $request = ['location_id' => 1, 'printer_id' => $printer];
    check(tx($db, fn () => $keys->Begin(1, 'issue', 'key-one-1234', $request))['replay'] === false, 'A new key is not a replay');
    tx($db, fn () => $keys->Record(1, 'issue', 'key-one-1234', 'print_job', 42, ['id' => 42]));
    $replay = tx($db, fn () => $keys->Begin(1, 'issue', 'key-one-1234', $request));
    check($replay['replay'] === true && (int)$replay['row']['resource_id'] === 42, 'The same key and inputs replay the original resource');
    refused(fn () => tx($db, fn () => $keys->Begin(1, 'issue', 'key-one-1234', array_merge($request, ['printer_id' => $printer + 1]))), 'idempotency_conflict');
    check(tx($db, fn () => $keys->Begin(2, 'issue', 'key-one-1234', $request))['replay'] === false, 'Keys are scoped to a principal');

    // --- Retirement stops new claims -------------------------------------------------------
    $db->exec('DELETE FROM locations WHERE id=1');
    refused(fn () => tx($db, fn () => $operations()->Reprint((int)$revised['id'], null)), 'label_retired');
});
