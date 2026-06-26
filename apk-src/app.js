import { app } from 'nitron'

app.init({
  name: 'BitHabit',
  packageId: 'com.bithabit.app',
  version: '1.0.2',
  entry: 'index.html',
  orientation: 'portrait',
  permissions: ['INTERNET'],
  icon: 'icons/icon-512.png',
})
