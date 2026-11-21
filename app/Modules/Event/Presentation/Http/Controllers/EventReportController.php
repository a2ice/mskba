<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Application\UseCases\ShowEventHandler;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Media\Application\Services\WebpImageNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class EventReportController extends Controller
{
    public function candidates(Request $request, string $event,
        \App\Modules\Identity\Application\Services\SearchDiscoverableUsers $search,
        \App\Modules\Notification\Application\Services\DirectMessageBlockService $blocks): \Illuminate\Http\JsonResponse
    {
        $item = Event::query()->whereRouteIdentifier($event)->firstOrFail();
        abort_unless($item->visibility->value === 'public' && in_array($item->status->value, ['published','completed'],true),404);
        $viewer = $request->user()->canonical();
        abort_if($viewer->isBlocked() || $viewer->trashed(),403);
        $q=trim((string)$request->query('q',''));
        if(mb_strlen($q)<2 || mb_strlen($q)>60)return response()->json(['users'=>[]]);
        $users=$search->handle($viewer,$q,limit:12,requiredAccess:\App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum::MESSAGES);
        return response()->json(['users'=>$users->reject(fn($u)=>$viewer->isSameIdentity($u)||$blocks->blockedBetween($viewer,$u))->take(10)->map(fn($u)=>['id'=>$u->id,'name'=>$u->nickname?:$u->username?:'Участник'])->values()]);
    }

    public function settings(Request $request, string $event): RedirectResponse
    {
        $data = $request->validate(['discussion_enabled'=>['required','boolean'], 'report_comments_enabled'=>['required','boolean']]);
        DB::transaction(function () use ($event, $request, $data): void {
            $item = Event::query()->whereRouteIdentifier($event)->lockForUpdate()->firstOrFail();
            abort_unless($item->canManageFeedback($request->user()), 403);
            $item->forceFill($data)->save();
        });
        return back()->with('status', 'Настройки сохранены.');
    }

    public function store(Request $request, string $event, WebpImageNormalizer $normalizer): RedirectResponse
    {
        $item = Event::query()->whereRouteIdentifier($event)->firstOrFail();
        abort_unless($item->canManageFeedback($request->user()) && $item->hasEnded(), 403);
        $data = $request->validate([
            'result_description'=>['nullable','string','max:10000'],
            'photos'=>['sometimes','array','max:12'],
            'photos.*'=>['file','image','mimes:jpg,jpeg,png,webp','max:10240','dimensions:max_width=6000,max_height=6000'],
            'videos'=>['sometimes','array','max:6'],
            'videos.*'=>['file','mimes:mp4,webm','max:10240'],
        ]);
        $files = [...$request->file('photos', []), ...$request->file('videos', [])];
        if (array_sum(array_map(fn ($file) => $file->getSize(), $files)) > 18 * 1024 * 1024) {
            throw ValidationException::withMessages(['photos'=>'За одну загрузку — не более 18 МБ.']);
        }
        $saved = [];
        try {
            DB::transaction(function () use ($item, $request, $data, $normalizer, &$saved): void {
                $locked = Event::query()->lockForUpdate()->findOrFail($item->id);
                abort_unless($locked->canManageFeedback($request->user()) && $locked->hasEnded(), 403);
                $existing = $locked->media()->whereIn('collection', ['event_results','event_report_media'])->get();
                $photos = $request->file('photos', []);
                $videos = $request->file('videos', []);
                if ($existing->filter(fn ($m)=>str_starts_with((string)$m->mime,'image/'))->count()+count($photos)>12
                    || $existing->filter(fn ($m)=>str_starts_with((string)$m->mime,'video/'))->count()+count($videos)>6) {
                    throw ValidationException::withMessages(['photos'=>'Не более 12 фотографий и 6 видео на мероприятие.']);
                }
                $order = (int) $existing->max('sort_order') + 1;
                foreach ($photos as $file) {
                    $image = $normalizer->normalize(file_get_contents($file->getRealPath()), 1200, 10*1024*1024);
                    $path = 'event-reports/'.$locked->id.'/'.Str::uuid().'.webp';
                    $saved[] = $path;
                    if (!Storage::disk('local')->put($path, $image['contents'])) throw new \RuntimeException('Не удалось сохранить фото.');
                    $locked->media()->create(['collection'=>'event_report_media','source'=>'upload','disk'=>'local','path'=>$path,'mime'=>'image/webp','size'=>strlen($image['contents']),'sort_order'=>$order++]);
                }
                foreach ($videos as $file) {
                    $mime = $file->getMimeType();
                    abort_unless(in_array($mime,['video/mp4','video/webm'],true),422);
                    $path = 'event-reports/'.$locked->id.'/'.Str::uuid().($mime==='video/webm'?'.webm':'.mp4');
                    $saved[] = $path;
                    if (!Storage::disk('local')->putFileAs(dirname($path),$file,basename($path))) throw new \RuntimeException('Не удалось сохранить видео.');
                    $locked->media()->create(['collection'=>'event_report_media','source'=>'upload','disk'=>'local','path'=>$path,'mime'=>$mime,'size'=>$file->getSize(),'sort_order'=>$order++]);
                }
                if (array_key_exists('result_description', $data)) {
                    $locked->forceFill(['result_description'=>$data['result_description']])->save();
                }
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($saved);
            throw $e;
        }
        return back()->with('status','Отчёт сохранён.');
    }

    public function media(Request $request, string $event, int $media, ShowEventHandler $events, CurrentActorResolver $actors): BinaryFileResponse
    {
        $item = $events->handle($event, $actors->resolveForRequest($request));
        $file = $item->media()->where('collection','event_report_media')->where('disk','local')->findOrFail($media);
        abort_unless(in_array($file->mime,['image/webp','video/mp4','video/webm'],true),404);
        return response()->file(Storage::disk('local')->path($file->path), ['Content-Type'=>$file->mime,'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }

    public function destroy(Request $request, string $event, int $media): RedirectResponse
    {
        DB::transaction(function () use ($request,$event,$media): void {
            $item = Event::query()->whereRouteIdentifier($event)->lockForUpdate()->firstOrFail();
            abort_unless($item->canManageFeedback($request->user()) && $item->hasEnded(),403);
            $file = $item->media()->whereIn('collection',['event_results','event_report_media'])->findOrFail($media);
            $disk=$file->disk;$path=$file->path;
            $file->eventResultPhotoTags()->delete();$file->delete();
            DB::afterCommit(fn()=>Storage::disk($disk)->delete($path));
        });
        return back()->with('status','Материал удалён.');
    }
}
