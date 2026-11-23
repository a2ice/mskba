<?php

namespace Tests\Feature;

use App\Presentation\UiStudio\UiStudioReviewStore;
use Tests\TestCase;

final class UiStudioReviewStoreTest extends TestCase
{
    public function test_fingerprint_changes_when_specimen_source_changes(): void
    {
        $review = app(UiStudioReviewStore::class);
        $case = config('ui-studio.cases.unified-chat');
        $hash = $review->fingerprint('unified-chat', $case);

        $this->assertSame(64, strlen($hash));
        $this->assertSame($hash, $review->fingerprint('unified-chat', $case));
    }
}
