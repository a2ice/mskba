<script setup>
defineProps({
    pageTitle: { type: String, required: true },
    environment: { type: String, required: true },
    auth: { type: Object, default: () => ({ user: null }) },
});
</script>

<template>
    <section class="section" aria-labelledby="preview-heading">
        <div class="container">
        <p class="eyebrow accent">MSKBA APP / ПЕРВЫЙ INERTIA ЭКРАН</p>
        <h1 id="preview-heading">{{ pageTitle }}</h1>
        <p class="section-description">
            Vue 3 + Inertia подключены к существующему Laravel-приложению.
            Этот экран использует реальные props сервера, а не демонстрационный каталог.
        </p>
        <div class="panel specimen-card">
            <p><strong>Окружение:</strong> {{ environment }}</p>
            <p><strong>Сессия:</strong> {{ auth?.user?.username ?? 'Гость' }}</p>
            <a class="button secondary" href="/">На главную</a>
        </div>
        </div>
    </section>
</template>
