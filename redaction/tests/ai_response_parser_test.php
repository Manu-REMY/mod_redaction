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
 * Unit tests for mod_redaction ai_response_parser class.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

/**
 * Test class for ai_response_parser.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_redaction\ai_response_parser
 */
class ai_response_parser_test extends \advanced_testcase {

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Test parsing a valid JSON response.
     */
    public function test_parse_valid_json(): void {
        $json = json_encode([
            'grade' => 15.5,
            'feedback' => 'Good work overall.',
            'criteria' => [
                ['name' => 'Pertinence', 'score' => 4, 'max' => 5, 'comment' => 'Relevant content'],
                ['name' => 'Structure', 'score' => 3.5, 'max' => 5, 'comment' => 'Well organized'],
            ],
            'keywords_found' => ['revolution', 'democracy'],
            'keywords_missing' => ['constitution'],
            'suggestions' => ['Add more historical context'],
            'confidence' => 0.85,
        ]);

        $result = ai_response_parser::parse($json);

        $this->assertEquals(15.5, $result->grade);
        $this->assertStringContainsString('Good work overall.', $result->feedback);
        $this->assertCount(2, $result->criteria);
        $this->assertEquals('Pertinence', $result->criteria[0]->name);
        $this->assertEquals(4, $result->criteria[0]->score);
        $this->assertEquals(5, $result->criteria[0]->max);
        $this->assertCount(2, $result->keywords_found);
        $this->assertCount(1, $result->keywords_missing);
        $this->assertCount(1, $result->suggestions);
        $this->assertEquals(0.85, $result->confidence);
    }

    /**
     * Test parsing JSON embedded in markdown code block.
     */
    public function test_parse_json_in_markdown(): void {
        $content = "Here is my evaluation:\n\n```json\n" . json_encode([
            'grade' => 12.0,
            'feedback' => 'Average work.',
            'criteria' => [],
            'confidence' => 0.7,
        ]) . "\n```\n\nHope this helps!";

        $result = ai_response_parser::parse($content);

        $this->assertEquals(12.0, $result->grade);
        $this->assertStringContainsString('Average work.', $result->feedback);
    }

    /**
     * Test parsing JSON embedded in code block without json language specifier.
     */
    public function test_parse_json_in_plain_code_block(): void {
        $content = "```\n" . json_encode([
            'grade' => 18.0,
            'feedback' => 'Excellent!',
        ]) . "\n```";

        $result = ai_response_parser::parse($content);
        $this->assertEquals(18.0, $result->grade);
    }

    /**
     * Test parsing with JSON mixed into prose text.
     */
    public function test_parse_json_in_prose(): void {
        $content = 'After careful evaluation, I give the following result: '
            . json_encode(['grade' => 14.0, 'feedback' => 'Good effort.'])
            . ' That is my assessment.';

        $result = ai_response_parser::parse($content);
        $this->assertEquals(14.0, $result->grade);
    }

    /**
     * Test grade clamping to maximum (20).
     */
    public function test_grade_clamping_max(): void {
        $json = json_encode(['grade' => 25.0, 'feedback' => 'Off scale']);

        $result = ai_response_parser::parse($json);
        $this->assertEquals(20.0, $result->grade);
    }

    /**
     * Test grade clamping to minimum (0).
     */
    public function test_grade_clamping_min(): void {
        $json = json_encode(['grade' => -5.0, 'feedback' => 'Negative']);

        $result = ai_response_parser::parse($json);
        $this->assertEquals(0.0, $result->grade);
    }

    /**
     * Test grade clamping at exact boundaries.
     */
    public function test_grade_at_boundaries(): void {
        $json0 = json_encode(['grade' => 0.0, 'feedback' => 'Zero']);
        $result0 = ai_response_parser::parse($json0);
        $this->assertEquals(0.0, $result0->grade);

        $json20 = json_encode(['grade' => 20.0, 'feedback' => 'Perfect']);
        $result20 = ai_response_parser::parse($json20);
        $this->assertEquals(20.0, $result20->grade);
    }

