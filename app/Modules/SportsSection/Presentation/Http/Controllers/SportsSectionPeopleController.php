<?php

namespace App\Modules\SportsSection\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Application\UseCases\ManageSectionCoachHandler;
use App\Modules\SportsSection\Application\UseCases\ManageSectionTraineeHandler;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SectionTraineeMembership;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SportsSectionPeopleController extends Controller
{
    public function addCoach(Request $request, SportsSection $sportsSection, ManageSectionCoachHandler $handler): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id'], 'permissions' => ['array'], 'permissions.*' => [Rule::enum(SportsSectionPermissionEnum::class)]]);
        $permissions = collect($data['permissions'] ?? [])->map(fn (string $value) => SportsSectionPermissionEnum::from($value))->all();

        return $this->execute(fn () => $handler->add($sportsSection, User::findOrFail($data['user_id']), $request->user(), $permissions), 'Тренер добавлен.');
    }

    public function permissions(Request $request, SportsSection $sportsSection, ContractMembership $membership, ManageSectionCoachHandler $handler): RedirectResponse
    {
        $data = $request->validate(['permissions' => ['array'], 'permissions.*' => [Rule::enum(SportsSectionPermissionEnum::class)]]);
        $permissions = collect($data['permissions'] ?? [])->map(fn (string $value) => SportsSectionPermissionEnum::from($value))->all();

        return $this->execute(fn () => $handler->permissions($sportsSection, $membership, $request->user(), $permissions), 'Права тренера обновлены.');
    }

    public function headCoach(Request $request, SportsSection $sportsSection, ContractMembership $membership, ManageSectionCoachHandler $handler): RedirectResponse
    {
        return $this->execute(fn () => $handler->transferHeadCoach($sportsSection, $membership, $request->user()), 'Главный тренер изменён.');
    }

    public function removeCoach(Request $request, SportsSection $sportsSection, ContractMembership $membership, ManageSectionCoachHandler $handler): RedirectResponse
    {
        return $this->execute(fn () => $handler->remove($sportsSection, $membership, $request->user()), 'Тренер отключён.');
    }

    public function addTrainee(Request $request, SportsSection $sportsSection, ManageSectionTraineeHandler $handler): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id'], 'notes' => ['nullable', 'string', 'max:2000']]);

        return $this->execute(fn () => $handler->activate($sportsSection, User::findOrFail($data['user_id']), $request->user(), $data['notes'] ?? null), 'Тренируемый добавлен.');
    }

    public function deactivateTrainee(Request $request, SportsSection $sportsSection, SectionTraineeMembership $membership, ManageSectionTraineeHandler $handler): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        return $this->execute(fn () => $handler->deactivate($sportsSection, $membership, $request->user(), $data['reason'] ?? null), 'Тренируемый переведён в неактивные.');
    }

    private function execute(callable $action, string $message): RedirectResponse
    {
        try {
            $action();

            return back()->with('status', $message);
        } catch (SportsSectionException $exception) {
            return back()->withErrors(['section' => $exception->getMessage()]);
        }
    }
}
