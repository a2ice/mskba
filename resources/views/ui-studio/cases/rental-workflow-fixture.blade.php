<script type="module">
const root=document.querySelector('[data-rental-studio]');
const q=s=>root.querySelector(s);
const el=(tag,cls,txt)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(txt!==undefined)n.textContent=txt;return n};
const btn=(label,fn,cls='button secondary')=>{const n=el('button',cls,label);n.type='button';n.addEventListener('click',fn);return n};
const names={customer:'Заказчик',agent:'Агент',portal:'MSKBA',venue:'Представитель площадки'};
const venue='Красный Октябрь на Тушинской',court='Игровая зона №1';
const fmt=new Intl.DateTimeFormat('ru-RU',{day:'numeric',month:'short',timeZone:'UTC'});
const money=n=>n.toLocaleString('ru-RU')+' ₽';
const time=m=>String(Math.floor(m/60)).padStart(2,'0')+':'+String(m%60).padStart(2,'0');
const date=d=>new Date(Date.UTC(2026,10,27+d));
const interval=(o)=>fmt.format(date(o.day))+' · '+time(o.start)+'–'+time(o.start+o.length);
const dialog=q('[data-rs-dialog]'),body=q('[data-rs-dialog-body]'),footer=q('[data-rs-dialog-footer]');
const initialMode=root.dataset.fixture==='inquiry'?'inquiry':'booking';
q('[data-rs-mode]').value=initialMode;
let chain=[],role='customer',edge=0,mode,phase,requested,offers,logs,invoices,holdUntil,clock,race,selected,offset,read,groupOpen;
const finalRole=()=>chain[chain.length-1];
const adjacent=()=>chain.slice(0,-1).map((a,i)=>i).filter(i=>chain[i]===role||chain[i+1]===role);
const typeLabel=()=>mode==='inquiry'?'Уточнение аренды':'Заявка на аренду';
const status=()=>({requested:'Ожидает решения',offered:'Предложены варианты',held:'Доступность подтверждена',confirmed:'Аренда подтверждена',rejected:'Отклонена',expired:'Истёк срок удержания'})[phase];
const now=()=>time(clock);
const amount=i=>6000+(chain.includes('agent')&&i===0?1000:chain[i]==='venue'||chain[i+1]==='venue'?0:500);
function eventAll(text){logs.forEach((list,i)=>{list.push({system:true,text,at:now()});if(!adjacent().includes(i)||i!==edge)read[i]++})}
function reset(){
 chain=['customer',...(q('[data-rs-agent]').checked?['agent']:[]),'portal',...(q('[data-rs-venue]').checked?['venue']:[])];
 role='customer';edge=0;mode=q('[data-rs-mode]').value;phase='requested';requested={day:4,start:1020,length:120};offers=[];logs=chain.slice(1).map(()=>[]);read=logs.map(()=>1);invoices=[];holdUntil=null;clock=930;race=root.dataset.fixture==='conflict';selected=[];offset=0;groupOpen=true;render();
}
function switchRole(r){role=r;edge=adjacent()[0];read[edge]=0;groupOpen=true;render()}
function bubble(title,text,kind='system'){const a=el('article','app-messages__bubble app-messages__bubble--'+kind+(kind==='system'?' rs-card':''));const h=el('div','app-messages__bubble-head');h.append(el('strong','',title));a.append(h,el('p','app-messages__bubble-body',text));return a}
function show(title){q('[data-rs-dialog-title]').textContent=title;body.replaceChildren();footer.replaceChildren();if(!dialog.open)dialog.showModal()}
function close(){if(dialog.open)dialog.close()}
function action(label,fn){footer.append(btn(label,fn,'button primary'))}
function statusCard(){let c=bubble(typeLabel()+' №1248',venue+' · '+court+'\n'+interval(requested));c.append(el('p','rs-status',status()));if(holdUntil!==null&&phase==='held')c.append(el('p','rs-muted','Удерживается до '+time(holdUntil)));return c}
function render(){
 const roles=q('[data-rs-roles]');roles.replaceChildren(...chain.map(r=>{let b=btn(names[r],()=>switchRole(r),r===role?'button primary':'button secondary');b.setAttribute('aria-pressed',String(r===role));return b}));
 const rail=q('[data-rs-rail]');rail.replaceChildren();const ix=adjacent();
 let parent=rail;
 if(ix.length>1){let g=el('details','rs-group');g.open=groupOpen;let s=el('summary','', 'Аренда №1248 · '+interval(requested)+' · '+venue+' · '+court+' ('+ix.reduce((n,i)=>n+read[i],0)+')');s.title=s.textContent;g.append(s);g.addEventListener('toggle',()=>groupOpen=g.open);rail.append(g);parent=g}
 ix.forEach(i=>{let other=chain[i]===role?chain[i+1]:chain[i];let b=btn('',()=>{edge=i;read[i]=0;render()},'rs-rail-item');b.setAttribute('aria-current',String(i===edge));let text=el('span','');text.append(el('strong','rs-ellipsis',names[other]),el('span','rs-muted',chain[i]===role?'Исходящий':'Входящий'));b.append(text,el('span',read[i]?'app-messages__unread':'rs-muted',read[i]?String(read[i]):''));parent.append(b)});
 q('[data-rs-title]').textContent=names[chain[edge]===role?chain[edge+1]:chain[edge]];q('[data-rs-subtitle]').textContent=typeLabel()+' №1248 · '+status();q('[data-rs-clock]').textContent='Демонстрационное время: '+now()+' · '+chain.map(r=>names[r]).join(' → ');
 let history=q('[data-rs-history]');history.replaceChildren(statusCard());
 logs[edge].forEach(m=>{let b=bubble(m.system?'Система MSKBA':names[m.author],m.text,m.system?'system':m.author===role?'outgoing':'incoming');b.append(el('time','app-messages__timestamp',m.at));history.append(b)});
 if(offers.length&&role==='customer'&&edge===0){let card=bubble('Варианты аренды','До бронирования интервалы не удерживаются.');offers.forEach((o,i)=>{let item=el('div','rs-offer');item.append(el('strong','',interval(o)),el('span','rs-muted','Предложение действительно до '+time(o.expires)),btn('Забронировать',()=>choose(i),'button primary'));card.append(item)});history.append(card)}
 const inv=invoices.find(v=>v.edge===edge);
 if(inv){let c=bubble('Счёт №1248-'+(edge+1),names[chain[edge+1]]+' → '+names[chain[edge]]);c.append(el('strong','',money(inv.amount)),el('span','rs-muted',({issued:'Выставлен',claimed:'Оплата заявлена',paid:'Оплачено',cancelled:'Отменён'})[inv.status]+' · до '+time(inv.due)));
 if(phase==='held'&&role===chain[edge]&&inv.status==='issued'&&(edge===0||invoices.find(x=>x.edge===edge-1)?.status==='paid'))c.append(btn('Я оплатил',()=>{inv.status='claimed';logs[edge].push({system:true,text:'Оплата заявлена.',at:now()});render()},'button primary'));
 if(phase==='held'&&role===chain[edge+1]&&inv.status==='claimed')c.append(btn('Подтвердить получение',()=>{inv.status='paid';logs[edge].push({system:true,text:'Получение средств подтверждено.',at:now()});render()},'button primary'));history.append(c)}
 const actions=q('[data-rs-actions]');actions.replaceChildren();const add=(label,fn)=>actions.append(btn(label,()=>{actions.closest('details').open=false;fn()},''));
 if(role===finalRole()&&['requested','offered'].includes(phase)){add('Посмотреть расписание',schedule);add(mode==='inquiry'?'Подтвердить доступность':'Подтвердить и выставить счёт',schedule);add('Отклонить и предложить варианты',()=>schedule(true));add('Отклонить',()=>reasonDialog([],true))}
 if(role!== 'customer'&&phase==='held'){let i=chain.indexOf(role)-1;if(i>=0&&!invoices.some(v=>v.edge===i)&&(i===chain.length-2||invoices.some(v=>v.edge===i+1)))add('Выставить счёт',()=>issue(i));if(role===finalRole()&&invoices.length===chain.length-1&&invoices.every(v=>v.status==='paid'))add('Подтвердить аренду',()=>{phase='confirmed';eventAll('Аренда подтверждена.');render()})}
 if(!actions.children.length)actions.append(el('span','rs-muted','Нет доступных действий'));
 q('[data-rs-send]').disabled=!q('[data-rs-text]').value.trim();
}
function issue(i){if(phase!=='held'||clock>=holdUntil||invoices.some(v=>v.edge===i))return;invoices.push({edge:i,status:'issued',due:holdUntil,amount:amount(i)});logs[i].push({system:true,text:'Счёт выставлен.',at:now()});render()}
function launchHold(o,minutes){requested={day:o.day,start:o.start,length:o.length};mode='booking';phase='held';holdUntil=clock+minutes;offers=[];eventAll('Доступность подтверждена. Время удерживается до '+time(holdUntil)+'.');issue(chain.length-2);render()}
function confirmAvailability(o){if(race){race=false;show('Время уже занято');body.append(el('p','','Другая заявка заняла этот интервал. Предложите другое время.'));action('К расписанию',()=>schedule(true));return}
 if(mode==='inquiry'){offers=[{...o,expires:clock+60}];phase='offered';eventAll('Запрошенное время доступно. Выберите «Забронировать» в чате.');close();render();return}
 show('Подтвердить доступность');body.append(el('p','',interval(o)),el('p','rs-muted','Максимальный срок холда по настройкам площадки — 30 минут.'));let l=el('label','','Удерживать время, минут '),n=el('input');n.type='number';n.min=1;n.max=30;n.value=30;l.append(n);body.append(l);action('Подтвердить и выставить счёт',()=>{if(!n.reportValidity())return;if(race){confirmAvailability(o);return}launchHold(o,Number(n.value));close()})
}
function choose(i){let o=offers[i];if(!o||role!=='customer')return;if(clock>=o.expires||race){race=false;offers.splice(i,1);eventAll('Вариант недоступен. Необходимо уточнить другое время.');render();return}launchHold(o,30)}
function kind(day,start){if(start<480||start>=1380)return 'closed';if((day===1&&start>=900&&start<1080)||(day===5&&start>=1080&&start<1200))return 'busy';if(day===3&&start>=1020&&start<1140)return 'held';return mode==='inquiry'?'unknown':'free'}
const same=(a,b)=>a.day===b.day&&a.start===b.start&&a.length===b.length;
const requestedCell=(d,m)=>d===requested.day&&m>=requested.start&&m<requested.start+requested.length;
const valid=(o)=>o.start+o.length<=1440&&Array.from({length:o.length/30},(_,i)=>kind(o.day,o.start+i*30)).every(x=>x==='free'||x==='unknown');
function schedule(alts=false){
 selected=[];offset=Math.max(0,requested.day-4);show('Расписание · '+court);
 const top=el('div','rs-control-row'),range=el('strong'),scroll=el('div','rs-grid-scroll'),table=el('table','rs-grid'),info=el('p','rs-muted'),apply=btn('Применить',()=>{if(!selected.length)return;if(selected.length===1&&same(selected[0],requested)&&!alts)confirmAvailability(selected[0]);else reasonDialog([...selected],alts)},'button primary');apply.disabled=true;
 top.append(btn('←',()=>{offset=Math.max(0,offset-8);draw()},'button secondary'),range,btn('→',()=>{offset+=8;draw()},'button secondary'));scroll.append(table);body.append(top,el('p','rs-muted','Оранжевая рамка — запрошено; зелёное — подтверждаем; оранжевое — альтернатива; красное — занято; жёлтое — холд; серое — неизвестно/недоступно.'),scroll,info);footer.append(apply);
 function draw(){range.textContent=fmt.format(date(offset))+' – '+fmt.format(date(offset+7));const head=el('thead'),hr=el('tr');hr.append(el('th','','Время'));for(let d=offset;d<offset+8;d++){let h=el('th',[0,6].includes(date(d).getUTCDay())?'rs-weekend':'',fmt.format(date(d)));hr.append(h)}head.append(hr);let tb=el('tbody');for(let m=0;m<1440;m+=30){let tr=el('tr');tr.append(el('th','',time(m)));for(let d=offset;d<offset+8;d++){let td=el('td'),k=kind(d,m),b=btn('',()=>{},'rs-slot');b.dataset.kind=k;b.dataset.requested=String(requestedCell(d,m));let picked=selected.find(o=>o.day===d&&m>=o.start&&m<o.start+o.length);if(picked)b.dataset.selected=same(picked,requested)?'requested':'alternative';b.setAttribute('aria-label',fmt.format(date(d))+' '+time(m)+' '+({free:'доступно',unknown:'занятость уточняется',busy:'занято',held:'холд',closed:'закрыто'})[k]);b.disabled=k==='closed';b.addEventListener('click',()=>{if(k==='busy'||k==='held'){info.textContent=k==='busy'?'Бронь №1201 · подтверждена представителем · оплачено':'Холд №1202 · до 16:00';return}let o=requestedCell(d,m)?{...requested}:{day:d,start:m,length:requested.length};let i=selected.findIndex(x=>same(x,o)||x.day===d&&m>=x.start&&m<x.start+x.length);if(i>=0)selected.splice(i,1);else if(valid(o)){if(same(o,requested)&&!alts)selected=[o];else{selected=selected.filter(x=>!same(x,requested));if(!selected.some(x=>x.day===o.day&&x.start<o.start+o.length&&o.start<x.start+x.length))selected.push(o)}}else{info.textContent='Интервал пересекает недоступное время.';return}info.textContent=selected.length?'Выбрано: '+selected.map(interval).join('; '):'Выберите интервал.';apply.disabled=!selected.length;draw()});td.append(b);tr.append(td)}tb.append(tr)}table.replaceChildren(head,tb)}
 draw();requestAnimationFrame(()=>{scroll.scrollTop=(requested.start/30)*29-70});
}
function reasonDialog(options,rejectDefault){show(options.length?'Предложить другое время':'Отклонить '+typeLabel().toLowerCase());options.forEach(o=>body.append(el('p','',interval(o))));let label=el('label','','Причина (не менее 10 символов)'),field=el('textarea');field.maxLength=2000;label.append(field);body.append(label);
 const save=reject=>{if(field.value.trim().length<10){field.focus();return}const reason=field.value.trim();const commit=()=>{offers=options.map(o=>({...o,expires:clock+60}));phase=options.length?'offered':reject?'rejected':'requested';eventAll((reject?'Исходное время отклонено. ':'')+(options.length?'Предложены варианты. ':'')+reason);close();render()};if(reject){show('Подтвердить отказ');body.append(el('p','','Вы уверены, что хотите отклонить исходную заявку?'));action('Подтвердить',commit);footer.prepend(btn('Назад',()=>reasonDialog(options,rejectDefault)))}else commit()};
 if(options.length&&!rejectDefault)action('Просто предложить',()=>save(false));action(options.length?'Отклонить и предложить':'Отклонить',()=>save(true));
}
q('[data-rs-close]').addEventListener('click',close);
q('[data-rs-send]').addEventListener('click',()=>{const f=q('[data-rs-text]');if(!f.value.trim())return;logs[edge].push({author:role,text:f.value.trim(),at:now()});f.value='';render();q('.rs-log').scrollTop=q('.rs-log').scrollHeight});
q('[data-rs-text]').addEventListener('input',()=>q('[data-rs-send]').disabled=!q('[data-rs-text]').value.trim());
q('[data-rs-reset]').addEventListener('click',reset);
q('[data-rs-race]').addEventListener('click',()=>{race=true;eventAll('Демонстрация: при следующей проверке возникнет конфликт.');render()});
q('[data-rs-expire]').addEventListener('click',()=>{if(phase==='held'){clock=holdUntil;phase='expired';invoices.forEach(v=>{if(v.status!=='paid')v.status='cancelled'});eventAll('Срок удержания истёк. Оплаченные средства требуют отдельного решения.');render()}});
q('[data-rs-mail]').addEventListener('click',()=>{show('Email · '+names[finalRole()]);body.append(el('strong','','Поступило обращение №1248'),el('p','',venue+' · '+court+'\n'+interval(requested)),el('p','rs-muted','Демонстрационный персональный подтверждённый контакт. Письмо не отправляется.'));action('Открыть заявку',()=>{close();switchRole(finalRole());if(['requested','offered'].includes(phase))schedule()})});
reset();
</script>
