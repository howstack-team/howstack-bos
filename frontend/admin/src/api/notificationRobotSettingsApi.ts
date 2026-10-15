import { ApiRequestError, request } from './httpClient'

export const ROBOT_LABELS = { dingtalk: '钉钉', wecom: '企业微信', feishu: '飞书' } as const
export type RobotChannel = keyof typeof ROBOT_LABELS
export interface NotificationRobotSettings {
  revision: number
  configured_secrets: Record<RobotChannel, Record<string, boolean>>
}
export interface NotificationRobotSettingsInput {
  revision: number
  robots: Record<RobotChannel, Record<string, string>>
  clear_secrets: Partial<Record<RobotChannel, string[]>>
}
export const robotKeys = (channel: RobotChannel) => channel === 'wecom' ? ['webhook_url'] : ['webhook_url', 'sign_secret']
export const emptyRobots = () => Object.fromEntries(Object.keys(ROBOT_LABELS).map(channel => [channel, Object.fromEntries(robotKeys(channel as RobotChannel).map(key => [key, '']))])) as NotificationRobotSettingsInput['robots']
const record = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value)
function parse(value: unknown): NotificationRobotSettings {
  const fail = (): never => { throw new ApiRequestError('机器人设置响应无效，请重新加载', 500, 50000) }
  if (!record(value) || !Number.isSafeInteger(value.revision) || Number(value.revision) < 0 || !record(value.configured_secrets)) return fail()
  if (Object.keys(value).some(key => !['revision', 'configured_secrets'].includes(key))
    || Object.keys(value.configured_secrets).some(key => !Object.hasOwn(ROBOT_LABELS, key))) return fail()
  for (const channel of Object.keys(ROBOT_LABELS) as RobotChannel[]) {
    const flags = value.configured_secrets[channel]
    if (!record(flags) || Object.keys(flags).length !== robotKeys(channel).length
      || robotKeys(channel).some(key => typeof flags[key] !== 'boolean')) return fail()
  }
  return value as unknown as NotificationRobotSettings
}
export const notificationRobotSettingsApi = {
  async get(signal?: AbortSignal) {
    return parse(await request<unknown>({ url: '/settings/notifications', signal }))
  },
  async save(data: NotificationRobotSettingsInput) {
    return parse(await request<unknown>({ url: '/settings/notifications', method: 'PUT', data }))
  },
}
