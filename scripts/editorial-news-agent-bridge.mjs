import { readFile, realpath, stat } from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const BASE = 'https://mskba.ru/api/v1/internal/editorial-news';
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));

export function validatePlan(plan) {
    if (!plan || typeof plan !== 'object' || Array.isArray(plan)) throw new Error('Invalid request.');
    const allowed = ['alias', 'title', 'summary', 'content', 'cover', 'telegram_chat_titles', 'vk_group_id', 'resume_existing_draft', 'allow_vk_wait', 'meta_title', 'meta_description', 'meta_keywords'];
    if (Object.keys(plan).some(key => !allowed.includes(key))) throw new Error('Unexpected request field.');
    for (const [key, max] of [['alias', 230], ['title', 255], ['summary', 1000], ['content', 50000]]) {
        if (typeof plan[key] !== 'string' || !plan[key].trim() || plan[key].length > max) throw new Error('Invalid ' + key);
    }
    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(plan.alias)) throw new Error('Invalid alias.');
    for (const [key, max] of [['meta_title', 255], ['meta_description', 320], ['meta_keywords', 1000]]) {
        if (plan[key] !== undefined && (typeof plan[key] !== 'string' || plan[key].length > max)) throw new Error('Invalid ' + key);
    }
    if (typeof plan.cover !== 'string' || !/^ops\/editorial-news\/covers\/[a-z0-9][a-z0-9.-]*\.(?:jpg|jpeg|png|webp)$/.test(plan.cover)) throw new Error('Invalid cover path.');
    const names = plan.telegram_chat_titles;
    if (!Array.isArray(names) || names.length > 100 || names.some(n => typeof n !== 'string' || !n.trim() || n.length > 255)) throw new Error('Invalid Telegram targets.');
    if (new Set(names.map(normalize)).size !== names.length) throw new Error('Duplicate Telegram targets.');
    if (plan.vk_group_id !== null && (!Number.isSafeInteger(plan.vk_group_id) || plan.vk_group_id <= 0)) throw new Error('Invalid VK target.');
    if (plan.resume_existing_draft !== undefined && typeof plan.resume_existing_draft !== 'boolean') throw new Error('Invalid resume flag.');
    if (plan.allow_vk_wait !== undefined && (typeof plan.allow_vk_wait !== 'boolean' || plan.vk_group_id === null)) throw new Error('Invalid VK deferral flag.');
    return plan;
}

function normalize(name) { return name.trim().toLowerCase(); }

export function selectDestinations(plan, registry) {
    const ids = plan.telegram_chat_titles.map(name => {
        const matches = (registry.telegram ?? []).filter(chat => typeof chat.title === 'string' && normalize(chat.title) === normalize(name));
        if (matches.length !== 1 || !Number.isSafeInteger(matches[0].id) || matches[0].id <= 0) throw new Error('Telegram target missing or ambiguous.');
        return matches[0].id;
    });
    if (new Set(ids).size !== ids.length) throw new Error('Duplicate target IDs.');
    const vk = plan.vk_group_id;
    if (vk !== null && (registry.vk?.group_id !== vk || (registry.vk?.available !== true && !(plan.allow_vk_wait === true && registry.vk?.can_defer === true)))) throw new Error('Requested VK group is unavailable; no publication sent.');
    return { telegram_chat_ids: ids, publish_in_vk: vk !== null, ...(vk === null ? {} : { vk_group_id: vk }), ...(plan.allow_vk_wait === true ? { defer_vk_until_authorized: true } : {}) };
}

export function verifySelection(data, selection) {
    const actual = (data.telegram_publications ?? []).map(row => row.chat_id).sort((a,b) => a-b);
    const expected = [...selection.telegram_chat_ids].sort((a,b) => a-b);
    if (JSON.stringify(actual) !== JSON.stringify(expected)
        || (data.vk_publication?.group_id ?? null) !== (selection.vk_group_id ?? null)) throw new Error('Server recipients do not match reviewed request.');
}

export function delivery(data, selection) {
    verifySelection(data, selection);
    const rows = [...data.telegram_publications, ...(data.vk_publication ? [data.vk_publication] : [])];
    if (rows.some(row => ['failed', 'unknown', 'closed'].includes(row.status))) return 'failed';
    if (data.vk_publication?.status === 'awaiting_auth' && data.telegram_publications.every(row => row.status === 'published')) return 'awaiting_auth';
    return data.status === 'published' && typeof data.url === 'string' && data.url.startsWith('https://mskba.ru/')
        && rows.every(row => row.status === 'published' && Number.isSafeInteger(row.message_id ?? row.post_id) && (row.message_id ?? row.post_id) > 0) ? 'published' : 'pending';
}

