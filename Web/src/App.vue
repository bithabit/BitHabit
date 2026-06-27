<template>
  <div id="app-root">
    <router-view v-slot="{ Component, route: r }">
      <Transition :name="transitionName" mode="out-in">
        <component :is="Component" :key="r.path" />
      </Transition>
    </router-view>
    <UpdatePrompt />
    <!-- 底部导航栏（仅登录后显示） -->
    <Transition name="nav-fade">
      <nav class="bottom-nav" v-if="auth.token">
        <router-link to="/" class="nav-item" active-class="nav-active">
          <span class="nav-icon">📋</span>
          <span class="nav-label">今日</span>
        </router-link>
        <router-link to="/plans" class="nav-item" active-class="nav-active">
          <span class="nav-icon">🎯</span>
          <span class="nav-label">计划</span>
        </router-link>
      </nav>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from './stores/auth'

const auth = useAuthStore()
const route = useRoute()

// --- 页面切换方向感知 ---
const mainTabs = ['/', '/plans']
const subPaths = ['/plan/', '/schedule']

const transitionName = ref('page-slide-left')

function getTabIndex(path: string): number {
  for (let i = 0; i < mainTabs.length; i++) {
    if (mainTabs[i] === path) return i
  }
  if (path.startsWith('/plan/') || path === '/plan/create') return 1
  return -1
}

watch(
  () => route.path,
  (to, from) => {
    const toIdx = getTabIndex(to)
    const fromIdx = getTabIndex(from)

    const toIsSub = subPaths.some(p => to.startsWith(p))
    const fromIsSub = subPaths.some(p => from.startsWith(p))

    if (toIsSub && !fromIsSub) {
      transitionName.value = 'page-slide-left'
    } else if (!toIsSub && fromIsSub) {
      transitionName.value = 'page-slide-right'
    } else if (toIdx >= 0 && fromIdx >= 0) {
      transitionName.value = toIdx > fromIdx ? 'page-slide-left' : 'page-slide-right'
    } else {
      transitionName.value = 'page-slide-left'
    }
  }
)
</script>

<style>
/* ====================================
   页面切换动画
   ==================================== */
.page-slide-left-enter-active,
.page-slide-right-enter-active {
  transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
.page-slide-left-leave-active,
.page-slide-right-leave-active {
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}
.page-slide-left-enter-from {
  opacity: 0;
  transform: translateX(24px);
}
.page-slide-left-leave-to {
  opacity: 0;
  transform: translateX(-12px);
}
.page-slide-right-enter-from {
  opacity: 0;
  transform: translateX(-24px);
}
.page-slide-right-leave-to {
  opacity: 0;
  transform: translateX(12px);
}
.nav-fade-enter-active,
.nav-fade-leave-active {
  transition: opacity 0.3s ease;
}
.nav-fade-enter-from,
.nav-fade-leave-to {
  opacity: 0;
}

/* ====================================
   底部导航栏（两栏）
   ==================================== */
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
  padding: 4px 28px;
  text-decoration: none;
  color: var(--color-text-placeholder);
  transition: color 0.2s;
}

.nav-icon {
  font-size: 1.25rem;
  transition: transform var(--duration-fast) var(--ease-spring);
}

.nav-item:active .nav-icon {
  transform: scale(1.2);
}

.nav-label {
  font-size: 0.6875rem;
  font-weight: 500;
}

.nav-item {
  position: relative;
}

.nav-item::before {
  content: '';
  position: absolute;
  top: -8px;
  left: 50%;
  transform: translateX(-50%) scaleX(0);
  width: 20px;
  height: 3px;
  background: var(--color-primary);
  border-radius: 0 0 2px 2px;
  transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

.nav-active {
  color: var(--color-primary) !important;
}

.nav-active::before {
  transform: translateX(-50%) scaleX(1);
}
</style>
