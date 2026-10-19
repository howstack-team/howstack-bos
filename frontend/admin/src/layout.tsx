import { useEffect, useState, useMemo } from 'react';
import { Switch, Route, Redirect, useHistory, useLocation } from 'react-router-dom';
import { Breadcrumb, Watermark } from '@arco-design/web-react';
import { useSelector } from 'react-redux';
import NProgress from 'nprogress';
import Navbar from './components/NavBar';
import Footer from './components/Footer';
import SoftwareUpdateNotice from './components/SoftwareUpdateNotice';
import useSoftwareUpdateNotice from './hooks/useSoftwareUpdateNotice';
import useRoute, { IRoute, toUserPermission } from '@/routes';
import { useAdminAuth } from './auth/AuthContext';
import { isArray } from './utils/is';
import useLocale from './utils/useLocale';
import getUrlParams from './utils/getUrlParams';
import lazyload from './utils/lazyload';
import { GlobalState } from './store';
import AdminLayout from './layouts';
import { cloudSectionFromPath } from './components/cloud-server/navigation';
import { normalizeLayoutVariant } from './layouts/preferences';

const CloudConfigurationPage = lazyload(() => import('./pages/ProductManagementPage'));
const ForbiddenPage = lazyload(() => import('./pages/exception/403'));

