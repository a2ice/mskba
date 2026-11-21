// Only contact IDs are stored, never OTP values.
export function createContactCodeAutoSubmit(storage) {
    const attempted = new Set();
    const key = id => 'mskba:contact-code-attempted:' + id;
    const validId = id => Number.isSafeInteger(Number(id)) && Number(id) > 0;
    function wasAttempted(id) {
        if (attempted.has(String(id))) return true;
        try { return (storage ?? globalThis.sessionStorage)?.getItem(key(id)) === '1'; }
        catch { return false; }
    }
    return {
        canSubmit(id, code) {
            return validId(id) && /^\d{6}$/.test(String(code).trim()) && !wasAttempted(id);
        },
        markAttempt(id) {
            if (!validId(id)) return;
            attempted.add(String(id));
            try { (storage ?? globalThis.sessionStorage)?.setItem(key(id), '1'); }
            catch { /* storage may be disabled */ }
        },
    };
}
