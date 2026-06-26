<template>
  <div class="home-page">
    <div class="home-header">
      <h1>BitHabit</h1>
      <div class="user-area">
        <span class="greeting">{{ authStore.userInfo?.nickname || '用户' }}</span>
        <button class="btn-logout" @click="handleLogout">退出</button>
      </div>
    </div>

    <div class="home-content">
      <div class="welcome-card">
        <p class="welcome-icon">☀️</p>
        <p class="welcome-text">暑假快乐！</p>
        <p class="welcome-hint" v-if="planCount">你有 {{ planCount }} 个计划</p>
      </div>

      <div class="nav-grid">
        <router-link to="/homework" class="nav-card">
          <span class="card-icon">📚</span>
          <span class="card-title">我的作业</span>
          <span class="card-desc">录入暑假作业</span>
        </router-link>
        <router-link to="/schedule" class="nav-card">
          <span class="card-icon">📅</span>
          <span class="card-title">日程配置</span>
          <span class="card-desc">设置可用时段</span>
        </router-link>
        <router-link to="/plan/create" class="nav-card">
          <span class="card-icon">🎯</span>
          <span class="card-title">生成计划</span>
          <span class="card-desc">智能分配日程</span>
        </router-link>
        <div class="nav-card nav-card-disabled">
          <span class="card-icon">📊</span>
          <span class="card-title">学习统计</span>
          <span class="card-desc">即将上线</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const authStore = useAuthStore()
const planCount = ref(0)

onMounted(async () => {
  const valid = await authStore.verifyToken()
  if (!valid) {
    router.replace('/login')
  }
})

function handleLogout() {
  authStore.clearAuth()
  router.replace('/login')
}
</script>

<style scoped>
.home-page {
  min-height: 100vh;
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
}

.home-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  background: var(--color-card);
  border-bottom: 1px solid var(--color-border);
  position: sticky;
  top: 0;
  z-index: 10;
}

.home-header h1 {
  font-size: 1.125rem;
  font-weight: 700;
  color: var(--color-primary);
}

.user-area {
  display: flex;
  align-items: center;
  gap: 12px;
}

.greeting {
  font-size: 0.875rem;
  color: var(--color-text-secondary);
}

.btn-logout {
  padding: 6px 14px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-card);
  color: var(--color-text-secondary);
  font-size: 0.8125rem;
  cursor: pointer;
  font-family: var(--font-family);
}

.btn-logout:hover {
  border-color: var(--color-error);
  color: var(--color-error);
}

.home-content {
  flex: 1;
  padding: 20px;
  padding-bottom: 80px;
  max-width: 600px;
  margin: 0 auto;
  width: 100%;
}

.welcome-card {
  text-align: center;
  padding: 24px 16px;
  background: linear-gradient(135deg, #EEF2FF, #E0E7FF);
  border-radius: var(--radius);
  margin-bottom: 24px;
}

.welcome-icon {
  font-size: 2.5rem;
  margin-bottom: 8px;
}

.welcome-text {
  font-size: 1.125rem;
  font-weight: 600;
  color: var(--color-text);
}

.welcome-hint {
  font-size: 0.8125rem;
  color: var(--color-text-secondary);
  margin-top: 4px;
}

.nav-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.nav-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 24px 16px;
  background: var(--color-card);
  border-radius: var(--radius);
  text-decoration: none;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
  transition: transform 0.15s, box-shadow 0.15s;
}

.nav-card:active {
  transform: scale(0.97);
}

.nav-card-disabled {
  opacity: 0.5;
  pointer-events: none;
}

.card-icon {
  font-size: 2rem;
  margin-bottom: 8px;
}

.card-title {
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--color-text);
  margin-bottom: 2px;
}

.card-desc {
  font-size: 0.75rem;
  color: var(--color-text-placeholder);
}
</style>
