<?php

namespace App\Modules\Rewards\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Rewards\Application\Services\RewardCatalogManager;
use App\Modules\Rewards\Application\Services\RewardMechanismRegistry;
use App\Modules\Rewards\Domain\Models\Reward;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class AdminRewardsController extends Controller
{
    public function index(RewardMechanismRegistry $registry): Response
    {
        $rewards = Reward::query()
            ->with(['currentVersion', 'versions'])
            ->orderBy('id')
            ->get();

        $implementedMechanisms = collect($registry->all())
            ->map(fn ($mechanism): string => $mechanism->label())
            ->all();

        return ThemeResolver::page('admin.rewards', [
            'rewards' => $rewards,
            'implementedMechanisms' => $implementedMechanisms,
        ]);
    }

    public function store(Request $request, RewardCatalogManager $manager): RedirectResponse
    {
        $validated = $this->validateReward($request, creating: true);

        $manager->create($this->managerPayload($validated));

        return back()->with('success', 'Вознаграждение создано. До подключения механизма начисление останется выключенным.');
    }

    public function update(
        Request $request,
        Reward $reward,
        RewardCatalogManager $manager,
    ): RedirectResponse {
        $validated = $this->validateReward($request, creating: false);

        if (! array_key_exists('mechanism_parameters', $validated)) {
            $validated['mechanism_parameters'] = $reward->currentVersion?->mechanism_parameters ?? [];
        }

        $manager->update($reward, $this->managerPayload($validated));

        return back()->with('success', 'Вознаграждение сохранено. Изменения условий и номинала версионируются.');
    }

    public function destroy(Reward $reward, RewardCatalogManager $manager): RedirectResponse
    {
        $manager->delete($reward);

        return back()->with('success', 'Вознаграждение удалено из активного каталога. История сохранена.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateReward(Request $request, bool $creating): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'mechanism_code' => ['nullable', 'string', 'max:96', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
            'is_enabled' => ['required', 'boolean'],
            'amount_rub' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'conditions' => ['nullable', 'string', 'max:8000'],
            'recipient_description' => ['required', 'string', 'max:4000'],
            'trigger_description' => ['required', 'string', 'max:4000'],
            'mechanism_parameters' => ['nullable', 'array'],
        ];

        if ($creating) {
            $rules['code'] = [
                'required',
                'string',
                'max:96',
                'regex:/^[a-z0-9][a-z0-9_]*$/',
                Rule::unique('rewards', 'code'),
            ];
        }

        return $request->validate($rules);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function managerPayload(array $validated): array
    {
        return [
            ...$validated,
            'amount_minor' => (int) round(((float) $validated['amount_rub']) * 100),
            'currency' => 'RUB',
            'mechanism_parameters' => (array) ($validated['mechanism_parameters'] ?? []),
        ];
    }
}
