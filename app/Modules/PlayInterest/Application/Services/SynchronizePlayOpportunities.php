<?php

namespace App\Modules\PlayInterest\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\PlayInterest\Domain\Models\PlayOpportunity;
use App\Modules\Social\Application\Services\SocialWallService;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A conservative first-party candidate matcher: a group is created only when
 * every admitted member has at least one actual common venue, format and
 * overlapping future time window of sufficient duration. No transitive-only
 * groups. Group storage is persistent and never regenerated on HTTP GET.
 */
final class SynchronizePlayOpportunities
{
    public function __construct(
        private readonly RecommendVenuesForIntent $recommender,
        private readonly SocialWallService $wall,
    ) {}

    private const FORMATS = ['1x1','2x2','3x3','4x4','5x5'];

    public function sync(int $maxIntents = 400): array
    {
        // A bounded batch prevents accidental unindexed all-by-all scans.
        // Future scaling: regional/format queues and incremental match indexes.
        $intents = PlayIntent::query()
            ->where('status','active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at','>',now()))
            ->with('user')
            ->orderByDesc('updated_at')->limit(max(2,min(2000,$maxIntents)))->get();

        $candidates = [];
        foreach ($intents as $intent) {
            $user = $intent->user?->canonical();
            if ($user === null || $user->trashed() || $user->isBlocked() || ! $user->isConfirmed()) {
                continue;
            }
            $venues = $this->candidateVenues($intent);
            if ($venues === []) continue;
            $windows = $this->calendarWindows($intent);
            if ($windows === []) continue;
            $candidates[] = [
                'intent'=>$intent,
                'user_id'=>(int)$user->id,
                'venues'=>$venues,
                'formats'=>$intent->formats ?: self::FORMATS,
                'windows'=>$windows,
                'duration'=>max(30,(int)($intent->duration_minutes ?: 60)),
            ];
        }

        $groupIds = [];
        $seenPairs = [];
        $checked = 0;
        for ($i=0; $i<count($candidates); $i++) {
            for ($j=$i+1; $j<count($candidates); $j++) {
                $a=$candidates[$i];$b=$candidates[$j];
                if ($a['user_id'] === $b['user_id']) continue;
                $pairKey=min($a['user_id'],$b['user_id']).':'.max($a['user_id'],$b['user_id']);
                $commonVenues=array_values(array_intersect($a['venues'],$b['venues']));
                if ($commonVenues === [])continue;
                $formats=array_values(array_intersect($a['formats'],$b['formats']));
                if ($formats === [])continue;
                foreach ($a['windows'] as $one) {
                    foreach ($b['windows'] as $two) {
                        if ($one['date']!==$two['date'])continue;
                        $start=max($one['start'],$two['start']);
                        $end=min($one['end'],$two['end']);
                        $duration=max($a['duration'],$b['duration']);
                        if ($end-$start<$duration)continue;
                        $weekly=$a['intent']->cadence!=='once' && $b['intent']->cadence!=='once';
                        $time=[
                            'kind'=>$weekly?'weekly':'date',
                            'date'=>$weekly?null:$one['date'],
                            'weekday'=>(int)CarbonImmutable::parse($one['date'])->dayOfWeekIso,
                            'start'=>$start,
                            'end'=>$end,
                            'duration_min'=>$duration,
                            'label'=>$weekly
                                ? $this->weekdayLabel((int)CarbonImmutable::parse($one['date'])->dayOfWeekIso)
                                    .' · '.$this->timeLabel($start).'–'.$this->timeLabel($end)
                                : CarbonImmutable::parse($one['date'])->format('d.m')
                                    .' · '.$this->timeLabel($start).'–'.$this->timeLabel($end),
                        ];
                        foreach ($formats as $format) {
                            // For the same pair+format+calendar type prefer the first
                            // upcoming opportunity rather than 30 almost-identical URLs.
                            $dedup=$pairKey.'|'.$format.'|'.($weekly?'weekly:'.$time['weekday']:'date:'.$time['date']);
                            if (isset($seenPairs[$dedup]))continue;
                            $seenPairs[$dedup]=true;
                            $group=$this->upsertPair($a,$b,$format,$commonVenues,$time);
                            if($group!==null)$groupIds[$group->id]=true;
                        }
                    }
                }
            }
            if ($i%20===0 && $i!==0)gc_collect_cycles();
        }

