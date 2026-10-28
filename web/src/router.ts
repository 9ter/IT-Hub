import { createRouter, createWebHistory } from 'vue-router'
import { checkAuth } from './store'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior: (_to, _from, saved) => saved ?? { top: 0 },
  routes: [
    { path: '/', name: 'home', component: () => import('./views/public/Home.vue') },
    // URL ใน QR Code: /it-hub/a/<รหัสอุปกรณ์>
    { path: '/a/:code', name: 'asset', component: () => import('./views/public/AssetView.vue'), props: true },
    { path: '/request/:type', name: 'request', component: () => import('./views/public/RequestForm.vue'), props: true },
    { path: '/track/:no?', name: 'track', component: () => import('./views/public/Track.vue'), props: true },

    { path: '/admin/login', name: 'login', component: () => import('./views/admin/Login.vue') },
    {
      path: '/admin',
      component: () => import('./views/admin/AdminLayout.vue'),
      meta: { admin: true },
      children: [
        { path: '', name: 'dashboard', component: () => import('./views/admin/Dashboard.vue') },
        { path: 'tickets', name: 'tickets', component: () => import('./views/admin/Tickets.vue') },
        { path: 'tickets/:id', name: 'ticket', component: () => import('./views/admin/TicketDetail.vue'), props: (r) => ({ id: Number(r.params.id) }) },
        { path: 'assets', name: 'assets', component: () => import('./views/admin/Assets.vue') },
        { path: 'assets/new', name: 'asset-new', component: () => import('./views/admin/AssetForm.vue') },
        { path: 'assets/:id', name: 'asset-detail', component: () => import('./views/admin/AssetDetail.vue'), props: (r) => ({ id: Number(r.params.id) }) },
        { path: 'assets/:id/edit', name: 'asset-edit', component: () => import('./views/admin/AssetForm.vue'), props: (r) => ({ id: Number(r.params.id) }) },
        { path: 'admins', name: 'admins', component: () => import('./views/admin/Admins.vue') },
      ],
    },
    // หน้าพิมพ์ QR ไม่มีเมนูด้านข้าง
    { path: '/admin/qr', name: 'qr-print', component: () => import('./views/admin/QrPrint.vue'), meta: { admin: true } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  if (!to.matched.some((r) => r.meta.admin)) return true
  try {
    return (await checkAuth()) ? true : { name: 'login', query: { next: to.fullPath } }
  } catch {
    return { name: 'login', query: { next: to.fullPath } }
  }
})

export default router
