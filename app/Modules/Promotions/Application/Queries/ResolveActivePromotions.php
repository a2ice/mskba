<?php

namespace App\Modules\Promotions\Application\Queries;

use App\Modules\Promotions\Domain\Enums\PromotionType;
use App\Modules\Promotions\Domain\Models\Promotion;
use App\Modules\Promotions\Application\Services\PromotionTextRenderer;
use App\Modules\Rewards\Application\Services\RewardMechanismRegistry;
use App\Modules\Rewards\Domain\Models\VenueRewardOverride;

final readonly class ResolveActivePromotions
{
    public function __construct(private RewardMechanismRegistry $mechanisms) {}

    /**
     * Only a server-verified active promotion can make a public promise of a reward.
     * All amounts are integer kopecks and venue overrides apply only to rental rewards.
     *
     * @return list<array<string, mixed>>
     */
    public function forPlacement(string $placementCode, ?int $venueId = null): array
    {
        $now = now();
        $promotions = Promotion::query()
            ->where('is_enabled', true)
            ->where(function ($q) use ($now): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->whereHas('placements', fn ($q) => $q->where('placement_code', $placementCode))
            ->with([
                'reward.currentVersion',
                'placements' => fn ($q) => $q->where('placement_code', $placementCode),
            ])
            ->orderBy('id')
            ->get();

        $result = [];
        foreach ($promotions as $promotion) {
            if ($promotion->type === PromotionType::MULTI_REWARDABLE) {
                continue; // Reserved for a later, explicitly designed aggregate.
            }

            $amountMinor = null;
            $rewardCode = null;
            $amountCaption = null;

            if ($promotion->type === PromotionType::REWARDABLE) {
                $reward = $promotion->reward;
                $version = $reward?->currentVersion;

                if ($reward === null || ! $reward->is_enabled || $version === null
                    || $version->currency !== 'RUB'
                    || $version->mechanism_code !== $reward->mechanism_code
                    || ! $this->mechanisms->has($reward->mechanism_code)
                    || $version->valid_from?->isFuture()
                    || ($version->valid_until !== null && ! $version->valid_until->isFuture())) {
                    continue;
                }

                $amountMinor = (int) $version->amount_minor;
                $rewardCode = (string) $reward->code;
                $amountCaption = $reward->amount_caption_template
                    ?? ($rewardCode === 'venue_rental_completed' ? 'Кешбэк: {amount}' : 'Бонус: {amount}');

                if ($rewardCode === 'venue_rental_completed' && $venueId !== null) {
                    $override = VenueRewardOverride::query()
                        ->where('venue_id', $venueId)
                        ->where('reward_id', $reward->id)
                        ->first();

                    if ($override?->mode === 'disabled') {
                        continue;
                    }

                    if ($override?->mode === 'custom') {
                        $amountMinor = (int) $override->amount_minor;
                    }
                }

                if ($amountMinor <= 0) {
                    continue;
                }
            } elseif ($promotion->type !== PromotionType::SIMPLE || $promotion->reward_id !== null) {
                continue;
            }

            $placement = $promotion->placements->first();

            $result[] = [
                'promotion_code' => $promotion->code,
                'type' => $promotion->type->value,
                'reward_code' => $rewardCode,
                'code' => $rewardCode ?? $promotion->code,
                'title' => PromotionTextRenderer::render($placement?->title_override ?? $promotion->title, $amountMinor),
                'description' => PromotionTextRenderer::render($placement?->description_override ?? $promotion->description, $amountMinor),
                'amount_minor' => $amountMinor,
                'amount_caption' => PromotionTextRenderer::render($amountCaption, $amountMinor),
                'cta_label' => $promotion->cta_label,
                'cta_url' => $promotion->cta_url,
            ];
        }

        return $result;
    }
}
