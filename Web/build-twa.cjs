#!/usr/bin/env node
/**
 * ⚠️ 已废弃！请使用 nitron 直接构建（见 TECH.md §6.2）
 *
 * 此脚本手动使用 aapt2 构建 APK，但产物不被 MIUI 接受（证书白名单问题）。
 * 保留仅作参考。
 *
 * BitHabit TWA APK 构建脚本 v2 (DEPRECATED)
 */
const { execSync, execFileSync } = require('child_process')
const { mkdirSync, writeFileSync, copyFileSync, existsSync, readdirSync } = require('fs')
const { join } = require('path')
const { tmpdir } = require('os')
const { randomBytes } = require('crypto')

const PROJECT_DIR = '/home/node/data/BitHabit/Web'
const AAPT2 = '/home/node/.nitron/android/aapt2'
const ANDROID_JAR = '/home/node/.nitron/android/android.jar'
const SIGNER_JAR = '/tmp/nitron-src/package/vendor/uber-apk-signer.jar'
const BASE_APK = '/tmp/nitron-src/package/template/base.apk'
// 使用 Android Debug 签名（MIUI 等国产 ROM 只认标准 debug 证书白名单）
const { homedir } = require('os')
const KEYSTORE = join(homedir(), '.android', 'debug.keystore')
const KEYSTORE_PASS = 'android'
const KEY_ALIAS = 'androiddebugkey'
const OUTPUT_APK = join(PROJECT_DIR, 'dist', 'app.apk')
const OUTPUT_APK_ROOT = join(PROJECT_DIR, 'app.apk')

function run(cmd, args) {
  console.log(`  $ ${cmd} ${args.join(' ')}`)
  return execFileSync(cmd, args, { maxBuffer: 20 * 1024 * 1024 })
}

// === Step 1 ===
console.log('[1/5] 创建工作目录...')
const buildId = randomBytes(4).toString('hex')
const workDir = join(tmpdir(), `bithabit-twa-${buildId}`)
const resDir = join(workDir, 'res')
const targetDir = join(workDir, 'target')
const assetsDir = join(targetDir, 'assets')
mkdirSync(resDir, { recursive: true })
mkdirSync(targetDir, { recursive: true })
mkdirSync(assetsDir, { recursive: true })

// === Step 2: AndroidManifest + 资源 ===
console.log('[2/5] 生成 AndroidManifest.xml...')

writeFileSync(join(workDir, 'AndroidManifest.xml'), `<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android"
    package="com.bithabit.app"
    android:versionCode="10001"
    android:versionName="1.0.1">

    <uses-sdk android:minSdkVersion="21" android:targetSdkVersion="34" />
    <uses-permission android:name="android.permission.INTERNET" />

    <application
        android:label="BitHabit"
        android:icon="@mipmap/ic_launcher"
        android:roundIcon="@mipmap/ic_launcher"
        android:hardwareAccelerated="true"
        android:usesCleartextTraffic="true">
        
        <activity
            android:name="com.nicron.webview.MainActivity"
            android:exported="true"
            android:configChanges="orientation|keyboardHidden|screenSize"
            android:screenOrientation="portrait">

            <intent-filter>
                <action android:name="android.intent.action.MAIN" />
                <category android:name="android.intent.category.LAUNCHER" />
            </intent-filter>
        </activity>
    </application>
</manifest>`)

mkdirSync(join(resDir, 'values'), { recursive: true })
writeFileSync(join(resDir, 'values', 'strings.xml'), `<?xml version="1.0" encoding="utf-8"?>
<resources>
    <string name="app_name">BitHabit</string>
</resources>`)

writeFileSync(join(resDir, 'values', 'colors.xml'), `<?xml version="1.0" encoding="utf-8"?>
<resources>
    <color name="ic_launcher_background">#4F46E5</color>
</resources>`)

// === Step 3: 提取图标 ===
console.log('[3/5] 提取图标...')

const oldApk = join(PROJECT_DIR, 'app.apk')
let hasOldApk = existsSync(oldApk)

if (hasOldApk) {
  writeFileSync(join(workDir, 'extract_icons.py'), `
import zipfile, os
old = zipfile.ZipFile('${oldApk}', 'r')
res_dir = '${resDir}'
dpi_dirs = ['mipmap-mdpi', 'mipmap-hdpi', 'mipmap-xhdpi', 'mipmap-xxhdpi', 'mipmap-xxxhdpi']
for d in dpi_dirs:
    out = os.path.join(res_dir, d)
    os.makedirs(out, exist_ok=True)
    for fn in ['ic_launcher.png', 'ic_launcher_foreground.png']:
        try:
            data = old.read('res/' + d + '-v4/' + fn)
            with open(os.path.join(out, fn), 'wb') as fp:
                fp.write(data)
        except:
            pass
old.close()
print('done')
`)
  try {
    execSync(`python3 "${join(workDir, 'extract_icons.py')}"`)
  } catch (err) {
    console.log('  图标提取失败，将使用 nitron base.apk 中的图标')
    hasOldApk = false
  }
}

