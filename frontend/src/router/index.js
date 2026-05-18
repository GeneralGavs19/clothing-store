import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { canAccessDashboard, canAccessReports, canManageCatalog, canManageUsers, canViewLogs, homeRouteName } from '../utils/permissions'
import AppShell from '../components/AppShell.vue'
import LoginView from '../views/LoginView.vue'
import DashboardView from '../views/DashboardView.vue'
import ProductsView from '../views/ProductsView.vue'
import CategoriesView from '../views/CategoriesView.vue'
import SalesView from '../views/SalesView.vue'
import UsersView from '../views/UsersView.vue'
import ReportsView from '../views/ReportsView.vue'
import LogsView from '../views/LogsView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: LoginView },
    {
      path: '/',
      component: AppShell,
      meta: { requiresAuth: true },
      children: [
        { path: '', redirect: () => ({ name: homeRouteName(useAuthStore().user) }) },
        { path: 'dashboard', name: 'dashboard', component: DashboardView, meta: { access: 'dashboard' } },
        { path: 'products', name: 'products', component: ProductsView },
        { path: 'sales', name: 'sales', component: SalesView },
        { path: 'categories', name: 'categories', component: CategoriesView, meta: { access: 'catalog' } },
        { path: 'users', name: 'users', component: UsersView, meta: { access: 'users' } },
        { path: 'reports', name: 'reports', component: ReportsView, meta: { access: 'reports' } },
        { path: 'logs', name: 'logs', component: LogsView, meta: { access: 'logs' } },
      ],
    },
  ],
})

function canAccessRoute(user, access) {
  if (!access) return true
  if (access === 'dashboard') return canAccessDashboard(user)
  if (access === 'catalog') return canManageCatalog(user)
  if (access === 'reports') return canAccessReports(user)
  if (access === 'users') return canManageUsers(user)
  if (access === 'logs') return canViewLogs(user)
  return true
}

router.beforeEach((to) => {
  const auth = useAuthStore()
  auth.restore()

  if (to.meta.requiresAuth && !auth.token) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.name === 'login' && auth.token) {
    return { name: homeRouteName(auth.user) }
  }

  const access = to.meta.access
  if (access && !canAccessRoute(auth.user, access)) {
    return { name: homeRouteName(auth.user) }
  }
})

export default router
