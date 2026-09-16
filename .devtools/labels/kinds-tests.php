<?php
// Plan 32: the five kinds that joined the label subsystem alongside location - product,
// stock_entry, recipe, chore, battery. Verification 2: issue, resolve and retire one label
// of each kind, and the retired branch carries each kind's snapshot.
//
//   LABEL_TEST_DSN='pgsql:host=127.0.0.1;port=5432;dbname=victual' PGUSER=... PGPASSWORD=... \
//     php .devtools/labels/kinds-tests.php
require __DIR__.'/test-support.php';

use Victual\Services\Labels\{FieldCatalogue,LabelCaptureService,LabelIdentityService,LabelOperationsService};

runLabelTests(function (PDO $db, string $schema) {
    [$worker, $printer, $locationTemplate] = seed($db);
    $identity = new LabelIdentityService($db);
    $allow = fn (string $kind): bool => true;

    // Fixture rows from test-support.php's fixture(): products(1,'Beans'), stock(1, product 1,
    // location 1), recipes(1,'Chili'), chores(1,'Water the plants'), batteries(1,'Smoke detector').
    $targets = ['product' => 1, 'stock_entry' => 1, 'recipe' => 1, 'chore' => 1, 'battery' => 1];

    foreach ($targets as $kind => $id) {
        // --- FieldCatalogue is real, not a stub: every declared field actually reads --------
        $catalogue = FieldCatalogue::For($kind);
        check(count($catalogue) > 0, "$kind: FieldCatalogue::For() declares at least one field");
        $capture = tx($db, fn () => (new LabelCaptureService($db))->Capture($kind, $id, null, array_keys($catalogue), 'en', 'UTC', null));
        foreach ($catalogue as $field => $definition) {
            check(array_key_exists($field, $capture['captured_fields']), "$kind: capturing every catalogue field reads $field without refusing");
        }

        // --- Issue, resolve live, retire by deleting the target -----------------------------
        $uid = tx($db, fn () => $identity->Issue($kind, $id, 0));
        check(Victual\Services\Labels\LabelIdentityService::Canonicalize($uid) === $uid, "$kind: Issue() returns a canonical uid");
        $resolved = $identity->Resolve($uid, $allow);
        check($resolved['status'] === 'resolved' && $resolved['kind'] === $kind, "$kind: Resolve() finds it live and names its own kind, not the literal from a hardcoded branch");

        $table = FieldCatalogue::TableFor($kind);
        $db->exec("DELETE FROM $table WHERE id=$id");
        $retired = $identity->Resolve($uid, $allow);
        check($retired['status'] === 'retired', "$kind: deleting the target retires its label (migration 0283's retire_${kind}_labels trigger)");
        check(is_array($retired['snapshot']) && ($retired['snapshot']['id'] ?? null) == $id, "$kind: the retirement snapshot names the deleted row's id");

        // Restore the row exactly as fixture() created it (test-support.php), so a later
        // kind's own capture - stock_entry.qu_name joins back through products.qu_id_stock -
        // still finds what it needs.
        $columns = match ($kind) {
            'product' => "(id,name,description,product_group_id,qu_id_stock) VALUES ($id,'Beans','Canned',1,1)",
            'stock_entry' => "(id,product_id,amount,best_before_date,purchased_date,location_id) VALUES ($id,1,3,'2099-12-31','2026-01-01',1)",
            'recipe' => "(id,name) VALUES ($id,'Chili')",
            'chore' => "(id,name) VALUES ($id,'Water the plants')",
            'battery' => "(id,name) VALUES ($id,'Smoke detector')",
        };
        $db->exec("INSERT INTO $table $columns");
    }

    // --- The generalised LabelOperationsService::IssueLocation() works end to end for a
    // non-location kind, including the default-printer fallback plan 32 question 3 answers ---
    $operations = new LabelOperationsService($db);
    $productTemplate = tx($db, fn () => (new \Victual\Services\Labels\LabelTemplateService($db))->Create('Fixture product label', null, 'product', null));
    $draft = (new \Victual\Services\Labels\LabelTemplateService($db))->GetDraft((int)$productTemplate['id']);
    tx($db, fn () => (new \Victual\Services\Labels\LabelTemplateService($db))->Publish((int)$productTemplate['id'], null));
    $db->exec('UPDATE label_printers SET is_default=1 WHERE id=' . (int)$printer);
    $job = tx($db, fn () => $operations->IssueLocation('product', 1, 0, null, null, null, 'en', 'UTC'));
    check($job['label_uid'] !== null && $job['artifact_id'] === null, 'IssueLocation(kind=product, printerId=null) resolves the default printer and queues a job');

    // --- PrintJobPayload::DescribeUnreadable() understands every kind's name field -----------
    $payload = json_decode((string)$db->query("SELECT payload FROM outbox WHERE id=(SELECT outbox_id FROM print_jobs WHERE id={$job['id']})")->fetchColumn(), true);
    check(\Victual\Services\Labels\PrintJobPayload::DescribeUnreadable($payload) === null, 'A real product job payload is readable');
});