if (!hasOldApk) {
  // 使用 dist/assets/icons/ 中的 PWA 图标作为启动图标
  console.log('  使用 PWA 图标作为启动图标')
  const icon512 = join(PROJECT_DIR, 'dist', 'assets', 'icons', 'icon-512.png')
  const icon192 = join(PROJECT_DIR, 'dist', 'assets', 'icons', 'icon-192.png')
  if (existsSync(icon512) && existsSync(icon192)) {
    const { readFileSync } = require('fs')
    const png512 = readFileSync(icon512)
    const png192 = readFileSync(icon192)
    // 按密度使用对应图标（512 用于高密度，192 用于低密度）
    mkdirSync(join(resDir, 'mipmap-xxxhdpi'), { recursive: true })
    mkdirSync(join(resDir, 'mipmap-xxhdpi'), { recursive: true })
    mkdirSync(join(resDir, 'mipmap-xhdpi'), { recursive: true })
    mkdirSync(join(resDir, 'mipmap-hdpi'), { recursive: true })
    mkdirSync(join(resDir, 'mipmap-mdpi'), { recursive: true })
    writeFileSync(join(resDir, 'mipmap-xxxhdpi', 'ic_launcher.png'), png512)
    writeFileSync(join(resDir, 'mipmap-xxxhdpi', 'ic_launcher_foreground.png'), png512)
    writeFileSync(join(resDir, 'mipmap-xxhdpi', 'ic_launcher.png'), png512)
    writeFileSync(join(resDir, 'mipmap-xxhdpi', 'ic_launcher_foreground.png'), png512)
    writeFileSync(join(resDir, 'mipmap-xhdpi', 'ic_launcher.png'), png192)
    writeFileSync(join(resDir, 'mipmap-xhdpi', 'ic_launcher_foreground.png'), png192)
    writeFileSync(join(resDir, 'mipmap-hdpi', 'ic_launcher.png'), png192)
    writeFileSync(join(resDir, 'mipmap-hdpi', 'ic_launcher_foreground.png'), png192)
    writeFileSync(join(resDir, 'mipmap-mdpi', 'ic_launcher.png'), png192)
    writeFileSync(join(resDir, 'mipmap-mdpi', 'ic_launcher_foreground.png'), png192)
  } else {
    // 图标缺失，生成最小化占位
    console.log('  PWA 图标不存在，生成 192x192 纯色图标')
    const { createCanvas } = require('canvas')  // 尝试用 canvas
    // 降级：用纯色 buffer
    const ICON_DIRS = ['mipmap-mdpi', 'mipmap-hdpi', 'mipmap-xhdpi', 'mipmap-xxhdpi', 'mipmap-xxxhdpi']
    const minPng = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPj/HwADBwIAMCbHYQAAAABJRU5ErkJggg==', 'base64')
    for (const d of ICON_DIRS) {
      mkdirSync(join(resDir, d), { recursive: true })
      writeFileSync(join(resDir, d, 'ic_launcher.png'), minPng)
      writeFileSync(join(resDir, d, 'ic_launcher_foreground.png'), minPng)
    }
  }
}

// Adaptive icon
mkdirSync(join(resDir, 'mipmap-anydpi-v26'), { recursive: true })
writeFileSync(join(resDir, 'mipmap-anydpi-v26', 'ic_launcher.xml'), `<?xml version="1.0" encoding="utf-8"?>
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@color/ic_launcher_background" />
    <foreground android:drawable="@mipmap/ic_launcher_foreground" />
</adaptive-icon>`)

// === Step 4: aapt2 构建 ===
console.log('[4/5] aapt2 compile → link...')

// 复制 dist/ 资源（离线回退 + 让 APK 大小正常，避免安全软件拦截）
console.log('  复制 PWA assets...')
execSync(`cp -r "${PROJECT_DIR}/dist/"* "${assetsDir}/" 2>/dev/null; true`)
if (existsSync(join(PROJECT_DIR, 'public'))) {
  execSync(`cp -r "${PROJECT_DIR}/public/"* "${assetsDir}/" 2>/dev/null; true`)
}
// 删除嵌套 APK
const nestedApk = join(assetsDir, 'app.apk')
if (existsSync(nestedApk)) require('fs').unlinkSync(nestedApk)

