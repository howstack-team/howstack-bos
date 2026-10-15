/** 列表排序参数；各接口在后端按业务字段白名单校验。 */
export interface TableSortQuery {
  sort_by?: string
  sort_order?: 'asc' | 'desc'
}
