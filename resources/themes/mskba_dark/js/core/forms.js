/**
 * Преобразует jQuery-форму в объект вида { field: value }.
 * Если в форме есть повторяющиеся имена полей, значения собираются в массив.
 */
export function serializeFormObject(form) {
    return form.serializeArray().reduce((payload, field) => {
        if (Object.prototype.hasOwnProperty.call(payload, field.name)) {
            payload[field.name] = Array.isArray(payload[field.name])
                ? [...payload[field.name], field.value]
                : [payload[field.name], field.value];

            return payload;
        }

        payload[field.name] = field.value;
        return payload;
    }, {});
}

/**
 * Возвращает объект данных формы с возможностью точечно переопределить поля.
 * Удобно, когда нужно взять все поля формы и заменить/добавить несколько значений.
 */
export function serializeFormData(form, overrides = {}) {
    return {
        ...serializeFormObject(form),
        ...overrides
    };
}

/**
 * Возвращает строку в формате application/x-www-form-urlencoded.
 * Использует form.serialize() как базу и применяет overrides с поддержкой массивов.
 */
export function toQueryString(form, overrides = {}) {
    const params = new URLSearchParams(form.serialize());

    Object.entries(overrides).forEach(([key, value]) => {
        params.delete(key);

        if (Array.isArray(value)) {
            value.forEach((item) => {
                if (item !== undefined && item !== null) {
                    params.append(key, String(item));
                }
            });

            return;
        }

        if (value !== undefined && value !== null) {
            params.append(key, String(value));
        }
    });

    return params.toString();
}

/**
 * Отправляет форму через AJAX и управляет её submit-состоянием.
 * @param {HTMLElement|jQuery|string} formLike
 * @param {Object} [options]
 * @param {Object} [options.data] - Значения, которые переопределят данные формы.
 * @param {Function} [options.onSuccess] - Получает response, textStatus, jqXHR, outcome.
 * @param {Function} [options.onError] - Получает jqXHR, textStatus, errorThrown, outcome.
 * @param {Function} [options.onComplete] - Получает outcome и исходные аргументы $.ajax.always.
 * @returns {JQuery.jqXHR}
 */
export function submitForm(formLike, options = {}) {
    const form = formLike?.jquery ? formLike : $(formLike);

    if (!form.length) {
        throw new Error('submitForm: form not found');
    }

    const {
        url = form.attr('action'),
        method = form.attr('method') || 'POST',
        dataType = 'json',
        data: extraData = {},
        submittingMessage = 'Отправляем...',
        onSuccess = () => {},
        onError = () => {},
        onComplete = () => {}
    } = options;

    const data = serializeFormData(form, extraData);

    if (!url) {
        setFormMessage(form, 'Форма временно недоступна. Попробуйте позже.', 'error');
        throw new Error('submitForm: URL is required');
    }

    const activeRequest = form.data('submitFormRequest');
    if (activeRequest && activeRequest.readyState !== 4) {
        return activeRequest;
    }

    setFormSubmitting(form, true, submittingMessage, 'info');

    const request = $.ajax({
        url,
        method,
        dataType,
        headers: {
            Accept: 'application/json'
        },
        data
    });

    form.data('submitFormRequest', request);

    request
        .done(function(response, textStatus, jqXHR) {
            const outcome = createSubmitOutcome(true, response, textStatus, jqXHR);

            setFormSubmitting(form, false, outcome.message, 'success');
            onSuccess(response, textStatus, jqXHR, outcome);
        })
        .fail(function(jqXHR, textStatus, errorThrown) {
            if (textStatus === 'abort') {
                setFormSubmitting(form, false, '', '');
                return;
            }

            const outcome = createSubmitOutcome(false, jqXHR, textStatus, errorThrown);

            setFormSubmitting(form, false, outcome.message, 'error');
            onError(jqXHR, textStatus, errorThrown, outcome);
        })
        .always(function(firstArg, textStatus, thirdArg) {
            const success = isSuccessfulAjaxCompletion(firstArg, thirdArg);
            const outcome = success
                ? createSubmitOutcome(true, firstArg, textStatus, thirdArg)
                : createSubmitOutcome(false, firstArg, textStatus, thirdArg);

            form.removeData('submitFormRequest');
            onComplete(outcome, firstArg, textStatus, thirdArg);
        });

    return request;
}

