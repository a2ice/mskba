const root = document.querySelector('[data-play-interests]');
if (root) {
    const outlet = root.querySelector('[data-play-interests-list]');
    const make = (tag, text, className = '') => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;
        return node;
    };
    const statusNames = {active:'Активно',paused:'На паузе',expired:'Истёк',cancelled:'Отменён',completed:'Выполнено'};
    const cadenceNames = {flexible:'Гибкий поиск',once:'Разово',regular:'Регулярно'};
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const request = async (url, method = 'GET') => {
        const res = await fetch(url,{method,credentials:'same-origin',headers:{
            Accept:'application/json','X-Requested-With':'XMLHttpRequest',
            'X-CSRF-TOKEN':csrf(),
        }});
        if(!res.ok)throw new Error(res.status===419?'Сессия истекла. Обнови страницу.':'Не удалось выполнить запрос.');
        return res.json();
    };
    const summary = intent => {
        const days = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
        const dayLabel = intent.weekdays?.length
            ? intent.weekdays.map(n=>days[Number(n)-1]).filter(Boolean).join(', ') : 'любые дни';
        const frequency = cadenceNames[intent.cadence] || intent.cadence;
        const dates = intent.dates?.length ? intent.dates.join(', ') : '';
        return [frequency, dates || dayLabel, (intent.formats || []).length
            ? intent.formats.join(', ').replaceAll('x','×') : 'любой формат'].join(' · ');
    };
    const button = (text, action) => {
        const b = make('button',text,'button secondary');b.type='button';
        b.addEventListener('click',action);return b;
    };
    function showError(message) { outlet.replaceChildren(make('p',message,'error')); }
    async function load() {
        try {
            const response = await request(root.dataset.listUrl);
            outlet.replaceChildren();
            if (!response.data?.length) {
                const empty=make('div',null,'app-play-interests__empty');
                empty.append(make('p','Пока нет сохранённых интересов.'),make('p','Открой понравившуюся площадку и нажми «Хочу здесь играть».','muted'));
                outlet.append(empty);return;
            }
            for (const intent of response.data) {
                const card = make('article',null,'app-play-interests__item');
                const head = make('div',null,'app-play-interests__item-head');
                head.append(make('strong','Интерес №'+intent.id),make('span',statusNames[intent.effective_status] || intent.status,'badge'));
                card.append(head,make('p',summary(intent),'muted'));
                const actions=make('div',null,'app-play-interests__actions');
                if(intent.effective_status === 'active') {
                    actions.append(button('Приостановить',async()=>{
                        try{await request(root.dataset.pauseUrl+'/'+intent.id+'/pause','PATCH');await load();}
                        catch(e){showError(e.message);}
                    }));
                }else if(intent.effective_status === 'paused') {
                    actions.append(button('Возобновить',async()=>{
                        try{await request(root.dataset.pauseUrl+'/'+intent.id+'/resume','PATCH');await load();}
                        catch(e){showError(e.message);}
                    }));
                }
                if(intent.effective_status === 'active') {
                    const alternatives=make('div',null,'app-play-interests__results');
                    actions.append(button('Подходящие площадки',async()=>{
                        alternatives.replaceChildren(make('p','Подбираем варианты…','muted'));
                        try{
                            const response=await request(root.dataset.pauseUrl+'/'+intent.id+'/recommendations');
                            alternatives.replaceChildren();
                            if(!response.data.length){alternatives.append(make('p','По выбранным условиям площадки пока не найдены.','muted'));return;}
                            const ul=make('ul');
                            for(const venue of response.data) {
                                const li=make('li'), a=make('a',venue.name);
                                const target=new URL(venue.url,location.origin);
                                if(target.origin!==location.origin)continue;
                                a.href=target.href;
                                li.append(a,make('span',' · доступность брони ещё не проверена','muted'));
                                ul.append(li);
                            }
                            alternatives.append(ul);
                        }catch(e){alternatives.replaceChildren(make('p',e.message,'error'));}
                    }));
                    card.append(alternatives);
                }
                if(!['cancelled','completed'].includes(intent.effective_status)) {
                    actions.append(button('Отменить поиск',async()=>{
                        if(!window.confirm('Отменить этот игровой интерес?'))return;
                        try{await request(root.dataset.pauseUrl+'/'+intent.id,'DELETE');await load();}
                        catch(e){showError(e.message);}
                    }));
                }
                card.append(actions);outlet.append(card);
            }
        } catch (e) { showError(e.message); }
    }
    void load();
}
