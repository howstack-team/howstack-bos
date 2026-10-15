import { Image } from '@arco-design/web-react'
import { IconImage } from '@arco-design/web-react/icon'

interface Props { name: string; src?: string }

/** 主题图片展示位；图片公开地址由宿主传入，未提供地址时不猜测主题资源路径。 */
export default function WebsiteThemeImage({ name, src }: Props) {
  const empty = <div className="website-theme-image-empty" role="img" aria-label={`${name}暂无主题图片`}>
    <IconImage fontSize={24} /><span>暂无主题图片</span>
  </div>
  return <div className="website-theme-image">
    {src ? <Image preview={false} src={src} alt={`${name}主题图片`} width="100%" height="auto" error={empty} /> : empty}
  </div>
}
