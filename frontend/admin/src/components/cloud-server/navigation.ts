/** 云服务器三级菜单使用独立路由；配置、可用区等上下文保留在 query。 */
export const CLOUD_CONFIGURATION_ROOT = '/products/cloud-servers'
export const CLOUD_SECTIONS = ['deployment', 'specification', 'network', 'storage', 'purchase'] as const
export type CloudSection = typeof CLOUD_SECTIONS[number]
export const cloudSectionPath = (section: CloudSection) => `${CLOUD_CONFIGURATION_ROOT}/${section}`
export const cloudSectionPermission = (section: CloudSection) => `admin.cloud-configuration.${section}.view`
export function cloudSectionFromPath(pathname: string): CloudSection | undefined {
  return CLOUD_SECTIONS.find(section => cloudSectionPath(section) === pathname)
}
