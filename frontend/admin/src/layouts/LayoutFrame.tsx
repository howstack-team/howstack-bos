import { Layout } from '@arco-design/web-react';
import { IconMenuFold, IconMenuUnfold } from '@arco-design/web-react/icon';
import type { ReactNode } from 'react';
import cs from 'classnames';
import type { AdminLayoutProps } from './types';
import styles from './styles/frame.module.less';

interface LayoutFrameProps extends Pick<AdminLayoutProps, 'navbar' | 'breadcrumb' | 'footer' | 'children'
  | 'showNavbar' | 'showMenu' | 'isNarrow' | 'collapsed' | 'mobileMenuOpen' | 'onCloseMobileMenu'> {
  expandedWidth: number;
  collapsedWidth: number;
  nativeCollapse?: boolean;
  navigation: ReactNode;
}

/** 共用顶部、侧栏容器和主内容区，页面纵向滚动不受布局切换影响。 */
export default function LayoutFrame(props: LayoutFrameProps) {
  const { showMenu, showNavbar, isNarrow, collapsed, mobileMenuOpen } = props;
  const navbarHeight = showNavbar ? 60 : 0;
  const sidebarWidth = isNarrow || !collapsed ? props.expandedWidth : props.collapsedWidth;

  return <Layout className={styles.layout}>
    <div className={cs(styles['layout-navbar'], { [styles['layout-navbar-hidden']]: !showNavbar })}>
      {props.navbar}
    </div>
    <Layout>
      {showMenu && isNarrow && mobileMenuOpen && <button type="button" className={styles['mobile-backdrop']}
        style={{ top: navbarHeight }} aria-label="关闭导航菜单" onClick={props.onCloseMobileMenu} />}
      {showMenu && <Layout.Sider className={cs(styles['layout-sider'], {
        [styles['layout-sider-open']]: isNarrow && mobileMenuOpen,
        [styles['layout-sider-with-navbar']]: showNavbar,
      })} width={props.expandedWidth} collapsedWidth={props.collapsedWidth}
        collapsed={Boolean(props.nativeCollapse && collapsed && !isNarrow)}
        trigger={null} style={{ width: sidebarWidth, ...(isNarrow ? {} : { paddingTop: navbarHeight }) }}>
        {props.navigation}
      </Layout.Sider>}
      <Layout className={styles['layout-content']} style={{
        paddingTop: navbarHeight,
        ...(showMenu && !isNarrow ? { paddingLeft: sidebarWidth } : {}),
      }}>
        <div className={styles['layout-content-wrapper']}>
          {props.breadcrumb && <div className={styles['layout-breadcrumb']}>{props.breadcrumb}</div>}
          <Layout.Content>{props.children}</Layout.Content>
        </div>
        {props.footer}
      </Layout>
    </Layout>
  </Layout>;
}

interface CollapseButtonProps {
  collapsed: boolean;
  onClick: () => void;
  doubleColumn?: boolean;
}

/** 桌面侧栏的折叠入口，两个布局使用各自明确的可访问名称。 */
export function CollapseButton({ collapsed, onClick, doubleColumn }: CollapseButtonProps) {
  const target = doubleColumn ? '二级导航' : '导航菜单';
  return <button type="button" className={cs(styles['collapse-btn'], { [styles['collapse-btn-centered']]: doubleColumn })} onClick={onClick}
    aria-label={`${collapsed ? '展开' : '收起'}${target}`} aria-expanded={!collapsed}>
    {collapsed ? <IconMenuUnfold /> : <IconMenuFold />}
  </button>;
}
