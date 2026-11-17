@php
    $contract = $this->getRecord()->loadMissing(['verification.reviewer', 'evidenceDocuments']);
    $verification = $contract->verification;
    $claim = $verification?->source_type === 'venue_ownership_claim'
        ? \App\Modules\Venue\Domain\Models\VenueOwnershipClaim::query()->find($verification->source_id)
        : null;
@endphp
<x-filament::section heading="Основание полномочий">
    <div class="mskba-contract-panel mskba-contract-verification">
        <p><strong>Тип:</strong>
            {{ match($contract->legal_nature) { 'evidence_backed' => 'Подтверждаемые полномочия', 'legal_agreement' => 'Юридическое соглашение', default => 'Техническое назначение' } }}
        </p>
        <p><strong>Подтверждение:</strong>
            {{ $contract->verification_requirement === 'none' ? 'Не требуется' : match($verification?->status) { 'verified' => 'Проверено', 'rejected' => 'Отклонено', default => 'Ожидает проверки' } }}
        </p>
        @if($verification?->verified_at)
            <p><strong>Проверено:</strong> {{ $verification->verified_at->format('d.m.Y H:i') }}
                · {{ $verification->reviewer?->username ?? '—' }}
            </p>
        @endif
        @if($verification?->decision_reason)
            <p><strong>Основание решения:</strong> {{ $verification->decision_reason }}</p>
        @endif
        @if($claim)
            <p><strong>Заявка площадки:</strong>
                <a href="{{ route('account.venue-ownership.show', $claim) }}" target="_blank" rel="noopener">№ {{ $claim->id }}</a>
            </p>
        @endif
        <div class="mskba-contract-table-wrap">
            <table class="mskba-contract-table">
                <thead><tr><th>Документ</th><th>Добавлен</th></tr></thead>
                <tbody>
                    @forelse($contract->evidenceDocuments as $document)
                        <tr>
                            <td data-label="Документ"><a href="{{ route('account.contract-evidence.download', [$contract->id, $document->id]) }}" target="_blank" rel="noopener">{{ $document->name }}</a></td>
                            <td data-label="Добавлен">{{ $document->created_at?->format('d.m.Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2">Дополнительных документов нет.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($contract->verification_requirement === 'required' && app(\App\Modules\Contract\Application\Services\BackofficeContractEditor::class)->canManage())
            <form method="POST" action="{{ route('backoffice.contracts.verification.review', $contract->id) }}" class="mskba-contract-verification__form">
                @csrf
                <label class="mskba-contract-verification__field">Решение
                    <select name="decision" class="mskba-contract-verification__input" required>
                        <option value="verified">Подтвердить полномочия</option>
                        <option value="rejected">Отклонить подтверждение</option>
                    </select>
                </label>
                <label class="d-grid gap-1">Причина решения
                    <textarea name="reason" class="fi-input" rows="3" minlength="5" maxlength="2000" required></textarea>
                </label>
                <div><button class="mskba-contract-verification__button" type="submit">Сохранить решение</button></div>
            </form>
        @endif
    </div>
</x-filament::section>
