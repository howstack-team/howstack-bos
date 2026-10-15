import type { ReactNode } from 'react'
import './SectionTitle.css'

/** 配置分组标题栏；浅灰横条配主题色竖线，保持宿主标题字号与语义。 */
export default function SectionTitle({ children }: { children: ReactNode }) {
  return <span className="section-title">{children}</span>
}
