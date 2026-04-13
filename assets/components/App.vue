<template>
  <div class="app-layout">
    <nav class="top-navbar">
      <div class="nav-container">
        <router-link to="/" class="brand">
          <span class="logo-icon">📊</span>
          <span class="brand-text">PMU Pro</span>
        </router-link>
        <div class="nav-links">
          <a href="/gestion" class="btn-manage">⚙️ Gestion backend</a>
        </div>
      </div>
    </nav>
    
    <main class="main-content">
      <router-view v-slot="{ Component }">
        <transition name="fade" mode="out-in">
          <component :is="Component" />
        </transition>
      </router-view>
    </main>
  </div>
</template>

<script>
export default {
    name: 'App'
};
</script>