        $this->pruneInvalidMembers();
        return ['intents'=>count($intents),'candidates'=>count($candidates),
            'groups_touched'=>count($groupIds)];
    }

    /** @return list<int> */
    private function candidateVenues(PlayIntent $intent): array
    {
        // Venue-less subscriptions without a geographic center cannot produce
        // trustworthy geographically intersecting groups.
        if ($intent->venue_id === null
            && ($intent->center_latitude === null || $intent->center_longitude === null)) {
            return [];
        }

        $ranked=$this->recommender->handle($intent,50);
        $ids=array_map(static fn ($row):int=>(int)$row['venue_id'],$ranked);
        if ($intent->venue_id !== null && ! in_array((int)$intent->venue_id,$ids,true)) {
            // A preferred venue may be closed/unpublished: don't fabricate eligibility.
            return [];
        }
        if ($intent->venue_id === null) {
            $lat=(float)$intent->center_latitude;
            $lon=(float)$intent->center_longitude;
            $radiusKm=max(1,(int)($intent->radius_km ?: 10));
            $venues=Venue::query()->whereIn('id',$ids)->with('location.address')->get()->keyBy('id');
            $ids=array_values(array_filter($ids,static function (int $id) use ($venues,$lat,$lon,$radiusKm):bool {
                $point=$venues->get($id)?->location?->address;
                if ($point?->latitude === null || $point?->longitude === null)return false;
                $angle=sin(deg2rad(((float)$point->latitude-$lat)/2))**2
                    +cos(deg2rad($lat))*cos(deg2rad((float)$point->latitude))
                    *sin(deg2rad(((float)$point->longitude-$lon)/2))**2;
                $dist=6371*2*atan2(sqrt($angle),sqrt(max(0,1-$angle)));
                return $dist<=$radiusKm;
            }));
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array{date:string,start:int,end:int}>
     * Minutes use the existing portal's local scheduling convention. An overnight
     * interval ends at >1440, retaining the original starting day.
     */
    private function calendarWindows(PlayIntent $intent): array
    {
        $configured=(array)($intent->windows??[]);
        if ($configured===[])return [];
        $allowedWeekdays=array_map('intval',(array)($intent->weekdays??[]));
        $dates=(array)($intent->dates??[]);
        $result=[];
        $timezone=(string)config('app.timezone','Europe/Moscow');
        $today=CarbonImmutable::today($timezone);
        for ($day=0;$day<30;$day++) {
            $date=$today->addDays($day);
            $dayString=$date->toDateString();
            $weekday=(int)$date->dayOfWeekIso;
            if($intent->cadence==='once'){
                if($dates!==[] && !in_array($dayString,$dates,true))continue;
                if($dates===[] && ($intent->period_from===null || $intent->period_until===null
                    || $date->lt($intent->period_from) || $date->gt($intent->period_until)))continue;
            } elseif($allowedWeekdays!==[] && !in_array($weekday,$allowedWeekdays,true))continue;
            foreach ($configured as $window) {
                if (!is_array($window))continue;
                if(isset($window['date']) && $window['date']!==null && $window['date']!==$dayString)continue;
                if(isset($window['weekday']) && $window['weekday']!==null
                    && (int)$window['weekday']!==$weekday)continue;
                $start=$this->minutes($window['start_time']??null);
                $end=$this->minutes($window['end_time']??null);
                if($start===null || $end===null)continue;
                if(!empty($window['end_next_day']))$end+=1440;
                if($end<=$start || $end-$start>480)continue;
                if($day===0 && $start<(int)CarbonImmutable::now($timezone)->format('H')*60
                    +(int)CarbonImmutable::now($timezone)->format('i'))continue;
                $result[]=['date'=>$dayString,'start'=>$start,'end'=>$end];
            }
        }

        return $result;
    }

    private function minutes(mixed $input): ?int
    {
        if(!is_string($input) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$input))return null;
        return (int)substr($input,0,2)*60+(int)substr($input,3,2);
    }

    private function weekdayLabel(int $day): string
    {
        return [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'][$day]??'';
    }

    private function timeLabel(int $minutes): string
    {
        return sprintf('%02d:%02d',intdiv($minutes,60)%24,$minutes%60);
    }

    private function upsertPair(array $a,array $b,string $format,array $venues,array $time): ?PlayOpportunity
    {
        // Bounded candidate set, then a row lock protects a group from two
        // creators narrowing its time/venue intersection at the same instant.
        return DB::transaction(function () use ($a,$b,$format,$venues,$time):?PlayOpportunity {
            $existing=PlayOpportunity::query()->where('format',$format)
                ->where('anchor_venue_id',$venues[0])->where('status','active')
                ->orderBy('id')->limit(100)->lockForUpdate()->get();

            foreach ($existing as $group) {
                $current=(array)$group->common_time;
                $kind=$current['kind']??null;
                if ($kind!==$time['kind'])continue;
                if ($kind==='weekly' && ($current['weekday']??null)!==$time['weekday'])continue;
                if ($kind==='date' && ($current['date']??null)!==$time['date'])continue;
                $shared=array_values(array_intersect((array)$group->common_venue_ids,$venues));
                if($shared===[])continue;
                $from=max((int)$current['start'],$time['start']);
                $to=min((int)$current['end'],$time['end']);
                $duration=max((int)($current['duration_min']??60),$time['duration_min']);
                if($to-$from<$duration)continue;

                $group->common_venue_ids=array_slice($shared,0,7);
                $group->common_time=[...$current,'start'=>$from,'end'=>$to,
                    'duration_min'=>$duration,
                    'label'=>($kind==='weekly'
                        ?$this->weekdayLabel($time['weekday'])
                        :CarbonImmutable::parse($time['date'])->format('d.m'))
                        .' · '.$this->timeLabel($from).'–'.$this->timeLabel($to)];
                $group->save();
                $this->upsertMember($group,$a);
                $this->upsertMember($group,$b);
                return $group;
            }

            $members=[$a['user_id'],$b['user_id']];
            sort($members);
            $key=hash('sha256',implode('|',[
                $format,$venues[0],$time['kind'],$time['date']??$time['weekday'],
                intdiv($time['start'],30),...$members,
            ]));
            $group=PlayOpportunity::query()->firstOrCreate(
                ['scope_key'=>$key],
                [
                    'public_id'=>(string)Str::uuid(),
                    'generated_title'=>'Баскетбол '.str_replace('x','×',$format)
                        .' · '.(count($venues)===1?'Площадка для игры':'Подходящие площадки')
                        .' · '.$time['label'],
                    'status'=>'active','format'=>$format,
                    'anchor_venue_id'=>$venues[0],
                    'common_venue_ids'=>array_slice($venues,0,7),
                    'common_time'=>$time,
                ]
            );
            if ($group->status !== 'active') {
                $group->update([
                    'status'=>'active',
                    'common_venue_ids'=>array_slice($venues,0,7),
                    'common_time'=>$time,
                ]);
            }
            $this->upsertMember($group,$a);
            $this->upsertMember($group,$b);

            return $group;
        },3);
    }

    private function upsertMember(PlayOpportunity $group,array $row): void
    {
        $member=$group->members()->where('user_id',$row['user_id'])->first();
        if($member!==null){
            $existing=array_map('intval',(array)$member->play_intent_ids);
            $member->update([
                'play_intent_ids'=>array_values(array_unique([...$existing,(int)$row['intent']->id])),
                'status'=>'active','left_at'=>null,
            ]);
            return;
        }
        $group->members()->create([
            'user_id'=>$row['user_id'],
            'play_intent_ids'=>[(int)$row['intent']->id],
            'status'=>'active','joined_at'=>now(),
        ]);
    }

    private function pruneInvalidMembers(): void
    {
        // Only withdraw membership for genuinely expired or cancelled demand;
        // a receding slot alone must not erase the community's discussion.
        PlayOpportunity::query()->where('status','active')->chunkById(100,function ($groups):void {
            foreach($groups as $group){
                DB::transaction(function () use($group):void{
                    $group=PlayOpportunity::query()->lockForUpdate()->findOrFail($group->id);
                    foreach($group->members()->where('status','active')->get() as $member){
                        $valid=PlayIntent::query()->where('status','active')
                            ->whereIn('id',(array)$member->play_intent_ids)
                            ->where(fn($q)=>$q->whereNull('expires_at')->orWhere('expires_at','>',now()))->exists();
                        if(!$valid){
                            $member->update(['status'=>'inactive','left_at'=>now()]);
                        }
                    }
                    if($group->admin_user_id!==null && !$group->hasActiveMember(
                        User::query()->find($group->admin_user_id)??new User
                    )){
                        $old=$group->admin_user_id;
                        $group->update(['admin_user_id'=>null,'admin_assigned_at'=>null,'admin_source'=>null]);
                        $this->wall->appendSystem('play_opportunity',$group->id,null,'group_admin_replaced',[
                            'previous_admin_user_id'=>$old,'reason'=>'membership_ended',
                        ]);
                    }
                    if($group->members()->where('status','active')->count()<2){
                        $group->update(['status'=>'paused']);
                    }
                });
            }
        });
    }
}
