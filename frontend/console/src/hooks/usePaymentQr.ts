import { useEffect, useState } from 'react'
import QRCode from 'qrcode'

/** 本地生成PNG支付码，不向第三方图片服务发送付款链接。 */
export function usePaymentQr(content: string | null | undefined) {
  const [image, setImage] = useState<{ content: string; source: string } | null>(null)
  useEffect(() => {
    let alive = true
    if (content) void QRCode.toDataURL(content, { width: 240, margin: 2, errorCorrectionLevel: 'M' }).then(value => { if (alive) setImage({ content, source: value }) }).catch(() => { if (alive) setImage(null) })
    return () => { alive = false }
  }, [content])
  return content && image?.content === content ? image.source : ''
}
