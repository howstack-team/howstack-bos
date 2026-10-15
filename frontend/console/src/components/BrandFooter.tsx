import { useConsoleTheme } from '../ConsoleThemeContext'
import './BrandFooter.css'

/** 登录、注册和登录后页面共用后台维护的公开版权及备案；空值不补造文案。 */
export default function BrandFooter() {
  const site = useConsoleTheme()?.site
  if (!site) return null
  return <div className="console-brand-footer">
    {site.copyright && <span>{site.copyright}</span>}
    {site.icp_record && <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer">{site.icp_record}</a>}
    {site.public_security_record && <a href="https://beian.mps.gov.cn/" target="_blank" rel="noopener noreferrer">{site.public_security_record}</a>}
  </div>
}
