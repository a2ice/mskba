<?php

namespace App\Modules\PlayInterest\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PlayInterest\Domain\Models\PlayGroupVenuePreference;
use App\Modules\PlayInterest\Domain\Models\PlayOpportunity;
use App\Modules\Social\Application\Exceptions\SocialActionRateLimitException;
use App\Modules\Social\Application\Services\SocialActionLimiter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class PlayGroupVenueController extends Controller
{
    public function rate(
        Request $request, PlayOpportunity $opportunity, int $venue,
        SocialActionLimiter $limiter,
    ): RedirectResponse {
        $data = $request->validate([
            'rating' => ['nullable','integer','between:1,10'],
            'unsuitable' => ['sometimes','boolean'],
            'comment' => ['nullable','string','max:300'],
            'time_mode' => ['nullable',Rule::in(['weekly','date'])],
            'weekday' => ['nullable','integer','between:1,7'],
            'date' => ['nullable','date_format:Y-m-d','after:today'],
            'start_time' => ['nullable','date_format:H:i'],
            'end_time' => ['nullable','date_format:H:i'],
        ]);
        $user = $request->user()->canonical();
        abort_unless($opportunity->hasActiveMember($user),403);
        abort_unless(in_array($venue,array_map('intval',(array)$opportunity->common_venue_ids),true),404);

        $times = null;
        if (! empty($data['time_mode'])) {
            if (empty($data['start_time']) || empty($data['end_time'])
                || $data['end_time'] <= $data['start_time']) {
                return back()->withInput()->with('error','Укажите корректный будущий интервал времени.');
            }
            if ($data['time_mode'] === 'weekly' && empty($data['weekday'])
                || $data['time_mode'] === 'date' && empty($data['date'])) {
                return back()->withInput()->with('error','Укажите день недели или конкретную дату.');
            }
            $times = [[
                'mode' => $data['time_mode'], 'weekday' => $data['weekday'] ?? null,
                'date' => $data['date'] ?? null,'start'=>$data['start_time'],'end'=>$data['end_time'],
            ]];
        }
        $comment = trim((string)($data['comment']??''));
        if (mb_strlen($comment) > 300) {
            return back()->withInput()->with('error','Комментарий не более 300 символов.');
        }
        $rating = $data['rating'] ?? null;
        $unsuitable = (bool)($data['unsuitable'] ?? false);
        if ($rating === null && ! $unsuitable && $comment === '' && $times === null) {
            return back()->withInput()->with('error','Поставь оценку, предложи время или оставь комментарий.');
        }
        try {
            $limiter->run($user,'play_opportunity',$opportunity->id,'venue_preference',function () use (
                $opportunity,$venue,$user,$rating,$unsuitable,$comment,$times
            ): array {
                abort_unless($opportunity->fresh()->hasActiveMember($user),403);
                $old = PlayGroupVenuePreference::query()->where('opportunity_id',$opportunity->id)
                    ->where('venue_id',$venue)->where('user_id',$user->id)->lockForUpdate()->first();
                $value=['rating'=>$rating,'unsuitable'=>$unsuitable,'comment'=>$comment?:null,'windows'=>$times];
                if ($old !== null && $old->only(array_keys($value)) === $value) {
                    return ['changed'=>false,'value'=>$old];
                }
                $item = PlayGroupVenuePreference::query()->updateOrCreate([
                    'opportunity_id'=>$opportunity->id,'venue_id'=>$venue,'user_id'=>$user->id,
                ],$value);
                return ['changed'=>true,'value'=>$item];
            });
            return back()->with('status','Оценка и предложения сохранены.');
        } catch (SocialActionRateLimitException $exception) {
            return back()->withInput()->with('error',$exception->getMessage());
        }
    }
}
