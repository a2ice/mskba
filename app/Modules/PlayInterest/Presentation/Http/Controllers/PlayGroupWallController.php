<?php

namespace App\Modules\PlayInterest\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PlayInterest\Domain\Models\PlayOpportunity;
use App\Modules\Social\Application\Exceptions\SocialActionRateLimitException;
use App\Modules\Social\Application\Services\SocialActionLimiter;
use App\Modules\Social\Domain\Models\SocialWallEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PlayGroupWallController extends Controller
{
    public function store(
        Request $request, PlayOpportunity $opportunity,
        SocialActionLimiter $actions,
    ): RedirectResponse {
        $data = $request->validate(['body' => ['required', 'string', 'max:200'],
            'client_request_id' => ['required', 'uuid']]);
        $user = $request->user()->canonical();
        abort_unless($opportunity->hasActiveMember($user), 403);
        $body = trim($data['body']);
        if ($body === '' || mb_strlen($body) > 200) {
            return back()->withInput()->with('error', 'Сообщение должно содержать 1–200 символов.');
        }
        try {
            $actions->run($user, 'play_opportunity', $opportunity->id, 'comment', function () use ($opportunity, $user, $body, $data): array {
                $old = SocialWallEntry::query()->where('scope_type','play_opportunity')
                    ->where('scope_id',$opportunity->id)->where('actor_user_id',$user->id)
                    ->where('client_request_id',$data['client_request_id'])->first();
                if ($old !== null) {
                    return ['changed'=>false,'value'=>$old];
                }
                abort_unless($opportunity->fresh()->hasActiveMember($user),403);
                $item = SocialWallEntry::query()->create([
                    'scope_type' => 'play_opportunity','scope_id'=>$opportunity->id,
                    'actor_user_id'=>$user->id,'kind'=>'comment',
                    'body'=>$body,'client_request_id'=>$data['client_request_id'],
                ]);
                return ['changed'=>true,'value'=>$item];
            });
            return back()->with('status', 'Сообщение опубликовано.');
        } catch (SocialActionRateLimitException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }
    }

    public function destroy(
        Request $request, PlayOpportunity $opportunity, int $entry,
        SocialActionLimiter $actions,
    ): RedirectResponse {
        $user = $request->user()->canonical();
        abort_unless($opportunity->hasActiveMember($user),403);
        try {
            $actions->run($user,'play_opportunity',$opportunity->id,'comment_delete',function () use ($opportunity,$entry,$user): array {
                $item = SocialWallEntry::query()->where('scope_type','play_opportunity')
                    ->where('scope_id',$opportunity->id)->where('kind','comment')
                    ->where('actor_user_id',$user->id)->lockForUpdate()->findOrFail($entry);
                if ($item->deleted_at !== null) return ['changed'=>false,'value'=>$item];
                $item->update(['deleted_at'=>now(),'deleted_by_user_id'=>$user->id]);
                return ['changed'=>true,'value'=>$item];
            });
            return back()->with('status','Сообщение зачёркнуто.');
        } catch (SocialActionRateLimitException $exception) {
            return back()->with('error',$exception->getMessage());
        }
    }
}
