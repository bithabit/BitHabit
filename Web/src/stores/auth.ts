import { defineStore } from 'pinia'
import { ref } from 'vue'
import { authApi } from '../api'
import type { AuthResponse, UserInfo } from '../api'

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem('bithabit_token'))
  const userInfo = ref<UserInfo | null>(null)
  const loading = ref(false)

  async function verifyToken(): Promise<boolean> {
    if (!token.value) return false

    const res = await authApi.getMe()
    if (res.ok) {
      userInfo.value = res.data
      return true
    }

    // Token 无效或过期
    clearAuth()
    return false
  }

  function setAuth(data: AuthResponse) {
    token.value = data.token
    userInfo.value = {
      userId: data.userId,
      username: data.username,
      nickname: data.nickname,
      createdAt: '',
    }
    localStorage.setItem('bithabit_token', data.token)
  }

  function clearAuth() {
    token.value = null
    userInfo.value = null
    localStorage.removeItem('bithabit_token')
  }

  return {
    token,
    userInfo,
    loading,
    verifyToken,
    setAuth,
    clearAuth,
  }
})
