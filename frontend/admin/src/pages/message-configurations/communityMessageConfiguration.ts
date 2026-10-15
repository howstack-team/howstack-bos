import type { MessageAudience, MessageChannel } from '../../api/messageConfigurationApi'
import type { MessageGroupKind, MessageRecipientGroup, MessageSceneDetail, MessageSceneRow } from '../../api/messageSceneApi'

/** 社区版按受众展示固定渠道；管理员站内策略留在底层，不由简化界面覆盖。 */
export const COMMUNITY_CHANNELS: Record<MessageAudience, MessageChannel[]> = {
  customer: ['email', 'sms', 'message', 'wechat'],
  admin: ['email', 'dingtalk', 'wecom', 'feishu'],
}

export function communityGroupKind(audience: MessageAudience, channel: MessageChannel): MessageGroupKind {
  return audience === 'customer' ? 'customer' : channel === 'email' || channel === 'message' ? 'admin' : 'robot'
}

/** 多组及个人范围属于保留的高级策略，简化编辑不能静默改变其语义。 */
export function hasCustomRecipients(groups: MessageRecipientGroup[], kind: MessageGroupKind): boolean {
  const matching = groups.filter(group => group.kind === kind)
  return matching.length > 1 || matching.some(group => group.recipients.mode !== 'founder')
}

export function communityChannelGroups(detail: MessageSceneDetail, audience: MessageAudience): MessageRecipientGroup[] {
  const groups = structuredClone(detail.groups)
  for (const channel of COMMUNITY_CHANNELS[audience]) {
    const kind = communityGroupKind(audience, channel), template = detail.group_templates[kind]
    if (!detail.metadata[audience]?.channels.includes(channel) || groups.some(group => group.kind === kind) || !template) continue
    // 仅显式编辑保存时补回缺失的普通组，读取不会写库；避免与其他组 ID 碰撞。
    const group = structuredClone(template)
    group.id = `community_${kind}`
    for (let index = 1; groups.some(item => item.id === group.id); index++) group.id = `community_${kind}_${index}`
    groups.push(group)
  }
  return groups
}

export function supportsCommunityChannel(row: MessageSceneRow, audience: MessageAudience, channel: MessageChannel): boolean {
  return row.metadata[audience]?.channels.includes(channel) === true
}
