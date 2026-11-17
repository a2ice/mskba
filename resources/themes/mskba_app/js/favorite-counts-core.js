// Pure helpers shared by the favorites badge and its node regression tests.
export const normalizeFavoriteCount = value => {
    if (value === null || value === undefined || value === '') return null;
    const count = Number(value);
    return Number.isSafeInteger(count) && count >= 0 ? count : null;
};

export const formatFavoriteCount = count => count > 99 ? '99+' : String(count);

export const canApplyFavoriteSync = (startedRevision, currentRevision, serial, currentSerial) =>
    startedRevision === currentRevision && serial === currentSerial;
