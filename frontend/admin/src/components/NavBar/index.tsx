import React, { useContext, useMemo } from 'react';
import { Avatar, Dropdown, Input, Menu, Message, Tooltip } from '@arco-design/web-react';
import { IconBook, IconClose, IconMenu, IconMoonFill, IconPoweroff, IconSunFill, IconUser } from '@arco-design/web-react/icon';
import type { UpdateNotice } from '@/api/officialPlatformApi';
import { useHistory } from 'react-router-dom';
import { GlobalContext } from '@/context';
import { useAdminAuth } from '@/auth/AuthContext';
import useRoute, { toUserPermission, type IRoute } from '@/routes';
import useLocale from '@/utils/useLocale';
import IconButton from './IconButton';
import Notifications from './Notifications';
import Settings from '../Settings';
import styles from './style/index.module.less';

function leaves(routes: IRoute[]): IRoute[] {
  return routes.flatMap((route) => route.children ? leaves(route.children) : [route]);
}

interface NavbarProps {
  show: boolean;
  showMenu: boolean;
  mobileMenuOpen: boolean;
  onMobileMenuToggle: () => void;
  notice?: UpdateNotice;
}

function Navbar({ show, showMenu, mobileMenuOpen, onMobileMenuToggle, notice }: NavbarProps) {
  const t = useLocale();
  const history = useHistory();
  const { user, permissions, logout } = useAdminAuth();
  const { theme, setTheme } = useContext(GlobalContext);
  const userPermission = useMemo(() => toUserPermission(permissions), [permissions]);
  const [allowedRoutes] = useRoute(userPermission);
  const availablePages = useMemo(() => leaves(allowedRoutes).filter(route => !route.ignore), [allowedRoutes]);

  async function signOut() {
    try {
      await logout();
      history.replace('/login');
    }
    catch (error) { Message.error(error instanceof Error ? error.message : '退出登录失败，请稍后重试'); }
  }

  function search(query: string) {
    const page = availablePages.find((route) => route.name.toLowerCase().includes(query.trim().toLowerCase()));
    if (page?.path) history.push(page.path);
    else if (query.trim()) Message.info('未找到匹配的页面');
  }

  const menuButton = showMenu && <button type="button" className={styles['mobile-menu-toggle']}
    aria-label={mobileMenuOpen ? '关闭导航菜单' : '打开导航菜单'} aria-expanded={mobileMenuOpen}
    onClick={onMobileMenuToggle}>{mobileMenuOpen ? <IconClose /> : <IconMenu />}</button>;

  if (!show) return <div className={styles['fixed-settings']}>{menuButton}<Settings /></div>;

  const droplist = <Menu onClickMenuItem={(key) => {
    if (key === 'profile') history.push('/profile');
    if (key === 'api-docs') window.open('/api-docs', '_blank', 'noopener,noreferrer');
    if (key === 'logout') void signOut();
  }}>
    {permissions.includes('admin.profile.view') && <Menu.Item key="profile"><IconUser className={styles['dropdown-icon']} />个人设置</Menu.Item>}
    {permissions.includes('admin.api-docs.view') && <Menu.Item key="api-docs"><IconBook className={styles['dropdown-icon']} />API 文档</Menu.Item>}
    <Menu.Item key="logout"><IconPoweroff className={styles['dropdown-icon']} />退出登录</Menu.Item>
  </Menu>;

  return <div className={styles.navbar}>
    <div className={styles.left}>{menuButton}<div className={styles.logo}>
      <img src={`${import.meta.env.BASE_URL}logo.png`} alt="" style={{ width: 32, height: 32, flexShrink: 0, objectFit: 'contain' }} />
      <div className={styles['logo-name']} title="云业务运营后台">云业务运营后台</div>
    </div></div>
    <ul className={styles.right}>
      <li className={styles['search-item']}><Input.Search className={styles.round} placeholder="搜索页面" onSearch={search} /></li>
      <li><Notifications notice={notice} /></li>
      <li><Tooltip content={theme === 'light' ? t['settings.navbar.theme.toDark'] : t['settings.navbar.theme.toLight']}>
        <IconButton icon={theme === 'dark' ? <IconSunFill /> : <IconMoonFill />}
          onClick={() => setTheme(theme === 'light' ? 'dark' : 'light')} />
      </Tooltip></li>
      <Settings />
      {user && <li><Dropdown droplist={droplist} position="br" trigger="click">
        <Avatar size={32} style={{ cursor: 'pointer', backgroundColor: '#14C9C9' }}>{user.nickname.slice(0, 1)}</Avatar>
      </Dropdown></li>}
    </ul>
  </div>;
}

export default Navbar;
