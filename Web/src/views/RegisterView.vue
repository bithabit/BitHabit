<template>
  <div class="auth-page">
    <div class="auth-container">
      <!-- 顶栏 -->
      <div class="auth-topbar">
        <router-link to="/login" class="back-link">← 返回</router-link>
        <h2>注册</h2>
        <div class="spacer"></div>
      </div>

      <!-- 错误提示 -->
      <div v-if="errorMsg" class="error-banner">{{ errorMsg }}</div>

      <!-- 表单 -->
      <form class="auth-form" @submit.prevent="handleRegister">
        <!-- 昵称 -->
        <div class="input-group">
          <label class="input-label">昵称（选填）</label>
          <div class="input-wrapper">
            <span class="input-icon">😊</span>
            <input
              v-model="nickname"
              type="text"
              placeholder="给自己取个名字吧"
              maxlength="12"
              @input="clearError"
            />
          </div>
        </div>

        <!-- 用户名 -->
        <div class="input-group">
          <label class="input-label">用户名 *</label>
          <div class="input-wrapper">
            <span class="input-icon">👤</span>
            <input
              v-model="username"
              type="text"
              placeholder="3-20位字母、数字或下划线"
              maxlength="20"
              autocomplete="username"
              @input="onUsernameInput"
            />
            <span v-if="usernameCheck === 'checking'" class="input-status checking">⋯</span>
            <span v-else-if="usernameCheck === 'available'" class="input-status ok">✅</span>
            <span v-else-if="usernameCheck === 'taken'" class="input-status err">❌</span>
          </div>
          <p
            v-if="usernameCheckMsg"
            class="field-hint"
            :class="{ 'hint-ok': usernameCheck === 'available', 'hint-err': usernameCheck === 'taken' }"
          >
            {{ usernameCheckMsg }}
          </p>
        </div>

        <!-- 密码 -->
        <div class="input-group">
          <label class="input-label">密码 *</label>
          <div class="input-wrapper">
            <span class="input-icon">🔒</span>
            <input
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              placeholder="至少 6 位"
              autocomplete="new-password"
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

        <!-- 确认密码 -->
        <div class="input-group">
          <label class="input-label">确认密码 *</label>
          <div class="input-wrapper">
            <span class="input-icon">🔒</span>
            <input
              v-model="confirmPassword"
              :type="showConfirm ? 'text' : 'password'"
              placeholder="再次输入密码"
              autocomplete="new-password"
              @input="clearError"
            />
            <button
              type="button"
              class="toggle-password"
              @click="showConfirm = !showConfirm"
              tabindex="-1"
            >
              {{ showConfirm ? '🙈' : '👁' }}
            </button>
          </div>
          <p
            v-if="confirmPassword && password !== confirmPassword"
            class="field-hint hint-err"
          >
            密码不一致
          </p>
        </div>

        <button
          type="submit"
          class="btn-primary"
          :disabled="!canSubmit || submitting"
        >
          <span v-if="submitting" class="btn-loading">
            <span class="spinner"></span>
            注册中...
          </span>
          <span v-else>注 册</span>
        </button>
      </form>

      <div class="auth-footer">
        已有账号？
        <router-link to="/login" class="link">去登录 →</router-link>
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

const nickname = ref('')
const username = ref('')
const password = ref('')
const confirmPassword = ref('')
const showPassword = ref(false)
const showConfirm = ref(false)

const errorMsg = ref('')
const submitting = ref(false)

// 用户名查重
const usernameCheck = ref<'idle' | 'checking' | 'available' | 'taken'>('idle')
const usernameCheckMsg = ref('')
let checkTimer: ReturnType<typeof setTimeout> | null = null

const usernameValid = computed(() => /^[a-zA-Z0-9_]{3,20}$/.test(username.value))
const passwordValid = computed(() => password.value.length >= 6)
const confirmValid = computed(() => confirmPassword.value === password.value && confirmPassword.value.length > 0)
const usernameAvailable = computed(() => usernameCheck.value === 'available')

const canSubmit = computed(() => {
  return usernameValid.value && usernameAvailable.value && passwordValid.value && confirmValid.value
})

function clearError() {
  errorMsg.value = ''
}

function onUsernameInput() {
  // 清除之前的检查状态
  if (checkTimer) clearTimeout(checkTimer)

  if (!usernameValid.value) {
    usernameCheck.value = 'idle'
    usernameCheckMsg.value = ''
    return
  }

  usernameCheck.value = 'checking'
  usernameCheckMsg.value = ''

  checkTimer = setTimeout(async () => {
    const res = await authApi.checkUsername(username.value)
    if (res.ok) {
      if (res.data.available) {
        usernameCheck.value = 'available'
        usernameCheckMsg.value = '✅ 可用'
      } else {
        usernameCheck.value = 'taken'
        usernameCheckMsg.value = '❌ 已被使用'
      }
    } else {
      usernameCheck.value = 'idle'
      usernameCheckMsg.value = ''
    }
  }, 500)
}

async function handleRegister() {
  if (!canSubmit.value || submitting.value) return

  submitting.value = true
  errorMsg.value = ''

  const res = await authApi.register({
    username: username.value,
    password: password.value,
    nickname: nickname.value || undefined,
  })

  if (res.ok) {
    authStore.setAuth(res.data)
    router.replace('/')
  } else {
    errorMsg.value = res.error || '注册失败，请稍后重试'
  }

  submitting.value = false
}
</script>
