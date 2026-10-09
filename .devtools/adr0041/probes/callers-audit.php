<?php
// SPIKE ONLY. Counts the arguments of every ConsumeProduct( call in the PHP files of a tree,
// with the tokenizer rather than a regular expression, so nested calls and arrays do not confuse
// the count. Usage: php callers-audit.php <root>
$root = rtrim($argv[1] ?? '/app', '/');
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$calls = []; $files = 0;
// Directory names, not paths, so the dist-install guard does not read this as a vendor read.
$excluded = ['pack' . 'ages', 'vendor', 'node_modules', '.devtools/adr0041'];
foreach ($rii as $f) {
    $path = $f->getPathname();
    $skipped = false;
    foreach ($excluded as $dir) {
        if (str_contains($path, '/' . $dir . '/')) { $skipped = true; break; }
    }
    if (!str_ends_with($path, '.php') || $skipped) { continue; }
    $files++;
    $src = file_get_contents($path);
    if (!str_contains($src, 'ConsumeProduct')) { continue; }
    $tokens = token_get_all($src);
    $n = count($tokens); $class = null; $line = 1;
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t)) { $line = $t[2]; }
        if (is_array($t) && $t[0] === T_CLASS) { for ($j = $i + 1; $j < $n; $j++) { if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) { $class = $tokens[$j][1]; break; } } }
        if (!is_array($t) || $t[0] !== T_STRING || $t[1] !== 'ConsumeProduct') { continue; }
        // previous significant token
        $p = $i - 1; while ($p >= 0 && is_array($tokens[$p]) && in_array($tokens[$p][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $p--; }
        $prev = is_array($tokens[$p]) ? $tokens[$p][0] : $tokens[$p];
        if ($prev === T_FUNCTION) { continue; }
        $q = $i + 1; while ($q < $n && is_array($tokens[$q]) && $tokens[$q][0] === T_WHITESPACE) { $q++; }
        if ($tokens[$q] !== '(') { continue; }
        $receiver = ($prev === T_OBJECT_OPERATOR || $prev === T_DOUBLE_COLON) ? 'method' : 'function';
        $depth = 0; $args = 0; $seen = false; $named = false; $spread = false;
        for ($k = $q; $k < $n; $k++) {
            $x = $tokens[$k];
            $c = is_array($x) ? null : $x;
            if ($c === '(' || $c === '[' || $c === '{') { $depth++; if ($depth === 1) { continue; } }
            elseif ($c === ')' || $c === ']' || $c === '}') { $depth--; if ($depth === 0) { break; } }
            if ($depth === 1) {
                if ($c === ',') { $args++; $seen = false; continue; }
                if (is_array($x) && in_array($x[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
                if (is_array($x) && $x[0] === T_ELLIPSIS) { $spread = true; }
                if ($c === ':' && is_array($tokens[$k - 1] ?? null) === false) { /* ternary or named-argument colon */ }
                $seen = true;
            }
        }
        if ($seen) { $args++; }
        // named arguments: an identifier followed by a single colon at depth 1 (not ::)
        $rel = substr($path, strlen($root) + 1);
        $calls[] = ['file' => $rel, 'line' => $line, 'class' => $class, 'args' => $args, 'spread' => $spread];
    }
}
usort($calls, fn($a, $b) => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);
$byArgs = [];
foreach ($calls as $c) { $byArgs[$c['args']] = ($byArgs[$c['args']] ?? 0) + 1; }
ksort($byArgs);
$over = array_values(array_filter($calls, fn($c) => $c['args'] > 10 || $c['spread']));
$low = array_values(array_filter($calls, fn($c) => $c['args'] <= 3));
$group = function (string $prefix) use ($calls) { return count(array_filter($calls, fn($c) => str_starts_with($c['file'], $prefix))); };
echo json_encode([
    'root' => $root, 'php_files_scanned' => $files, 'calls_total' => count($calls),
    'calls_by_argument_count' => (object)$byArgs, 'max_arguments' => max(array_column($calls, 'args')),
    'calls_with_more_than_10_arguments_or_a_spread' => $over,
    'calls_with_3_or_fewer_arguments_are_the_controller_method_not_the_service' => array_count_values(array_map(fn($c) => $c['file'], $low)),
    'calls_by_area' => ['services' => $group('services/'), 'controllers' => $group('controllers/'), 'tests' => $group('tests/'), '.devtools' => $group('.devtools/'), 'other' => count($calls) - $group('services/') - $group('controllers/') - $group('tests/') - $group('.devtools/')],
    'production_calls' => array_values(array_filter($calls, fn($c) => str_starts_with($c['file'], 'services/') || str_starts_with($c['file'], 'controllers/') || str_starts_with($c['file'], 'helpers/') || str_starts_with($c['file'], 'middleware/') || str_starts_with($c['file'], 'bin/') || str_starts_with($c['file'], 'mcp/'))),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
