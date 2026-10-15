import { cloneElement, isValidElement, useEffect, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { Select } from '@arco-design/web-react'
import type { PaginationProps } from '@arco-design/web-react'
import { DEFAULT_TABLE_PAGE_SIZE, TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination'
import './ResponsiveTablePagination.css'

interface ResponsiveTablePaginationProps {
  /** Table 通过公开 renderPagination 插槽提供的原生分页器。 */
  paginationNode?: ReactNode
}

/**
 * 根据表格实际可用宽度重排分页，保留 Table 的数据切片、受控页码及分页回调。
 * 窄屏将总条数和条数选择器放在首行，原生精简分页放在第二行。
 */
export default function ResponsiveTablePagination({ paginationNode }: ResponsiveTablePaginationProps) {
  const container = useRef<HTMLDivElement>(null)
  const [compact, setCompact] = useState(() => window.matchMedia('(max-width: 768px)').matches)

  useEffect(() => {
    const element = container.current
    if (!element) return
    const update = () => {
      const width = element.getBoundingClientRect().width
      if (width > 0) setCompact(width < 640)
    }
    update()
    const observer = new ResizeObserver(update)
    observer.observe(element)
    return () => observer.disconnect()
  }, [])

  if (!isValidElement<PaginationProps>(paginationNode)) return paginationNode
  const props = paginationNode.props
  const total = props.total ?? 0
  const pageSize = props.pageSize ?? DEFAULT_TABLE_PAGE_SIZE
  const current = props.current ?? 1
  const hide = props.hideOnSinglePage && total <= pageSize

  function changePageSize(size: number) {
    const nextPage = props.pageSizeChangeResetCurrent === false
      ? Math.min(current, Math.max(1, Math.ceil(total / size))) : 1
    // 与原生 Pagination 的条数切换保持相同回调顺序，Table 继续负责内部状态。
    props.onPageSizeChange?.(size, nextPage)
    props.onChange?.(nextPage, size)
  }

  const totalText = typeof props.showTotal === 'function'
    ? props.showTotal(total, [total ? (current - 1) * pageSize + 1 : 0, Math.min(current * pageSize, total)])
    : `共 ${total} 条`

  return <div ref={container} className="responsive-table-pagination" hidden={hide}>
    {compact ? <div className="responsive-table-pagination-compact">
      {(props.showTotal || props.sizeCanChange) && <div className="responsive-table-pagination-summary">
        <span className="responsive-table-pagination-total">{props.showTotal ? totalText : null}</span>
        {props.sizeCanChange && <Select {...props.selectProps} aria-label="每页条数" size="small"
          value={pageSize} disabled={props.disabled || props.selectProps?.disabled}
          options={(props.sizeOptions ?? TABLE_PAGE_SIZE_OPTIONS).map((size) => ({ label: `${size} 条/页`, value: size }))}
          style={{ ...props.selectProps?.style, width: 112, flexShrink: 0 }} onChange={changePageSize} />}
      </div>}
      <div className="responsive-table-pagination-controls">
        {cloneElement(paginationNode, { simple: true, showTotal: false, sizeCanChange: false, showJumper: true, size: 'small' })}
      </div>
    </div> : paginationNode}
  </div>
}
