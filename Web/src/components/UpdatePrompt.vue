<template>
  <Transition name="update-slide">
    <div v-if="needRefresh" class="update-prompt" role="alert">
      <span class="update-icon">📦</span>
      <span class="update-text">新版本已就绪</span>
      <button class="update-btn" @click="update">更新并刷新</button>
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { useRegisterSW } from 'virtual:pwa-register/vue'

const { needRefresh, updateServiceWorker } = useRegisterSW()

function update() {
  updateServiceWorker()
}
</script>

<style scoped>
.update-prompt {
  position: fixed;
  bottom: 80px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 999;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 20px;
  background: var(--color-primary, #4F46E5);
  color: #fff;
  border-radius: 12px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
  font-size: 0.875rem;
  max-width: 90vw;
  white-space: nowrap;
}

.update-icon {
  font-size: 1.2rem;
}

.update-text {
  flex: 1;
  font-weight: 500;
}

.update-btn {
  padding: 6px 16px;
  border: 1px solid rgba(255, 255, 255, 0.4);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.15);
  color: #fff;
  font-size: 0.8rem;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.2s;
}

.update-btn:hover,
.update-btn:active {
  background: rgba(255, 255, 255, 0.3);
}

/* 进场/离场动画 */
.update-slide-enter-active,
.update-slide-leave-active {
  transition: all 0.3s ease;
}

.update-slide-enter-from,
.update-slide-leave-to {
  opacity: 0;
  transform: translateX(-50%) translateY(20px);
}
</style>
