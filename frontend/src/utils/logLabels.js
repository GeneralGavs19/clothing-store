export const actionLabels = {
  'auth.login': 'Вход в систему',
  'auth.logout': 'Выход',
  'users.created': 'Создан пользователь',
  'users.updated': 'Изменён пользователь',
  'users.disabled': 'Отключён пользователь',
  'categories.created': 'Создана категория',
  'categories.updated': 'Изменена категория',
  'categories.deleted': 'Удалена категория',
  'products.created': 'Создан товар',
  'products.updated': 'Изменён товар',
  'products.deleted': 'Удалён товар',
  'products.archived': 'Архивирован товар',
  'sales.created': 'Создана продажа',
  'sales.approved': 'Подтверждена продажа',
  'sales.rejected': 'Отменена продажа',
  'sales.deleted': 'Удалена продажа',
  'stock.transfer': 'Перемещение остатка',
  'stock.adjust': 'Корректировка остатка',
  'reports.backup_generated': 'Создана резервная копия',
  'reports.statistics_rebuilt': 'Пересчитана статистика',
}

export function actionLabel(action) {
  return actionLabels[action] || action
}

export function formatMoney(value) {
  return new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency: 'KZT',
    maximumFractionDigits: 0,
  }).format(Number(value || 0))
}
