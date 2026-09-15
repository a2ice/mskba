<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Venue\Application\UseCases\ShowEditableVenueHandler;
use App\Modules\Venue\Application\UseCases\UpdateVenueConditionsHandler;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Exceptions\VenueAccessDeniedException;
use App\Modules\Venue\Domain\Exceptions\VenueNotFoundException;
use App\Modules\Venue\Domain\Exceptions\VenuePendingModerationException;
use App\Modules\Venue\Presentation\Http\Requests\UpdateVenueConditionsRequest;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

final class VenueConditionsController extends Controller
{
    public function edit(Request $request, string $alias, ShowEditableVenueHandler $show, CurrentActorResolver $actors): Response
    {
        try {
            $venue = $show->handle($alias, $request->user(), $actors->resolveForRequest($request));
        } catch (VenueAccessDeniedException|VenueNotFoundException $exception) {
            abort($exception->getCode(), $exception->getMessage());
        }

        return ThemeResolver::page('venues.conditions', ['venue' => $venue, 'venueRevision' => $venue->draftRevision]);
    }

    public function update(UpdateVenueConditionsRequest $request, string $alias, UpdateVenueConditionsHandler $update, CurrentActorResolver $actors): RedirectResponse
    {
        try {
            $venue = $update->handle($alias, $request->user(), $actors->resolveForRequest($request), $request->validated());
        } catch (VenueAccessDeniedException|VenueNotFoundException $exception) {
            abort($exception->getCode(), $exception->getMessage());
        } catch (VenuePendingModerationException|InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('account.venues.conditions.edit', $venue->routeIdentifier())->with('status',
            $venue->status === VenueStatusEnum::CONFIRMED
                ? 'Условия сохранены в черновик. Отправьте изменения на модерацию.'
                : 'Условия сохранены.',
        );
    }
}
