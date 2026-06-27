import { createRouter, createWebHistory } from 'vue-router'
import type { RouteRecordRaw } from 'vue-router'

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'today',
    component: () => import('../views/TodayView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/plans',
    name: 'plans',
    component: () => import('../views/PlansListView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/plan/create',
    name: 'planCreate',
    component: () => import('../views/PlanCreateView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/plan/:id',
    name: 'planDetail',
    component: () => import('../views/PlanDetailView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/plan/:id/allocate',
    name: 'planAllocate',
    component: () => import('../views/PlanAllocateView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/plan/:planId/calendar',
    name: 'planCalendar',
    component: () => import('../views/CalendarView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/schedule',
    name: 'schedule',
    component: () => import('../views/ScheduleView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/LoginView.vue'),
  },
  {
    path: '/register',
    name: 'register',
    component: () => import('../views/RegisterView.vue'),
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach((to, _from, next) => {
  const token = localStorage.getItem('bithabit_token')
  if (!token && to.meta.requiresAuth) {
    next('/login')
  } else if (token && (to.path === '/login' || to.path === '/register')) {
    next('/')
  } else {
    next()
  }
})

export default router
