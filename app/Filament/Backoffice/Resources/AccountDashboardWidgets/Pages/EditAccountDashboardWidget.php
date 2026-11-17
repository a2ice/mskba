<?php

namespace App\Filament\Backoffice\Resources\AccountDashboardWidgets\Pages;

use App\Filament\Backoffice\Resources\AccountDashboardWidgets\AccountDashboardWidgetResource;
use App\Modules\Dashboard\Application\Services\DashboardWidgetSaveCooldown;
use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class EditAccountDashboardWidget extends EditRecord
{
    protected static string $resource = AccountDashboardWidgetResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function getSaveFormAction(): Action
    {
        $remaining = app(DashboardWidgetSaveCooldown::class)->remaining($this->getRecord());

        // Alpine enforces the visible button state (including after a Livewire
        // save); the transactional server check below is authoritative.
        return parent::getSaveFormAction()->extraAttributes([
            'x-data' => '{ remaining: 0, deadline: 0, timer: null, lock(seconds) { this.deadline = Date.now() + Math.max(0, seconds) * 1000; this.tick(); clearInterval(this.timer); this.timer = setInterval(() => this.tick(), 250); }, tick() { this.remaining = Math.max(0, Math.ceil((this.deadline - Date.now()) / 1000)); if (!this.remaining) clearInterval(this.timer); } }',
            'x-init' => 'lock('.$remaining.')',
            'x-on:dashboard-widget-save-locked.window' => 'lock($event.detail.seconds)',
            'x-bind:disabled' => 'remaining > 0',
            'x-bind:aria-disabled' => 'remaining > 0',
            'x-bind:title' => 'remaining > 0 ? "Можно сохранить через " + remaining + " с" : ""',
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var AccountDashboardWidget $record */
        return DB::transaction(function () use ($record, $data): AccountDashboardWidget {
            $locked = AccountDashboardWidget::query()->whereKey($record->getKey())
                ->lockForUpdate()->firstOrFail();
            app(DashboardWidgetSaveCooldown::class)->assertReady($locked);

            $data['last_edit_at'] = now();
            $locked->update($data);
            $record->setRawAttributes($locked->getAttributes(), true);

            return $record;
        });
    }

    protected function afterSave(): void
    {
        $this->dispatch('dashboard-widget-save-locked',
            seconds: (int) $this->getRecord()->refresh_delay_seconds);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        AccountDashboardWidgetResource::assertSafeData($data, $this->record);

        return $data;
    }
}
