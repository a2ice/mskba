// Scenario-only configuration. WizardShell stays independent of registration.
const year = new Date().getFullYear();

export const registrationSteps = [
    { id: 'role', title: 'Кем ты хочешь быть в MSKBA?',
        hint: 'Ты можешь выбрать роль сейчас, а позже изменить её или добавить другую.',
        fields: ['role'], required: [] },
    { id: 'person', title: 'Расскажи немного о себе',
        hint: 'Это поможет подбирать игры. Все поля необязательные — их можно заполнить позже.',
        fields: ['gender', 'birth_date'], required: [], visibleWhen: values => values.role === 'player' },
    { id: 'sport', title: 'Спортивные характеристики',
        hint: 'Чтобы подобрать подходящую команду. Вводи только то, что знаешь.',
        fields: ['height_cm', 'weight_kg', 'position', 'body_type', 'experience_started_year'],
        required: [], visibleWhen: values => values.role === 'player' },
    { id: 'name', title: 'Как к тебе обращаться?',
        hint: 'ФИО необязательно и пригодится для персонализации профиля.',
        fields: ['last_name', 'first_name', 'middle_name'], required: [] },
    { id: 'account', title: 'Создай аккаунт',
        hint: 'Логин и пароль нужны для входа. Согласие на обработку данных обязательно.',
        fields: ['username', 'password', 'password_confirmation', 'privacy_consent'],
        required: ['username', 'password', 'password_confirmation', 'privacy_consent'] },
];

const validValues = {
    role: ['player', 'coach', 'referee', 'venue_related', 'media', 'statistician', 'organizer'],
    gender: ['male', 'female'],
    position: ['point_guard', 'shooting_guard', 'small_forward', 'power_forward', 'center'],
    body_type: ['slim', 'athletic', 'muscular', 'stocky', 'large'],
};

export function validateRegistrationField(field, values, required) {
    const v = values[field];
    if (required && (v === '' || v === null || v === undefined || v === false)) return 'Заполните обязательное поле.';
    if (v === '' || v === null || v === undefined || v === false) return '';
    if (validValues[field] && !validValues[field].includes(v)) return 'Выбери допустимое значение.';
    if (field === 'birth_date') {
        const today = new Date().toISOString().slice(0, 10);
        if (!/^\d{4}-\d\d-\d\d$/.test(v) || !Number.isFinite(Date.parse(v)) || v >= today)
            return 'Укажите дату рождения раньше сегодняшнего дня.';
    }
    const bounds = {
        height_cm: [150, 220], weight_kg: [40, 140],
        experience_started_year: [year - 50, year - 10],
    };
    if (bounds[field]) {
        const n = Number(v), [min, max] = bounds[field];
        if (!Number.isFinite(n) || n < min || n > max ||
            (field !== 'weight_kg' && !Number.isInteger(n)))
            return 'Введи значение от ' + min + ' до ' + max + '.';
    }
    if (field === 'username' && (String(v).length < 3 || String(v).length > 32))
        return 'Логин должен содержать от 3 до 32 символов.';
    if (field === 'password' && (String(v).length < 6 || String(v).length > 255))
        return 'Пароль должен содержать от 6 до 255 символов.';
    if (field === 'password_confirmation' && v !== values.password)
        return 'Пароли не совпадают.';
    for (const name of ['first_name', 'middle_name', 'last_name']) {
        if (field === name && String(v).length > 255) return 'Максимум 255 символов.';
    }
    return '';
}
