<?php

// System appearance presets. Database copies are editable independently of deployment.
return [
    'night' => [
        'slug' => 'arena_night',
        'name' => 'Ночная арена',
        'description' => 'Графитовые поверхности и фирменный оранжевый акцент.',
        'mode' => 'dark',
        'colors' => [
            'bg' => '#090A0B', 'surface' => '#141618', 'surface-raised' => '#1D2023',
            'field' => '#0E1012', 'text' => '#F5F6F7', 'muted' => '#ADB3BB',
            'faint' => '#929AA4', 'accent' => '#FF7900', 'accent-hover' => '#FF922E',
            'accent-active' => '#E86C00', 'on-accent' => '#14100A', 'line' => '#30353B',
            'control-line' => '#707A86', 'success' => '#7CDCAB', 'warning' => '#FFD078',
            'danger' => '#FF9398', 'info' => '#8CCBFF',
        ],
        'css_code' => <<<'CSS'
:root {
    --theme-font-family: Inter, Arial, sans-serif;
    --header-bg: #090a0bf5;
    --body-glow: #ff79000b;
    --ambient-shadow: #0004;
    --surface-glint: #ffffff03;
    --danger-bg: #301b1e;
    --danger-hover-bg: #422226;
    --success-bg: #173025;
    --warning-bg: #33291b;
    --danger-badge-bg: #301b1e;
    --glass-bg: #101214e6;
    --tile-bg: #281b11;
    --tile-border: #5c381f;
    --avatar-bg: #2a1b10;
    --avatar-border: #8a481b;
    --backdrop-bg: #000b;
    --subtle-accent-bg: #ff790014;
    --subtle-white-bg: #ffffff09;
    --shadow-accent: 0 8px 24px #ff790026;
    --shadow-menu: #0009;
    --shadow-popover: #0008;
    --shadow-modal: #000b;
    --loader-bg: #090a0bc9;
    --surface-corner-glow: #ff79001e;
    --surface-corner-glow-success: #35cb7922;
    --surface-corner-glow-error: #ff545e22;
    --radius-control: 12px;
    --radius-card: 24px;
    --radius-hero: 32px;
}
CSS,
    ],
    'day' => [
        'slug' => 'court_day',
        'name' => 'Светлый паркет',
        'description' => 'Светлая социальная палитра: белые карточки, мягкий серый фон и фирменный оранжевый.',
        'mode' => 'light',
        'colors' => [
            'bg' => '#F3F4F6', 'surface' => '#FFFFFF', 'surface-raised' => '#F7F8FA',
            'field' => '#F8F9FA', 'text' => '#18212B', 'muted' => '#59636F',
            'faint' => '#6D7681', 'accent' => '#BD4F00', 'accent-hover' => '#A74500',
            'accent-active' => '#8C3900', 'on-accent' => '#FFFFFF', 'line' => '#E0E3E8',
            'control-line' => '#8994A0', 'success' => '#176A43', 'warning' => '#865300',
            'danger' => '#B02E38', 'info' => '#205F99',
        ],
        'css_code' => <<<'CSS'
:root {
    --theme-font-family: Inter, Arial, sans-serif;
    --header-bg: #fffffffA;
    --body-glow: #00000000;
    --ambient-shadow: #18212b12;
    --surface-glint: #ffffff00;
    --danger-bg: #fff0f1;
    --danger-hover-bg: #ffe3e6;
    --success-bg: #e3f5e9;
    --warning-bg: #fff4d9;
    --danger-badge-bg: #fff0f1;
    --glass-bg: #fffffff5;
    --tile-bg: #FFF5ED;
    --tile-border: #EFD3BF;
    --avatar-bg: #FFF5ED;
    --avatar-border: #D09A72;
    --backdrop-bg: #1a202b88;
    --subtle-accent-bg: #bd4f0012;
    --subtle-white-bg: #F3F4F6;
    --shadow-accent: 0 4px 12px #bd4f0018;
    --shadow-menu: #18212b18;
    --shadow-popover: #18212b18;
    --shadow-modal: #18212b40;
    --loader-bg: #ffffffda;
    --surface-corner-glow: #00000000;
    --surface-corner-glow-success: #176a4314;
    --surface-corner-glow-error: #b02e3814;
    --radius-control: 12px;
    --radius-card: 24px;
    --radius-hero: 32px;
    --light-page-top: #F7F8FA;
    --light-page-center: #F3F4F6;
    --light-page-bottom: #F1F2F4;
    --light-title-top: #18212B;
    --light-title-bottom: #18212B;
    --light-card-blue: #FFFFFF;
    --light-card-amber: #FFFFFF;
    --light-card-shadow: #18212b0c;
    --light-card-shadow-hover: #18212b16;
    --light-card-line: #E0E3E8;
    --light-button-top: #BD4F00;
    --light-button-bottom: #BD4F00;
    --light-nav-active-top: #FFF4EB;
    --light-nav-active-bottom: #FFF4EB;
    --light-display-font-family: Inter, Arial, sans-serif;
    --light-display-gloss-top: #435468;
    --light-display-gloss-bottom: #18212B;
    --light-premium-chrome: #ffffffed;
    --light-premium-menu: #fffffff4;
    --light-premium-blur: 12px;
    --light-premium-shadow: #26364514;
}
CSS,
    ],
];