const routeLoaders = {
    'system/profile': () => import('./pages/PersonalSettingsPage'),
    'dashboard/workplace': () => import('./pages/DashboardPage'),
    'dashboard/analysis': () => import('./pages/BusinessAnalysisPage'),
    'customers/members': () => import('./pages/MembersPage'),
    'customers/member-groups': () => import('./pages/MemberGroupsPage'),
    'customers/member-levels': () => import('./pages/MemberLevelsPage'),
    'customers/real-name-authentications': () => import('./pages/RealNameAuthenticationsPage'),
    'finance/orders': () => import('./pages/OrdersPage'),
    'products/cloud-servers/deployment': () => import('./pages/ProductManagementPage'),
    'products/cloud-servers/specification': () => import('./pages/ProductManagementPage'),
    'products/cloud-servers/network': () => import('./pages/ProductManagementPage'),
    'products/cloud-servers/storage': () => import('./pages/ProductManagementPage'),
    'products/cloud-servers/purchase': () => import('./pages/ProductManagementPage'),
    'products/provider-connections': () => import('./pages/ProviderConnectionsPage'),
    'services/cloud-servers': () => import('./pages/ServiceInstancesPage').then(({ default: Page }) => ({ default: () => <Page productType="cloud_server" /> })),
    'services/physical-servers': () => import('./pages/ServiceInstancesPage').then(({ default: Page }) => ({ default: () => <Page productType="physical_server" /> })),
    'services/server-colocations': () => import('./pages/ServiceInstancesPage').then(({ default: Page }) => ({ default: () => <Page productType="server_colocation" /> })),
    'support/ticket-types': () => import('./pages/TicketCatalogPage').then(({ default: Page }) => ({ default: () => <Page kind="types" /> })),
    'support/tickets': () => import('./pages/TicketsPage'),
    'support/quick-replies': () => import('./pages/TicketCatalogPage').then(({ default: Page }) => ({ default: () => <Page kind="quick-replies" /> })),
    'content/help': () => import('./pages/ContentManagementPage').then(({ default: Page }) => ({ default: () => <Page kind="help" resource="entries" /> })),
    'content/files': () => import('./pages/FileManagementPage'),
    'content/website-base': () => import('./pages/WebsiteSettingsPage'),
    'content/website-navigation': () => import('./pages/WebsiteSettingsPage'),
    'content/website-seo': () => import('./pages/WebsiteSeoPage'),
    'content/themes': () => import('./pages/ThemeManagementPage'),
    'content/help-categories': () => import('./pages/ContentManagementPage').then(({ default: Page }) => ({ default: () => <Page kind="help" resource="categories" /> })),
    'content/news': () => import('./pages/ContentManagementPage').then(({ default: Page }) => ({ default: () => <Page kind="news" resource="entries" /> })),
    'content/news-categories': () => import('./pages/ContentManagementPage').then(({ default: Page }) => ({ default: () => <Page kind="news" resource="categories" /> })),
    'finance/recharges': () => import('./pages/FinanceRecordsPage').then(({ default: Page }) => ({ default: () => <Page resource="recharges" /> })),
    'finance/payments': () => import('./pages/FinanceRecordsPage').then(({ default: Page }) => ({ default: () => <Page resource="payments" /> })),
    'finance/transactions': () => import('./pages/FinanceRecordsPage').then(({ default: Page }) => ({ default: () => <Page resource="transactions" /> })),
    'finance/refunds': () => import('./pages/FinanceRecordsPage').then(({ default: Page }) => ({ default: () => <Page resource="refunds" /> })),
    'finance/invoices': () => import('./pages/FinanceRecordsPage').then(({ default: Page }) => ({ default: () => <Page resource="invoices" /> })),
    'finance/withdrawals': () => import('./pages/FinanceRecordsPage').then(({ default: Page }) => ({ default: () => <Page resource="withdrawals" /> })),
    'system/administrators': () => import('./pages/AdministratorsPage'),
    'system/departments': () => import('./pages/DepartmentsPage'),
    'system/roles': () => import('./pages/RolesPage'),
    'system/message-configurations': () => import('./pages/message-configurations/MessageConfigurationsPage'),
    'system/settings': () => import('./pages/SettingsPage'),
    'system/channels/payment': () => import('./pages/channels/ChannelsPage').then(({ default: Page }) => ({ default: () => <Page category="payment" /> })),
    'system/channels/sms': () => import('./pages/channels/ChannelsPage').then(({ default: Page }) => ({ default: () => <Page category="sms" /> })),
    'system/channels/mail': () => import('./pages/channels/ChannelsPage').then(({ default: Page }) => ({ default: () => <Page category="mail" /> })),
    'system/channels/identity': () => import('./pages/channels/ChannelsPage').then(({ default: Page }) => ({ default: () => <Page category="identity" /> })),
    'system/channels/storage': () => import('./pages/channels/ChannelsPage').then(({ default: Page }) => ({ default: () => <Page category="storage" /> })),
    'system/channels/oauth': () => import('./pages/channels/ChannelsPage').then(({ default: Page }) => ({ default: () => <Page category="oauth" /> })),
    'system/channels/captcha': () => import('./pages/channels/ChannelsPage').then(({ default: Page }) => ({ default: () => <Page category="captcha" /> })),
    'system/maintenance': () => import('./pages/SystemVersionPage'),
    'system/application-center': () => import('./pages/ApplicationCenterPage'),
    'audit/messages': () => import('./pages/MessageLogsPage'),
    'audit/operations': () => import('./pages/OperationLogsPage'),
    'audit/logins': () => import('./pages/LoginLogsPage'),
};

// 权限刷新会重算可访问路由，复用组件类型以保留当前页面和弹层状态。
const routeComponents = Object.fromEntries(
  Object.entries(routeLoaders).map(([key, loader]) => [key, lazyload(loader)])
);

function getFlattenRoutes(routes: IRoute[]) {
  const res: IRoute[] = [];
  function travel(_routes: IRoute[]) {
    _routes.forEach((route) => {
      if (route.external) return;
      if (route.key && !route.children?.length) {
        try {
          const component = routeComponents[route.key];
          if (component) res.push({ ...route, component });
        } catch (e) {
          console.log(route.key);
          console.error(e);
        }
      }

      if (isArray(route.children) && route.children.length) {
        travel(route.children);
      }
    });
  }
  travel(routes);
  return res;
}

function findRoutePath(routes: IRoute[], key?: string, ancestors: IRoute[] = []): IRoute[] {
  for (const route of routes) {
    const path = [...ancestors, route];
    if (route.key === key) return path;
    if (route.children) {
      const found = findRoutePath(route.children, key, path);
      if (found.length) return found;
    }
  }
  return [];
}