    /**
     * Test MIN_GRADE and MAX_GRADE constants.
     */
    public function test_grade_constants(): void {
        $this->assertEquals(0.0, ai_response_parser::MIN_GRADE);
        $this->assertEquals(20.0, ai_response_parser::MAX_GRADE);
    }

    /**
     * Test confidence clamping between 0 and 1.
     */
    public function test_confidence_clamping(): void {
        $json = json_encode(['grade' => 10.0, 'feedback' => 'Ok', 'confidence' => 1.5]);
        $result = ai_response_parser::parse($json);
        $this->assertEquals(1.0, $result->confidence);

        $json2 = json_encode(['grade' => 10.0, 'feedback' => 'Ok', 'confidence' => -0.5]);
        $result2 = ai_response_parser::parse($json2);
        $this->assertEquals(0.0, $result2->confidence);
    }

    /**
     * Test default confidence when not provided.
     */
    public function test_default_confidence(): void {
        $json = json_encode(['grade' => 10.0, 'feedback' => 'Ok']);
        $result = ai_response_parser::parse($json);
        $this->assertEquals(0.8, $result->confidence);
    }

    /**
     * Test criteria extraction with all fields.
     */
    public function test_criteria_extraction(): void {
        $json = json_encode([
            'grade' => 14.0,
            'feedback' => 'Good.',
            'criteria' => [
                ['name' => 'Pertinence', 'score' => 4, 'max' => 5, 'comment' => 'Relevant'],
                ['name' => 'Structure', 'score' => 3, 'max' => 5, 'comment' => 'Needs improvement'],
                ['name' => 'Expression', 'score' => 4.5, 'max' => 5, 'comment' => 'Well written'],
                ['name' => 'Argumentation', 'score' => 2.5, 'max' => 5, 'comment' => 'Weak arguments'],
            ],
        ]);

        $result = ai_response_parser::parse($json);

        $this->assertCount(4, $result->criteria);

        $this->assertEquals('Pertinence', $result->criteria[0]->name);
        $this->assertEquals(4, $result->criteria[0]->score);
        $this->assertEquals(5, $result->criteria[0]->max);
        $this->assertStringContainsString('Relevant', $result->criteria[0]->comment);

        $this->assertEquals('Argumentation', $result->criteria[3]->name);
        $this->assertEquals(2.5, $result->criteria[3]->score);
    }

    /**
     * Test criteria with missing optional fields use defaults.
     */
    public function test_criteria_defaults(): void {
        $json = json_encode([
            'grade' => 10.0,
            'feedback' => 'Ok',
            'criteria' => [
                ['name' => 'Test'],
            ],
        ]);

        $result = ai_response_parser::parse($json);

        $this->assertCount(1, $result->criteria);
        $this->assertEquals('Test', $result->criteria[0]->name);
        $this->assertEquals(0, $result->criteria[0]->score);
        $this->assertEquals(5, $result->criteria[0]->max);
        $this->assertEquals('', $result->criteria[0]->comment);
    }

    /**
     * Test empty criteria array.
     */
    public function test_empty_criteria(): void {
        $json = json_encode(['grade' => 10.0, 'feedback' => 'Ok', 'criteria' => []]);

        $result = ai_response_parser::parse($json);
        $this->assertCount(0, $result->criteria);
    }

    /**
     * Test missing criteria key defaults to empty array.
     */
    public function test_missing_criteria(): void {
        $json = json_encode(['grade' => 10.0, 'feedback' => 'Ok']);

        $result = ai_response_parser::parse($json);
        $this->assertCount(0, $result->criteria);
    }

    /**
     * Test keywords_found extraction.
     */
    public function test_keywords_found(): void {
        $json = json_encode([
            'grade' => 10.0,
            'feedback' => 'Ok',
            'keywords_found' => ['term1', ' term2 ', 'term3'],
        ]);

        $result = ai_response_parser::parse($json);
        $this->assertCount(3, $result->keywords_found);
        $this->assertEquals('term2', $result->keywords_found[1]); // Should be trimmed.
    }

