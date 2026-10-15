import { useEffect, useRef, useState } from 'react'
import { Alert } from '@arco-design/web-react'
import Player from 'xgplayer'
import MusicPreset from 'xgplayer-music'
import 'xgplayer/dist/index.min.css'
import 'xgplayer-music/dist/index.min.css'
import type { FileAsset } from '../api/filesApi'
import FileTypeIcon from './FileTypeIcon'
import './MediaPreview.css'

/** 原始地址通过签名跳转读取；不自动播放、不缓存下一首、不输出厂商错误。 */
export default function MediaPreviewBody({ file }: { file: FileAsset }) {
  const host = useRef<HTMLDivElement>(null)
  const [error, setError] = useState('')
  const audio = file.type === 'audio'

  useEffect(() => {
    if (!host.current || !file.url) return
    const player = new Player({
      el: host.current, url: file.url, lang: 'zh-cn', autoplay: false, loop: false,
      playsinline: true, preload: 'metadata', width: '100%', height: '100%',
      fluid: false, volume: 0.6, closeVideoClick: audio, closeVideoDblclick: true,
      controls: { mode: 'flex', initShow: true, autoHide: !audio },
      commonStyle: { playedColor: 'rgb(var(--primary-6))', sliderBtnStyle: { backgroundColor: 'rgb(var(--primary-6))' },
        ...(audio ? { progressColor: 'var(--color-fill-3)', cachedColor: 'var(--color-fill-4)', volumeColor: 'rgb(var(--primary-6))' } : {}) },
      // 文件名由 React 展示，禁用会将标题写入 innerHTML 的 musicmeta 插件。
      ignores: audio ? ['musicprev', 'musicnext', 'musicbackward', 'musicforward', 'musiccover', 'musicmeta', 'playbackrate', 'download', 'keyboard', 'error'] : ['download', 'keyboard', 'error'],
      ...(audio ? { presets: ['default', MusicPreset], mediaType: 'audio',
        music: { list: [{ src: file.url, vid: file.id }], mode: 'order', preloadNext: false } } : {}),
    })
    player.on('error', () => setError('无法播放此文件，请检查文件内容或下载后查看。'))
    return () => {
      player.pause()
      player.destroy()
    }
  }, [file, audio])

  return <div className={`media-preview${audio ? ' media-preview-audio' : ''}`}>
    {audio && <div className="media-preview-cover"><FileTypeIcon extension="mp3" type="audio" /></div>}
    <div ref={host} className="media-preview-player" />
    {error && <Alert className="media-preview-error" type="warning" content={error} />}
  </div>
}
