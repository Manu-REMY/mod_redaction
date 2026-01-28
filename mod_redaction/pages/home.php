<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Home page content for redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Check if consignes are complete.
$consignescomplete = redaction_consignes_complete($redaction->id);
$correctioncomplete = redaction_correction_complete($redaction->id);

// Get consignes data.
$consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);

?>

<style>
    .redaction-home {
        max-width: 1200px;
        margin: 0 auto;
    }

    .redaction-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 25px;
        margin: 25px 0;
    }

    .redaction-card {
        background: white;
        border-radius: 12px;
        padding: 25px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
        border-top: 5px solid;
        position: relative;
    }

    .redaction-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
    }

    .redaction-card.teacher {
        border-top-color: #667eea;
    }

    .redaction-card.student {
        border-top-color: #48bb78;
    }

    .redaction-card.grading {
        border-top-color: #ed8936;
    }

    .card-icon {
        font-size: 48px;
        text-align: center;
        margin-bottom: 15px;
    }

    .card-title {
        font-size: 20px;
        font-weight: bold;
        color: #333;
        margin-bottom: 10px;
        text-align: center;
    }

    .card-description {
        color: #666;
        line-height: 1.6;
        margin-bottom: 15px;
        text-align: center;
        font-size: 14px;
    }

    .card-status {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 8px;
        border-radius: 6px;
        font-size: 14px;
        margin-bottom: 15px;
    }

    .card-status.complete {
        background: #d4edda;
        color: #155724;
    }

    .card-status.incomplete {
        background: #fff3cd;
        color: #856404;
    }

    .card-status.submitted {
        background: #cce5ff;
        color: #004085;
    }

    .card-status.graded {
        background: #d4edda;
        color: #155724;
    }

    .card-status.info {
        background: #e7f3ff;
        color: #0056b3;
    }

    .card-button {
        display: block;
        width: 100%;
        padding: 12px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        text-align: center;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.3s;
    }

    .card-button:hover {
        transform: scale(1.02);
        color: white;
        text-decoration: none;
    }

    .card-button.student-btn {
        background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
    }

    .card-button.grading-btn {
        background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
    }

    .section-header {
        margin: 30px 0 20px;
        padding-bottom: 10px;
        border-bottom: 3px solid #667eea;
    }

    .section-header h2 {
        color: #667eea;
        font-size: 24px;
        margin: 0;
    }

    .section-header p {
        color: #666;
        margin: 5px 0 0;
    }

    .alert-warning {
        margin: 20px 0;
        padding: 15px;
        background: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: 8px;
        color: #856404;
    }

    .alert-info {
        margin: 20px 0;
        padding: 15px;
        background: #e7f3ff;
        border: 1px solid #b8daff;
        border-radius: 8px;
        color: #004085;
    }

    .group-badge {
        display: inline-block;
        background: #667eea;
        color: white;
        padding: 4px 12px;
        border-radius: 20px;
        font-weight: 600;
    }
</style>