    /**
     * Test keywords_missing extraction.
     */
    public function test_keywords_missing(): void {
        $json = json_encode([
            'grade' => 10.0,
            'feedback' => 'Ok',
            'keywords_missing' => ['missing1', 'missing2'],
        ]);

        $result = ai_response_parser::parse($json);
        $this->assertCount(2, $result->keywords_missing);
        $this->assertContains('missing1', $result->keywords_missing);
    }

    /**
     * Test empty keywords default to empty arrays.
     */
    public function test_empty_keywords(): void {
        $json = json_encode(['grade' => 10.0, 'feedback' => 'Ok']);

        $result = ai_response_parser::parse($json);
        $this->assertIsArray($result->keywords_found);
        $this->assertEmpty($result->keywords_found);
        $this->assertIsArray($result->keywords_missing);
        $this->assertEmpty($result->keywords_missing);
    }

    /**
     * Test suggestions extraction.
     */
    public function test_suggestions(): void {
        $json = json_encode([
            'grade' => 10.0,
            'feedback' => 'Ok',
            'suggestions' => ['Try harder', 'Use more examples'],
        ]);

        $result = ai_response_parser::parse($json);
        $this->assertCount(2, $result->suggestions);
    }

    /**
     * Test empty suggestions default.
     */
    public function test_empty_suggestions(): void {
        $json = json_encode(['grade' => 10.0, 'feedback' => 'Ok']);

        $result = ai_response_parser::parse($json);
        $this->assertIsArray($result->suggestions);
        $this->assertEmpty($result->suggestions);
    }

    /**
     * Test parse throws for completely invalid content.
     */
    public function test_parse_invalid_content(): void {
        $this->expectException(\moodle_exception::class);
        ai_response_parser::parse('This is not JSON at all and has no braces.');
    }

    /**
     * Test missing grade defaults to 0.
     */
    public function test_missing_grade_defaults_zero(): void {
        $json = json_encode(['feedback' => 'No grade given']);

        $result = ai_response_parser::parse($json);
        $this->assertEquals(0.0, $result->grade);
    }

    /**
     * Test missing feedback defaults to empty string.
     */
    public function test_missing_feedback_defaults_empty(): void {
        $json = json_encode(['grade' => 10.0]);

        $result = ai_response_parser::parse($json);
        $this->assertEquals('', $result->feedback);
    }

    /**
     * Test calculate_grade_from_criteria with valid criteria.
     */
    public function test_calculate_grade_from_criteria(): void {
        $criteria = [
            (object) ['score' => 4, 'max' => 5],
            (object) ['score' => 3, 'max' => 5],
            (object) ['score' => 5, 'max' => 5],
            (object) ['score' => 2, 'max' => 5],
        ];

        // (4+3+5+2) / (5+5+5+5) * 20 = 14/20 * 20 = 14.0.
        $grade = ai_response_parser::calculate_grade_from_criteria($criteria);
        $this->assertEquals(14.0, $grade);
    }

    /**
     * Test calculate_grade_from_criteria with array format.
     */
    public function test_calculate_grade_from_criteria_array(): void {
        $criteria = [
            ['score' => 5, 'max' => 5],
            ['score' => 5, 'max' => 5],
        ];

        $grade = ai_response_parser::calculate_grade_from_criteria($criteria);
        $this->assertEquals(20.0, $grade);
    }

    /**
     * Test calculate_grade_from_criteria with empty array.
     */
    public function test_calculate_grade_from_criteria_empty(): void {
        $grade = ai_response_parser::calculate_grade_from_criteria([]);
        $this->assertEquals(0.0, $grade);
    }

    /**
     * Test calculate_grade_from_criteria with zero max.
     */
    public function test_calculate_grade_from_criteria_zero_max(): void {
        $criteria = [
            (object) ['score' => 0, 'max' => 0],
        ];

        $grade = ai_response_parser::calculate_grade_from_criteria($criteria);
        $this->assertEquals(0.0, $grade);
    }

