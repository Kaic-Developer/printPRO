<?php

namespace Tests\Unit;

use App\Services\QuoteWizardValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QuoteWizardValidatorTest extends TestCase
{
    public function test_visible_optional_fields_remain_optional_and_visible_required_fields_are_required(): void
    {
        $schema = ['fields' => [
            ['key' => 'personalization', 'type' => 'select', 'required' => true, 'options' => [['value' => 'embroidery']]],
            ['key' => 'estimated_stitches', 'type' => 'integer', 'required' => false, 'visible_when' => ['field' => 'personalization', 'equals' => 'embroidery']],
            ['key' => 'embroidery_matrix', 'type' => 'boolean', 'required' => true, 'visible_when' => ['field' => 'personalization', 'equals' => 'embroidery']],
        ]];

        $answers = (new QuoteWizardValidator)->validate($schema, ['personalization' => 'embroidery', 'embroidery_matrix' => '0']);

        $this->assertArrayNotHasKey('estimated_stitches', $answers);
        $this->assertSame('0', $answers['embroidery_matrix']);
    }

    public function test_visible_required_fields_cannot_be_omitted(): void
    {
        $this->expectException(ValidationException::class);

        (new QuoteWizardValidator)->validate(['fields' => [
            ['key' => 'personalization', 'type' => 'select', 'required' => true, 'options' => [['value' => 'embroidery']]],
            ['key' => 'embroidery_matrix', 'type' => 'boolean', 'required' => true, 'visible_when' => ['field' => 'personalization', 'equals' => 'embroidery']],
        ]], ['personalization' => 'embroidery']);
    }

    public function test_nested_conditional_fields_are_ignored_when_their_controller_is_hidden(): void
    {
        $schema = ['fields' => [
            ['key' => 'technique', 'type' => 'select', 'required' => true, 'options' => [['value' => 'dtf'], ['value' => 'dtg']]],
            ['key' => 'print_size', 'type' => 'select', 'required' => true, 'options' => [['value' => 'a4'], ['value' => 'custom_area']], 'visible_when' => ['field' => 'technique', 'equals' => 'dtf']],
            ['key' => 'custom_width_cm', 'type' => 'decimal', 'required' => true, 'visible_when' => ['field' => 'print_size', 'equals' => 'custom_area']],
        ]];

        $answers = (new QuoteWizardValidator)->validate($schema, [
            'technique' => 'dtg',
            'print_size' => 'custom_area',
            'custom_width_cm' => 'invalid-but-hidden',
        ]);

        $this->assertSame(['technique' => 'dtg'], $answers);
    }
}
