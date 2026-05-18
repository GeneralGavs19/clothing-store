export function roleLabel(role) {
  return {
    admin_programmer: 'Админ программист',
    admin: 'Администратор',
    cashier: 'Кассир',
  }[role] || role
}

export function canAccessDashboard(user) {
  return user?.role === 'admin' || user?.role === 'admin_programmer'
}

export function canManageCatalog(user) {
  return canAccessDashboard(user)
}

export function canAccessReports(user) {
  return canAccessDashboard(user)
}

export function canManageUsers(user) {
  return user?.role === 'admin_programmer'
}

export function canViewLogs(user) {
  return canManageUsers(user)
}

export function homeRouteName(user) {
  return canAccessDashboard(user) ? 'dashboard' : 'products'
}
