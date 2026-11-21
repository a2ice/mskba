<?php

namespace App\Presentation\UiStudio;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class UiStudioController
{
    public function __construct(private readonly ThemeSnapshot $snapshot) {}

    private function guard(Request $request): void
    {
        abort_unless(app()->environment('ui-studio') && config('ui-studio.enabled')
            && $request->getHost() === config('ui-studio.host'), 404);
        $user = config('ui-studio.user');
        $hash = config('ui-studio.password_hash');
        abort_unless(is_string($user) && $user !== '' && is_string($hash) && $hash !== '', 503);
        abort_unless(hash_equals($user, (string) $request->getUser())
            && password_verify((string) $request->getPassword(), $hash), 401, 'UI Studio',
            ['WWW-Authenticate' => 'Basic realm="MSKBA UI Studio"']);
    }

    private function headers(Response $response, bool $embed = false): Response
    {
        return $response->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'none'; object-src 'none'; base-uri 'none'; frame-src 'self'; frame-ancestors 'self'; form-action ".($embed ? "'none'" : "'self'"));
    }

    public function show(Request $request, ?string $ui_name = null): Response
    {
        $this->guard($request);
        $cases = config('ui-studio.cases');
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

        return $this->headers(response()->view($embed ? 'ui-studio.frame' : 'ui-studio.index',
            compact('cases', 'case', 'ui_name', 'snapshot', 'themes', 'theme', 'compare', 'template', 'templates', 'state', 'width', 'supported')), $embed);
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
