import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {rentalDefaults, rangeSlots, eligibleRuns, summarizeRental, groupRentalSlots} from '../../resources/themes/mskba_app/js/venue-rental-search-logic.js';
const date = '2026-11-20';
const slot = (starts_at, ends_at, status='available', selectable=status==='available') => ({starts_at,ends_at,status,selectable});
const day = (slots, overrides={}) => ({date,slots,step_minutes:30,minimum_minutes:60,maximum_minutes:180,booking_enabled:true,stale:false,...overrides});
const range = {from:date,to:date,fromTime:'00:00',toTime:'23:59'};
test('rental defaults are Moscow wall time, lead plus ten minutes and midnight rollover',()=>{
    assert.deepEqual(rentalDefaults(Date.parse('2026-11-20T10:00:00Z'),120,30),{today:date,date,time:'15:10',maxDate:'2026-11-28'});
    assert.equal(rentalDefaults(Date.parse('2026-11-20T19:00:00Z'),120,30).date,'2026-11-21');
    assert.equal(rentalDefaults(Date.parse('2026-11-20T19:00:00Z'),120,30).time,'00:00');
    assert.equal(rentalDefaults(Date.parse('2026-11-20T20:40:00Z'),0,30).date,'2026-11-21');
    assert.equal(rentalDefaults(Date.parse('2026-11-20T10:00:30Z'),120,30).time,'15:11');
});
test('last slot up to midnight remains included; an explicit midnight end means start of day',()=>{
    const data=day([slot('23:00','23:30'),slot('23:30','24:00')]);
    assert.equal(rangeSlots(data,range).length,2);
    assert.equal(rangeSlots(data,{...range,toTime:'00:00'}).length,0);
    assert.equal(rangeSlots(data,{...range,fromTime:'23:15'}).length,1);
    assert.equal(eligibleRuns(data,{...range,fromTime:'23:15'}).length,0);
    assert.equal(eligibleRuns(data,range).length,2);
});
test('occupied runs collapse across unrelated booking keys, but unknown and gaps are separate',()=>{
    const slots=[slot('10:00','10:30'),slot('10:30','11:00','booked'),slot('11:00','11:30','booked'),slot('11:30','12:00','unknown'),slot('12:00','12:30','booked')];
    assert.deepEqual(groupRentalSlots(slots).map(x=>x.count),[1,2,1,1]);
    assert.equal(eligibleRuns(day(slots),range).length,0);
});
test('night and off-peak supply cannot trigger a prime-time congratulation',()=>{
    const night=day([slot('00:00','00:30'),slot('00:30','01:00'),slot('18:00','18:30','booked')]);
    assert.match(summarizeRental([night],range),/ночью|утром/);
    const off=day([slot('10:00','10:30'),slot('10:30','11:00'),slot('18:00','18:30','booked')]);
    assert.match(summarizeRental([off],range),/прайм-тайм/);
    const prime=day([slot('18:00','18:30'),slot('18:30','19:00')]);
    assert.match(summarizeRental([prime],range),/повезло/);
    assert.match(summarizeRental([day(prime.slots,{stale:true})],range),/не удалось подтвердить/);
    const weekend=day([slot('10:00','10:30'),slot('10:30','11:00')],{date:'2026-11-21'});
    assert.match(summarizeRental([weekend],{...range,from:'2026-11-21',to:'2026-11-21'}),/повезло/);
});
test('rental CTA uses the new wizard even for one hall and preserves legacy quote',()=>{
    const read=path=>readFileSync(new URL('../../'+path,import.meta.url),'utf8');
    const js=read('resources/themes/mskba_app/js/venue-rental-search.js');
    const view=read('resources/themes/mskba_app/views/pages/venues/show.blade.php');
    const wizard=read('resources/themes/mskba_app/views/pages/venues/partials/rental-search-dialog.blade.php');
    assert.match(js,/data-venue-rental-open/);
    assert.match(js,/back:\(\) => open\(false\)/);
    assert.match(view,/\$venue->selectedCourt\['id'\]/);
    assert.match(view,/data-rental-result-back/);
    assert.match(view,/route\('venues.rental.quote'/);
    assert.match(wizard,/mskba-wizard__progress/);
    assert.match(wizard,/data-rental-end-fields hidden/);
});
