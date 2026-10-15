export const channelName = 'howstack.member-session'
/** 通知仅包含随机事件，不携带账号、Cookie、验证码或密码。 */
export function notifyMemberSessionChanged() {
  try { localStorage.setItem(channelName, `${Date.now()}:${Math.random()}`) } catch { /* 存储不可用时仍有 focus 校验。 */ }
  const channel = typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel(channelName)
  channel?.postMessage('changed'); channel?.close()
  window.dispatchEvent(new Event('member:session-changed'))
}
