import { useEffect, useState, useMemo } from 'react';
import { Switch, Route, Redirect, useHistory, useLocation } from 'react-router-dom';
import { Layout, Menu, Breadcrumb, Watermark } from '@arco-design/web-react';
import cs from 'classnames';
import {
  IconDashboard,
  IconUserGroup,
  IconStamp,
  IconStorage,
  IconCustomerService,
  IconBook,
  IconSettings,
  IconHistory,
  IconMenuFold,
  IconMenuUnfold,
} from '@arco-design/web-react/icon';
import { useSelector } from 'react-redux';
import NProgress from 'nprogress';
import Navbar from './components/NavBar';
import Footer from './components/Footer';
import useRoute, { IRoute, toUserPermission } from '@/routes';
import { useAdminAuth } from './auth/AuthContext';
import { isArray } from './utils/is';
import useLocale from './utils/useLocale';
import getUrlParams from './utils/getUrlParams';
import lazyload from './utils/lazyload';
import { GlobalState } from './store';
import styles from './style/layout.module.less';

const MenuItem = Menu.Item;
const SubMenu = Menu.SubMenu;

const Sider = Layout.Sider;
const Content = Layout.Content;

function getIconFromKey(key) {
  switch (key) {
    case 'dashboard':
      return <IconDashboard className={styles.icon} />;
    case 'customers':
      return <IconUserGroup className={styles.icon} />;
    case 'system':
      return <IconSettings className={styles.icon} />;
    case 'audit':
      return <IconHistory className={styles.icon} />;
    case 'finance':
      return <IconStamp className={styles.icon} />;
    case 'services':
      return <IconStorage className={styles.icon} />;
    case 'support':
      return <IconCustomerService className={styles.icon} />;
    case 'content':
      return <IconBook className={styles.icon} />;
    default:
      return null;
  }
}

