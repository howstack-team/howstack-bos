import type { ReactNode } from 'react';
import type { IRoute } from '../routes';

/** 后台提供的布局样式，仅影响展示，不改变权限和业务路由。 */
export type LayoutVariant = 'classic' | 'double-column';

/** 两种布局共用的导航状态和页面内容。 */
export interface AdminLayoutProps {
  variant: LayoutVariant;
  routes: IRoute[];
  locale: Record<string, string>;
  selectedKeys: string[];
  openKeys: string[];
  activeGroup?: IRoute;
  updateVersion?: string;
  collapsed: boolean;
  isNarrow: boolean;
  mobileMenuOpen: boolean;
  showNavbar: boolean;
  showMenu: boolean;
  classicMenuWidth: number;
  secondaryMenuWidth: number;
  navbar: ReactNode;
  breadcrumb?: ReactNode;
  footer?: ReactNode;
  children: ReactNode;
  onMenuItemClick: (key: string) => void;
  onGroupClick: (key: string) => void;
  onOpenKeysChange: (keys: string[]) => void;
  onToggleCollapse: () => void;
  onCloseMobileMenu: () => void;
}
