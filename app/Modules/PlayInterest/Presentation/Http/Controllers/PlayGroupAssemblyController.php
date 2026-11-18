<?php

namespace App\Modules\PlayInterest\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coordination\Application\UseCases\CreateCoordinationHandler;
use App\Modules\Coordination\Domain\Enums\PollResultsVisibilityEnum;
use App\Modules\PlayInterest\Domain\Models\PlayGroupAssembly;
use App\Modules\PlayInterest\Domain\Models\PlayOpportunity;
use App\Modules\Social\Application\Services\SocialWallService;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PlayGroupAssemblyController extends Controller
{
    public function store(
        Request $request, PlayOpportunity $opportunity,
        CurrentActorResolver $actors,
        CreateCoordinationHandler $coordination,
        SocialWallService $wall,
    ): RedirectResponse {
        $user=$request->user()->canonical();
        abort_unless($opportunity->isAdministrator($user),403);
        $actor=$actors->resolveForRequest($request);
        abort_unless($actor!==null,403);

        try {
            $assembly=DB::transaction(function () use ($opportunity,$user,$actor,$coordination,$wall):PlayGroupAssembly {
                $group=PlayOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
                abort_unless($group->isAdministrator($user),403);
                $existing=$group->assemblies()->where('status','active')->lockForUpdate()->first();
                if($existing!==null)return $existing;

                $venueIds=array_slice(array_values(array_unique(array_map('intval',
                    (array)$group->common_venue_ids))),0,7);
                $validVenues=Venue::query()->whereKey($venueIds)
                    ->where('status','confirmed')
                    ->where('operational_status','active')->pluck('id')->map('intval')->all();
                if($validVenues===[])throw new InvalidArgumentException('Не найдены подходящие площадки для голосования.');

                $hasChoices=count($validVenues)>=2;
                $data=[
                    'title'=>'Согласование игры: '.$group->displayTitle(),
                    'flow_type'=>'single',
                    'question'=>$hasChoices?'На какой площадке сыграем?':'Готовы организовать игру на этой площадке?',
                    'subject_type'=>$hasChoices?'venue':'text',
                    'selection_mode'=>'single',
                    'results_visibility'=>PollResultsVisibilityEnum::AFTER_VOTE,
                    'allows_vote_changes'=>true,
                    'allows_suggestions'=>false,
                    'is_anonymous'=>false,
                    'closes_at'=>now()->addDays(2)->toDateTimeString(),
                    'options'=>$hasChoices?$validVenues:['Готов участвовать в согласовании','Предлагаю другое время'],
                ];
                $session=$coordination->handle($actor,$data);
                $assembly=$group->assemblies()->create([
                    'created_by_user_id'=>$user->id,
                    'coordination_session_id'=>$session->id,
                    'status'=>'active',
                ]);
                $wall->appendSystem('play_opportunity',$group->id,$user,'group_assembly_started',[
                    'coordination_session_id'=>$session->id,'assembly_id'=>$assembly->id,
                ]);
                return $assembly;
            },3);

            return redirect()->route('coordination.show',$assembly->coordination_session_id)
                ->with('status','Согласование игры начато; сначала выберите условия, затем подтвердите участие.');
        }catch(InvalidArgumentException $exception){
            return back()->with('error',$exception->getMessage());
        }
    }
}