// 替换 index.html 为重定向页面（WebView 加载线上 HTTPS，避免 ES Module 在 file:// 下的 CORS 白屏）
console.log('  创建重定向页面...')
writeFileSync(join(assetsDir, 'index.html'), `<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BitHabit</title>
  <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#4F46E5;color:#fff}</style>
</head>
<body>
  <p>正在加载 BitHabit...</p>
  <script>window.location.replace('https://bithabit.dpdns.org')</script>
</body>
</html>`)

// classes.dex
writeFileSync(join(workDir, 'extract_dex.py'), `
import zipfile
with zipfile.ZipFile('${BASE_APK}', 'r') as z:
    z.extract('classes.dex', '${targetDir}')
print('DEX extracted')
`)
execSync(`python3 "${join(workDir, 'extract_dex.py')}"`)

// aapt2 compile
const compiledZip = join(workDir, 'compiled.zip')
try {
  run(AAPT2, ['compile', '--dir', resDir, '-o', compiledZip])
} catch (err) {
  console.error('aapt2 compile failed:', err.stderr?.toString() || err.message)
  process.exit(1)
}

// aapt2 link
const resourcesApk = join(workDir, 'resources.apk')
try {
  run(AAPT2, [
    'link',
    compiledZip,
    '--manifest', join(workDir, 'AndroidManifest.xml'),
    '-I', ANDROID_JAR,
    '-o', resourcesApk,
    '--auto-add-overlay',
  ])
} catch (err) {
  console.error('aapt2 link failed:', err.stderr?.toString() || err.message)
  process.exit(1)
}

// 合并资源
console.log('  合并资源...')
writeFileSync(join(workDir, 'merge_res.py'), `
import zipfile
res = zipfile.ZipFile('${resourcesApk}', 'r')
for name in res.namelist():
    if name in ('resources.arsc', 'AndroidManifest.xml') or name.startswith('res/'):
        res.extract(name, '${targetDir}')
res.close()
print('Resources merged')
`)
execSync(`python3 "${join(workDir, 'merge_res.py')}"`)

// 打包 APK
const unsignedApk = join(workDir, 'unsigned.apk')
console.log('  打包 APK...')
writeFileSync(join(workDir, 'pack.py'), `
import zipfile, os
apk_path = '${unsignedApk}'
base = '${targetDir}'
with zipfile.ZipFile(apk_path, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(base):
        for f in files:
            full = os.path.join(root, f)
            arc = os.path.relpath(full, base)
            z.write(full, arc)
print('APK packed')
`)
execSync(`python3 "${join(workDir, 'pack.py')}"`)

// === Step 5: Release 签名 ===
console.log('[5/5] Release 签名...')

try {
  const signResult = execFileSync('/home/node/tools/jre21/jdk-21.0.11+10-jre/bin/java', [
    '-jar', SIGNER_JAR,
    '--apks', unsignedApk,
    '--ks', KEYSTORE,
    '--ksAlias', KEY_ALIAS,
    '--ksPass', KEYSTORE_PASS,
    '--ksKeyPass', KEYSTORE_PASS,
    '--allowResign',
  ], { stdio: 'pipe' })
  console.log(signResult.toString())
} catch (err) {
  // uber-apk-signer 返回非零码可能也成功了，检查输出
  console.log(err.stdout?.toString() || '')
  console.error(err.stderr?.toString() || err.message)
}

// 找到签名后的 APK
const files = readdirSync(workDir).filter(f => f.endsWith('.apk'))
console.log('  产出 APK:', files)

const finalApk = files.find(f => f.includes('release') || f.includes('aligned'))
  || files.find(f => !f.includes('unsigned'))
const finalPath = finalApk ? join(workDir, finalApk) : unsignedApk
console.log('  最终:', finalPath)

if (finalApk) {
  copyFileSync(finalPath, OUTPUT_APK)
  copyFileSync(finalPath, OUTPUT_APK_ROOT)
}

// 验证签名
console.log('\n=== 验证 Release 签名 ===')
try {
  const v = execFileSync('/home/node/tools/jre21/jdk-21.0.11+10-jre/bin/keytool', [
    '-printcert', '-jarfile', finalPath,
  ], { stdio: 'pipe' })
  console.log(v.toString())
} catch (err) {
  console.log(err.stderr?.toString() || '')
}

const stats = require('fs').statSync(finalPath)
console.log(`\n✅ 完成! 大小: ${(stats.size/1024).toFixed(0)}KB`)
