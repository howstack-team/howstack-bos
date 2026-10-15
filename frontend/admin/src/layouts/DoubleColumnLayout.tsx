import { Badge, Menu } from '@arco-design/web-react';
import { CollapseButton } from './LayoutFrame';
import NavigationIcon from './NavigationIcon';
import { renderNavigationItems } from './NavigationItems';
import type { AdminLayoutProps } from './types';
import styles from './styles/double-column.module.less';

/** 双列布局：左列选择一级分类，右列管理其子菜单；折叠时保留一级列。 */
export default function DoubleColumnLayout(props: AdminLayoutProps) {
  const { activeGroup, updateVersion, locale } = props;
  return <div className={styles.columns}>
      <nav className={styles.primary} aria-label="一级导航">
        <div className={styles.scroll}>
          <Menu selectedKeys={activeGroup ? [activeGroup.key] : []} onClickMenuItem={props.onGroupClick}>
            {props.routes.map(route => <Menu.Item key={route.key} style={{ height: 'auto', padding: '8px 4px', lineHeight: '20px' }}>
              <span className={styles['primary-item']}>
                {route.key === 'system' && updateVersion
                  ? <Badge dot count={1} aria-label={`发现新版本 v${updateVersion}`}><NavigationIcon routeKey={route.key} /></Badge>
                  : <NavigationIcon routeKey={route.key} />}
                <span>{locale[route.name] || route.name}</span>
              </span>
            </Menu.Item>)}
          </Menu>
        </div>
        {!props.isNarrow && <CollapseButton collapsed={props.collapsed} onClick={props.onToggleCollapse} doubleColumn />}
      </nav>
      {(props.isNarrow || !props.collapsed) && activeGroup && <nav className={styles.secondary} aria-label="二级导航">
        <h2 className={styles.heading}>{locale[activeGroup.name] || activeGroup.name}</h2>
        <div className={styles.scroll}>
          <Menu accordion={false} levelIndent={16} style={{ padding: 8 }} selectedKeys={props.selectedKeys}
            openKeys={props.openKeys} onClickMenuItem={props.onMenuItemClick}
            onClickSubMenu={(_key, keys) => props.onOpenKeysChange(keys)}>
            {renderNavigationItems({ routes: activeGroup.children || [], locale, updateVersion })}
          </Menu>
        </div>
      </nav>}
    </div>;
}
