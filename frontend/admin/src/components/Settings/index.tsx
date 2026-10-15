import React, { useState } from 'react';
import { Drawer, Alert, Message } from '@arco-design/web-react';
import { IconSettings } from '@arco-design/web-react/icon';
import copy from 'copy-to-clipboard';
import { useSelector } from 'react-redux';
import { GlobalState } from '../../store';
import Block from './block';
import ColorPanel from './color';
import IconButton from '../NavBar/IconButton';
import useLocale from '@/utils/useLocale';
import LayoutSelector from '../../layouts/LayoutSelector';

interface SettingProps {
  trigger?: React.ReactElement;
}

function Setting(props: SettingProps) {
  const { trigger } = props;
  const [visible, setVisible] = useState(false);
  const locale = useLocale();
  const settings = useSelector((state: GlobalState) => state.settings);

  function onCopySettings() {
    copy(JSON.stringify(settings, null, 2));
    Message.success(locale['settings.copySettings.message']);
  }

  return (
    <>
      {trigger ? (
        React.cloneElement(trigger as React.ReactElement, {
          onClick: () => setVisible(true),
        })
      ) : (
        <IconButton aria-label="页面配置" icon={<IconSettings />} onClick={() => setVisible(true)} />
      )}
      <Drawer maskClosable
        width={300}
        title={
          <>
            <IconSettings />
            {locale['settings.title']}
          </>
        }
        visible={visible}
        okText={locale['settings.copySettings']}
        cancelText={locale['settings.close']}
        onOk={onCopySettings}
        onCancel={() => setVisible(false)}
      >
        <Block title={locale['settings.themeColor']}>
          <ColorPanel />
        </Block>
        <Block title={locale['settings.layout']}>
          <LayoutSelector />
        </Block>
        <Block
          title={locale['settings.content']}
          options={[
            { name: 'settings.navbar', value: 'navbar' },
            { name: 'settings.menu', value: 'menu' },
            { name: 'settings.footer', value: 'footer' },
            settings.layout === 'double-column'
              ? { name: 'settings.menuWidth', value: 'menuWidth', type: 'number' }
              : { name: 'settings.classicMenuWidth', value: 'classicMenuWidth', type: 'number' },
          ]}
        />
        <Block
          title={locale['settings.otherSettings']}
          options={[
            { name: 'settings.colorWeek', value: 'colorWeek' },
            { name: 'settings.watermark', value: 'watermark' },
          ]}
        />
        <Alert content={locale['settings.alertContent']} />
      </Drawer>
    </>
  );
}

export default Setting;
