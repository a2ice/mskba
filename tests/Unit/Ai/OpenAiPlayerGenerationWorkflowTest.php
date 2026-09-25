<?php

namespace Tests\Unit\Ai;

use Tests\TestCase;

final class OpenAiPlayerGenerationWorkflowTest extends TestCase
{
    public function test_workflow_reports_openai_http_failures_and_avoids_unsupported_fidelity_field(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/openai-player-generate.yml'));

        $this->assertIsString($workflow);
        $this->assertStringNotContainsString('input_fidelity=high', $workflow);
        $this->assertStringContainsString('notify_failure generation_failed "${error_message}"', $workflow);
        $this->assertStringContainsString("provider_error=\"$(jq -r '.error.message // empty'", $workflow);
    }
}