async function main(requestPath, token) {
    if (!token) throw new Error('Set MSKBA_EDITORIAL_NEWS_TOKEN in editorial-production environment secrets.');
    if (!/^ops\/editorial-news\/requests\/[a-z0-9][a-z0-9-]*\.json$/.test(requestPath ?? '')) throw new Error('Invalid request path.');
    const requestReal = await realpath(requestPath);
    if (requestReal !== path.resolve(requestPath)) throw new Error('Request path must not contain symlinks.');
    const plan = validatePlan(JSON.parse(await readFile(requestReal, 'utf8')));
    const coverReal = await realpath(plan.cover);
    if (coverReal !== path.resolve(plan.cover)) throw new Error('Cover path must not contain symlinks.');
    const meta = await stat(coverReal);
    if (!meta.isFile() || meta.size <= 0 || meta.size > 5 * 1024 * 1024) throw new Error('Cover must be a regular file up to 5 MiB.');
    const endpoint = '/' + plan.alias;
    async function api(method, suffix, body, allow404 = false) {
        let response;
        try {
            response = await fetch(BASE + suffix, {
                method, redirect: 'error', signal: AbortSignal.timeout(30000),
                headers: { Accept: 'application/json', Authorization: 'Bearer ' + token,
                    ...(typeof body === 'string' ? {'Content-Type': 'application/json'} : {}) },
                ...(body === undefined ? {} : { body }),
            });
        } catch {
            throw new Error('API transport failure: check current state before retrying, no automatic mutation retry.');
        }
        if (allow404 && response.status === 404) return null;
        if (!response.ok) throw new Error('API ' + method + ' returned HTTP ' + response.status + '.');
        const result = await response.json();
        if (!result?.data) throw new Error('Invalid API response.');
        return result.data;
    }
    // An existing published item is read-only. Do not require live VK photo auth just to inspect it.
    let item = await api('GET', endpoint, undefined, true);
    if (item && (item.title !== plan.title || item.has_cover !== true)) throw new Error('Existing alias does not match reviewed material.');
    let selection;
    if (item?.status === 'published') {
        const existing = item.telegram_publications ?? [];
        if (existing.length !== plan.telegram_chat_titles.length || (item.vk_publication?.group_id ?? null) !== plan.vk_group_id) throw new Error('Existing material has different destinations.');
        // Do not write to an already published alias, even if a target registry has changed.
        console.log(JSON.stringify({ alias: plan.alias, url: item.url, status: item.status, telegram: existing, vk: item.vk_publication }));
        throw new Error('Alias already published; inspect reported destinations and status. No mutation performed.');
    }
    selection = selectDestinations(plan, await api('GET', '/destinations/list'));
    if (item && (item.status !== 'draft' || plan.resume_existing_draft !== true)) throw new Error('Existing draft: review in admin before explicitly resuming.');
    if (!item) {
        const form = new FormData();
        for (const key of ['alias', 'title', 'summary', 'content', 'meta_title', 'meta_description', 'meta_keywords']) {
            if (plan[key] !== undefined) form.set(key, plan[key]);
        }
        const ext = path.extname(plan.cover);
        const mime = ['.jpeg', '.jpg'].includes(ext) ? 'image/jpeg' : 'image/' + ext.slice(1);
        form.set('cover', new Blob([await readFile(coverReal)], { type: mime }), path.basename(plan.cover));
        item = await api('POST', '', form);
        if (item.title !== plan.title || item.status !== 'draft' || !item.has_cover) throw new Error('Draft was not confirmed.');
    }
    item = await api('POST', endpoint + '/publish', JSON.stringify(selection));
    for (let attempt = 0; attempt < 25; attempt++) {
        const result = delivery(item, selection);
        if (result !== 'pending') {
            console.log(JSON.stringify({ alias: plan.alias, url: item.url, status: item.status, telegram: item.telegram_publications, vk: item.vk_publication }));
            if (result === 'awaiting_auth') throw new Error('VK is waiting for photo authorization; no text-only post or retry performed.');
            if (result !== 'published') throw new Error('At least one external delivery failed or is unknown; reconcile before retrying.');
            return;
        }
        await pause(5000);
        item = await api('GET', endpoint);
    }
    console.log(JSON.stringify({ alias: plan.alias, url: item.url, status: item.status, telegram: item.telegram_publications, vk: item.vk_publication }));
    throw new Error('Delivery not yet confirmed. Check status; do not re-create.');
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
    main(process.argv[2], process.env.MSKBA_EDITORIAL_NEWS_TOKEN).catch(error => {
        console.error(error instanceof SyntaxError ? 'Invalid JSON.' : error.message);
        process.exitCode = 1;
    });
}
