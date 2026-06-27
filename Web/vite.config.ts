import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    VitePWA({
      // 'prompt' 模式让 APP 自行控制更新时机，避免 SW 静默缓存旧内容
      registerType: 'prompt',
      manifest: {
        name: 'BitHabit - 暑假作业计划助手',
        short_name: 'BitHabit',
        description: '帮助学生制定暑假作业计划',
        start_url: '/',
        scope: '/',
        display: 'standalone',
        orientation: 'portrait',
        theme_color: '#4F46E5',
        background_color: '#FFFFFF',
        icons: [
          {
            src: '/assets/icons/icon-192.png',
            sizes: '192x192',
            type: 'image/png',
          },
          {
            src: '/assets/icons/icon-512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'any maskable',
          },
        ],
      },
      // 输出 manifest.json（而非 .webmanifest），确保 Apache 自动发送正确的 Content-Type
      manifestFilename: 'manifest.json',
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,png,ico,json,woff2}'],
        navigateFallbackDenylist: [/^\/api\//],
        runtimeCaching: [
          {
            urlPattern: /^\/api\//,
            handler: 'NetworkOnly',
          },
        ],
      },
    }),
  ],
  server: {
    host: '0.0.0.0',
    port: 5173,
    // 将 API 请求代理到 PHP 后端
    proxy: {
      '/api': {
        target: 'http://192.168.1.22:1110',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      input: {
        index: './index.dev.html',
      },
    },
  },
})
