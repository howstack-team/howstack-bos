import { Badge, Menu } from '@arco-design/web-react';
import type { ReactNode } from 'react';
import type { IRoute } from '../routes';
import NavigationIcon from './NavigationIcon';
import styles from './styles/navigation.module.less';

interface NavigationItemsOptions {
  routes: IRoute[];
  locale: Record<string, string>;
  updateVersion?: string;
  classic?: boolean;
}

/** 生成原生 Menu 子项，保留层级、权限过滤结果和更新徽标。 */
export function renderNavigationItems({ routes, locale, updateVersion, classic }: NavigationItemsOptions): ReactNode[] {
  function travel(items: IRoute[], depth = 0): ReactNode[] {
    return items.filter(route => !route.ignore).map(route => {
      const title = locale[route.name] || route.name;
      const label = route.key === 'system/maintenance' && updateVersion
        ? <Badge dot count={1} offset={[8, 0]} aria-label={`发现新版本 v${updateVersion}`}><span>{title}</span></Badge>
        : title;
      const titleNode = <>
        {classic && (depth === 0
          ? <span className={styles['root-icon']}>
            {route.key === 'system' && updateVersion
              ? <Badge dot count={1} aria-label={`发现新版本 v${updateVersion}`}><NavigationIcon routeKey={route.key} /></Badge>
              : <NavigationIcon routeKey={route.key} />}
          </span>
          : <span className={styles['icon-placeholder']} aria-hidden="true" />)}
        <span style={classic && depth > 1 ? { marginLeft: (depth - 1) * 16 } : undefined}>{label}</span>
      </>;
      const children = route.children?.filter(child => !child.ignore) || [];
      if (route.children?.length && !children.length) return null;
      return children.length
        ? <Menu.SubMenu key={route.key} title={titleNode}>{travel(children, depth + 1)}</Menu.SubMenu>
        : <Menu.Item key={route.key} style={{ height: 38, lineHeight: '38px' }}>{titleNode}</Menu.Item>;
    });
  }
  return travel(routes);
}
