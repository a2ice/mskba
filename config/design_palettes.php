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
        'description' => 'Светлый глянцевый паркет, ледяные и тёплые карточки, фирменный оранжевый.',
        'mode' => 'light',
        'colors' => [
            'bg' => '#F8FAFE', 'surface' => '#FFFFFF', 'surface-raised' => '#EDF4FC',
            'field' => '#F7FAFF', 'text' => '#1A2838', 'muted' => '#506073',
            'faint' => '#5E7082', 'accent' => '#BD4F00', 'accent-hover' => '#A74500',
            'accent-active' => '#8C3900', 'on-accent' => '#FFFFFF', 'line' => '#C6D8EA',
            'control-line' => '#7D91A5', 'success' => '#176A43', 'warning' => '#865300',
            'danger' => '#B02E38', 'info' => '#205F99',
        ],
        'css_code' => <<<'CSS'
:root {
    --theme-font-family: Inter, Arial, sans-serif;
    --header-bg: #f6fafffa;
    --body-glow: #c5daf030;
    --ambient-shadow: #375a791a;
    --surface-glint: #ffffff9c;
    --danger-bg: #fff0f1;
    --danger-hover-bg: #ffe3e6;
    --success-bg: #e3f5e9;
    --warning-bg: #fff4d9;
    --danger-badge-bg: #fff0f1;
    --glass-bg: #f7fbffee;
    --tile-bg: #fff0e5;
    --tile-border: #dfb08d;
    --avatar-bg: #fff0e5;
    --avatar-border: #c47d47;
    --backdrop-bg: #1a202b88;
    --subtle-accent-bg: #bd4f0012;
    --subtle-white-bg: #d8e8f899;
    --shadow-accent: 0 8px 24px #bd4f0018;
    --shadow-menu: #27475e24;
    --shadow-popover: #27475e26;
    --shadow-modal: #27475e30;
    --loader-bg: #e9f3ffde;
    --surface-corner-glow: #b5d5f442;
    --surface-corner-glow-success: #176a4314;
    --surface-corner-glow-error: #b02e3814;
    --radius-control: 12px;
    --radius-card: 24px;
    --radius-hero: 32px;
    --light-page-top: #ffffff;
    --light-page-center: #f9fbfe;
    --light-page-bottom: #eef5fc;
    --light-title-top: #172332;
    --light-title-bottom: #3a526d;
    --light-card-blue: #e9f3ff;
    --light-card-amber: #fff7ed;
    --light-card-shadow: #274e7914;
    --light-card-shadow-hover: #244d7626;
    --light-card-line: #c9dbee;
    --light-button-top: #c15400;
    --light-button-bottom: #983c00;
    --light-nav-active-top: #fff9f1;
    --light-nav-active-bottom: #f2e7dc;
}
CSS,
    ],
];
