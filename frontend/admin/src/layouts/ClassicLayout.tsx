import { Menu } from '@arco-design/web-react';
import { CollapseButton } from './LayoutFrame';
import { renderNavigationItems } from './NavigationItems';
import type { AdminLayoutProps } from './types';
import styles from './styles/classic.module.less';

/** 经典单列布局：一级图标与文字并排，子菜单在同一侧栏内展开。 */
export default function ClassicLayout(props: AdminLayoutProps) {
  return <nav className={styles.navigation} aria-label="后台导航">
      <div className={styles.scroll}>
        <Menu accordion={false} levelIndent={0} selectedKeys={props.selectedKeys} openKeys={props.openKeys}
          onClickMenuItem={props.onMenuItemClick} onClickSubMenu={(_key, keys) => props.onOpenKeysChange(keys)}>
          {renderNavigationItems({ ...props, classic: true })}
        </Menu>
      </div>
      {!props.isNarrow && <CollapseButton collapsed={props.collapsed} onClick={props.onToggleCollapse} />}
    </nav>;
}
