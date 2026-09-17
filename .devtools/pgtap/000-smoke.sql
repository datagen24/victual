-- ADR-0025 spike 3: proves pg_prove can reach a database with the pgtap extension
-- installed, the way the CI job and the dev image both need to before anything else
-- here depends on it.

SELECT plan(1);
SELECT pass('pgTAP is installed and pg_prove can run a test');
SELECT * FROM finish();
