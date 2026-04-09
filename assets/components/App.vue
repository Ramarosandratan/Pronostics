<template>
    <div class="app-container">
        <h1>{{ message }}</h1>
        <p>Bienvenue dans votre application PMU Pronostics !</p>
        <a class="manage-link" href="/gestion">Ouvrir les pages de gestion</a>
    </div>
</template>

<script>
import { ref } from 'vue';

export default {
    name: 'App',
    setup() {
        const message = ref('🚀 Bonjour depuis Vue.js dans Symfony !');

        return {
            message
        };
    }
};
</script>

<style scoped>
.app-container {
    padding: 2rem;
    text-align: center;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
}

h1 {
    color: #41b883;
    margin: 0 0 1rem 0;
}

p {
    color: #666;
    font-size: 1.1rem;
}

.manage-link {
    display: inline-block;
    margin-top: 1rem;
    padding: 0.7rem 1rem;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    background: #1f6feb;
    color: #fff;
}
</style>