function getFlattenRoutes(routes) {
  const mod = {
    'dashboard/workplace': () => import('./pages/DashboardPage'),
    'customers/members': () => import('./pages/MembersPage'),
    'customers/member-groups': () => import('./pages/MemberGroupsPage'),
    'customers/member-levels': () => import('./pages/MemberLevelsPage'),
    'customers/real-name-authentications': () => import('./pages/RealNameAuthenticationsPage'),
    'finance/orders': () => import('./pages/OrdersPage'),
    'services/cloud-servers': () => import('./pages/ServiceInstancesPage').then(({ default: Page }) => ({ default: () => <Page productType="cloud_server" /> })),
    'services/physical-servers': () => import('./pages/ServiceInstancesPage').then(({ default: Page }) => ({ default: () => <Page productType="physical_server" /> })),
    'services/server-colocations': () => import('./pages/ServiceInstancesPage').then(({ default: Page }) => ({ default: () => <Page productType="server_colocation" /> })),
    'support/ticket-types': () => import('./pages/TicketCatalogPage').then(({ default: Page }) => ({ default: () => <Page kind="types" /> })),
    'support/tickets': () => import('./pages/TicketsPage'),
    'support/quick-replies': () => import('./pages/TicketCatalogPage').then(({ default: Page }) => ({ default: () => <Page kind="quick-replies" /> })),
    'content/help': () => import('./pages/ContentManagementPage').then(({ default: Page }) => ({ default: () => <Page kind="help" resource="entries" /> })),
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
    'system/settings': () => import('./pages/SettingsPage'),
    'system/maintenance': () => import('./pages/SystemMaintenancePage'),
    'audit/operations': () => import('./pages/OperationLogsPage'),
    'audit/logins': () => import('./pages/LoginLogsPage'),
  };
  const res = [];
  function travel(_routes) {
    _routes.forEach((route) => {
      const visibleChildren = (route.children || []).filter(
        (child) => !child.ignore
      );
      if (route.key && (!route.children || !visibleChildren.length)) {
        try {
          route.component = lazyload(mod[route.key]);
          res.push(route);
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

function PageLayout() {
  const urlParams = getUrlParams();
  const history = useHistory();
  const pathname = useLocation().pathname;
  const locale = useLocale();
  const { permissions, user } = useAdminAuth();
  const settings = useSelector((state: GlobalState) => state.settings);

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
  const [collapsed, setCollapsed] = useState<boolean>(false);
  const [isNarrow, setIsNarrow] = useState(() => window.matchMedia('(max-width: 768px)').matches);
  const [mobileMenuPath, setMobileMenuPath] = useState<string | null>(null);
  const mobileMenuOpen = mobileMenuPath === pathname;

  useEffect(() => history.listen((location) => {
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
        setCollapsed(false);
      }
    };
    media.addEventListener('change', update);
    return () => media.removeEventListener('change', update);
  }, []);

  const navbarHeight = 60;
  const menuWidth = collapsed ? 48 : settings.menuWidth;

  const showNavbar = settings.navbar && urlParams.navbar !== false;
  const showMenu = settings.menu && urlParams.menu !== false;
  const showFooter = settings.footer && urlParams.footer !== false;

  function onClickMenuItem(key) {
    const currentRoute = flattenRoutes.find((r) => r.key === key);
    setMobileMenuPath(null);
    const component = currentRoute.component;
    const preload = component.preload();
    NProgress.start();
    preload.then(() => {
      history.push(currentRoute.path ? currentRoute.path : `/${key}`);
      NProgress.done();
    });
  }

  function toggleCollapse() {
    setCollapsed((collapsed) => !collapsed);
  }

  const paddingLeft = showMenu && !isNarrow ? { paddingLeft: menuWidth } : {};
  const paddingTop = showNavbar ? { paddingTop: navbarHeight } : {};
  const paddingStyle = { ...paddingLeft, ...paddingTop };

  function renderRoutes(locale) {
    return function travel(_routes: IRoute[], depth = 0) {
      return _routes.map((route) => {
        const { ignore } = route;
        const title = locale[route.name] || route.name;
        const titleDom = <>
          {depth === 0 ? getIconFromKey(route.key) : <span className={styles['icon-empty']} aria-hidden="true" />}
          {title}
        </>;

        const visibleChildren = (route.children || []).filter((child) => !child.ignore);

        if (ignore) {
          return '';
        }
        if (visibleChildren.length) {
          return (
            <SubMenu key={route.key} title={titleDom}>
              {travel(visibleChildren, depth + 1)}
            </SubMenu>
          );
        }
        return <MenuItem key={route.key}>{titleDom}</MenuItem>;
      });
    };
  }

  const layout = (
    <Layout className={styles.layout}>
      <div
        className={cs(styles['layout-navbar'], {
          [styles['layout-navbar-hidden']]: !showNavbar,
        })}
      >
        <Navbar show={showNavbar} showMenu={showMenu} mobileMenuOpen={mobileMenuOpen}
          onMobileMenuToggle={() => setMobileMenuPath((current) => current === pathname ? null : pathname)} />
      </div>
        <Layout>
          {showMenu && isNarrow && mobileMenuOpen && <button type="button" className={styles['mobile-backdrop']}
            style={{ top: showNavbar ? navbarHeight : 0 }} aria-label="关闭导航菜单" onClick={() => setMobileMenuPath(null)} />}
          {showMenu && (
            <Sider
              className={cs(styles['layout-sider'], {
                [styles['layout-sider-open']]: isNarrow && mobileMenuOpen,
                [styles['layout-sider-with-navbar']]: showNavbar,
              })}
              width={isNarrow ? 260 : menuWidth}
              collapsed={isNarrow ? false : collapsed}
              trigger={null}
              collapsible
              style={isNarrow ? {} : paddingTop}
            >
              <div className={styles['menu-wrapper']}>
                <Menu
                  collapse={isNarrow ? false : collapsed}
                  accordion={false}
                  levelIndent={0}
                  onClickMenuItem={onClickMenuItem}
                  selectedKeys={selectedKeys}
                  openKeys={openKeys}
                  onClickSubMenu={(_key, keys) => setOpenKeys(keys)}
                >
                  {renderRoutes(locale)(routes)}
                </Menu>
              </div>
              <div className={styles['collapse-btn']} onClick={toggleCollapse}>
                {collapsed ? <IconMenuUnfold /> : <IconMenuFold />}
              </div>
            </Sider>
          )}
          <Layout className={styles['layout-content']} style={paddingStyle}>
            <div className={styles['layout-content-wrapper']}>
              {!!breadcrumb.length && (
                <div className={styles['layout-breadcrumb']}>
                  <Breadcrumb>
                    {breadcrumb.map((node, index) => (
                      <Breadcrumb.Item key={index}>
                        {typeof node === 'string' ? locale[node] || node : node}
                      </Breadcrumb.Item>
                    ))}
                  </Breadcrumb>
                </div>
              )}
              <Content>
                <Switch>
                  {flattenRoutes.map((route, index) => {
                    return (
                      <Route
                        key={index}
                        exact
                        path={route.path || `/${route.key}`}
                        component={route.component}
                      />
                    );
                  })}
                  <Route exact path="/license"><Redirect to="/maintenance" /></Route>
                  <Route exact path="/updates"><Redirect to="/maintenance?tab=updates" /></Route>
                  <Route exact path="/">
                    {defaultRoute ? <Redirect to={defaultRoute} /> : <div>暂无访问权限</div>}
                  </Route>
                  <Route
                    path="*"
                    component={lazyload(() => import('./pages/exception/403'))}
                  />
                </Switch>
              </Content>
            </div>
            {showFooter && <Footer />}
          </Layout>
        </Layout>
    </Layout>
  );

  return settings.watermark && user?.nickname
    ? <Watermark content={user.nickname}>{layout}</Watermark>
    : layout;
}

export default PageLayout;
