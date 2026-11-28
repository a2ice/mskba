@once
<style>
.event-game-plan{display:grid;gap:8px;min-width:0}
.event-game-plan [hidden]{display:none!important}
.event-game-plan__list{display:grid;gap:8px}
.event-game-plan__list:empty{display:none}
.event-game-plan__trigger,.event-game-plan__trigger label{display:flex;align-items:center;gap:8px;min-width:0}
.event-game-plan__trigger label{font-weight:600;cursor:pointer}
.event-game-plan__trigger input[type=checkbox]{width:20px;height:20px;min-width:20px;min-height:20px;flex:none;margin:0;padding:0;accent-color:var(--accent,#ed782c)}
.event-game-plan__help,.event-game-plan__icon{display:grid;place-items:center;flex:none;width:36px;height:36px;padding:0}
.event-game-plan__help svg,.event-game-plan__icon svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.event-game-plan__add,.event-game-plan__name{width:fit-content;background:none;border:0;padding:0;color:var(--accent,#ed782c);font:inherit;font-weight:600;cursor:pointer;text-align:left;overflow-wrap:anywhere}
.event-game-plan__row{display:flex;align-items:flex-start;gap:6px;border:1px solid var(--line,#606772);border-radius:12px;padding:10px 12px}
.event-game-plan__details{flex:1;min-width:0;display:grid;gap:3px}
.event-game-plan .event-game-editor__hint{margin:0;color:var(--muted,#929aa4);font-size:14px;line-height:20px;font-weight:400}
.event-game-plan__danger,.event-game-plan__remove{color:var(--danger,#d23e45)}
</style>
@endonce
