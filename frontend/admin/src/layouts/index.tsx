import ClassicLayout from './ClassicLayout';
import DoubleColumnLayout from './DoubleColumnLayout';
import LayoutFrame from './LayoutFrame';
import type { AdminLayoutProps } from './types';

/** 使用页面配置选择布局，两种样式共用路由和业务内容。 */
export default function AdminLayout(props: AdminLayoutProps) {
  const doubleColumn = props.variant === 'double-column';
  // 只切换导航组件，保持顶部配置和业务页面挂载，避免输入或弹窗状态丢失。
  return <LayoutFrame {...props}
    expandedWidth={doubleColumn ? 88 + props.secondaryMenuWidth : props.classicMenuWidth}
    collapsedWidth={doubleColumn ? 88 : 48} nativeCollapse={!doubleColumn}
    navigation={doubleColumn ? <DoubleColumnLayout {...props} /> : <ClassicLayout {...props} />} />;
}
