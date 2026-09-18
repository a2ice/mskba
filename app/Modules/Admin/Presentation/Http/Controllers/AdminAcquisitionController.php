<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Acquisition\Application\Services\AcquisitionCampaignManager;
use App\Modules\Acquisition\Application\Services\AcquisitionFlyerContextFactory;
use App\Modules\Acquisition\Application\Services\AcquisitionQrCodeRenderer;
use App\Modules\Acquisition\Application\UseCases\GetAdminAcquisitionCampaignStatsHandler;
use App\Modules\Acquisition\Application\UseCases\ListAdminAcquisitionCampaignsHandler;
use App\Modules\Acquisition\Domain\Enums\AcquisitionChannelEnum;
use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use App\Modules\Admin\Presentation\Http\Requests\UpsertAcquisitionCampaignRequest;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Template\Application\Contracts\DocumentRenderer;
use App\Modules\Template\Application\Contracts\TemplateRenderer;
use App\Modules\Template\Domain\Enums\DocumentFormatEnum;
use App\Modules\Venue\Application\UseCases\SearchVenuesHandler;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class AdminAcquisitionController extends Controller
{
    public function index(Request $request, ListAdminAcquisitionCampaignsHandler $campaigns): Response
    {
        return ThemeResolver::page('admin.acquisition.index', [
            'campaigns' => $campaigns->handle($request->query()),
            'filters' => $request->query(),
            'channels' => AcquisitionChannelEnum::cases(),
        ]);
    }

    public function create(Request $request): Response
    {
        return ThemeResolver::page('admin.acquisition.form', [
            'campaign' => new AcquisitionCampaign,
            'channels' => AcquisitionChannelEnum::cases(),
            'stats' => null,
            'selectedVenue' => $this->selectedVenue($request),
        ]);
    }

    public function store(
        UpsertAcquisitionCampaignRequest $request,
        AcquisitionCampaignManager $manager,
    ): RedirectResponse {
        $campaign = $manager->save($request->validated());

        return redirect()
            ->route('admin.acquisition.edit', $campaign)
            ->with('success', 'Кампания создана. QR и листовка готовы к использованию.');
    }

    public function edit(
        Request $request,
        AcquisitionCampaign $campaign,
        GetAdminAcquisitionCampaignStatsHandler $stats,
    ): Response {
        $campaign->loadMissing('venue.location.address');

        return ThemeResolver::page('admin.acquisition.form', [
            'campaign' => $campaign,
            'channels' => AcquisitionChannelEnum::cases(),
            'stats' => $stats->handle($campaign),
            'selectedVenue' => $this->selectedVenue($request, $campaign),
        ]);
    }

    public function update(
        UpsertAcquisitionCampaignRequest $request,
        AcquisitionCampaign $campaign,
        AcquisitionCampaignManager $manager,
    ): RedirectResponse {
        $manager->save($request->validated(), $campaign);

        return back()->with('success', 'Кампания сохранена.');
    }

    public function venueCandidates(
        Request $request,
        SearchVenuesHandler $venues,
        CurrentActorResolver $actors,
    ): JsonResponse {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $items = $venues->handle(
            user: $request->user(),
            actor: $actors->resolveForRequest($request),
            query: $validated['q'],
            limit: 20,
        );

        return response()->json([
            'candidates' => collect($items)->map(fn ($venue): array => [
                'id' => $venue->id,
                'name' => $venue->name,
                'meta' => collect([$venue->displayAddress, $venue->status])->filter()->implode(' · '),
            ])->values()->all(),
        ]);
    }

    public function qrSvg(AcquisitionCampaign $campaign, AcquisitionQrCodeRenderer $qr): SymfonyResponse
    {
        return response($qr->svg($campaign), 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="'.$campaign->public_code.'.svg"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function qrPng(AcquisitionCampaign $campaign, AcquisitionQrCodeRenderer $qr): SymfonyResponse
    {
        return response($qr->png($campaign), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$campaign->public_code.'.png"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function flyerPreview(
        AcquisitionCampaign $campaign,
        AcquisitionFlyerContextFactory $context,
        TemplateRenderer $templates,
    ): SymfonyResponse {
        $templateKey = (string) data_get($campaign->metadata, 'template_key', 'acquisition.flyer.a4');

        return response($templates->render($templateKey, $context->make($campaign)), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function flyerPdf(
        AcquisitionCampaign $campaign,
        AcquisitionFlyerContextFactory $context,
        TemplateRenderer $templates,
        DocumentRenderer $documents,
    ): SymfonyResponse {
        $templateKey = (string) data_get($campaign->metadata, 'template_key', 'acquisition.flyer.a4');
        $html = $templates->render($templateKey, $context->make($campaign));
        $document = $documents->render($html, DocumentFormatEnum::PDF);

        return response($document->contents, 200, [
            'Content-Type' => $document->mimeType(),
            'Content-Disposition' => 'attachment; filename="'.$campaign->public_code.'-flyer.'.$document->extension().'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function selectedVenue(Request $request, ?AcquisitionCampaign $campaign = null): ?Venue
    {
        $oldVenueId = $request->old('venue_id');

        if (is_numeric($oldVenueId)) {
            return Venue::query()->find((int) $oldVenueId);
        }

        return $campaign?->venue;
    }
}
