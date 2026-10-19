import MessageInbox from '../components/MessageInbox'

/** 深层消息链接复用已有收件箱，与顶栏保持同一 API 和查询缓存。 */
export default function MessageCenterPage() {
  return <MessageInbox narrow={false} presentation="page" />
}
