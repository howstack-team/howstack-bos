import { Radio } from '@arco-design/web-react';
import { useDispatch, useSelector } from 'react-redux';
import type { GlobalState } from '../store';
import useLocale from '../utils/useLocale';
import { LAYOUT_STORAGE_KEY, normalizeLayoutVariant } from './preferences';

/** 页面配置内的布局切换入口，保存当前浏览器偏好。 */
export default function LayoutSelector() {
  const settings = useSelector((state: GlobalState) => state.settings);
  const dispatch = useDispatch();
  const locale = useLocale();
  return <Radio.Group type="button" size="small" aria-label="后台布局" value={normalizeLayoutVariant(settings.layout)}
    options={[
      { label: locale['settings.layout.classic'], value: 'classic' },
      { label: locale['settings.layout.doubleColumn'], value: 'double-column' },
    ]} onChange={value => {
      const variant = normalizeLayoutVariant(value);
      dispatch({ type: 'update-settings', payload: { settings: { ...settings, layout: variant } } });
      try {
        localStorage.setItem(LAYOUT_STORAGE_KEY, variant);
      } catch {
        // 存储不可用时，布局仍在当前页面生效。
      }
    }} />;
}
