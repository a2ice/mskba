<?php

namespace App\Modules\SportsSection\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\SportsSection\Application\Services\SportsSectionGalleryManager;
use App\Modules\SportsSection\Application\UseCases\ManageSectionPricingPlanHandler;
use App\Modules\SportsSection\Application\UseCases\ManageSportsSectionContactHandler;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SectionPricingPlan;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\VenueBooking\Application\Services\MinorAmountParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

final class SportsSectionResourceController extends Controller
{
    public function addPlan(Request $request, SportsSection $sportsSection, ManageSectionPricingPlanHandler $handler, MinorAmountParser $amounts): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'amount' => ['required', 'numeric', 'min:0.01'],
            'sessions_count' => ['nullable', 'integer', 'min:1'], 'duration_days' => ['nullable', 'integer', 'min:1'],
        ]);
        $data['amount_minor'] = $amounts->parse((string) $data['amount'], $sportsSection->currency);
        unset($data['amount']);

        return $this->execute(fn () => $handler->store($sportsSection, $request->user(), $data), 'Тариф добавлен.');
    }

    public function togglePlan(Request $request, SportsSection $sportsSection, SectionPricingPlan $plan, ManageSectionPricingPlanHandler $handler): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        return $this->execute(fn () => $handler->toggle($sportsSection, $plan, $request->user(), (bool) $data['is_active']), 'Тариф обновлён.');
    }

    public function addContact(Request $request, SportsSection $sportsSection, ManageSportsSectionContactHandler $handler): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', Rule::enum(ContactTypeEnum::class)], 'value' => ['required', 'string', 'max:255'], 'label' => ['nullable', 'string', 'max:100']]);

        return $this->execute(fn () => $handler->store($sportsSection, $request->user(), $data), 'Контакт добавлен.');
    }

    public function deleteContact(Request $request, SportsSection $sportsSection, Contact $contact, ManageSportsSectionContactHandler $handler): RedirectResponse
    {
        return $this->execute(fn () => $handler->delete($sportsSection, $contact, $request->user()), 'Контакт удалён.');
    }

    public function addPhoto(Request $request, SportsSection $sportsSection, SportsSectionGalleryManager $gallery): RedirectResponse
    {
        $request->validate(['photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120']]);
        $path = $request->file('photo')?->getRealPath();
        $contents = is_string($path) ? file_get_contents($path) : false;
        if (! is_string($contents)) {
            return back()->withErrors(['section' => 'Не удалось прочитать изображение.']);
        }

        return $this->execute(fn () => $gallery->store($sportsSection, $request->user(), $contents), 'Фотография добавлена.');
    }

    public function featurePhoto(Request $request, SportsSection $sportsSection, int $photo, SportsSectionGalleryManager $gallery): RedirectResponse
    {
        return $this->execute(fn () => $gallery->feature($sportsSection, $request->user(), $photo), 'Основная фотография изменена.');
    }

    public function deletePhoto(Request $request, SportsSection $sportsSection, int $photo, SportsSectionGalleryManager $gallery): RedirectResponse
    {
        return $this->execute(fn () => $gallery->delete($sportsSection, $request->user(), $photo), 'Фотография удалена.');
    }

    private function execute(callable $action, string $message): RedirectResponse
    {
        try {
            $action();

            return back()->with('status', $message);
        } catch (SportsSectionException|RuntimeException|\InvalidArgumentException $exception) {
            return back()->withErrors(['section' => $exception->getMessage()]);
        }
    }
}
