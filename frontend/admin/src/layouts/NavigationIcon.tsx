import {
  IconDashboard, IconUserGroup, IconStamp, IconStorage, IconCustomerService,
  IconBook, IconSettings, IconHistory, IconCommon,
} from '@arco-design/web-react/icon';
import styles from './styles/navigation.module.less';

/** 一级分类统一使用同一套图标，子级菜单不添加图标。 */
export default function NavigationIcon({ routeKey }: { routeKey: string }) {
  const icons = {
    dashboard: IconDashboard, customers: IconUserGroup, finance: IconStamp,
    services: IconStorage, support: IconCustomerService, content: IconBook,
    system: IconSettings, audit: IconHistory, products: IconCommon,
  };
  const Icon = icons[routeKey as keyof typeof icons];
  return Icon ? <Icon className={styles.icon} style={{ marginRight: 0 }} /> : null;
}
