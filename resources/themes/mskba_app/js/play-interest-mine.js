const root = document.querySelector('[data-play-interests]');
if (root) {
    const outlet = root.querySelector('[data-play-interests-list]');
    const filters = root.querySelector('[data-subscriptions-filters]');
    const pagination = root.querySelector('[data-subscriptions-pagination]');
    const detailsDialog = root.querySelector('[data-subscription-details-dialog]');
    const detailsBody = detailsDialog?.querySelector('[data-subscription-details-body]');
    const detailsTitle = detailsDialog?.querySelector('[data-subscription-details-title]');
    const detailsEdit = detailsDialog?.querySelector('[data-subscription-details-edit]');
    let detailsTrigger = null;
    detailsDialog?.querySelector('[data-subscription-details-close]')?.addEventListener('click',()=>detailsDialog.close());
    detailsDialog?.addEventListener('close',()=>detailsTrigger?.focus({preventScroll:true}));
    const typeSelect = filters?.querySelector('[name="type"]');
    const statusSelect = filters?.querySelector('[name="status"]');
    const validTypes = new Set(['all','play']);
    const validStatuses = new Set(['all','active','paused','expired','cancelled','completed']);
    let currentPage = 1;
    let loadSequence = 0;

    function syncFromUrl() {
        const url = new URL(location.href);
        typeSelect.value = validTypes.has(url.searchParams.get('type')) ? url.searchParams.get('type') : 'all';
        statusSelect.value = validStatuses.has(url.searchParams.get('status')) ? url.searchParams.get('status') : 'all';
        currentPage = Math.max(1, Number.parseInt(url.searchParams.get('page'), 10) || 1);
    }
    function updateUrl() {
        const url = new URL(location.href);
        for (const [key, value] of [['type',typeSelect.value],['status',statusSelect.value],['page',String(currentPage)]]) {
            if (value==='all' || (key==='page' && value==='1')) url.searchParams.delete(key);
            else url.searchParams.set(key,value);
        }
        history.pushState(null,'',url);
    }
    const openPage = page => {
        currentPage = page;
        updateUrl();
        void load();
    };
    function renderPagination(meta) {
        pagination.replaceChildren();
        pagination.hidden = !meta || meta.total === 0;
        if (pagination.hidden) return;
        const total = meta.total, last = Math.max(1,meta.last_page), page = meta.current_page;
        const offset = (page-1)*meta.per_page;
        pagination.append(make('span',`Показано ${offset+1}–${Math.min(total, offset+meta.per_page)} из ${total}`,'app-play-interests__page-summary'));
        if (last < 2) return;
        const controls = make('div',null,'app-play-interests__page-buttons');
        const addPage = (text,value,disabled=false,active=false) => {
            const control = make('button',text,'button secondary');
            control.type = 'button';
            control.disabled = disabled;
            if (active) { control.classList.add('is-active'); control.setAttribute('aria-current','page'); }
            control.addEventListener('click',()=>openPage(value));
            controls.append(control);
        };
        addPage('Назад',Math.max(1,page-1),page===1);
        const pages = [...new Set([1,last,page-1,page,page+1].filter(n=>n>=1 && n<=last))].sort((a,b)=>a-b);
        let previous = 0;
        for (const number of pages) {
            if(number-previous>1) controls.append(make('span','…','app-play-interests__page-ellipsis'));
            addPage(String(number),number,false,number===page);
            previous=number;
        }
        addPage('Далее',Math.min(last,page+1),page===last);
        pagination.append(controls);
    }
    syncFromUrl();
    filters?.addEventListener('change',()=>{currentPage=1;updateUrl();void load();});
    filters?.addEventListener('reset',event=>{
        event.preventDefault();
        typeSelect.value='all';
        statusSelect.value='all';
        currentPage=1;
        updateUrl();
        void load();
    });
    window.addEventListener('popstate',()=>{syncFromUrl();void load();});
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
        // This describes matching conditions, not the user's chosen venue.
        const venueKind = intent.venue_id && !intent.include_similar_venues
            ? null
            : {any:'любая площадка',indoor:'крытая площадка',outdoor:'открытая площадка'}[intent.venue_kind || 'any'];
        return [frequency, dates || dayLabel, (intent.formats || []).length
            ? intent.formats.join(', ').replaceAll('x','×') : 'любой формат', venueKind].filter(Boolean).join(' · ');
    };
    const button = (text, action) => {
        const b = make('button',text,'button secondary');b.type='button';
        b.addEventListener('click',action);return b;
    };
    // The shared list exposes only generic actions. Type-specific settings
    // live in the detail viewer and in the existing subscription editor.
    function openDetails(intent, trigger) {
        if (!detailsDialog || !detailsBody) return;
        detailsTrigger=trigger;
        detailsTitle.textContent=intent.name || 'Подписка №'+intent.id;
        detailsBody.replaceChildren();
        const addFact=(label,value)=>{
            if(value===null || value===undefined || value==='')return;
            const row=make('div',null,'app-subscription-details__fact');
            row.append(make('dt',label),make('dd',String(value)));
            detailsBody.append(row);
        };
        addFact('Тип','Игровая подписка');
        addFact('Статус',statusNames[intent.effective_status]||intent.status);
        addFact('Площадка',intent.venue?.name || (intent.venue_id?'Площадка больше недоступна':'Поиск без конкретной площадки'));
        addFact('Условия',summary(intent));
        if(intent.expires_at)addFact('Действует до',new Date(intent.expires_at).toLocaleDateString('ru-RU'));
        addFact('Уведомления о подходящих играх',intent.notify_matches?'Включены':'Выключены');
        addFact('Предложения площадок',intent.notify_offers?'Включены':'Выключены');
        addFact('Публичность игровых данных',intent.show_on_venue?'Включена':'Выключена');
        if(detailsEdit){
            const canEdit=!['cancelled','completed'].includes(intent.effective_status);
            detailsEdit.hidden=!canEdit;
            detailsEdit.onclick=()=>{
                detailsDialog.close();
                window.dispatchEvent(new CustomEvent('mskba:play-intent:edit',{detail:{intent}}));
            };
        }
        detailsDialog.showModal();
    }

    function showError(message) { outlet.replaceChildren(make('p',message,'error')); }
    async function load() {
        const sequence=++loadSequence;
        try {
            const target=new URL(root.dataset.listUrl,location.origin);
            target.searchParams.set('type',typeSelect.value);
            target.searchParams.set('status',statusSelect.value);
            target.searchParams.set('page',String(currentPage));
            const response = await request(target.href);
            if(sequence!==loadSequence)return;
            if(response.meta && currentPage>response.meta.last_page){
                currentPage=response.meta.last_page;
                updateUrl();
                return load();
            }
            outlet.replaceChildren();
            renderPagination(response.meta);
            if (!response.data?.length) {
                const empty=make('div',null,'app-play-interests__empty');
                const filtered=typeSelect.value!=='all'||statusSelect.value!=='all';
                empty.append(make('p',filtered?'По выбранным фильтрам подписок нет.':'Пока нет подписок.'));
                if (!filtered) empty.append(make('p','Когда ты оформишь первую подписку, она появится здесь.','muted'));
                outlet.append(empty);return;
            }
            for (const intent of response.data) {
                const card = make('article',null,'app-play-interests__item');
                const head = make('div',null,'app-play-interests__item-head');
                head.append(make('strong',intent.name || 'Подписка №'+intent.id),make('span',statusNames[intent.effective_status] || intent.status,'badge'));
                card.append(head);
                if (intent.venue) {
                    const venueLine = make('p',null,'app-play-interests__venue');
                    venueLine.append(make('span','Площадка: '));
                    const venueLink = make('a',intent.venue.name);
                    // The API supplies the canonical internal URL.
                    const target = new URL(intent.venue.url,location.origin);
                    if(target.origin === location.origin) {
                        venueLink.href=target.href;
                        venueLine.append(venueLink);
                    }else venueLine.append(make('span',intent.venue.name));
                    card.append(venueLine);
                } else if (intent.venue_id) {
                    card.append(make('p','Площадка больше недоступна','muted'));
                } else {
                    card.append(make('p','Поиск без конкретной площадки','muted'));
                }
                card.append(make('p',summary(intent),'muted'));
                const actions=make('div',null,'app-play-interests__actions');
                const viewButton=button('Посмотреть',()=>openDetails(intent,viewButton));
                actions.append(viewButton);
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
                if(intent.effective_status === 'expired') {
                    actions.append(button('Восстановить подписку',async()=>{
                        if (intent.cadence === 'once') {
                            const notice=make('p','Укажи новые даты и сохрани подписку, чтобы восстановить разовый поиск.','muted');
                            card.insertBefore(notice,actions);
                            window.dispatchEvent(new CustomEvent('mskba:play-intent:edit',{detail:{intent}}));
                            return;
                        }
                        try {
                            await request(root.dataset.pauseUrl+'/'+intent.id+'/restore','PATCH');
                            await load();
                        } catch(e) { showError(e.message); }
                    }));
                }
                if(!['cancelled','completed','expired'].includes(intent.effective_status)) {
                    actions.append(button('Отменить подписку',async()=>{
                        if(!window.confirm('Отменить эту игровую подписку?'))return;
                        try{await request(root.dataset.pauseUrl+'/'+intent.id,'DELETE');await load();}
                        catch(e){showError(e.message);}
                    }));
                }
                card.append(actions);outlet.append(card);
            }
        } catch (e) {
            if(sequence===loadSequence){pagination.hidden=true;showError(e.message);}
        }
    }
    window.addEventListener('mskba:play-intent:saved',()=>{void load();});
    void load();
}
