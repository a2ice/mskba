<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\District;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class AdminGeographyController extends Controller
{
    public function index(): Response
    {
        $cities = City::query()
            ->withCount('addresses')
            ->with([
                'districts' => fn ($query) => $query
                    ->withCount('addresses')
                    ->orderBy('id'),
            ])
            ->orderBy('id')
            ->get();

        return ThemeResolver::page('admin.geography', [
            'cities' => $cities,
        ]);
    }

    public function storeCity(Request $request): RedirectResponse
    {
        $data = $this->validateCity($request);

        City::query()->create($this->normalizeDirectoryData($data));

        return back()->with('status', 'Город добавлен.');
    }

    public function updateCity(Request $request, City $city): RedirectResponse
    {
        $data = $this->validateCity($request, $city);

        $city->update($this->normalizeDirectoryData($data));

        return back()->with('status', 'Город сохранён.');
    }

    public function destroyCity(City $city): RedirectResponse
    {
        if ($city->districts()->exists()) {
            return back()->withErrors([
                'geography' => 'Нельзя удалить город, пока у него есть районы или округа. Сначала удалите или перенесите их.',
            ]);
        }

        if ($city->addresses()->exists()) {
            return back()->withErrors([
                'geography' => 'Нельзя удалить город, пока он используется в адресах.',
            ]);
        }

        $city->delete();

        return back()->with('status', 'Город удалён.');
    }

    public function storeDistrict(Request $request): RedirectResponse
    {
        $data = $this->validateDistrict($request);

        District::query()->create($this->normalizeDirectoryData($data));

        return back()->with('status', 'Район или округ добавлен.');
    }

    public function updateDistrict(Request $request, District $district): RedirectResponse
    {
        $data = $this->validateDistrict($request, $district);

        if ((int) $data['city_id'] !== (int) $district->city_id && $district->addresses()->exists()) {
            return back()
                ->withInput()
                ->withErrors([
                    'geography' => 'Нельзя перенести район в другой город, пока он используется в адресах.',
                ]);
        }

        $district->update($this->normalizeDirectoryData($data));

        return back()->with('status', 'Район или округ сохранён.');
    }

    public function destroyDistrict(District $district): RedirectResponse
    {
        if ($district->addresses()->exists()) {
            return back()->withErrors([
                'geography' => 'Нельзя удалить район или округ, пока он используется в адресах.',
            ]);
        }

        $district->delete();

        return back()->with('status', 'Район или округ удалён.');
    }

    /** @return array<string, mixed> */
    private function validateCity(Request $request, ?City $city = null): array
    {
        $this->normalizeDirectoryRequest($request);

        $nameRule = Rule::unique('cities', 'name');
        $aliasRule = Rule::unique('cities', 'alias');

        if ($city !== null) {
            $nameRule->ignore($city->id);
            $aliasRule->ignore($city->id);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255', $nameRule],
            'alias' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $aliasRule],
            'short_name' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validateDistrict(Request $request, ?District $district = null): array
    {
        $this->normalizeDirectoryRequest($request);

        $cityId = (int) $request->input('city_id');
        $nameRule = Rule::unique('districts', 'name')
            ->where(fn ($query) => $query->where('city_id', $cityId));
        $aliasRule = Rule::unique('districts', 'alias')
            ->where(fn ($query) => $query->where('city_id', $cityId));

        if ($district !== null) {
            $nameRule->ignore($district->id);
            $aliasRule->ignore($district->id);
        }

        return $request->validate([
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'name' => ['required', 'string', 'max:255', $nameRule],
            'alias' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $aliasRule],
            'short_name' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function normalizeDirectoryRequest(Request $request): void
    {
        $request->merge($this->normalizeDirectoryData(
            $request->only(['name', 'alias', 'short_name', 'description'])
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeDirectoryData(array $data): array
    {
        foreach (['name', 'alias', 'short_name', 'description'] as $field) {
            if (! array_key_exists($field, $data) || ! is_string($data[$field])) {
                continue;
            }

            $value = trim($data[$field]);
            $data[$field] = $value === '' ? null : $value;
        }

        return $data;
    }
}
