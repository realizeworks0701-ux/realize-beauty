import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import type { FeatureKey } from '@/types'

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
      meta: { feature: 'customer' },
    },
    {
      path: '/customers/create',
      name: 'customer-create',
      component: () => import('@/pages/customer/CustomerFormPage.vue'),
      meta: { feature: 'customer' },
    },
    {
      path: '/customers/:id',
      name: 'customer-detail',
      component: () => import('@/pages/customer/CustomerDetailPage.vue'),
      meta: { feature: 'customer' },
    },
    {
      path: '/customers/:id/edit',
      name: 'customer-edit',
      component: () => import('@/pages/customer/CustomerFormPage.vue'),
      meta: { feature: 'customer' },
    },
    {
      path: '/customers/:id/records',
      name: 'customer-record-list',
      component: () => import('@/pages/record/RecordListPage.vue'),
      meta: { feature: 'medical_record' },
    },
    {
      path: '/customers/:id/records/create',
      name: 'record-create',
      component: () => import('@/pages/record/RecordFormPage.vue'),
      meta: { feature: 'medical_record' },
    },
    {
      path: '/records',
      name: 'record-list',
      component: () => import('@/pages/record/RecordListAllPage.vue'),
      meta: { feature: 'medical_record' },
    },
    {
      path: '/records/:id',
      name: 'record-detail',
      component: () => import('@/pages/record/RecordDetailPage.vue'),
      meta: { feature: 'medical_record' },
    },
    {
      path: '/records/:id/edit',
      name: 'record-edit',
      component: () => import('@/pages/record/RecordFormPage.vue'),
      meta: { feature: 'medical_record' },
    },
    {
      path: '/reservations',
      name: 'reservation-calendar',
      component: () => import('@/pages/reservation/ReservationCalendarPage.vue'),
      meta: { feature: 'reservation' },
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
      meta: { feature: 'reservation' },
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
      meta: { feature: 'line' },
    },
    {
      path: '/settings/google-calendar',
      name: 'settings-google-calendar',
      component: () => import('@/pages/settings/GoogleCalendarSettingsPage.vue'),
      meta: { feature: 'google_calendar' },
    },
    {
      path: '/settings/plan',
      name: 'settings-plan',
      component: () => import('@/pages/settings/PlanSettingsPage.vue'),
    },
    {
      // 契約プランに含まれない機能への導線。ガード自体はサーバの403が正で、この画面は案内のみ
      path: '/plan-required/:feature',
      name: 'feature-locked',
      component: () => import('@/pages/plan/FeatureLockedPage.vue'),
    },
    // ---- 公開ルート（認証ガード対象外。顧客向けのため管理画面へは誘導しない） ----
    {
      path: '/booking/cancel/:token',
      name: 'public-booking-cancel',
      component: () => import('@/pages/public/BookingCancelPage.vue'),
      meta: { public: true, legacyTheme: true },
    },
    {
      path: '/booking/:slug',
      name: 'public-booking',
      component: () => import('@/pages/public/BookingPage.vue'),
      meta: { public: true, legacyTheme: true },
    },
    {
      // /booking 配下の不正パスは公開用の「ページが見つかりません」に落とす
      // （既存のキャッチオール → /dashboard → /login に流さない）
      path: '/booking/:pathMatch(.*)*',
      name: 'public-not-found',
      component: () => import('@/pages/public/PublicNotFoundPage.vue'),
      meta: { public: true, legacyTheme: true },
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
  // 契約プランに含まれない画面はアップグレード導線へ振り替える。
  // これは案内のためのもので、遮断そのものはAPIの403が担う（ADR-029）。
  // 機能フラグをまだ持たないセッション（デプロイ直後の古いキャッシュ）では
  // 振り替えず素通しする。閉じると既存ログインが全画面から締め出される。
  const feature = to.meta.feature
  if (
    typeof feature === 'string' &&
    auth.user?.features !== undefined &&
    auth.user.features[feature as FeatureKey] !== true
  ) {
    return { name: 'feature-locked', params: { feature } }
  }
  return true
})

export default router
