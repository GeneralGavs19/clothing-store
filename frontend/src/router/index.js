import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
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
        { path: '', redirect: '/dashboard' },
        { path: 'dashboard', name: 'dashboard', component: DashboardView },
        { path: 'products', name: 'products', component: ProductsView },
        { path: 'sales', name: 'sales', component: SalesView },
        { path: 'categories', name: 'categories', component: CategoriesView, meta: { roles: ['admin'] } },
        { path: 'users', name: 'users', component: UsersView, meta: { roles: ['admin'] } },
        { path: 'reports', name: 'reports', component: ReportsView, meta: { roles: ['admin'] } },
        { path: 'logs', name: 'logs', component: LogsView },
      ],
    },
  ],
})

router.beforeEach((to) => {
  const auth = useAuthStore()
  auth.restore()

  if (to.meta.requiresAuth && !auth.token) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.name === 'login' && auth.token) return { name: 'dashboard' }

  const roles = to.meta.roles
  if (roles && !roles.includes(auth.user?.role)) return { name: 'dashboard' }
})

export default router