    /**
     * Test format_for_display returns HTML with grade.
     */
    public function test_format_for_display(): void {
        $result = (object) [
            'grade' => 15.5,
            'feedback' => 'Good work.',
            'criteria' => [
                (object) ['name' => 'Pertinence', 'score' => 4, 'max' => 5, 'comment' => 'Relevant'],
            ],
            'keywords_found' => [],
            'keywords_missing' => [],
            'suggestions' => [],
            'confidence' => 0.9,
        ];

        $html = ai_response_parser::format_for_display($result);

        $this->assertStringContainsString('15.5', $html);
        $this->assertStringContainsString('/20', $html);
        $this->assertStringContainsString('Good work.', $html);
        $this->assertStringContainsString('Pertinence', $html);
        $this->assertStringContainsString('4/5', $html);
    }

    /**
     * Test format_for_display with empty feedback.
     */
    public function test_format_for_display_empty_feedback(): void {
        $result = (object) [
            'grade' => 10.0,
            'feedback' => '',
            'criteria' => [],
            'keywords_found' => [],
            'keywords_missing' => [],
            'suggestions' => [],
            'confidence' => 0.5,
        ];

        $html = ai_response_parser::format_for_display($result);

        $this->assertStringContainsString('10.0', $html);
        $this->assertStringContainsString('ai-result', $html);
    }

    /**
     * Test feedback sanitization strips dangerous tags.
     */
    public function test_feedback_sanitization(): void {
        $json = json_encode([
            'grade' => 10.0,
            'feedback' => '<script>alert("xss")</script><p>Safe content</p>',
        ]);

        $result = ai_response_parser::parse($json);

        $this->assertStringNotContainsString('<script>', $result->feedback);
        $this->assertStringContainsString('Safe content', $result->feedback);
    }

    /**
     * Test parsing with integer grade (not float).
     */
    public function test_integer_grade(): void {
        $json = json_encode(['grade' => 15, 'feedback' => 'Integer grade']);

        $result = ai_response_parser::parse($json);
        $this->assertEquals(15.0, $result->grade);
        $this->assertIsFloat($result->grade);
    }

    /**
     * Test strengths extraction.
     */
    public function test_strengths_extraction(): void {
        $json = json_encode([
            'grade' => 15.0,
            'feedback' => 'Good.',
            'strengths' => ['Good argumentation', 'Clear structure', 'Rich vocabulary'],
        ]);

        $result = ai_response_parser::parse($json);
        $this->assertCount(3, $result->strengths);
        $this->assertStringContainsString('Good argumentation', $result->strengths[0]);
        $this->assertStringContainsString('Clear structure', $result->strengths[1]);
    }

    /**
     * Test weaknesses extraction.
     */
    public function test_weaknesses_extraction(): void {
        $json = json_encode([
            'grade' => 12.0,
            'feedback' => 'Average.',
            'weaknesses' => ['Needs better transitions', 'Lack of examples'],
        ]);

        $result = ai_response_parser::parse($json);
        $this->assertCount(2, $result->weaknesses);
        $this->assertStringContainsString('Needs better transitions', $result->weaknesses[0]);
    }

    /**
     * Test empty strengths/weaknesses default to empty arrays.
     */
    public function test_empty_strengths_weaknesses(): void {
        $json = json_encode(['grade' => 10.0, 'feedback' => 'Ok']);

        $result = ai_response_parser::parse($json);
        $this->assertIsArray($result->strengths);
        $this->assertEmpty($result->strengths);
        $this->assertIsArray($result->weaknesses);
        $this->assertEmpty($result->weaknesses);
    }

    /**
     * Test overall_appreciation extraction.
     */
    public function test_overall_appreciation(): void {
        $json = json_encode([
            'grade' => 16.0,
            'feedback' => 'Good work.',
            'overall_appreciation' => 'An excellent essay showing clear understanding.',
        ]);

        $result = ai_response_parser::parse($json);
        $this->assertStringContainsString('excellent essay', $result->overall_appreciation);
    }

    /**
     * Test empty overall_appreciation defaults to empty string.
     */
    public function test_empty_overall_appreciation(): void {
        $json = json_encode(['grade' => 10.0, 'feedback' => 'Ok']);

        $result = ai_response_parser::parse($json);
        $this->assertEquals('', $result->overall_appreciation);
    }

