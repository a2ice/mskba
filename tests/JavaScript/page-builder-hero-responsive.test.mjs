import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const stylesheet = readFileSync(new URL('../../resources/css/page-builder-hero-slider.css', import.meta.url), 'utf8');
const slideView = readFileSync(new URL('../../resources/views/page-builder/components/hero-slider-slide-layers.blade.php', import.meta.url), 'utf8');
const linksView = readFileSync(new URL('../../resources/views/page-builder/components/hero-slider-links.blade.php', import.meta.url), 'utf8');

test('tablet stage responds to the container width with a compact height', () => {
    assert.match(stylesheet, /@container mskba-hero \(max-width:1050px\)[\s\S]*?\.mskba-hero-slider__stage \{height:clamp\(400px,45cqw,500px\);\}/);
});

test('mobile uses the same accessible, continuous highlight marquee as tablet', () => {
    assert.match(stylesheet, /@container mskba-hero \(max-width:640px\)[\s\S]*?\.mskba-hero-slide__actions \{[\s\S]*?flex-direction:column;/);
    assert.match(stylesheet, /@container mskba-hero \(max-width:640px\)[\s\S]*?\.mskba-hero-slide__highlights \{[\s\S]*?display:block;/);
    assert.doesNotMatch(stylesheet, /\.mskba-hero-slide__highlights \{display:none;\}/);
    assert.match(stylesheet, /@keyframes mskba-hero-highlight-scroll/);
    assert.match(stylesheet, /prefers-reduced-motion:reduce[\s\S]*?\.mskba-hero-slide__highlights-track \{animation:none!important;/);
    assert.match(linksView, /mskba-hero-slide__highlights-group--duplicate[^"]*" aria-hidden="true" inert/);
    assert.match(slideView, /@include\('page-builder.components.hero-slider-links'/);
});

test('mobile flow places link layers in document flow rather than hidden or in absolute coordinates', () => {
    assert.match(stylesheet, /\.mskba-page-builder \.mskba-hero-scene \.mskba-hero-slide--mobile-flow\s+\.mskba-hero-slide__layer--links \{[\s\S]*?display:block!important;[\s\S]*?position:relative;[\s\S]*?inset:auto;[\s\S]*?order:20;/);
    assert.match(stylesheet, /\.mskba-hero-slide--mobile-flow \{[\s\S]*?padding-bottom:clamp\(18px,5cqw,28px\);/);
});
