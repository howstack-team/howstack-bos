import type { LayoutVariant } from './types';

/** 布局偏好保存在当前浏览器，不承载任何授权或业务配置。 */
export const LAYOUT_STORAGE_KEY = 'admin-layout-style';

/** 将外部配置限制为已实现的布局，未知值使用经典布局。 */
export function normalizeLayoutVariant(value: unknown): LayoutVariant {
  return value === 'double-column' ? 'double-column' : 'classic';
}

/** 恢复布局偏好；本地存储不可用时使用项目默认配置。 */
export function readLayoutPreference(defaultVariant: unknown): LayoutVariant {
  try {
    return normalizeLayoutVariant(localStorage.getItem(LAYOUT_STORAGE_KEY) ?? defaultVariant);
  } catch {
    return normalizeLayoutVariant(defaultVariant);
  }
}
