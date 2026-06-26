#!/usr/bin/env node
/**
 * BitHabit TWA APK 构建脚本 v2
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
const KEYSTORE = join(PROJECT_DIR, 'bithabit-release.keystore')
const KEYSTORE_PASS = 'BitHabit2026!Secure'
const KEY_ALIAS = 'bithabit'
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
    android:versionCode="10000"
    android:versionName="1.0.0">

    <uses-sdk android:minSdkVersion="21" android:targetSdkVersion="34" />
    <uses-permission android:name="android.permission.INTERNET" />

    <application
        android:label="BitHabit"
        android:icon="@mipmap/ic_launcher"
        android:roundIcon="@mipmap/ic_launcher"
        android:hardwareAccelerated="true"
        android:usesCleartextTraffic="true">

        <meta-data
            android:name="asset_statements"
            android:resource="@string/asset_statements" />

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
    <string name="asset_statements">[{"include":"https://bithabit.dpdns.org/.well-known/assetlinks.json"}]</string>
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
  // 旧 APK 不可用，生成最小化 PNG 图标（纯色 1x1 像素）
  console.log('  旧 APK 不可用，生成占位图标')
  const ICON_DIRS = ['mipmap-mdpi', 'mipmap-hdpi', 'mipmap-xhdpi', 'mipmap-xxhdpi', 'mipmap-xxxhdpi']
  // 最小的有效 PNG: 1x1 蓝色像素
  const minPng = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPj/HwADBwIAMCbHYQAAAABJRU5ErkJggg==', 'base64')
  for (const d of ICON_DIRS) {
    mkdirSync(join(resDir, d), { recursive: true })
    writeFileSync(join(resDir, d, 'ic_launcher.png'), minPng)
    writeFileSync(join(resDir, d, 'ic_launcher_foreground.png'), minPng)
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

console.log('  复制 PWA assets...')
execSync(`cp -r "${PROJECT_DIR}/dist/"* "${assetsDir}/" 2>/dev/null; true`)
if (existsSync(join(PROJECT_DIR, 'public'))) {
  execSync(`cp -r "${PROJECT_DIR}/public/"* "${assetsDir}/" 2>/dev/null; true`)
}

// 修复 HTML 绝对路径 → 相对路径（WebView 从 file:// 加载）
console.log('  修复 HTML 路径...')
const { readFileSync } = require('fs')
let indexHtml = readFileSync(join(assetsDir, 'index.html'), 'utf-8')
// 将 src="/... 和 href="/... 等绝对路径改为相对路径
indexHtml = indexHtml.replace(/((?:src|href|content)=")\/([^"]+)/g, '$1./$2')
writeFileSync(join(assetsDir, 'index.html'), indexHtml)

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
