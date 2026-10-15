import { useMemo, useState } from 'react'
import type { TableProps } from '@arco-design/web-react'
import { useHistory, useLocation } from 'react-router-dom'
import { DEFAULT_TABLE_PAGE_SIZE, TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination'

interface TableQueryOptions {
  textFields?: readonly string[]
  statusValues?: readonly (string | number)[]
  typeValues?: readonly string[]
  kindValues?: readonly string[]
  sortFields?: readonly string[]
  enumFields?: Readonly<Record<string, readonly (string | number)[]>>
}
type Filters = { keyword: string; fields: Record<string, string>; status?: string; type?: string; kind?: string }

/** 列表的待填写条件与已应用 URL 状态；查询/重置归第一页，分页与浏览器返回可恢复。 */
export default function useTableQuery(options: TableQueryOptions = {}) {
  const history = useHistory()
  const location = useLocation()
  const definitions = JSON.stringify(options)
  const applied = useMemo(() => {
    const config = JSON.parse(definitions) as TableQueryOptions
    const params = new URLSearchParams(location.search)
    const allowed = (key: string, values?: readonly (string | number)[]) => {
      const value = params.get(key)
      return value !== null && values?.some(item => String(item) === value) ? value : undefined
    }
    const number = (key: string, fallback: number) => {
      const raw = params.get(key)
      const value = raw && /^\d+$/.test(raw) ? Number(raw) : NaN
      return Number.isSafeInteger(value) && value > 0 ? value : fallback
    }
    const pageSize = number('page_size', DEFAULT_TABLE_PAGE_SIZE)
    const sortBy = allowed('sort_by', config.sortFields)
    const sortOrder = sortBy ? allowed('sort_order', ['asc', 'desc']) ?? 'asc' : undefined
    const enums = Object.fromEntries(Object.entries(config.enumFields ?? []).map(([key, values]) => [key, allowed(key, values)]).filter(([, value]) => value !== undefined)) as Record<string, string>
    return {
      sortBy, sortOrder, enums,
      fields: Object.fromEntries((config.textFields ?? []).map(key => [key, params.get(key) ?? '']).filter(([, value]) => value !== '')),
      keyword: params.get('keyword') ?? '', status: allowed('status', config.statusValues),
      type: allowed('type', config.typeValues), kind: allowed('kind', config.kindValues),
      page: Math.min(number('page', 1), 100000), pageSize: TABLE_PAGE_SIZE_OPTIONS.includes(pageSize) ? pageSize : DEFAULT_TABLE_PAGE_SIZE,
    }
  }, [location.search, definitions])
  const queryParams = useMemo(() => ({ ...applied.fields, ...applied.enums,
    ...(applied.status === undefined ? {} : { status: applied.status }),
    ...(applied.type === undefined ? {} : { type: applied.type }),
    ...(applied.kind === undefined ? {} : { kind: applied.kind }),
    ...(applied.sortBy ? { sort_by: applied.sortBy, sort_order: applied.sortOrder! } : {}),
  }), [applied])
  const [draft, setDraft] = useState<{ search: string; filters: Filters }>(() => ({ search: location.search, filters: applied }))
  const [refreshKey, setRefreshKey] = useState(0)
  const filters = draft.search === location.search ? draft.filters : applied
  const setFilter = (key: 'keyword' | 'status' | 'type' | 'kind', value: string | number | undefined) => {
    setDraft({ search: location.search, filters: { ...filters, [key]: value === undefined ? undefined : String(value) } })
  }
  const update = (values: Record<string, string | number | undefined>) => {
    const current = history.location
    const params = new URLSearchParams(current.search)
    Object.entries(values).forEach(([key, value]) => {
      if (value === undefined || value === '') params.delete(key)
      else params.set(key, String(value))
    })
    const search = params.toString() ? `?${params.toString()}` : ''
    if (search !== current.search) history.push({ ...current, search })
  }
  const apply = (values: Filters, resetting = false) => {
    const normalized = { ...values, keyword: values.keyword.trim(), fields: Object.fromEntries(Object.entries(values.fields).map(([key, value]) => [key, value.trim()])) }
    const params: Record<string, string | number | undefined> = { keyword: normalized.keyword, page: 1 }
    if (options.textFields) {
      params.keyword = undefined
      options.textFields.forEach(key => { params[key] = normalized.fields[key] })
    }
    if (options.statusValues) params.status = normalized.status
    if (options.typeValues) params.type = normalized.type
    if (options.kindValues) params.kind = normalized.kind
    if (resetting) {
      params.sort_by = undefined; params.sort_order = undefined
      Object.keys(options.enumFields ?? {}).forEach(key => { params[key] = undefined })
    }
    update(params)
    setDraft({ search: history.location.search, filters: normalized })
    setRefreshKey(value => value + 1)
  }
  const columnOptions = {
    ...(options.enumFields ?? {}),
    ...(options.statusValues ? { status: options.statusValues } : {}),
    ...(options.typeValues ? { type: options.typeValues } : {}),
    ...(options.kindValues ? { kind: options.kindValues } : {}),
  }
  const onTableChange: NonNullable<TableProps<unknown>['onChange']> = (_pagination, sorter, columnFilters, extra) => {
    if (extra.action === 'paginate') return
    const values: Record<string, string | number | undefined> = { page: 1 }
    if (extra.action === 'sort') {
      const sort = Array.isArray(sorter) ? sorter[0] : sorter
      const field = sort?.field === undefined ? undefined : String(sort.field)
      const accepted = field && options.sortFields?.includes(field) && sort.direction
      values.sort_by = accepted ? field : undefined
      values.sort_order = accepted ? sort.direction === 'ascend' ? 'asc' : 'desc' : undefined
    } else {
      Object.entries(columnOptions).forEach(([key, choices]) => {
        const value = columnFilters[key]?.[0]
        values[key] = value !== undefined && choices.some(choice => String(choice) === String(value)) ? value : undefined
      })
    }
    update(values)
    // 表头直接应用条件，同步查询区；不意外提交尚在填写的其他输入。
    setDraft({ search: '', filters: applied })
  }
  return {
    queryParams, onTableChange,
    sortColumn: (key: string) => ({ key, dataIndex: key, sorter: true,
      sortOrder: applied.sortBy === key ? applied.sortOrder === 'asc' ? 'ascend' as const : 'descend' as const : undefined }),
    filterColumn: (key: string, choices: Readonly<Record<string, string>>) => ({ key, dataIndex: key,
      filters: Object.entries(choices).map(([value, text]) => ({ value, text })), filterMultiple: false,
      filteredValue: queryParams[key] === undefined ? [] : [queryParams[key]] }),
    draftFields: filters.fields,
    setField: (key: string, value: string) => {
      if (!options.textFields?.includes(key)) return
      setDraft({ search: location.search, filters: { ...filters, fields: { ...filters.fields, [key]: value } } })
    },
    /** 同一控件同时填写多个字段（如日期范围），避免连续 setField 使用旧状态。 */
    setFields: (values: Record<string, string>) => {
      const accepted = Object.fromEntries(Object.entries(values).filter(([key]) => options.textFields?.includes(key)))
      setDraft({ search: location.search, filters: { ...filters, fields: { ...filters.fields, ...accepted } } })
    },
    discard: () => setDraft({ search: location.search, filters: applied }),
    ...applied, input: filters.keyword, draftStatus: filters.status, draftType: filters.type, draftKind: filters.kind,
    setInput: (value: string) => setFilter('keyword', value),
    setDraftStatus: (value: string | number | undefined) => setFilter('status', value),
    setDraftType: (value: string | undefined) => setFilter('type', value),
    setDraftKind: (value: string | undefined) => setFilter('kind', value),
    search: () => apply(filters), reset: () => apply({ keyword: '', fields: {} }, true), refreshKey,
    setPage: (page: number) => update({ page }),
    setPageSize: (size: number) => update({ page: 1, page_size: size }),
  }
}
