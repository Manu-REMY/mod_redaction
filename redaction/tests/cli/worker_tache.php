<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Runs one evaluation through the real task class in a fresh process, with what a cron process has
 * loaded and nothing more. Started by verif_correction.php, which holds the checks.
 *
 * Usage: php worker_tache.php reseau|simule <evaluationid>
 * reseau: the real provider code, sent to a closed local port with a dummy key: curl runs, no AI is reached.
 * simule: a stand-in provider, to go through the whole write path.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

$dir = __DIR__;
$configfile = null;
while ($dir !== dirname($dir)) {
    if (is_readable($dir . '/config.php') && is_readable($dir . '/version.php') && is_dir($dir . '/lib')) {
        $configfile = $dir . '/config.php';
        break;
    }
    $dir = dirname($dir);
}
if ($configfile === null) {
    fwrite(STDERR, "config.php not found\n");
    exit(2);
}
require($configfile);

if ($argv[1] === 'reseau') {
    $provider = new class('cle-factice') extends \mod_redaction\ai_provider\openai_provider {
        /** @var int No retry: the port is closed on purpose. */
        protected int $maxretries = 0;

        protected function get_endpoint(): string {
            return 'http://127.0.0.1:9/v1/chat/completions';
        }
    };
} else {
    $provider = new class implements \mod_redaction\ai_provider\provider_interface {
        public function get_name(): string {
            return 'simule';
        }

        public function get_models(): array {
            return ['simule'];
        }

        public function get_default_model(): string {
            return 'simule';
        }

        public function evaluate(string $systemprompt, string $userprompt, string $model, int $maxtokens): array {
            return ['content' => json_encode(['note' => 15, 'commentaire' => 'Bien.']), 'prompt_tokens' => 1, 'completion_tokens' => 1];
        }

        public function estimate_tokens(string $text): int {
            return 1;
        }

        public function test_connection(): array {
            return ['success' => true, 'message' => ''];
        }
    };
}
\mod_redaction\ai_evaluator::$fournisseurtest = $provider;

// What must be missing for this check to mean anything.
echo 'curl-before=' . (class_exists('curl', false) ? 1 : 0) . "\n";
$coursedir = $CFG->dirroot . '/course/lib.php';
echo 'courselib-before=' . (in_array(realpath($coursedir), get_included_files(), true) ? 1 : 0) . "\n";

$task = new \mod_redaction\task\evaluate_submission();
$task->set_custom_data(['evaluationid' => (int) $argv[2]]);
$task->execute();
exit(0);