<div class="redaction-home">

    <?php if ($caneditconsignes): ?>
        <!-- Section Enseignant -->
        <div class="section-header">
            <h2><?php echo get_string('teacher_section', 'redaction'); ?></h2>
            <p><?php echo get_string('consignes_desc', 'redaction'); ?></p>
        </div>

        <div class="redaction-cards">
            <!-- Carte Consignes -->
            <div class="redaction-card teacher">
                <div class="card-icon">📋</div>
                <h3 class="card-title"><?php echo get_string('consignes', 'redaction'); ?></h3>
                <p class="card-description"><?php echo get_string('consignes_desc', 'redaction'); ?></p>

                <?php if ($consignescomplete): ?>
                    <div class="card-status complete">
                        ✓ <?php echo get_string('complete', 'redaction'); ?>
                    </div>
                <?php else: ?>
                    <div class="card-status incomplete">
                        ⏳ <?php echo get_string('incomplete', 'redaction'); ?>
                    </div>
                <?php endif; ?>

                <a href="view.php?id=<?php echo $cm->id; ?>&page=consignes" class="card-button">
                    <?php echo get_string('consignes', 'redaction'); ?>
                </a>
            </div>

            <!-- Carte Modèle de correction -->
            <div class="redaction-card teacher">
                <div class="card-icon">📝</div>
                <h3 class="card-title"><?php echo get_string('correction_model', 'redaction'); ?></h3>
                <p class="card-description"><?php echo get_string('correction_model_desc', 'redaction'); ?></p>

                <?php if ($correctioncomplete): ?>
                    <div class="card-status complete">
                        ✓ <?php echo get_string('complete', 'redaction'); ?>
                    </div>
                <?php else: ?>
                    <div class="card-status incomplete">
                        ⏳ <?php echo get_string('incomplete', 'redaction'); ?>
                    </div>
                <?php endif; ?>

                <a href="view.php?id=<?php echo $cm->id; ?>&page=correction" class="card-button">
                    <?php echo get_string('correction_model', 'redaction'); ?>
                </a>
            </div>
        </div>

        <!-- Section Notation (si permission) -->
        <?php if ($cangrade): ?>
            <div class="section-header" style="border-bottom-color: #ed8936;">
                <h2 style="color: #ed8936;"><?php echo get_string('grading', 'redaction'); ?></h2>
                <p><?php echo get_string('grading_desc', 'redaction'); ?></p>
            </div>

            <div class="redaction-cards">
                <div class="redaction-card grading">
                    <div class="card-icon">✏️</div>
                    <h3 class="card-title"><?php echo get_string('grading', 'redaction'); ?></h3>
                    <p class="card-description"><?php echo get_string('grading_desc', 'redaction'); ?></p>

                    <div class="card-status info">
                        📊 <?php
                        // Count submissions.
                        $submittedcount = $DB->count_records('redaction_submission', [
                            'redactionid' => $redaction->id,
                            'status' => 1
                        ]);
                        echo $submittedcount . ' soumission(s)';
                        ?>
                    </div>

                    <a href="view.php?id=<?php echo $cm->id; ?>&page=grading" class="card-button grading-btn">
                        <?php echo get_string('grading', 'redaction'); ?>
                    </a>
                </div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- Section Élève -->
        <div class="section-header" style="border-bottom-color: #48bb78;">
            <h2 style="color: #48bb78;"><?php echo get_string('student_section', 'redaction'); ?></h2>
        </div>

        <?php if (!$consignescomplete): ?>
            <div class="alert-warning">
                ⚠️ <?php echo get_string('error:noconsignes', 'redaction'); ?>
            </div>
        <?php elseif ($redaction->group_submission && $usergroup == 0): ?>
            <div class="alert-warning">
                ❌ Vous n'êtes dans aucun groupe. Contactez votre enseignant.
            </div>
        <?php else: ?>
            <?php
            // Get group info if group mode.
            $groupinfo = null;
            if ($redaction->group_submission && $usergroup > 0) {
                $groupinfo = $DB->get_record('groups', ['id' => $usergroup]);
            }

            // Get submission.
            $submission = redaction_get_or_create_submission($redaction, $usergroup, $USER->id);
            ?>

            <?php if ($groupinfo): ?>
                <div class="alert-info">
                    👥 Vous travaillez en groupe : <span class="group-badge"><?php echo s($groupinfo->name); ?></span>
                </div>
            <?php endif; ?>

            <div class="redaction-cards">
                <!-- Carte Consignes (lecture seule) -->
                <div class="redaction-card teacher" style="opacity: 0.9;">
                    <div class="card-icon">📋</div>
                    <h3 class="card-title"><?php echo get_string('consignes', 'redaction'); ?></h3>
                    <p class="card-description">
                        <?php echo $consignes && $consignes->titre ? s($consignes->titre) : get_string('consignes_desc', 'redaction'); ?>
                    </p>

                    <div class="card-status info">
                        👁️ Consultation
                    </div>

                    <a href="view.php?id=<?php echo $cm->id; ?>&page=redaction" class="card-button">
                        Voir les consignes
                    </a>
                </div>

                <!-- Carte Ma rédaction -->
                <div class="redaction-card student">
                    <div class="card-icon">✍️</div>
                    <h3 class="card-title"><?php echo get_string('my_redaction', 'redaction'); ?></h3>
                    <p class="card-description"><?php echo get_string('my_redaction_desc', 'redaction'); ?></p>

                    <?php if ($submission->status == 1): ?>
                        <div class="card-status submitted">
                            ✓ <?php echo get_string('status_submitted', 'redaction'); ?>
                        </div>
                        <?php if ($submission->grade !== null): ?>
                            <div class="card-status graded">
                                Note : <?php echo number_format($submission->grade, 1); ?> / 20
                            </div>
                        <?php endif; ?>
                    <?php elseif (!empty($submission->contenu)): ?>
                        <div class="card-status incomplete">
                            📝 <?php echo get_string('status_draft', 'redaction'); ?>
                        </div>
                    <?php else: ?>
                        <div class="card-status incomplete">
                            ⏳ À compléter
                        </div>
                    <?php endif; ?>

                    <a href="view.php?id=<?php echo $cm->id; ?>&page=redaction" class="card-button student-btn">
                        <?php echo $submission->status == 1 ? 'Voir ma rédaction' : 'Travailler'; ?>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</div>