/** 只为导航保留可见入口，不改变个人设置等隐藏页面的路由。 */
function getMenuRoutes(routes: IRoute[]): IRoute[] {
  return routes.flatMap((route) => {
    if (route.ignore) return [];
    if (!route.children?.length) return [route];
    const children = getMenuRoutes(route.children);
    return children.length ? [{ ...route, children }] : [];
  });
}

function PageLayout() {
  const urlParams = getUrlParams();
  const history = useHistory();
  const pathname = useLocation().pathname;
  const locale = useLocale();
  const { permissions, user } = useAdminAuth();
  const { notice, hidden: updateNoticeHidden, remindLater } = useSoftwareUpdateNotice();
  const updateAvailable = Boolean(notice?.available && notice.latest_version);
  const settings = useSelector((state: GlobalState) => state.settings);
  const variant = normalizeLayoutVariant(settings.layout);

  const userPermission = useMemo(() => toUserPermission(permissions), [permissions]);
  const [routes, defaultRoute] = useRoute(userPermission);
  const flattenRoutes = useMemo(() => getFlattenRoutes(routes) || [], [routes]);
  const currentRoute = flattenRoutes.find((route) => (route.path || `/${route.key}`) === pathname);
  const routePath = useMemo(() => findRoutePath(routes, currentRoute?.key), [routes, currentRoute?.key]);
  const ancestorKeys = useMemo(() => routePath.slice(0, -1).map((route) => route.key), [routePath]);
  const [openKeys, setOpenKeys] = useState<string[]>(ancestorKeys);
  const selectedKeys = currentRoute ? [currentRoute.key] : [];
  const breadcrumb = currentRoute && currentRoute.breadcrumb !== false
    ? routePath.map((route) => route.name) : [];
  const [collapsedVariant, setCollapsedVariant] = useState<typeof variant | null>(null);
  const collapsed = collapsedVariant === variant;
  const [isNarrow, setIsNarrow] = useState(() => window.matchMedia('(max-width: 768px)').matches);
  const [mobileMenuPath, setMobileMenuPath] = useState<string | null>(null);
  const mobileMenuOpen = mobileMenuPath === pathname;
  const menuRoutes = useMemo(() => getMenuRoutes(routes), [routes]);
  const [menuGroup, setMenuGroup] = useState<{ pathname: string; key: string } | null>(null);
  // 浏览其他分类时保留当前页面，路由变化后恢复目标页面所属分类。
  const groupKey = menuGroup?.pathname === pathname ? menuGroup.key : routePath[0]?.key;
  const activeGroup = menuRoutes.find((route) => route.key === groupKey) || menuRoutes[0];

  useEffect(() => history.listen((location) => {
    setMenuGroup(null);
    const route = flattenRoutes.find((item) => (item.path || `/${item.key}`) === location.pathname);
    const nextAncestorKeys = findRoutePath(routes, route?.key).slice(0, -1).map((item) => item.key);
    // 路由跳转只补充目标菜单，保留用户已经展开的其他分组。
    setOpenKeys((current) => {
      const addedKeys = nextAncestorKeys.filter((key) => !current.includes(key));
      return addedKeys.length ? [...current, ...addedKeys] : current;
    });
  }), [history, routes, flattenRoutes]);

  useEffect(() => {
    const media = window.matchMedia('(max-width: 768px)');
    const update = () => {
      const nextNarrow = media.matches;
      setIsNarrow(nextNarrow);
      if (!nextNarrow) {
        setMobileMenuPath(null);
        setCollapsedVariant(null);
      }
    };
    media.addEventListener('change', update);
    return () => media.removeEventListener('change', update);
  }, []);

  useEffect(() => {
    if (!isNarrow || !mobileMenuOpen) return;
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setMobileMenuPath(null);
    };
    window.addEventListener('keydown', closeOnEscape);
    return () => window.removeEventListener('keydown', closeOnEscape);
  }, [isNarrow, mobileMenuOpen]);

  const showNavbar = settings.navbar && urlParams.navbar !== false;
  const showMenu = settings.menu && urlParams.menu !== false;
  const showFooter = settings.footer && urlParams.footer !== false;

  function onClickMenuItem(key) {
    const currentRoute = flattenRoutes.find((r) => r.key === key);
    if (!currentRoute) return;
    setMobileMenuPath(null);
    const component = currentRoute.component;
    const preload = component.preload();
    NProgress.start();
    preload.then(() => {
      const nextPath = currentRoute.path || `/${key}`;
      const search = new URLSearchParams();
      if (cloudSectionFromPath(history.location.pathname) && cloudSectionFromPath(nextPath)) {
        const current = new URLSearchParams(history.location.search);
        for (const key of ['configuration', 'zone']) {
          const value = current.get(key);
          if (value) search.set(key, value);
        }
      }
      history.push({ pathname: nextPath, search: search.toString() });
      NProgress.done();
    });
  }

  function onClickMenuGroup(key: string) {
    const group = menuRoutes.find((route) => route.key === key);
    if (!group) return;
    setMenuGroup({ pathname, key });
    if (!isNarrow) setCollapsedVariant(null);
    if (!group.children?.length) onClickMenuItem(key);
  }

  function toggleCollapse() {
    setCollapsedVariant(collapsed ? null : variant);
  }

  const layout = <AdminLayout variant={variant} routes={menuRoutes} locale={locale}
    selectedKeys={selectedKeys} openKeys={openKeys} activeGroup={activeGroup}
    updateVersion={updateAvailable ? notice.latest_version : undefined}
    collapsed={collapsed} isNarrow={isNarrow} mobileMenuOpen={mobileMenuOpen}
    showNavbar={showNavbar} showMenu={showMenu}
    classicMenuWidth={Math.max(180, settings.classicMenuWidth || 220)}
    secondaryMenuWidth={Math.max(160, settings.menuWidth || 168)}
    onMenuItemClick={onClickMenuItem} onGroupClick={onClickMenuGroup}
    onOpenKeysChange={setOpenKeys} onToggleCollapse={toggleCollapse} onCloseMobileMenu={() => setMobileMenuPath(null)}
    navbar={<Navbar show={showNavbar} showMenu={showMenu} mobileMenuOpen={mobileMenuOpen} notice={notice}
      onMobileMenuToggle={() => setMobileMenuPath(current => current === pathname ? null : pathname)} />}
    breadcrumb={breadcrumb.length ? <Breadcrumb>
      {breadcrumb.map((node, index) => <Breadcrumb.Item key={index}>
        {typeof node === 'string' ? locale[node] || node : node}
      </Breadcrumb.Item>)}
    </Breadcrumb> : null}
    footer={showFooter ? <Footer /> : null}>
    <SoftwareUpdateNotice notice={notice} hidden={updateNoticeHidden} onRemindLater={remindLater} />
    <Switch>
      <Route path="/products/cloud-servers">
        {pathname === '/products/cloud-servers' || flattenRoutes.some(route => route.path === pathname && pathname.startsWith('/products/cloud-servers/'))
          ? <CloudConfigurationPage /> : <ForbiddenPage />}
      </Route>
      {flattenRoutes.map((route) => {
        return (
          <Route
            key={route.key}
            exact
            path={route.path || `/${route.key}`}
            component={route.component}
          />
        );
      })}

      <Route exact path="/">
        {defaultRoute ? <Redirect to={defaultRoute} /> : <div>暂无访问权限</div>}
      </Route>
      <Route
        path="*"
        component={ForbiddenPage}
      />
    </Switch>
  </AdminLayout>;

  return settings.watermark && user?.nickname
    ? <Watermark content={user.nickname}>{layout}</Watermark>
    : layout;
}

export default PageLayout;
