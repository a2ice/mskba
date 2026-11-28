const forms = {
    views: ['просмотр', 'просмотра', 'просмотров'],
    likes: ['лайк', 'лайка', 'лайков'],
    comments: ['комментарий', 'комментария', 'комментариев'],
};
const plural = new Intl.PluralRules('ru');
const countWord = (kind, n) => forms[kind][({one:0, few:1})[plural.select(n)] ?? 2];
function setCounter(id, kind, value) {
    if (!Number.isSafeInteger(Number(value)) || Number(value) < 0) return;
    const n = Number(value);
    document.querySelectorAll('[data-news-engagement]').forEach(group => {
        if (Number(group.dataset.newsEngagement) !== Number(id)) return;
        const count = group.querySelector('[data-news-count="'+kind+'"]');
        const word = group.querySelector('[data-news-word="'+kind+'"]');
        if (count) count.textContent = String(n);
        if (word) word.textContent = countWord(kind, n);
    });
}
window.addEventListener('mskba:content-favorite:updated', event => {
    const {id, state, count} = event.detail || {};
    if (!id) return;
    setCounter(id, 'likes', count);
    document.querySelectorAll('[data-news-engagement] [data-favorite-type="content"]').forEach(button => {
        if (Number(button.dataset.favoriteId) !== Number(id)) return;
        button.setAttribute('aria-pressed', String(state === 'active'));
        button.title = state === 'active' ? 'Убрать из избранного' : 'Добавить в избранное';
        button.setAttribute('aria-label', button.title);
    });
});

