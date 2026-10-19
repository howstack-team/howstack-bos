export const operationLabels: Record<string, string> = { power_on: '开机', power_off: '关机', reboot: '重启', reinstall: '重装系统', reset_password: '重置密码' }
export const operationStatuses: Record<string, string> = { queued: '等待执行', processing: '处理中', waiting: '等待供应商确认', succeeded: '已完成', failed: '执行失败', attention: '待管理员核查', cancelled: '已取消' }
export const powerLabels: Record<string, string> = { on: '已开机', off: '已关机', unknown: '电源状态未知' }
export interface InstanceOperations {
  vnc_available: boolean
  installed_image?: { id: number; name: string; default_username: string; default_port: number } | null
  actions: string[]; busy: boolean; power_status: string | null; power_checked_at: string | null
  images: { id: number; name: string; default_username: string; default_port: number }[]
  history: { id: number; action: string; status: string; error_code: string | null; created_at: string; updated_at: string }[]
}
export interface OperationInput { action: string; request_key: string; acknowledged: boolean; image_id?: number; password?: string }
export function parseInstanceOperations(value: unknown): InstanceOperations {
  if (!value || typeof value !== 'object') throw new Error('实例运维数据格式无效')
  const row = value as Record<string, unknown>
  if (typeof row.vnc_available !== 'boolean' || !Array.isArray(row.actions) || !row.actions.every(action => typeof action === 'string' && action in operationLabels)
    || typeof row.busy !== 'boolean' || !Array.isArray(row.images) || !Array.isArray(row.history)
    || !row.images.every(image => image && Number.isSafeInteger(image.id) && typeof image.name === 'string')
    || !row.history.every(task => task && Number.isSafeInteger(task.id) && typeof task.action === 'string' && typeof task.status === 'string')) throw new Error('实例运维数据格式无效')
  return row as unknown as InstanceOperations
}
export function operationWarning(action: string) {
  return ({ power_on: '确认启动此实例。', power_off: '正常关机可能中断正在运行的业务，请先保存数据。', reboot: '重启将暂时中断业务，请先保存数据。',
    reinstall: '重装将清空系统盘全部数据，数据盘保留。请先备份重要数据，新密码将替换原密码。', reset_password: '新密码将替换原登录密码；操作期间请勿进行其他实例操作。' } as Record<string, string>)[action] ?? ''
}
export const operationPasswordError = (value: string) => /^[\x21-\x7E]{8,64}$/.test(value) ? undefined : '请输入 8–64 位非空格英文、数字或符号'
