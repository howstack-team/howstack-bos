import type { ConfigProviderProps } from '@arco-design/web-react'

/** 控制台的全局组件默认值；所有业务开关使用 round 类型。 */
export const appComponentConfig: NonNullable<ConfigProviderProps['componentConfig']> = {
  Card: { bordered: false },
  List: { bordered: false },
  Table: { border: false },
  Switch: { type: 'round' },
  Modal: { maskClosable: true },
  Drawer: { maskClosable: true },
}
