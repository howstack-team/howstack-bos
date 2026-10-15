import { useEffect, useState, useCallback } from 'react';
import { Alert, Badge, Button, Collapse, Empty, Link, Menu, Message, Modal, Pagination, Select, Spin, Tag, Typography } from '@arco-design/web-react';
import { IconDown, IconNotification, IconUp } from '@arco-design/web-react/icon';
import { useHistory } from 'react-router-dom';
import type { UpdateNotice } from '@/api/officialPlatformApi';
import { useAdminAuth } from '@/auth/AuthContext';
import useRoute, { toUserPermission, type IRoute } from '@/routes';
import IconButton from './IconButton';
import { notificationCategories, notificationDestinations, type CenterNotification } from './notificationCenterData';
import { messageApi, type InboxSummary } from '@/api/messageApi';
import './notifications.css';

function collectPaths(items: IRoute[]): string[] {
  return items.flatMap((item) => item.children ? collectPaths(item.children) : item.path ? [item.path] : []);
}

/** 消息中心复用更新摘要；演示数据仅在开发环境按需加载，真实消息与已读状态来自数据库；演示数据需要显式开启。 */
export default function Notifications({ notice }: { notice?: UpdateNotice }) {
  const history = useHistory();
  const { permissions } = useAdminAuth();
  const [permittedRoutes] = useRoute(toUserPermission(permissions));
  const permittedPaths = collectPaths(permittedRoutes);
  const [visible, setVisible] = useState(false);
  const [preview, setPreview] = useState<CenterNotification[]>();
  const [previewError, setPreviewError] = useState(false);
  const demoEnabled = import.meta.env.DEV && new URLSearchParams(window.location.search).get('notificationMock') === '1';
  const [categoryId, setCategoryId] = useState('trade');
  const [topicId, setTopicId] = useState('new-order');
  const [realRows, setRealRows] = useState<CenterNotification[]>([]);
  const [realTotal, setRealTotal] = useState(0);
  const [summary, setSummary] = useState<InboxSummary>({ unread: 0, categories: {}, topics: {} });
  const [apiError, setApiError] = useState('');
  const [realLoading, setRealLoading] = useState(false);
  const [refresh, setRefresh] = useState(0);
  const [readIds, setReadIds] = useState<Set<string>>(new Set());
  const [expanded, setExpanded] = useState<string[]>([]);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const [isNarrow, setIsNarrow] = useState(() => window.matchMedia('(max-width: 767px)').matches);

  useEffect(() => {
    const media = window.matchMedia('(max-width: 767px)');
    const resize = () => setIsNarrow(media.matches);
    media.addEventListener('change', resize);
    return () => media.removeEventListener('change', resize);
  }, []);

  useEffect(() => {
    if (!demoEnabled || preview) return undefined;
    let active = true;
    void import('../../mock/notificationPreviewData').then(({ notificationPreviewData }) => {
      if (active) { setPreview(notificationPreviewData); setPreviewError(false); }
    }).catch(() => { if (active) setPreviewError(true); });
    return () => { active = false; };
  }, [demoEnabled, preview]);

  const reloadSummary = useCallback(async (signal?: AbortSignal) => {
    try { setSummary(await messageApi.summary(signal)); if (!signal?.aborted) setApiError(''); }
    catch (cause) { if (!signal?.aborted) setApiError(cause instanceof Error ? cause.message : '消息加载失败'); }
  }, []);
  useEffect(() => {
    if (demoEnabled) return undefined;
    const controller = new AbortController();
    const first = window.setTimeout(() => { void reloadSummary(controller.signal); }, 0);
    const timer = window.setInterval(() => { void reloadSummary(controller.signal); }, 30000);
    return () => { controller.abort(); window.clearInterval(timer); window.clearTimeout(first); };
  }, [demoEnabled, reloadSummary, refresh]);
  useEffect(() => {
    if (!visible || demoEnabled) return undefined;
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
    setRealLoading(true); setRealRows([]);
    void messageApi.inbox({ category: categoryId, topic: topicId, page, page_size: pageSize }, controller.signal).then(result => {
      if (!controller.signal.aborted) { setRealRows(result.data.map(row => ({ id: `message-${row.id}`, category: row.category, topic: row.topic,
        title: row.title, detail: row.body, time: row.created_at, unread: row.unread, demo: false }))); setRealTotal(result.total); setApiError(''); }
    }).catch(cause => { if (!controller.signal.aborted) setApiError(cause instanceof Error ? cause.message : '消息加载失败'); })
      .finally(() => { if (!controller.signal.aborted) setRealLoading(false); });
    }, 0);
    return () => { controller.abort(); window.clearTimeout(timer); };
  }, [visible, demoEnabled, categoryId, topicId, page, pageSize, refresh]);

  const messages: CenterNotification[] = demoEnabled ? [...(preview || [])] : [...realRows];
  if (notice?.available && notice.latest_version && (demoEnabled || !summary.topics.update)) {
    messages.unshift({
      id: `update-${notice.latest_version}`,
      category: 'system', topic: 'update',
      title: `发现新版本 v${notice.latest_version}`,
      detail: notice.force_update ? '此版本要求更新' : '新版本已发布，可查看更新日志后进行更新。',
      time: notice.checked_at || '-', unread: true, demo: false,
    });
  }
  const isUnread = (message: CenterNotification) => message.unread && !readIds.has(message.id);
  const countUnread = (category?: string, topic?: string) => (demoEnabled ? 0 : topic ? summary.topics[topic] ?? 0 : category ? summary.categories[category] ?? 0 : summary.unread)
    + messages.filter(message => (!category || message.category === category) && (!topic || message.topic === topic)
      && (demoEnabled || !message.id.startsWith('message-')) && isUnread(message)).length;
  const category = notificationCategories.find((item) => item.id === categoryId) || notificationCategories[0];
  const filtered = messages.filter((message) => message.category === category.id && message.topic === topicId);
  const currentRows = demoEnabled ? filtered.slice((page - 1) * pageSize, page * pageSize) : filtered;
  const total = demoEnabled ? filtered.length : realTotal + filtered.filter(row => !row.id.startsWith('message-')).length;
  const selectTopic = (id: string) => { setTopicId(id); setPage(1); setExpanded([]); };
  const selectCategory = (id: string) => {
    setCategoryId(id);
    selectTopic(notificationCategories.find((item) => item.id === id)?.topics[0].id || 'update');
  };
  const markRead = (ids: string[]) => {
    const realIds = ids.filter(id => id.startsWith('message-') && !readIds.has(id));
    if (!realIds.length) { setReadIds(previous => new Set([...previous, ...ids])); return; }
    void Promise.all(realIds.map(id => messageApi.read(Number(id.slice(8))))).then(() => {
      setReadIds(previous => new Set([...previous, ...ids])); void reloadSummary();
    }).catch(cause => Message.error(cause instanceof Error ? cause.message : '标记已读失败'));
  };
  const markAllRead = () => {
    if (demoEnabled) { markRead(messages.map(message => message.id)); return; }
    void messageApi.readAll().then(() => { setReadIds(previous => new Set([...previous, ...messages.map(message => message.id)])); setRefresh(value => value + 1); })
      .catch(cause => Message.error(cause instanceof Error ? cause.message : '标记已读失败'));
  };
  const toggleMessage = (id: string) => {
    setExpanded((previous) => previous.includes(id) ? previous.filter((key) => key !== id) : [...previous, id]);
    markRead([id]);
  };
  const categoryLabel = (label: string, count: number) => (
    <span className="notification-center-category-label"><span>{label}</span>
      <Badge count={count} />
    </span>
  );

  return <>
    <Badge className="notification-center-trigger-badge" count={countUnread()} maxCount={99} aria-label={`${countUnread()}条未读消息`}>
      <IconButton icon={<IconNotification />} aria-label="通知" aria-expanded={visible} onClick={() => setVisible(true)} />
    </Badge>
    <Modal title={<span className="notification-center-title">消息中心{demoEnabled && <Tag size="small">含演示数据</Tag>}</span>}
      visible={visible} onCancel={() => setVisible(false)} maskClosable unmountOnExit footer={null}
      style={{ width: 1180, maxWidth: 'calc(100vw - 32px)' }}>
      <section className="notification-center" aria-label="消息中心">
        <aside className="notification-center-categories" aria-label="消息分类">
          <Menu selectedKeys={[category.id]} onClickMenuItem={selectCategory} style={{ width: '100%' }}>
            {notificationCategories.map((item) => <Menu.Item key={item.id} style={{ paddingLeft: 16, paddingRight: 16, background: category.id === item.id ? 'var(--color-primary-light-1)' : undefined }}>
              {categoryLabel(item.label, countUnread(item.id))}
            </Menu.Item>)}
          </Menu>
          <div className="notification-center-category-footer">
            <Button disabled={!countUnread()} onClick={markAllRead}>全部已读</Button>
          </div>
        </aside>
        <aside className="notification-center-topics" aria-label="消息子分类">
          <Menu selectedKeys={[topicId]} onClickMenuItem={selectTopic} style={{ width: '100%' }}>
            {category.topics.map((item) => <Menu.Item key={item.id} style={{ paddingLeft: 16, paddingRight: 16, background: topicId === item.id ? 'var(--color-primary-light-1)' : undefined }}>
              {categoryLabel(item.label, countUnread(category.id, item.id))}
            </Menu.Item>)}
          </Menu>
        </aside>
        <div className="notification-center-mobile-filters">
          <Select aria-label="消息分类" value={category.id} onChange={selectCategory}
            options={notificationCategories.map((item) => ({ value: item.id, label: categoryLabel(item.label, countUnread(item.id)) }))} />
          <Select aria-label="消息子分类" value={topicId} onChange={selectTopic}
            options={category.topics.map((item) => ({ value: item.id, label: categoryLabel(item.label, countUnread(category.id, item.id)) }))} />
        </div>
        <section className="notification-center-messages" aria-label="通知列表">
          {apiError && !demoEnabled && <Alert type="warning" content={apiError} action={<Button onClick={() => setRefresh(value => value + 1)}>重新加载</Button>} />}
          {previewError && demoEnabled && <Alert type="warning" content="演示消息加载失败，请重新打开消息中心。" />}
          <Spin loading={realLoading || (visible && demoEnabled && !preview && !previewError)} style={{ width: '100%' }}>
            {currentRows.length ? <div className="notification-center-message-list">
              {currentRows.map((message) => <Collapse key={message.id}
                className={isUnread(message) ? undefined : 'notification-center-read-message'}
                activeKey={expanded.filter((id) => id === message.id)} expandIconPosition="right" onChange={(_, keys) => {
                  setExpanded((previous) => [...previous.filter((id) => id !== message.id), ...keys]);
                  markRead(keys);
                }}>
                <Collapse.Item name={message.id} showExpandIcon={false}
                extra={<div className="notification-center-row-meta">
                  <time className="notification-center-time notification-center-time-desktop">{message.time}</time>
                  <Link href="#" style={{ color: 'var(--color-text-3)' }}
                    aria-label={expanded.includes(message.id) ? '收起消息' : '展开消息'} aria-expanded={expanded.includes(message.id)}
                    onClick={(event) => { event.preventDefault(); toggleMessage(message.id); }}>
                    {expanded.includes(message.id) ? <IconUp /> : <IconDown />}
                  </Link>
                </div>}
                header={<div className="notification-center-message-header">
                  <span className="notification-center-summary">
                    <span className={`notification-center-dot${isUnread(message) ? ' is-unread' : ''}`} />
                    <span>{message.title}</span>
                  </span>
                  <time className="notification-center-time notification-center-time-mobile">{message.time}</time>
                </div>}>
                <div className="notification-center-detail">
                  <Typography.Paragraph style={{ marginBottom: 8, whiteSpace: 'pre-wrap' }}>{message.detail}</Typography.Paragraph>
                  <div className="notification-center-detail-footer">
                    {message.demo && <Typography.Text type="secondary">演示消息</Typography.Text>}
                    {permittedPaths.includes(notificationDestinations[message.topic]) && <Link
                      href={history.createHref({ pathname: notificationDestinations[message.topic] })}
                      onClick={(event) => {
                        event.preventDefault();
                        setVisible(false);
                        history.push(notificationDestinations[message.topic]);
                      }}>查看详情</Link>}
                  </div>
                </div>
                </Collapse.Item>
              </Collapse>)}
            </div> : <Empty description="暂无通知" />}
          </Spin>
        </section>
        <footer className="notification-center-footer">
          <div className="notification-center-mobile-actions">
            <Button disabled={!countUnread()} onClick={markAllRead}>全部已读</Button>
            <Typography.Text type="secondary">共 {total} 条</Typography.Text>
          </div>
          <Pagination className="notification-center-pagination" total={total} current={page} pageSize={pageSize} size="small" simple={isNarrow} showTotal={!isNarrow} sizeCanChange
            sizeOptions={[10, 20, 50, 100]}
            onChange={(value) => { setPage(value); setExpanded([]); }}
            onPageSizeChange={(value) => { setPageSize(value); setPage(1); setExpanded([]); }} />
        </footer>
      </section>
    </Modal>
  </>;
}
