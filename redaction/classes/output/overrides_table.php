<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_redaction\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Renderable for the overrides listing.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class overrides_table implements \renderable, \templatable {

    /** @var string 'user' or 'group' */
    protected $mode;
    /** @var int Course module id */
    protected $cmid;
    /** @var array<int,\stdClass> Override records joined with target label */
    protected $rows;
    /** @var int|null Instance deadline timestamp */
    protected $instancedeadline;

    public function __construct(string $mode, int $cmid, array $rows, ?int $instancedeadline) {
        $this->mode = $mode;
        $this->cmid = $cmid;
        $this->rows = $rows;
        $this->instancedeadline = $instancedeadline;
    }

    public function export_for_template(\renderer_base $output) {
        $data = (object) [
            'mode' => $this->mode,
            'cmid' => $this->cmid,
            'is_user_mode' => $this->mode === 'user',
            'is_group_mode' => $this->mode === 'group',
            'has_rows' => !empty($this->rows),
            'rows' => [],
            'instance_deadline' => $this->instancedeadline
                ? userdate($this->instancedeadline)
                : get_string('none'),
            'add_url' => (new \moodle_url('/mod/redaction/pages/override_edit.php', [
                'id' => $this->cmid,
                'mode' => $this->mode,
            ]))->out(false),
        ];

        foreach ($this->rows as $row) {
            $data->rows[] = (object) [
                'id' => (int) $row->id,
                'target' => $row->_target_label ?? '',
                'deadline' => !empty($row->deadline_date) ? userdate($row->deadline_date) : '',
                'sortorder' => (int) ($row->sortorder ?? 0),
                'edit_url' => (new \moodle_url('/mod/redaction/pages/override_edit.php', [
                    'id' => $this->cmid,
                    'overrideid' => (int) $row->id,
                ]))->out(false),
                'delete_url' => (new \moodle_url('/mod/redaction/pages/override_delete.php', [
                    'id' => $this->cmid,
                    'overrideid' => (int) $row->id,
                    'sesskey' => sesskey(),
                ]))->out(false),
            ];
        }

        return $data;
    }
}