export function clearFormMessage(formLike) {
    const form = formLike?.jquery ? formLike : $(formLike);

    if (!form.length) {
        return;
    }

    setFormMessage(form, '', '');
}

export function resetFormState(formLike) {
    const form = formLike?.jquery ? formLike : $(formLike);

    if (!form.length) {
        return;
    }

    const activeRequest = form.data('submitFormRequest');
    if (activeRequest && activeRequest.readyState !== 4 && typeof activeRequest.abort === 'function') {
        activeRequest.abort();
    }

    form
        .removeClass('is-submitting')
        .removeData('submitFormRequest');

    form.find('button[type="submit"], input[type="submit"]')
        .prop('disabled', false);

    clearFormMessage(form);
}

/*
* Cтавим форму в состояние "отправляется" через функцию, чтобы можно было централизованно управлять этим состоянием (например, блокировать повторные отправки)
* 
*/

const setFormSubmitting = function(form, isSubmitting, message = '...', messageType = 'info') {
    const submitButtons = form.find('button[type="submit"], input[type="submit"]');

    submitButtons.prop('disabled', isSubmitting);
    form.toggleClass('is-submitting', isSubmitting);

    setFormMessage(form, message, messageType);
};

export function setFormMessage(formLike, message = '', messageType = 'info') {
    const form = formLike?.jquery ? formLike : $(formLike);
    const messagePlaceholder = form.find('.form-message');

    if (!messagePlaceholder.length) {
        return;
    }

    messagePlaceholder
        .text(message || '')
        .removeClass('error success info')
        .toggleClass(messageType, Boolean(messageType))
        .attr('data-state', messageType || 'info');

    if (!messageType) {
        messagePlaceholder.removeAttr('data-state');
    }
}

function createSubmitOutcome(success, responseOrXhr, textStatus, xhrOrError) {
    const jqXHR = success ? xhrOrError : responseOrXhr;
    const response = success ? responseOrXhr : jqXHR?.responseJSON;
    const status = Number(jqXHR?.status || 0);

    return {
        success,
        status,
        textStatus,
        response,
        message: getResponseMessage(response, jqXHR, success),
        jqXHR: jqXHR || null,
        error: success ? null : xhrOrError
    };
}

function getResponseMessage(response, jqXHR, success) {
    if (response?.message) {
        return response.message;
    }

    const validationMessage = getFirstValidationMessage(response?.errors);
    if (validationMessage) {
        return validationMessage;
    }

    if (jqXHR?.responseJSON?.message) {
        return jqXHR.responseJSON.message;
    }

    const xhrValidationMessage = getFirstValidationMessage(jqXHR?.responseJSON?.errors);
    if (xhrValidationMessage) {
        return xhrValidationMessage;
    }

    if (jqXHR?.status === 419) {
        return 'Сессия истекла. Обновите страницу и попробуйте снова.';
    }

    if (jqXHR?.status === 429) {
        return 'Слишком много попыток. Попробуйте позже.';
    }

    if (success) {
        return 'Готово.';
    }

    return 'Не удалось обработать запрос. Попробуйте ещё раз.';
}

function getFirstValidationMessage(errors) {
    if (!errors || typeof errors !== 'object') {
        return '';
    }

    const firstFieldErrors = Object.values(errors)[0];
    if (Array.isArray(firstFieldErrors)) {
        return firstFieldErrors[0] || '';
    }

    return typeof firstFieldErrors === 'string' ? firstFieldErrors : '';
}

function isSuccessfulAjaxCompletion(firstArg, thirdArg) {
    const jqXHR = thirdArg && typeof thirdArg.status === 'number' ? thirdArg : firstArg;

    return Boolean(jqXHR && jqXHR.status >= 200 && jqXHR.status < 300);
}

export function handleSubmitForm(form, options = {}, callback = () => {}) {
    try {
        submitForm(form, options);
    } catch (error) {
        if (typeof callback === 'function') {
            callback(error);
        } else {
            console.error('handleSubmitForm error: ', error);
        }
    }
}
