<?php

namespace App\Modules\Contract\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Application\Services\ContractTemplateService;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Contract\Domain\Models\ContractTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ContractTemplateController extends Controller
{
    public function store(Request $request, ContractMembership $membership, ContractTemplateService $templates): RedirectResponse
    {
        $data = $this->validateTemplate($request);
        $templates->save($request->user(), $membership->scope_type, (int) $membership->scope_id,
            $data['template_name'], $data['template_permissions'], (bool) ($data['shared'] ?? false));

        return back()->with('status', 'Шаблон сохранён.');
    }

    public function update(Request $request, ContractMembership $membership, ContractTemplate $template, ContractTemplateService $templates): RedirectResponse
    {
        $data = $this->validateTemplate($request);
        $templates->save($request->user(), $membership->scope_type, (int) $membership->scope_id,
            $data['template_name'], $data['template_permissions'], (bool) ($data['shared'] ?? false), $template);

        return back()->with('status', 'Шаблон обновлён.');
    }

    public function destroy(Request $request, ContractMembership $membership, ContractTemplate $template, ContractTemplateService $templates): RedirectResponse
    {
        $templates->delete($request->user(), $membership->scope_type, (int) $membership->scope_id, $template);

        return back()->with('status', 'Шаблон удалён.');
    }

    /** @return array<string, mixed> */
    private function validateTemplate(Request $request): array
    {
        return $request->validate([
            'template_name' => ['required', 'string', 'min:2', 'max:150'],
            'template_permissions' => ['required', 'array', 'min:1'],
            'template_permissions.*' => ['required', 'string', 'distinct'],
            'shared' => ['sometimes', 'boolean'],
        ]);
    }
}
