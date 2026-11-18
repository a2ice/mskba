<?php

namespace App\Modules\PlayInterest\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PlayInterest\Application\Services\PlayGroupGovernance;
use App\Modules\PlayInterest\Domain\Models\PlayOpportunity;
use App\Modules\Social\Application\Exceptions\SocialActionRateLimitException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PlayGroupElectionController extends Controller
{
    public function nominate(Request $request, PlayOpportunity $opportunity, PlayGroupGovernance $governance): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ]);
        try {
            $governance->nominate($opportunity, $request->user(), $data['reason']);
            return back()->with('status', 'Заявка на администратора группы опубликована.');
        } catch (InvalidArgumentException|SocialActionRateLimitException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }
    }

    public function vote(Request $request, PlayOpportunity $opportunity, PlayGroupGovernance $governance): RedirectResponse
    {
        $data = $request->validate(['candidate_id' => ['required', 'integer', 'min:1']]);
        try {
            $governance->vote($opportunity, $request->user(), (int) $data['candidate_id']);
            return back()->with('status', 'Выбор сохранён и отмечен в истории группы.');
        } catch (InvalidArgumentException|SocialActionRateLimitException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function retract(Request $request, PlayOpportunity $opportunity, PlayGroupGovernance $governance): RedirectResponse
    {
        try {
            $governance->retractVote($opportunity, $request->user());
            return back()->with('status', 'Голос отозван.');
        } catch (InvalidArgumentException|SocialActionRateLimitException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function title(Request $request, PlayOpportunity $opportunity, PlayGroupGovernance $governance): RedirectResponse
    {
        $data = $request->validate(['custom_title' => ['nullable', 'string', 'max:80']]);
        try {
            $governance->rename($opportunity, $request->user(), $data['custom_title'] ?? null);
            return back()->with('status', 'Название группы обновлено.');
        } catch (InvalidArgumentException|SocialActionRateLimitException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }
    }
}
