import React, { ReactNode } from 'react';
import { Switch, Divider, InputNumber } from '@arco-design/web-react';
import { useSelector, useDispatch } from 'react-redux';
import { GlobalState } from '../../store';
import useLocale from '../../utils/useLocale';
import styles from './style/block.module.less';

export interface BlockProps {
  title?: ReactNode;
  options?: { name: string; value: string; type?: 'switch' | 'number' }[];
  children?: ReactNode;
}

export default function Block(props: BlockProps) {
  const { title, options, children } = props;
  const locale = useLocale();
  const settings = useSelector((state: GlobalState) => state.settings);
  const dispatch = useDispatch();

  return (
    <div className={styles.block}>
      <h5 className={styles.title}>{title}</h5>
      {options &&
        options.map((option) => {
          const type = option.type || 'switch';

          return (
            <div className={styles['switch-wrapper']} key={option.value}>
              <span>{locale[option.name]}</span>
              {type === 'switch' && (
                <Switch type="round"
                  size="small"
                  checked={!!settings[option.value]}
                  onChange={(checked) => {
                    const newSetting = {
                      ...settings,
                      [option.value]: checked,
                    };
                    dispatch({
                      type: 'update-settings',
                      payload: { settings: newSetting },
                    });
                    if (option.value === 'watermark') {
                      try {
                        localStorage.setItem('admin-watermark-enabled', String(checked));
                      } catch {
                        // 浏览器禁用本地存储时，本次会话的开关仍可正常使用。
                      }
                    }
                    // set color week
                    if (checked && option.value === 'colorWeek') {
                      document.body.style.filter = 'invert(80%)';
                    }
                    if (!checked && option.value === 'colorWeek') {
                      document.body.style.filter = 'none';
                    }
                  }}
                />
              )}
              {type === 'number' && (
                <InputNumber
                  mode="button"
                  precision={0}
                  suffix={option.value === 'menuWidth' || option.value === 'classicMenuWidth' ? 'px' : undefined}
                  placeholder={`请输入${locale[option.name]}`}
                  aria-label={locale[option.name]}
                  style={{ width: 140 }}
                  size="small"
                  min={option.value === 'menuWidth' ? 160 : option.value === 'classicMenuWidth' ? 180 : undefined}
                  value={settings[option.value]}
                  onChange={(value) => {
                    const newSetting = {
                      ...settings,
                      [option.value]: value,
                    };
                    dispatch({
                      type: 'update-settings',
                      payload: { settings: newSetting },
                    });
                  }}
                />
              )}
            </div>
          );
        })}
      {children}
      <Divider />
    </div>
  );
}