    /**
     * Test criteria level extraction from explicit value.
     */
    public function test_criteria_level_explicit(): void {
        $json = json_encode([
            'grade' => 15.0,
            'feedback' => 'Good.',
            'criteria' => [
                ['name' => 'Pertinence', 'score' => 4, 'max' => 5, 'comment' => 'Good', 'level' => 'good'],
                ['name' => 'Structure', 'score' => 2, 'max' => 5, 'comment' => 'Weak', 'level' => 'medium'],
            ],
        ]);

        $result = ai_response_parser::parse($json);
        $this->assertEquals('good', $result->criteria[0]->level);
        $this->assertEquals('medium', $result->criteria[1]->level);
    }

    /**
     * Test criteria level is calculated when not provided.
     */
    public function test_criteria_level_calculated(): void {
        $json = json_encode([
            'grade' => 15.0,
            'feedback' => 'Good.',
            'criteria' => [
                ['name' => 'Pertinence', 'score' => 5, 'max' => 5, 'comment' => 'Perfect'],
                ['name' => 'Structure', 'score' => 3.5, 'max' => 5, 'comment' => 'Good'],
                ['name' => 'Expression', 'score' => 2, 'max' => 5, 'comment' => 'Average'],
                ['name' => 'Argumentation', 'score' => 1, 'max' => 5, 'comment' => 'Weak'],
            ],
        ]);

        $result = ai_response_parser::parse($json);
        $this->assertEquals('excellent', $result->criteria[0]->level); // 100%
        $this->assertEquals('good', $result->criteria[1]->level);      // 70%
        $this->assertEquals('medium', $result->criteria[2]->level);    // 40%
        $this->assertEquals('low', $result->criteria[3]->level);       // 20%
    }

    /**
     * Test invalid criteria level falls back to calculated.
     */
    public function test_criteria_level_invalid_falls_back(): void {
        $json = json_encode([
            'grade' => 15.0,
            'feedback' => 'Good.',
            'criteria' => [
                ['name' => 'Test', 'score' => 4, 'max' => 5, 'comment' => 'Good', 'level' => 'invalid_value'],
            ],
        ]);

        $result = ai_response_parser::parse($json);
        $this->assertEquals('excellent', $result->criteria[0]->level); // 80% = excellent
    }

    /**
     * Test calculate_level static method.
     */
    public function test_calculate_level(): void {
        $this->assertEquals('excellent', ai_response_parser::calculate_level(80));
        $this->assertEquals('excellent', ai_response_parser::calculate_level(100));
        $this->assertEquals('good', ai_response_parser::calculate_level(60));
        $this->assertEquals('good', ai_response_parser::calculate_level(79));
        $this->assertEquals('medium', ai_response_parser::calculate_level(40));
        $this->assertEquals('medium', ai_response_parser::calculate_level(59));
        $this->assertEquals('low', ai_response_parser::calculate_level(39));
        $this->assertEquals('low', ai_response_parser::calculate_level(0));
    }

    /**
     * Test get_grade_level static method.
     */
    public function test_get_grade_level(): void {
        $this->assertEquals('excellent', ai_response_parser::get_grade_level(16.0)); // 80%
        $this->assertEquals('excellent', ai_response_parser::get_grade_level(20.0)); // 100%
        $this->assertEquals('good', ai_response_parser::get_grade_level(12.0));      // 60%
        $this->assertEquals('medium', ai_response_parser::get_grade_level(8.0));     // 40%
        $this->assertEquals('low', ai_response_parser::get_grade_level(4.0));        // 20%
        $this->assertEquals('low', ai_response_parser::get_grade_level(0.0));        // 0%
    }

