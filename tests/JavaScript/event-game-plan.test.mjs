import test from 'node:test';
import assert from 'node:assert/strict';
import {restoreEventGamePlanDraft, gamePlanValidation} from '../../resources/js/event-game-plan.js';

test('new empty game list takes precedence over a stale legacy selection',()=>{
    assert.deepEqual(restoreEventGamePlanDraft({draft:{type:'game',create_game:'1',game_format:'streetball_3x3',games:[]},gamePlan:[]}),[]);
    assert.deepEqual(restoreEventGamePlanDraft({draft:{type:'game',create_game:'0',game_format:'streetball_3x3'}}),[]);
    assert.deepEqual(restoreEventGamePlanDraft({draft:{type:'game'}}),[]);
});

test('legacy opted-in draft keeps the actual format and defaults',()=>{
    const [row]=restoreEventGamePlanDraft({draft:{type:'game',create_game:'1',game_format:'basketball_5x5'}});
    assert.equal(row.value.format,'basketball_5x5');
    assert.equal(row.value.timing_mode,'periods');
    assert.equal(row.value.periods_count,4);
    assert.deepEqual(restoreEventGamePlanDraft({draft:{type:'training',create_game:'1',game_format:'basketball_5x5'}}),[]);
});

test('auth draft preserves selected team metadata and an unconfigured game',()=>{
    const gamePlan=[
        {value:{title:'Игра',format:null},teams:{}},
        {value:{title:'Матч',format:'basketball_5x5',recruitment_mode:'preformed_teams',team_a_id:11,team_b_id:22},teams:{a:{id:11,name:'A',logo_url:'/a.webp'},b:{id:22,name:'B',logo_url:'/b.webp'}}},
    ];
    const saved=JSON.parse(JSON.stringify({gamePlan,draft:{games:gamePlan.map(row=>row.value)}}));
    assert.deepEqual(restoreEventGamePlanDraft(saved),gamePlan);
    assert.deepEqual(restoreEventGamePlanDraft({draft:{games:[{title:'Игра',format:null}]}}),[{value:{title:'Игра',format:null},teams:{}}]);
});

test('server errors reopen the corresponding game and keep field messages separate',()=>{
    assert.deepEqual(gamePlanValidation({'venue_id':['Выберите площадку'],'games.1.team_a_id':['Выберите команду.'],'games.1.team_b_id':['Команды должны быть разными.'],'games.2.title':['Укажите название.']}),{index:1,errors:{team_a_id:'Выберите команду.',team_b_id:'Команды должны быть разными.'}});
    assert.equal(gamePlanValidation({title:['Укажите название.']}),null);
    assert.equal(gamePlanValidation(null),null);
});
