{{-- First-party SVG presets are fixed in code, not uploaded or injected via editor HTML. --}}
<svg viewBox="0 0 32 32" fill="none" stroke="currentColor"
                                         stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                        @switch($icon)
                                            @case('court')
                                                <rect x="3.5" y="4.5" width="25" height="23" rx="2"/>
                                                <path d="M16 5v22M4 16h24M4 11c5 1 7 3 7 5s-2 4-7 5m24-10c-5 1-7 3-7 5s2 4 7 5"/>
                                                @break
                                            @case('trophy')
                                                <path d="M9 5h14v7a7 7 0 0 1-14 0V5Zm14 3h5v2c0 4-2 6-6 7M9 8H4v2c0 4 2 6 6 7M16 19v6m-6 3h12m-11-3h10"/>
                                                @break
                                            @case('users')
                                                <circle cx="12" cy="11" r="4"/><circle cx="23" cy="12" r="3"/>
                                                <path d="M3.5 27v-3c0-5 3.2-7.5 8.5-7.5s8.5 2.5 8.5 7.5v3H3.5Zm17-9.5c5.2 0 8 2.4 8 7V27h-5"/>
                                                @break
                                            @case('video')
                                                <rect x="3.5" y="5" width="25" height="22" rx="3"/><path d="m13 11 9 5-9 5V11Z"/>
                                                @break
                                            @case('chart')
                                                <rect x="3.5" y="19" width="5" height="9" rx=".6"/><rect x="13.5" y="13" width="5" height="15" rx=".6"/><rect x="23.5" y="5" width="5" height="23" rx=".6"/>
                                                @break
                                        @endswitch
                                    </svg>