    /**
     * Test full enhanced response parsing with all new fields.
     */
    public function test_parse_full_enhanced_response(): void {
        $json = json_encode([
            'grade' => 15.0,
            'feedback' => 'Ton paragraphe est bien structuré et montre une bonne compréhension.',
            'criteria' => [
                ['name' => 'Clarté', 'score' => 4, 'max' => 5, 'comment' => 'Clear', 'level' => 'excellent'],
                ['name' => 'Pertinence', 'score' => 4, 'max' => 5, 'comment' => 'Relevant', 'level' => 'good'],
                ['name' => 'Structure', 'score' => 3, 'max' => 5, 'comment' => 'Ok', 'level' => 'medium'],
                ['name' => 'Originalité', 'score' => 2, 'max' => 5, 'comment' => 'Basic', 'level' => 'medium'],
            ],
            'strengths' => ['Good examples', 'Clear language'],
            'weaknesses' => ['Needs more depth', 'Missing conclusion'],
            'keywords_found' => ['IA', 'technologie'],
            'keywords_missing' => ['éthique', 'données'],
            'suggestions' => ['Add a conclusion', 'Explore ethical aspects'],
            'overall_appreciation' => 'A solid effort with room for growth.',
            'confidence' => 0.88,
        ]);

        $result = ai_response_parser::parse($json);

        // Grade & confidence.
        $this->assertEquals(15.0, $result->grade);
        $this->assertEquals(0.88, $result->confidence);

        // Criteria with levels.
        $this->assertCount(4, $result->criteria);
        $this->assertEquals('excellent', $result->criteria[0]->level);
        $this->assertEquals('good', $result->criteria[1]->level);
        $this->assertEquals('medium', $result->criteria[2]->level);

        // Strengths & Weaknesses.
        $this->assertCount(2, $result->strengths);
        $this->assertCount(2, $result->weaknesses);

        // Keywords.
        $this->assertCount(2, $result->keywords_found);
        $this->assertCount(2, $result->keywords_missing);

        // Suggestions.
        $this->assertCount(2, $result->suggestions);

        // Overall appreciation.
        $this->assertStringContainsString('solid effort', $result->overall_appreciation);
    }

    /**
     * Test format_for_display includes new fields.
     */
    public function test_format_for_display_enhanced(): void {
        $result = (object) [
            'grade' => 16.0,
            'feedback' => 'Excellent work.',
            'criteria' => [
                (object) ['name' => 'Pertinence', 'score' => 4.5, 'max' => 5, 'comment' => 'Great', 'level' => 'excellent'],
            ],
            'strengths' => ['Strong argument', 'Clear writing'],
            'weaknesses' => ['Missing counterpoints'],
            'keywords_found' => ['democracy'],
            'keywords_missing' => ['constitution'],
            'suggestions' => ['Add counterarguments', 'Improve conclusion'],
            'overall_appreciation' => 'Outstanding essay.',
            'confidence' => 0.92,
        ];

        $html = ai_response_parser::format_for_display($result);

        // Check grade and level.
        $this->assertStringContainsString('16.0', $html);
        $this->assertStringContainsString('/20', $html);
        $this->assertStringContainsString('ai-level-excellent', $html);

        // Check overall appreciation.
        $this->assertStringContainsString('Outstanding essay.', $html);

        // Check strengths & weaknesses.
        $this->assertStringContainsString('Strong argument', $html);
        $this->assertStringContainsString('Missing counterpoints', $html);

        // Check suggestions.
        $this->assertStringContainsString('Add counterarguments', $html);
        $this->assertStringContainsString('Improve conclusion', $html);
    }

    /**
     * Test strengths/weaknesses HTML sanitization.
     */
    public function test_strengths_weaknesses_sanitization(): void {
        $json = json_encode([
            'grade' => 10.0,
            'feedback' => 'Ok',
            'strengths' => ['<script>bad()</script>Good point'],
            'weaknesses' => ['<img onerror="bad()" src="">Needs work'],
        ]);

        $result = ai_response_parser::parse($json);
        $this->assertStringNotContainsString('<script>', $result->strengths[0]);
        $this->assertStringContainsString('Good point', $result->strengths[0]);
        $this->assertStringNotContainsString('onerror', $result->weaknesses[0]);
        $this->assertStringContainsString('Needs work', $result->weaknesses[0]);
    }
}
