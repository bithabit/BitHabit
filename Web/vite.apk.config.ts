/**
 * BitHabit APK 专用 Vite 构建配置
 * 
 * 与主构建的区别：
 * - 使用 vite-plugin-singlefile 内联所有 JS/CSS 到单个 HTML
 * - 不使用 PWA 插件（APK 本身即应用，无需 SW）
 * - 输出到 dist-apk/ 目录，不干扰主构建
 */
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { viteSingleFile } from 'vite-plugin-singlefile'

export default defineConfig({
  plugins: [
    vue(),
    viteSingleFile(),
  ],
  build: {
    outDir: 'dist-apk',
    emptyOutDir: true,
    rollupOptions: {
      input: {
        index: './index.dev.html',
      },
    },
  },
})
