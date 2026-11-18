<?php
// Pure PHP check (no Moodle needed): the AMD modules only load core modules that still exist in Moodle 5.3.
// core/modal_factory was removed in Moodle 5.3: a module that requires it never runs (RequireJS error).
// Usage: php test_moodle53_modules.php

$failures = 0;
function test(string $label, callable $fn): void {
    global $failures;
    $ok = false;
    try {
        $ok = (bool) $fn();
    } catch (\Throwable $e) {
        echo 'EXC   ' . $e->getMessage() . "\n";
    }
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

$amd = __DIR__ . '/../../amd';
$removed = ['core/modal_factory'];

foreach (array_merge(glob($amd . '/src/*.js'), glob($amd . '/build/*.min.js')) as $file) {
    $code = file_get_contents($file);
    foreach ($removed as $module) {
        test(basename($file) . ' does not load ' . $module, function() use ($code, $module) {
            return strpos($code, $module) === false;
        });
    }
}

test('grading_overview confirms with core/modal_save_cancel', function() use ($amd) {
    $src = file_get_contents($amd . '/src/grading_overview.js');
    return strpos($src, "'core/modal_save_cancel'") !== false && strpos($src, 'ModalSaveCancel.create(') !== false;
});

test('grading_overview build is up to date (uses core/modal_save_cancel)', function() use ($amd) {
    return strpos(file_get_contents($amd . '/build/grading_overview.min.js'), 'core/modal_save_cancel') !== false;
});

test('closing the confirmation with the cross counts as a cancel', function() use ($amd) {
    $src = file_get_contents($amd . '/src/grading_overview.js');
    return (bool) preg_match('/ModalEvents\.hidden, function\(\) \{\s*resolve\(\{confirmed: false/', $src);
});

echo $failures ? "\n$failures failure(s)\n" : "\nAll tests passed\n";
exit($failures ? 1 : 0);
