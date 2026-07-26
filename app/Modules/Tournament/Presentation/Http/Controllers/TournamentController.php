<?php

namespace App\Modules\Tournament\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class TournamentController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['all', 'current', 'upcoming', 'past'])],
            'query' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => [
                'nullable',
                'date_format:Y-m-d',
                Rule::when($request->filled('date_from'), ['after_or_equal:date_from']),
            ],
        ]);

        return ThemeResolver::page('tournaments.index', [
            'period' => $validated['period'] ?? 'all',
            'query' => $validated['query'] ?? null,
            'dateFrom' => $validated['date_from'] ?? null,
            'dateTo' => $validated['date_to'] ?? null,
        ]);
    }
}
