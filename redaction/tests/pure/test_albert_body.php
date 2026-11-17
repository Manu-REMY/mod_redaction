<?php
// Pure PHP check of the body sent to Albert: the reasoning of deepseek-v4-flash is switched off (17/11/2026: under load,
// Albert wrote about 12 tokens per second and its reasoning made a correction last more than 5 minutes).
// Usage: php test_albert_body.php

define('MOODLE_INTERNAL', true);

/**
 * Stand-in for Moodle's get_config: no admin key.
 *
 * @param string $plugin
 * @param string $name
 * @return string
 */
function get_config($plugin, $name) {
    return '';
}

require(__DIR__ . '/../../classes/ai_provider/provider_interface.php');
require(__DIR__ . '/../../classes/ai_provider/base_provider.php');
require(__DIR__ . '/../../classes/ai_provider/albert_provider.php');

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

$body = function(string $model): array {
    $provider = new \mod_redaction\ai_provider\albert_provider('cle');
    $method = new \ReflectionMethod($provider, 'build_body');
    return $method->invoke($provider, 'systeme', 'eleve', $model, 2000);
};

test('deepseek-v4-flash: reasoning switched off', fn() => ($body('deepseek-v4-flash')['chat_template_kwargs'] ?? null) === ['thinking' => false]);
test('deepseek-v4-flash: model, messages and max tokens unchanged', function() use ($body) {
    $b = $body('deepseek-v4-flash');
    return $b['model'] === 'deepseek-v4-flash' && $b['max_tokens'] === 2000 && $b['temperature'] === 0.3
        && $b['messages'] === [['role' => 'system', 'content' => 'systeme'], ['role' => 'user', 'content' => 'eleve']];
});
test('a model without reasoning: no extra parameter', fn() => !array_key_exists('chat_template_kwargs', $body('albert-base')));

echo $failures === 0 ? "\nOK\n" : "\n$failures FAIL\n";
exit($failures === 0 ? 0 : 1);
