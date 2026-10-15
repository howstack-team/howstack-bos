import { useContext, useEffect, useRef, useState } from 'react'
import { Alert } from '@arco-design/web-react'
import { VChart, type ISpec } from '@visactor/vchart/esm/core'
import { registerLineChart } from '@visactor/vchart/esm/chart/line'
import { registerPieChart } from '@visactor/vchart/esm/chart/pie'
import { registerCartesianBandAxis, registerCartesianLinearAxis } from '@visactor/vchart/esm/component/axis/cartesian'
import { registerDiscreteLegend } from '@visactor/vchart/esm/component/legend/discrete'
import { registerTooltip } from '@visactor/vchart/esm/component/tooltip'
import { registerDomTooltipHandler } from '@visactor/vchart/esm/plugin/components/tooltip-handler'
import { registerCartesianCrossHair } from '@visactor/vchart/esm/component/crosshair/cartesian'
import { GlobalContext } from '../context'

[registerLineChart, registerPieChart, registerCartesianBandAxis, registerCartesianLinearAxis,
  registerDiscreteLegend, registerTooltip, registerDomTooltipHandler, registerCartesianCrossHair].forEach(register => register())
interface AnalysisChartProps { spec: ISpec; label: string }

/** 按需注册经营分析所需的 VChart 图形；容器自适应、跟随主题并在更新或卸载时释放实例。 */
export default function AnalysisChart({ spec, label }: AnalysisChartProps) {
  const host = useRef<HTMLDivElement>(null)
  const { theme } = useContext(GlobalContext)
  const [failure, setFailure] = useState<{ spec: ISpec; theme: string; message: string } | null>(null)
  const error = failure?.spec === spec && failure.theme === theme ? failure.message : ''
  useEffect(() => {
    if (!host.current) return
    const element = host.current
    let active = true
    let chart: VChart | undefined
    element.dataset.rendered = 'false'
    void Promise.resolve().then(() => {
      if (!active) return
      chart = new VChart(spec, { dom: element, autoFit: true, animation: false, poptip: false, theme: theme === 'dark' ? 'dark' : 'light' })
      return chart.renderAsync()
    }).then(() => {
      if (active) { element.dataset.rendered = 'true'; setFailure(null) }
    }).catch(() => {
      if (active) setFailure({ spec, theme, message: '图表加载失败，请查看下方数据或刷新重试' })
    })
    return () => { active = false; chart?.release() }
  }, [spec, theme])
  return <div className="analysis-chart-wrapper">
    {error && <Alert type="error" content={error} />}
    <div ref={host} className="analysis-chart" role="img" aria-label={label} />
  </div>
}