const root = document.querySelector('[data-news-comments]');
if (root) {
    const id = Number(root.dataset.contentId);
    const list = root.querySelector('[data-news-comments-list]');
    const scroll = root.querySelector('[data-news-comments-scroll]');
    const empty = root.querySelector('[data-news-comments-empty]');
    const loading = root.querySelector('[data-news-comments-loading]');
    const more = root.querySelector('[data-news-comments-more]');
    const mineButton = root.querySelector('[data-news-comments-mine]');
    const status = root.querySelector('[data-news-comments-status]');
    const dialog = document.querySelector('[data-news-comment-dialog]');
    const form = dialog.querySelector('[data-news-comment-form]');
    const error = form.querySelector('[data-news-comment-error]');
    const submit = form.querySelector('[data-news-comment-submit]');
    const title = dialog.querySelector('[data-news-comment-title]');
    const authKey = 'mskba:news-comment:pending:v1';
    const currentPath = () => location.pathname + location.search;
    const clearIntent = () => { try { sessionStorage.removeItem(authKey); } catch {} };
    const authenticated = () => root.dataset.authenticated === '1';
    let mine = false, cursor = null, done = false, busy = false, failed = false, generation = 0, pending, editing = null, mutating = false;

    function updateCount(count) {
        setCounter(id, 'comments', count);
        if (Number.isSafeInteger(Number(count)) && Number(count) >= 0) root.querySelector('[data-news-comments-total]').textContent = String(count);
    }
    function node(tag, className, value) {
        const el = document.createElement(tag);
        if (className) el.className = className;
        if (value != null) el.textContent = value;
        return el;
    }
    function message(value) {
        status.textContent = value;
        status.hidden = !value;
    }
    async function request(url, method = 'GET', body = null, signal = undefined) {
        const res = await fetch(url, {
            method, signal, credentials: 'same-origin',
            headers: {Accept:'application/json','Content-Type':'application/json','X-Requested-With':'XMLHttpRequest',
                'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || ''},
            ...(body ? {body:JSON.stringify(body)} : {}),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            const description = Object.values(data.errors || {}).flat()[0] || data.message;
            throw new Error(description || 'Не удалось выполнить действие. Попробуйте ещё раз.');
        }
        return data;
    }
    function requireAuth() {
        if (!document.getElementById('mskba-auth-dialog-root')) {
            message('Не удалось открыть окно входа. Обновите страницу.'); return;
        }
        try { sessionStorage.setItem(authKey, JSON.stringify({path:currentPath(), at:Date.now()})); }
        catch { message('Не удалось сохранить действие для входа.'); return; }
        window.dispatchEvent(new CustomEvent('mskba:auth:open',{detail:{mode:'login'}}));
    }
    function open(comment = null) {
        if (!authenticated()) { requireAuth(); return; }
        if (dialog.open || mutating) return;
        editing = comment;
        form.reset();
        form.elements.body.value = comment?.body || '';
        title.textContent = comment ? 'Редактировать комментарий' : 'Оставить комментарий';
        submit.textContent = comment ? 'Сохранить' : 'Отправить';
        error.hidden = true;
        dialog.showModal();
    }
    function resume() {
        if (!authenticated() || document.querySelector('[data-onboarding-pending]')) return;
        let intent;
        try { intent = JSON.parse(sessionStorage.getItem(authKey) || 'null'); } catch { clearIntent(); return; }
        if (!intent) return;
        clearIntent();
        if (intent.path !== currentPath() || !Number.isFinite(intent.at) || Date.now()-intent.at > 15*60*1000 || Date.now()<intent.at) return;
        open();
    }
    window.addEventListener('mskba:auth:cancel', clearIntent);
    window.addEventListener('mskba:auth:success', () => {
        if (authenticated()) { resume(); return; }
        try {
            const intent = JSON.parse(sessionStorage.getItem(authKey) || 'null');
            if (intent?.path === currentPath()) location.reload();
        } catch { clearIntent(); }
    });
    window.addEventListener('mskba:onboarding-completed', resume);
    function render(comment) {
        const article = node('article','app-venue-review');
        const author = node('div','app-venue-review__author');
        const avatar = node('span','app-venue-review__avatar');
        const initial = (comment.author || 'Игрок').slice(0,1).toUpperCase();
        if (comment.avatar) {
            const img = node('img'); img.alt=''; img.loading='lazy'; img.src=comment.avatar;
            img.addEventListener('error', () => { img.remove(); avatar.textContent=initial; });
            avatar.append(img);
        } else avatar.textContent=initial;
        author.append(avatar,node('strong','',comment.author || 'Игрок'));
        const detail = node('div','app-venue-review__detail');
        const date = new Date(comment.created_at);
        if (!Number.isNaN(date.getTime())) {
            const time=node('time','app-news-comment-date',date.toLocaleDateString('ru-RU',{year:'numeric',month:'long',day:'numeric'}));
            time.dateTime=comment.created_at; detail.append(time);
        }
        detail.append(node('p','app-venue-review__body',comment.body));
        if (comment.own && !comment.published) detail.append(node('span','muted','На модерации'));
        if (comment.own) {
            const actions=node('div','app-venue-reviews__actions');
            const edit=node('button','button secondary app-venue-review__edit','Редактировать');
            const remove=node('button','button secondary app-venue-review__edit','Удалить');
            edit.type=remove.type='button';
            edit.addEventListener('click',()=>open(comment));
            remove.addEventListener('click',async()=>{
                if (mutating || !window.confirm('Удалить комментарий?')) return;
                mutating=true; remove.disabled=true;
                try {
                    const data=await request(root.dataset.commentsUrl+'/'+comment.id,'DELETE');
                    updateCount(data.count); message('Комментарий удалён.'); reset();
                } catch(e) { message(e.message); }
                finally { mutating=false; remove.disabled=false; }
            });
            actions.append(edit,remove); detail.append(actions);
        }
        article.append(author,detail); list.append(article);
    }
    async function load() {
        if (busy || done || failed) return;
        const current=generation;
        pending=new AbortController(); busy=true; loading.hidden=false; more.hidden=true;
        try {
            const url=new URL(root.dataset.commentsUrl,location.origin);
            if (mine) url.searchParams.set('mine','1');
            if (cursor!==null) url.searchParams.set('before',String(cursor));
            const data=await request(url,'GET',null,pending.signal);
            if (current!==generation) return;
            data.comments.forEach(render); cursor=data.next; done=cursor==null;
            empty.textContent=mine?'У тебя пока нет комментариев':'Пока комментариев нет';
            empty.hidden=list.childElementCount>0;
            updateCount(data.count); setCounter(id,'views',data.views);
        } catch(e) {
            if (current!==generation || e.name==='AbortError') return;
            failed=true; message(e.message);
        } finally {
            if (current===generation) {
                busy=false; loading.hidden=true;
                more.hidden=done; more.textContent=failed?'Повторить загрузку':'Показать ещё';
            }
        }
    }
    function reset() {
        generation++; pending?.abort();
        busy=done=failed=false; cursor=null;
        list.replaceChildren(); empty.hidden=true; more.hidden=true;
        scroll.scrollTop=0; void load();
    }
    function setMine(value) {
        mine=value; mineButton?.setAttribute('aria-pressed',String(mine));
        if (mineButton) mineButton.textContent=mine?'Показывать все комментарии':'Показывать мои комментарии';
        reset();
    }
    root.querySelector('[data-news-comment-open]').addEventListener('click',()=>open());
    dialog.querySelector('[data-news-comment-close]').addEventListener('click',()=>dialog.close());
    mineButton?.addEventListener('click',()=>{message('');setMine(!mine);});
    more.addEventListener('click',()=>{failed=false;message('');void load();});
    scroll.addEventListener('scroll',()=>{
        if (scroll.scrollTop+scroll.clientHeight>=scroll.scrollHeight-40) void load();
    },{passive:true});
    form.addEventListener('submit',async event=>{
        event.preventDefault();
        if (mutating || !authenticated()) return;
        mutating=true; submit.disabled=true; error.hidden=true;
        try {
            const payload={body:form.elements.body.value};
            if (editing) payload.revision=editing.revision;
            const data=await request(root.dataset.commentsUrl+(editing?'/'+editing.id:''),editing?'PATCH':'POST',payload);
            updateCount(data.count); dialog.close(); message('Комментарий сохранён и ожидает модерации.');
            setMine(true);
        } catch(e) {error.textContent=e.message;error.hidden=false;}
        finally {mutating=false;submit.disabled=false;}
    });
    void load();
    resume();
}
