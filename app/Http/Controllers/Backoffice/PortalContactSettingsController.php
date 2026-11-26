<?php

namespace App\Http\Controllers\Backoffice;

use App\Modules\Portal\Application\Services\PortalContactSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PortalContactSettingsController
{
    public function __invoke(Request $request, PortalContactSettings $settings): RedirectResponse
    {
        $actor = $request->user('web');
        abort_unless($settings->canManage($actor), 403);
        try {
            $settings->save($actor, $request->only(['email', 'phone', 'telegram_url', 'vk_url',
                'email_public', 'phone_public', 'telegram_public', 'vk_public']));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(url('/backoffice/portal-contacts'));
        }

        return redirect('/backoffice/portal-contacts')->with('portal_contacts_status', 'Контакты портала сохранены.');
    }
}
