<template>
  <div class="auth-page">
    <div class="auth-container">
      <!-- Logo & 标题 -->
      <div class="auth-header">
        <div class="logo">🏗️</div>
        <h1>BitHabit</h1>
        <p class="subtitle">暑假作业计划助手</p>
      </div>

      <!-- 错误提示 -->
      <div v-if="errorMsg" class="error-banner">{{ errorMsg }}</div>

      <!-- 表单 -->
      <form class="auth-form" @submit.prevent="handleLogin">
        <div class="input-group">
          <div class="input-wrapper">
            <span class="input-icon">👤</span>
            <input
              v-model="username"
              type="text"
              placeholder="用户名"
              maxlength="20"
              autocomplete="username"
              @input="clearError"
            />
          </div>
        </div>

        <div class="input-group">
          <div class="input-wrapper">
            <span class="input-icon">🔒</span>
            <input
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              placeholder="密码"
              autocomplete="current-password"
              @input="clearError"
            />
            <button
              type="button"
              class="toggle-password"
              @click="showPassword = !showPassword"
              tabindex="-1"
            >
              {{ showPassword ? '🙈' : '👁' }}
            </button>
          </div>
        </div>

        <button
          type="submit"
          class="btn-primary"
          :disabled="!canSubmit || submitting"
        >
          <span v-if="submitting" class="btn-loading">
            <span class="spinner"></span>
            登录中...
          </span>
          <span v-else>登 录</span>
        </button>
      </form>

      <div class="auth-footer">
        还没有账号？
        <router-link to="/register" class="link">去注册 →</router-link>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { authApi } from '../api'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const authStore = useAuthStore()

const username = ref('')
const password = ref('')
const showPassword = ref(false)
const errorMsg = ref('')
const submitting = ref(false)

const canSubmit = computed(() => {
  return username.value.length >= 3 && password.value.length >= 1
})

function clearError() {
  errorMsg.value = ''
}

async function handleLogin() {
  if (!canSubmit.value || submitting.value) return

  submitting.value = true
  errorMsg.value = ''

  const res = await authApi.login(username.value, password.value)

  if (res.ok) {
    authStore.setAuth(res.data)
    router.replace('/')
  } else {
    errorMsg.value = res.error || '用户名或密码错误'
  }

  submitting.value = false
}
</script>
