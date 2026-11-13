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

namespace mod_redaction\navigation\views;

use core\navigation\views\secondary as core_secondary;

/**
 * Tabs of the activity: Results right after Settings, as in a quiz, then the overrides.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class secondary extends core_secondary {
    /**
     * Order of the tabs of the activity.
     *
     * @return array
     */
    protected function get_default_module_mapping(): array {
        $mapping = parent::get_default_module_mapping();
        $mapping[self::TYPE_SETTING] = array_merge($mapping[self::TYPE_SETTING], [
            'mod_redaction_results' => 2,
            'mod_redaction_overrides_user' => 3,
            'mod_redaction_overrides_group' => 4,
        ]);
        return $mapping;
    }
}
