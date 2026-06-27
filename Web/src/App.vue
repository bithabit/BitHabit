<template>
  <div id="app-root">
    <router-view />
    <UpdatePrompt />
    <!-- 底部导航栏（仅登录后显示） -->
    <nav class="bottom-nav" v-if="auth.token">
      <router-link to="/" class="nav-item" active-class="nav-active" :class="{ 'nav-active': isTodayActive }">
        <span class="nav-icon">📋</span>
        <span class="nav-label">今日</span>
      </router-link>
      <router-link to="/homework" class="nav-item" active-class="nav-active">
        <span class="nav-icon">📚</span>
        <span class="nav-label">作业</span>
      </router-link>
      <router-link to="/plans" class="nav-item" active-class="nav-active">
        <span class="nav-icon">🎯</span>
        <span class="nav-label">计划</span>
      </router-link>
    </nav>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from './stores/auth'
import UpdatePrompt from './components/UpdatePrompt.vue'

const auth = useAuthStore()
const route = useRoute()

const isTodayActive = computed(() => route.path === '/')
</script>

<style>
.bottom-nav {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  display: flex;
  justify-content: space-around;
  background: var(--color-card);
  border-top: 1px solid var(--color-border);
  padding: 8px 0 env(safe-area-inset-bottom, 8px);
  z-index: 100;
}

.nav-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  padding: 4px 12px;
  text-decoration: none;
  color: var(--color-text-placeholder);
  transition: color 0.2s;
}

.nav-icon {
  font-size: 1.25rem;
}

.nav-label {
  font-size: 0.6875rem;
  font-weight: 500;
}

.nav-active {
  color: var(--color-primary) !important;
}
</style>
