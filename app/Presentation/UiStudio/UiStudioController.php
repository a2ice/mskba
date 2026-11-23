<?php

namespace App\Presentation\UiStudio;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class UiStudioController
{
    public function __construct(
        private readonly ThemeSnapshot $snapshot,
        private readonly UiStudioBridgeTicket $tickets,
        private readonly UiStudioReviewStore $reviews,
    ) {}

    private function guard(Request $request): void
    {
        $this->environmentGuard($request);
        if ((int) $request->session()->get('ui_studio_authorized_until', 0) > time()) {
            return;
        }

        // Standalone access for authorised infrastructure reviewers.
        $user = config('ui-studio.user');
        $hash = config('ui-studio.password_hash');
        abort_unless(is_string($user) && $user !== '' && is_string($hash) && $hash !== '', 503);
        abort_unless(hash_equals($user, (string) $request->getUser())
            && password_verify((string) $request->getPassword(), $hash), 401, 'UI Studio',
            ['WWW-Authenticate' => 'Basic realm="MSKBA UI Studio"']);
    }

    private function environmentGuard(Request $request): void
    {
        abort_unless(app()->environment('ui-studio') && config('ui-studio.enabled')
            && $request->getHost() === config('ui-studio.host'), 404);
    }

    private function headers(Response $response, bool $embed = false): Response
    {
        $parents = implode(' ', config('ui-studio.parent_origins', []));
        $ancestors = "'self' ". $parents;

        return $response->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-src 'self'; frame-ancestors ".$ancestors."; form-action ".($embed ? "'none'" : "'self'"));
        // X-Frame-Options deliberately omitted: CSP frame-ancestors lists the
        // exact trusted Filament origins. SAMEORIGIN would block these frames.
    }

    public function bridge(Request $request): Response
    {
        $this->environmentGuard($request);

        return $this->headers(response()->view('ui-studio.bridge'));
    }

    public function exchange(Request $request): JsonResponse
    {
        $this->environmentGuard($request);
        $data = $request->validate(['ticket' => ['required', 'string', 'max:2048']]);
        $assertion = $this->tickets->consume($data['ticket']);
        $request->session()->regenerate();
        $request->session()->put([
            'ui_studio_authorized_until' => time() + 7200,
            'ui_studio_reviewer_id' => (int) $assertion['sub'],
            'ui_studio_parent_origin' => $assertion['origin'],
        ]);

        return response()->json(['authorized' => true])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, ?string $ui_name = null): Response
    {
        $this->guard($request);
        $cases = config('ui-studio.cases');
        $acceptedCases = [];
        if ($ui_name === null) {
            foreach ($cases as $slug => $candidate) {
                $acceptedCases[$slug] = $this->reviews->accepted($slug, $candidate);
            }
        }
        $case = $ui_name === null ? null : ($cases[$ui_name] ?? null);
        abort_if($ui_name !== null && $case === null, 404);

        $snapshot = $this->snapshot->read();
        $themes = $snapshot['themes'];
        $theme = $request->query('theme', array_key_first($themes));
        $compare = $request->query('compare', '');
        $template = $request->query('template', 'mskba_app');
        $state = $request->query('state', 'normal');
        $width = $request->query('width', '1440');
        $templates = config('themes.items');
        abort_unless(is_string($theme) && isset($themes[$theme])
            && is_string($compare) && ($compare === '' || isset($themes[$compare]))
            && is_string($template) && isset($templates[$template])
            && is_string($width) && in_array($width, ['1440', '768', '390', '360'], true)
            && is_string($state) && ($case === null || isset($case['states'][$state])), 422);
        $embed = $request->boolean('embed');
        if ($embed && $request->has('theme_hash')) {
            abort_unless($request->query('theme_hash') === $themes[$theme]['hash'], 409, 'Theme snapshot changed; reload studio.');
        }
        abort_if($embed && $case === null, 404);
        $supported = $case !== null && in_array($template, $case['templates'], true);
        $fingerprint = $case !== null ? $this->reviews->fingerprint($ui_name, $case) : null;
        $acceptedReview = $case !== null ? $this->reviews->accepted($ui_name, $case) : null;

        return $this->headers(response()->view($embed ? 'ui-studio.frame' : 'ui-studio.index',
            compact('cases', 'case', 'ui_name', 'snapshot', 'themes', 'theme', 'compare', 'template', 'templates', 'state', 'width', 'supported', 'fingerprint', 'acceptedReview', 'acceptedCases')), $embed);
    }

    public function accept(Request $request): JsonResponse
    {
        $this->environmentGuard($request);
        // Basic Auth is deliberately insufficient to record review decisions.
        abort_unless((int) $request->session()->get('ui_studio_authorized_until', 0) > time()
            && (int) $request->session()->get('ui_studio_reviewer_id', 0) > 0, 403);

        $data = $request->validate([
            'case' => ['required', 'string', 'max:80'],
            'revision' => ['required', 'integer', 'min:1'],
            'fingerprint' => ['required', 'string', 'size:64'],
            'theme_hash' => ['required', 'string', 'size:64'],
            'selected' => ['required', 'array'],
            'selected.*' => ['string', 'max:100'],
        ]);
        $case = config('ui-studio.cases.'.$data['case']);
        abort_unless(is_array($case) && (int) $case['revision'] === (int) $data['revision'], 409);
        abort_unless(hash_equals($this->reviews->fingerprint($data['case'], $case), $data['fingerprint']), 409, 'Interface changed: review it again.');

        $knownThemes = $this->snapshot->read()['themes'];
        $knownTheme = collect($knownThemes)->first(fn (array $item) => $item['hash'] === $data['theme_hash']);
        abort_unless($knownTheme !== null, 409, 'Theme snapshot changed.');

        $review = $this->reviews->accept(
            $data['case'], $case, $data['selected'],
            $data['theme_hash'], (int) $request->session()->get('ui_studio_reviewer_id'),
        );

        return response()->json(['accepted' => true, 'review' => $review])
            ->header('Cache-Control', 'no-store');
    }

    public function asset(Request $request, string $slug, string $hash, string $kind): Response
    {
        $this->guard($request);
        $theme = $this->snapshot->read()['themes'][$slug] ?? null;
        abort_if($theme === null, 404);
        abort_unless(hash_equals($theme['hash'], $hash), 409, 'Theme snapshot changed; reload studio.');

        return $this->headers(response($theme[$kind], 200, ['Content-Type' => $kind === 'css'
            ? 'text/css; charset=UTF-8' : 'application/javascript; charset=UTF-8']));
    }
}
