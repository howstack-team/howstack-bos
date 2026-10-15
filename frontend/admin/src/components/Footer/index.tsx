import React from 'react';
import { Layout } from '@arco-design/web-react';
import { FooterProps } from '@arco-design/web-react/es/Layout/interface';
import cs from 'classnames';
import styles from './style/index.module.less';

/** 页面页脚；不传 children 时展示系统名称，登录页等入口可覆盖展示文案。 */
function Footer(props: FooterProps = {}) {
  const { className, children = '皓量云擎云业务运营系统', ...restProps } = props;
  return (
    <Layout.Footer className={cs(styles.footer, className)} {...restProps}>
      {children}
    </Layout.Footer>
  );
}

export default Footer;
