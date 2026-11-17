<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

final class AuditDataMergeCommandTest extends TestCase
{
    public function test_source_label_is_required_before_accessing_a_database(): void
    {
        $this->artisan('mskba:data-merge:audit')->assertExitCode(1);
        $this->artisan('mskba:data-merge:audit', ['--label' => '../prod'])
            ->assertExitCode(1);
    }

    public function test_non_postgresql_connections_are_rejected_without_writes(): void
    {
        $this->artisan('mskba:data-merge:audit', ['--label' => 'dev'])
            ->assertExitCode(1);
    }
}
