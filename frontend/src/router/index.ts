import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/pages/auth/LoginPage.vue'),
      meta: { public: true },
    },
    {
      path: '/',
      redirect: '/dashboard',
    },
    {
      path: '/dashboard',
      name: 'dashboard',
      component: () => import('@/pages/dashboard/DashboardPage.vue'),
    },
    {
      path: '/customers',
      name: 'customer-list',
      component: () => import('@/pages/customer/CustomerListPage.vue'),
    },
    {
      path: '/customers/create',
      name: 'customer-create',
      component: () => import('@/pages/customer/CustomerFormPage.vue'),
    },
    {
      path: '/customers/:id',
      name: 'customer-detail',
      component: () => import('@/pages/customer/CustomerDetailPage.vue'),
    },
    {
      path: '/customers/:id/edit',
      name: 'customer-edit',
      component: () => import('@/pages/customer/CustomerFormPage.vue'),
    },
    {
      path: '/customers/:id/records',
      name: 'record-list',
      component: () => import('@/pages/record/RecordListPage.vue'),
    },
    {
      path: '/customers/:id/records/create',
      name: 'record-create',
      component: () => import('@/pages/record/RecordFormPage.vue'),
    },
    {
      path: '/records/:id',
      name: 'record-detail',
      component: () => import('@/pages/record/RecordDetailPage.vue'),
    },
    {
      path: '/records/:id/edit',
      name: 'record-edit',
      component: () => import('@/pages/record/RecordFormPage.vue'),
    },
    {
      path: '/reservations',
      name: 'reservation-calendar',
      component: () => import('@/pages/reservation/ReservationCalendarPage.vue'),
    },
    {
      path: '/settings',
      name: 'settings',
      component: () => import('@/pages/settings/SettingsPage.vue'),
    },
    {
      path: '/settings/menus',
      name: 'settings-menus',
      component: () => import('@/pages/settings/MenuSettingsPage.vue'),
    },
    {
      path: '/settings/business-hours',
      name: 'settings-business-hours',
      component: () => import('@/pages/settings/BusinessHoursSettingsPage.vue'),
    },
    {
      path: '/settings/line',
      name: 'settings-line',
      component: () => import('@/pages/settings/LineSettingsPage.vue'),
    },
    // ---- 公開ルート（認証ガード対象外。顧客向けのため管理画面へは誘導しない） ----
    {
      path: '/booking/cancel/:token',
      name: 'public-booking-cancel',
      component: () => import('@/pages/public/BookingCancelPage.vue'),
      meta: { public: true },
    },
    {
      path: '/booking/:slug',
      name: 'public-booking',
      component: () => import('@/pages/public/BookingPage.vue'),
      meta: { public: true },
    },
    {
      // /booking 配下の不正パスは公開用の「ページが見つかりません」に落とす
      // （既存のキャッチオール → /dashboard → /login に流さない）
      path: '/booking/:pathMatch(.*)*',
      name: 'public-not-found',
      component: () => import('@/pages/public/PublicNotFoundPage.vue'),
      meta: { public: true },
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/dashboard',
    },
  ],
})

router.beforeEach((to) => {
  const auth = useAuthStore()
  if (to.meta.public !== true && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (to.name === 'login' && auth.isAuthenticated) {
    return { name: 'dashboard' }
  }
  return true
})

export default router
