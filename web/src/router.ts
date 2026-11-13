import { createRouter, createWebHistory } from 'vue-router'
import { ref } from 'vue'
import { checkAuth } from './store'
import { companySkipped, selectedCompany } from './companies'

export const navigationErrorHref = ref<string | null>(null)

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior: (_to, _from, saved) => saved ?? { top: 0 },
  routes: [
    // ก่อนเข้าหน้าหลัก/ฟอร์มคำขอ ให้เลือกบริษัท (ข้อมูลจากระบบจัดซื้อ) — ลิงก์ลงนาม/ติดตาม/QR ไม่ต้องเลือก
    { path: '/choose-company', name: 'choose-company', component: () => import('./views/public/ChooseCompany.vue') },
    { path: '/', name: 'home', component: () => import('./views/public/Home.vue'), meta: { company: true } },
    // URL ใน QR Code: /it-hub/a/<รหัสอุปกรณ์>
    { path: '/a/:code', name: 'asset', component: () => import('./views/public/AssetView.vue'), props: true },
    { path: '/request/:type', name: 'request', component: () => import('./views/public/RequestForm.vue'), props: true, meta: { company: true } },
    { path: '/track/:no?', name: 'track', component: () => import('./views/public/Track.vue'), props: true },
    { path: '/approve', name: 'approve', component: () => import('./views/public/Approve.vue') },
    { path: '/request-edit', name: 'request-edit', component: () => import('./views/public/RequestEdit.vue') },
    { path: '/document-approve', name: 'document-approve', component: () => import('./views/public/DocumentApprove.vue') },

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
        { path: 'nas', name: 'nas', component: () => import('./views/admin/Nas.vue') },
        { path: 'employees', name: 'employees', component: () => import('./views/admin/Employees.vue') },
        { path: 'documents', name: 'documents', component: () => import('./views/admin/Documents.vue') },
        { path: 'borrows', name: 'borrow-records', component: () => import('./views/admin/Documents.vue') },
        { path: 'inspections', name: 'inspections', component: () => import('./views/admin/Inspections.vue') },
        { path: 'borrows/items', name: 'borrow-items', component: () => import('./views/admin/BorrowItems.vue') },
        { path: 'documents/new', name: 'document-new', component: () => import('./views/admin/DocumentDetail.vue') },
        { path: 'documents/:id', name: 'document-detail', component: () => import('./views/admin/DocumentDetail.vue') },
      ],
    },
    // หน้าพิมพ์ QR ไม่มีเมนูด้านข้าง
    { path: '/admin/qr', name: 'qr-print', component: () => import('./views/admin/QrPrint.vue'), meta: { admin: true } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  if (to.meta.company && !selectedCompany.value && !companySkipped.value) {
    return { name: 'choose-company', query: to.fullPath === '/' ? {} : { next: to.fullPath } }
  }
  if (!to.matched.some((r) => r.meta.admin)) return true
  try {
    return (await checkAuth()) ? true : { name: 'login', query: { next: to.fullPath } }
  } catch {
    return { name: 'login', query: { next: to.fullPath } }
  }
})

router.onError((error, to) => {
  console.error('Unable to open IT Hub page', error)
  navigationErrorHref.value = router.resolve(to.fullPath).href
})

router.afterEach((_to, _from, failure) => {
  if (!failure) navigationErrorHref.value = null
})

export default router
