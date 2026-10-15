import type { ILineChartSpec } from '@visactor/vchart/esm/chart/line'
import type { IPieChartSpec } from '@visactor/vchart/esm/chart/pie'
import type { BusinessAnalysis } from '../api/businessAnalysisApi'
import { ORDER_STATUSES } from './businessEnums'
import { formatMoneyMinor } from './money'

/** 实付、退款和净实付使用同一人民币元坐标轴，负净实付不截断为零。 */
export function analysisTrendSpec(data: BusinessAnalysis): ILineChartSpec {
  const fields = [ ['订单实付', 'paid_amount_minor'], ['已完成退款', 'refund_amount_minor'], ['净实付', 'net_paid_amount_minor'] ] as const
  const values = data.trend.flatMap(point => fields.map(([series, key]) => ({ date: point.date, series, amount_minor: point[key], value: point[key] / 100 })))
  const tooltipValue = (datum?: Record<string, unknown>) => typeof datum?.amount_minor === 'number' ? formatMoneyMinor(datum.amount_minor) : '-'
  return { type: 'line', data: { id: 'trend', values }, xField: 'date', yField: 'value', seriesField: 'series',
    animation: false, background: 'transparent', color: ['#165DFF', '#FF7D00', '#00B42A'],
    line: { style: { lineWidth: 2 } }, point: { visible: data.trend.length <= 31 },
    legends: [{ visible: true, orient: 'bottom', position: 'middle', item: { shape: { style: { symbolType: 'circle' } } } }],
    axes: [{ orient: 'bottom', type: 'band', label: { autoHide: true, autoRotate: false } },
      { orient: 'left', type: 'linear', title: { visible: true, text: '金额（元）' }, label: { formatMethod: value => Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 2 }) } }],
    crosshair: { xField: { visible: true, line: { type: 'line' } } },
    tooltip: { mark: { title: { value: datum => String(datum?.date ?? '') }, content: [{ key: datum => String(datum?.series ?? ''), value: tooltipValue }] },
      dimension: { title: { value: datum => String(datum?.date ?? '') }, content: [{ key: datum => String(datum?.series ?? ''), value: tooltipValue }] } },
  }
}
/** 展示本期创建订单在查询时的状态分布，中文枚举与业务列表一致。 */
export function analysisStatusSpec(data: BusinessAnalysis): IPieChartSpec {
  return { type: 'pie', data: { id: 'statuses', values: data.order_statuses.filter(row => row.count > 0).map(row => ({
    name: ORDER_STATUSES[row.status]?.text ?? row.status, count: row.count,
  })) }, valueField: 'count', categoryField: 'name', innerRadius: 0.64, outerRadius: 0.85,
    animation: false, background: 'transparent', label: { visible: false },
    color: ['#165DFF', '#14C9C9', '#FF7D00', '#86909C', '#F7BA1E', '#722ED1', '#F53F3F'],
    legends: [{ visible: true, orient: 'bottom', position: 'middle' }],
  }
}
