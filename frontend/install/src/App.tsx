import { cloneElement, isValidElement, useEffect, useMemo, useRef, useState } from 'react'
import type { CSSProperties, ReactNode } from 'react'
import {
  Alert, Button, Card, Checkbox, ConfigProvider, Descriptions,
  Divider, Form, Grid, Input, InputNumber, Link, List, Message,
  Progress, Result, Space, Spin, Steps, Table, Tag, Typography,
} from '@arco-design/web-react'
import { IconCheckCircleFill, IconCloseCircleFill, IconMinusCircleFill } from '@arco-design/web-react/icon'
import type { ColumnProps } from '@arco-design/web-react/es/Table'
import zhCN from '@arco-design/web-react/es/locale/zh-CN'
import {
  executePhase,
  loadBootstrap,
  loadRandomDefaults,
  testConnections,
} from './api'
import type {
  BootstrapData,
  ConnectionResult,
  EnvironmentItem,
  FinalResult,
  InstallationConfiguration,
  InstallPhase,
} from './types'
import logoUrl from './assets/logo-white.png'
import mysqlIconUrl from './assets/mysql.svg'
import redisIconUrl from './assets/redis.svg'

type PhaseStatus = 'wait' | 'process' | 'finish' | 'error'

interface PhaseItem {
  key: InstallPhase
  title: string
  description: string
  status: PhaseStatus
}

const phaseDefinitions: Array<Omit<PhaseItem, 'status'>> = [
  { key: 'database', title: '初始化数据库', description: '验证连接并创建基础表结构' },
  { key: 'system', title: '写入系统数据', description: '保存网站配置并创建管理员账号' },
  { key: 'configuration', title: '生成运行配置', description: '安全写入系统运行配置' },
  { key: 'finalize', title: '完成安装', description: '写入安装标记并刷新运行配置' },
]

const { Paragraph, Text, Title } = Typography
const { Row, Col } = Grid

function InstallerContent() {
  const [form] = Form.useForm<InstallationConfiguration, unknown, string>()
  const [viewportWidth, setViewportWidth] = useState(window.innerWidth)
  const screens = { sm: viewportWidth >= 576, md: viewportWidth >= 768, lg: viewportWidth >= 992 }
  const [current, setCurrent] = useState(0)
  const [bootstrap, setBootstrap] = useState<BootstrapData | null>(null)
  const [installationKey, setInstallationKey] = useState('')
  const [bootError, setBootError] = useState('')
  const [loading, setLoading] = useState(true)
  const [testing, setTesting] = useState(false)
  const [connectionResult, setConnectionResult] = useState<ConnectionResult | null>(null)
  const [progress, setProgress] = useState(0)
  const [installError, setInstallError] = useState('')
  const [finalResult, setFinalResult] = useState<FinalResult | null>(null)
  const installing = useRef(false)
  const [phases, setPhases] = useState<PhaseItem[]>(
    phaseDefinitions.map((phase) => ({ ...phase, status: 'wait' })),
  )
  const agreementAgreed = Form.useWatch('agreement_agreed', form) ?? false

  const stepItems = useMemo(
    () => [
      { title: <><span className="step-title-full">使用协议</span><span className="step-title-short">协议</span></> },
      { title: <><span className="step-title-full">环境检测</span><span className="step-title-short">检测</span></> },
      { title: <><span className="step-title-full">数据库配置</span><span className="step-title-short">数据库</span></> },
      { title: <><span className="step-title-full">系统配置</span><span className="step-title-short">系统</span></> },
      { title: <><span className="step-title-full">执行安装</span><span className="step-title-short">安装</span></> },
      { title: <><span className="step-title-full">安装结果</span><span className="step-title-short">结果</span></> },
    ],
    [],
  )

  useEffect(() => {
    void initialize()
    const handleResize = () => setViewportWidth(window.innerWidth)
    window.addEventListener('resize', handleResize)
    return () => window.removeEventListener('resize', handleResize)
  }, [])

  async function initialize(key = '') {
    setLoading(true)
    setBootError('')
    try {
      const data = await loadBootstrap(key)
      setBootstrap(data)
      form.setFieldsValue({
        agreement_agreed: false,
        mysql: {
          host: '127.0.0.1',
          port: 3306,
          database: '',
          username: 'root',
          password: '',
        },
        redis: {
          host: '127.0.0.1',
          port: 6379,
          password: '',
          database: 0,
        },
        system: {
          site_title: '皓量云擎云业务运营系统',
          site_url: window.location.origin,
          admin_path: data.defaults.admin_path,
        },
        admin: {
          nickname: '超级管理员',
          username: data.defaults.admin_username,
          password: data.defaults.admin_password,
        },
      })
    } catch (error) {
      setBootError(error instanceof Error ? error.message : '安装器初始化失败')
    } finally {
      setLoading(false)
    }
  }

  async function refreshEnvironment() {
    setLoading(true)
    try {
      const data = await loadBootstrap()
      setBootstrap((currentData) =>
        currentData ? { ...currentData, environment: data.environment, token: data.token } : data,
      )
      if (data.environment.passed) {
        void Message.success('环境检测已通过')
      } else {
        void Message.warning('仍有必需项目未通过')
      }
    } catch (error) {
      void Message.error(error instanceof Error ? error.message : '环境检测失败')
    } finally {
      setLoading(false)
    }
  }

  async function handleRandom(target: 'path' | 'username' | 'password') {
    try {
      const data = await loadRandomDefaults()
      if (target === 'path') {
        form.setFieldValue('system.admin_path', data.admin_path)
        return
      }
      form.setFieldValue(
        `admin.${target}`,
        target === 'username' ? data.admin_username : data.admin_password,
      )
    } catch (error) {
      void Message.error(error instanceof Error ? error.message : '随机生成失败')
    }
  }

  async function handleTestConnections(showSuccess = true): Promise<boolean> {
    try {
      await form.validate([
        'mysql.host',
        'mysql.port',
        'mysql.database',
        'mysql.username',
        'redis.host',
        'redis.port',
        'redis.database',
      ])
    } catch {
      return false
    }

    setTesting(true)
    try {
      // getFields 保留已卸载步骤的值；getFieldsValue 只收集当前注册字段。
      const values = form.getFields() as InstallationConfiguration
      const result = await testConnections({ mysql: values.mysql, redis: values.redis })
      setConnectionResult(result)
      if (showSuccess) {
        void Message.success('MySQL 与 Redis 连接正常')
      }
      return true
    } catch (error) {
      setConnectionResult(null)
      void Message.error(error instanceof Error ? error.message : '连接测试失败')
      return false
    } finally {
      setTesting(false)
    }
  }

  async function next() {
    if (current === 0) {
      if (!agreementAgreed) {
        void Message.warning('请先阅读并同意软件使用协议')
        return
      }
      setCurrent(1)
      return
    }

    if (current === 1) {
      if (!bootstrap?.environment.passed) {
        void Message.warning('请先修复未通过的必需环境项')
        return
      }
      setCurrent(2)
      return
    }

    if (current === 2) {
      if (!connectionResult) {
        void Message.warning('请先测试 MySQL 与 Redis 连接')
        return
      }
      setCurrent(3)
      return
    }

    if (current === 3) {
      try {
        await form.validate()
      } catch {
        return
      }
      await runInstallation(form.getFields() as InstallationConfiguration)
    }
  }

  async function runInstallation(configuration: InstallationConfiguration) {
    if (installing.current) return
    installing.current = true
    setCurrent(4)
    setProgress(5)
    setInstallError('')
    setFinalResult(null)
    setPhases(phaseDefinitions.map((phase) => ({ ...phase, status: 'wait' })))

    for (let index = 0; index < phaseDefinitions.length; index += 1) {
      const phase = phaseDefinitions[index]
      setPhases((items) =>
        items.map((item, itemIndex) => ({
          ...item,
          status: itemIndex < index ? 'finish' : itemIndex === index ? 'process' : 'wait',
        })),
      )

      try {
        const result = await executePhase(phase.key, configuration)
        setProgress(result.progress)
        setPhases((items) =>
          items.map((item) =>
            item.key === phase.key
              ? { ...item, status: 'finish', description: result.message }
              : item,
          ),
        )

        if (result.result) {
          setFinalResult({
            ...result.result,
            admin_password: configuration.admin.password,
          })
        }
      } catch (error) {
        const errorMessage = error instanceof Error ? error.message : '安装执行失败'
        setPhases((items) =>
          items.map((item) =>
            item.key === phase.key
              ? { ...item, status: 'error', description: errorMessage }
              : item,
          ),
        )
        setInstallError(errorMessage)
        setCurrent(5)
        installing.current = false
        return
      }
    }

    setCurrent(5)
    installing.current = false
  }

  async function copy(value: string, label: string) {
    try {
      await navigator.clipboard.writeText(value)
      void Message.success(`${label}已复制`)
    } catch {
      void Message.error('复制失败，请手动选择文本')
    }
  }

  function copyAll() {
    if (!finalResult) return
    const text = [
      `首页：${finalResult.home_url}`,
      `后台：${finalResult.admin_url}`,
      `管理员账号：${finalResult.admin_username}`,
      `管理员密码：${finalResult.admin_password}`,
    ].join('\n')
    void copy(text, '全部安装信息')
  }

  function copyableValue(value: string, secret = false): ReactNode {
    return (
      <Text
        className={`result-value${secret ? ' secret-value' : ''}`}
        copyable={{ text: value, tooltips: ['复制', '复制成功'] }}
      >
        {value}
      </Text>
    )
  }

  function renderAgreement() {
    return (
      <div className="step-panel">
        <div className="section-heading">
          <Title heading={4}>{bootstrap?.agreement.title}</Title>
          <Paragraph type="secondary">
            请完整阅读宁波皓量云擎网络科技有限公司的软件使用协议。继续安装即表示你理解并接受协议条款。
          </Paragraph>
        </div>
        <div className="agreement-content" tabIndex={0} aria-label="皓量云擎云业务运营系统软件使用协议">
          <pre>{bootstrap?.agreement.content}</pre>
        </div>
        <Form.Item field="agreement_agreed" triggerPropName="checked" className="agreement-field">
          <Checkbox>
            我已阅读并同意《皓量云擎云业务运营系统软件使用协议》（版本 {bootstrap?.agreement.version}）
          </Checkbox>
        </Form.Item>
      </div>
    )
  }

  function renderEnvironment() {
    const environment = bootstrap?.environment
    return (
      <div className="step-panel">
        <div className="section-heading with-action">
          <div>
            <Title heading={4}>环境检测</Title>
            <Paragraph type="secondary">检测服务器环境是否满足系统安装要求。</Paragraph>
          </div>
          <Button size="small" onClick={() => void refreshEnvironment()} loading={loading}>
            重新检测
          </Button>
        </div>
        <Table<EnvironmentItem>
          className="environment-table"
          size="small"
          border
          data={environment?.items ?? []}
          pagination={false}
          rowKey="key"
          tableLayoutFixed
          columns={([
            {
              key: 'item',
              title: '检测项目',
              render: (_, item) => (
                <div className="environment-name-cell">
                  <Space size={8} wrap>
                    <Text bold>{item.name}</Text>
                    {!item.required && <Text type="secondary">可选</Text>}
                  </Space>
                  <Text className="environment-current-mobile" type="secondary">
                    {item.value}
                  </Text>
                </div>
              ),
            },
            {
              key: 'current',
              title: '当前环境',
              dataIndex: 'value',
              width: 128,
            },
            {
              key: 'requirement',
              title: '要求',
              width: 168,
              render: (_, item) => (
                <Text>
                  {item.key === 'php_version'
                    ? 'PHP 8.4 或更高版本'
                    : item.required ? '必须安装或可用' : '非必需'}
                </Text>
              ),
            },
            {
              key: 'result',
              title: '检测结果',
              width: 112,
              align: 'center',
              render: (_, item) => (
                <Space size="mini">
                  {item.passed
                    ? <IconCheckCircleFill className="environment-result-success" aria-hidden="true" />
                    : item.required
                      ? <IconCloseCircleFill className="environment-result-error" aria-hidden="true" />
                      : <IconMinusCircleFill className="environment-result-warning" aria-hidden="true" />}
                  <span>{item.passed ? '通过' : item.required ? '未通过' : '可忽略'}</span>
                </Space>
              ),
            },
            {
              key: 'description',
              title: '说明',
              render: (_, item) => (
                <Text type="secondary">
                  {item.help || (item.passed ? '当前环境符合要求' : '请根据提示完成配置')}
                </Text>
              ),
            },
          ] as ColumnProps<EnvironmentItem>[]).filter((column) => (
            column.key !== 'current' || screens.sm
          ) && (column.key !== 'requirement' || screens.md)
            && (column.key !== 'description' || screens.lg))}
        />
        <Alert
          className="environment-summary"
          type={environment?.passed ? 'success' : 'warning'}
          showIcon
          content={environment?.passed
            ? '环境检测通过！当前服务器环境符合安装要求，可以继续下一步。'
            : '环境检测未通过，请修复必需项目后重新检测。'}
        />
      </div>
    )
  }

  function renderDatabase() {
    return (
      <div className="step-panel">
        <div className="section-heading">
          <Title heading={4}>数据库与缓存配置</Title>
          <Paragraph type="secondary">数据库需要提前创建，安装账号需具备建表和写入权限。</Paragraph>
        </div>

        <div className="form-section-grid">
          <Card
            size="small"
            title={(
              <Space size={8} align="center">
                <img
                  className="database-card-icon"
                  src={mysqlIconUrl}
                  alt=""
                  aria-hidden="true"
                  draggable={false}
                />
                <span>MySQL</span>
              </Space>
            )}
            className="form-section-card"
          >
            <Row gutter={16}>
              <Col xs={24} md={16}>
                <Form.Item field="mysql.host" label="主机地址" rules={[{ required: true, message: '请填写主机地址' }]}>
                  <Input placeholder="127.0.0.1" />
                </Form.Item>
              </Col>
              <Col xs={24} md={8}>
                <Form.Item field="mysql.port" label="端口" rules={[{ required: true, message: '请填写端口' }]}>
                  <InputNumber mode="button" precision={0} placeholder="请输入端口" min={1} max={65535} className="full-width" />
                </Form.Item>
              </Col>
              <Col span={24}>
                <Form.Item field="mysql.database" label="数据库名称" rules={[{ required: true, message: '请填写数据库名称' }]}>
                  <Input placeholder="请输入已创建的数据库名称" />
                </Form.Item>
              </Col>
              <Col span={24}>
                <Form.Item
                  field="mysql.username"
                  label="数据库账号"
                  rules={[{ required: true, message: '请填写数据库账号' }]}
                >
                  <Input
                    placeholder="请输入数据库账号"
                    id="mysql_account"
                    autoComplete="one-time-code"
                    data-1p-ignore="true"
                    data-lpignore="true"
                  />
                </Form.Item>
              </Col>
              <Col span={24}>
                <Form.Item field="mysql.password" label="密码" extra="未设置数据库密码时可留空。">
                  <Input.Password placeholder="请输入数据库密码（选填）" autoComplete="new-password" />
                </Form.Item>
              </Col>
            </Row>
          </Card>

          <Card
            size="small"
            title={(
              <Space size={8} align="center">
                <img
                  className="database-card-icon"
                  src={redisIconUrl}
                  alt=""
                  aria-hidden="true"
                  draggable={false}
                />
                <span>Redis</span>
              </Space>
            )}
            className="form-section-card"
          >
            <Row gutter={16}>
              <Col xs={24} md={16}>
                <Form.Item field="redis.host" label="主机地址" rules={[{ required: true, message: '请填写主机地址' }]}>
                  <Input placeholder="127.0.0.1" />
                </Form.Item>
              </Col>
              <Col xs={24} md={8}>
                <Form.Item field="redis.port" label="端口" rules={[{ required: true, message: '请填写端口' }]}>
                  <InputNumber mode="button" precision={0} placeholder="请输入端口" min={1} max={65535} className="full-width" />
                </Form.Item>
              </Col>
              <Col xs={24} md={16}>
                <Form.Item field="redis.password" label="密码" extra="Redis 未启用密码认证时可留空。">
                  <Input.Password placeholder="请输入 Redis 密码（选填）" autoComplete="new-password" />
                </Form.Item>
              </Col>
              <Col xs={24} md={8}>
                <Form.Item field="redis.database" label="数据库编号" rules={[{ required: true, message: '请填写数据库编号' }]}>
                  <InputNumber mode="button" precision={0} placeholder="请输入数据库编号" min={0} max={255} className="full-width" />
                </Form.Item>
              </Col>
            </Row>
          </Card>
        </div>

        <Space className="connection-actions" align="center" size={12} wrap>
          <Button type="outline" loading={testing} onClick={() => void handleTestConnections()}>
            测试连接
          </Button>
          {connectionResult ? (
            <Text type="success">
              连接正常：MySQL {connectionResult.mysql.version} / Redis {connectionResult.redis.version}
            </Text>
          ) : (
            <Text type="secondary">修改连接信息后需要重新测试</Text>
          )}
        </Space>
      </div>
    )
  }

  function renderSystem() {
    return (
      <div className="step-panel">
        <div className="section-heading">
          <Title heading={4}>系统与管理员配置</Title>
          <Paragraph type="secondary">确认网站信息、安全后台路径和首个超级管理员账号。</Paragraph>
        </div>

        <div className="form-section-grid">
          <Card size="small" title="网站基本信息" className="form-section-card">
            <Row gutter={16}>
              <Col span={24}>
                <Form.Item field="system.site_title" label="网站标题" rules={[{ required: true, message: '网站标题应为 2 至 120 个字符', minLength: 2, maxLength: 120 }]}>
                  <Input placeholder="请输入网站标题" />
                </Form.Item>
              </Col>
              <Col span={24}>
                <Form.Item field="system.site_url" label="网站地址" rules={[
                  { required: true, message: '请填写网站地址' },
                  { type: 'url', match: /^https?:\/\//, message: '请输入以 http:// 或 https:// 开头的有效网站地址' },
                ]}>
                  <Input placeholder="https://example.com" />
                </Form.Item>
              </Col>
              <Col span={24}>
                <Form.Item
                  field="system.admin_path"
                  label="后台访问路径"
                  tooltip="3–32 位小写字母、数字或连字符，须以小写字母开头。"
                  extra="安装后可通过该路径访问运营后台，请妥善保存。"
                  rules={[
                    { required: true, message: '请填写后台访问路径' },
                    { match: /^[a-z][a-z0-9-]{2,31}$/, message: '请输入 3 至 32 位小写字母、数字或连字符' },
                  ]}
                >
                  <Input
                    placeholder="请输入后台访问路径"
                    addBefore="/"
                    addAfter={
                      <Link onClick={() => void handleRandom('path')}>
                        随机生成
                      </Link>
                    }
                  />
                </Form.Item>
              </Col>
            </Row>
          </Card>

          <Card size="small" title="管理员信息" className="form-section-card">
            <Row gutter={16}>
              <Col span={24}>
                <Form.Item
                  field="admin.nickname"
                  label="管理员昵称"
                  rules={[{ required: true, message: '管理员昵称应为 2 至 32 个字符', minLength: 2, maxLength: 32 }]}
                >
                  <Input placeholder="请输入管理员昵称" autoComplete="nickname" />
                </Form.Item>
              </Col>
              <Col span={24}>
                <Form.Item
                  field="admin.username"
                  label="管理员账号"
                  tooltip="6–32 位英文字母、数字或下划线，须以英文字母开头。"
                  rules={[
                    { required: true, message: '请填写管理员账号' },
                    {
                      match: /^[a-zA-Z][a-zA-Z0-9_]{5,31}$/,
                      message: '请输入 6 至 32 位字符，以英文字母开头，只能包含英文字母、数字或下划线',
                    },
                  ]}
                >
                  <Input
                    placeholder="请输入管理员账号"
                    autoComplete="one-time-code"
                    data-1p-ignore="true"
                    data-lpignore="true"
                    addAfter={
                      <Link onClick={() => void handleRandom('username')}>
                        随机生成
                      </Link>
                    }
                  />
                </Form.Item>
              </Col>
              <Col span={24}>
                <Form.Item
                  field="admin.password"
                  label="管理员密码"
                  extra="至少 8 位，并同时包含大写字母、小写字母、数字和特殊字符。"
                  rules={[
                    { required: true, message: '管理员密码应为 8 至 128 个字符', minLength: 8, maxLength: 128 },
                    {
                      validator: (value: string | undefined, callback) => {
                        if (!value) { callback(); return }
                        if (/[a-z]/.test(value) && /[A-Z]/.test(value) && /\d/.test(value) && /[^a-zA-Z\d]/.test(value)) {
                          callback()
                        } else {
                          callback('密码复杂度不符合要求')
                        }
                      },
                    },
                  ]}
                >
                  <Input.Password
                    placeholder="请输入管理员密码"
                    autoComplete="new-password"
                    addAfter={
                      <Link onClick={() => void handleRandom('password')}>
                        随机生成
                      </Link>
                    }
                  />
                </Form.Item>
              </Col>
            </Row>
          </Card>
        </div>
      </div>
    )
  }

  function renderExecution() {
    return (
      <div className="step-panel execution-panel">
        <div className="section-heading centered">
          <Title heading={4}>正在安装系统</Title>
          <Paragraph type="secondary">请保持当前页面打开，不要重复提交或关闭浏览器。</Paragraph>
        </div>
        <Progress percent={progress} status={installError ? 'error' : progress === 100 ? 'success' : 'normal'} />
        <List
          className="phase-list"
          size="small"
          bordered
          dataSource={phases}
          render={(item) => (
            <List.Item key={item.key} extra={<Tag color={phaseTagColor(item.status)}>{phaseStatusText(item.status)}</Tag>}>
              <List.Item.Meta title={item.title} description={item.description} />
            </List.Item>
          )}
        />
      </div>
    )
  }

  function renderResult() {
    if (installError || !finalResult) {
      return (
        <Result
          status="error"
          title="安装失败"
          subTitle={installError || '未能取得安装结果，请检查系统日志后重试。'}
          extra={(
            <Space size={8} wrap>
              <Button type="primary" onClick={() => setCurrent(3)}>
                返回检查配置
              </Button>
              <Button onClick={() => void runInstallation(form.getFields() as InstallationConfiguration)}>
                重新执行安装
              </Button>
            </Space>
          )}
        >
          <Alert type="warning" showIcon content="已完成的数据库步骤可以安全重试，安装器不会重复创建相同表。" />
        </Result>
      )
    }

    return (
      <Result
        status="success"
        title="安装成功"
        subTitle="请立即保存管理员信息，离开本页后将无法再次查看明文密码。"
        extra={(
          <Space size={8} wrap>
            <Button href={finalResult.home_url}>访问首页</Button>
            <Button type="primary" href={finalResult.admin_url}>进入运营后台</Button>
            <Button onClick={copyAll}>复制全部</Button>
          </Space>
        )}
      >
        <Descriptions
          size="small"
          border
          column={screens.sm ? 2 : 1}
          data={[
            { key: 'home', label: '首页地址', value: copyableValue(finalResult.home_url) },
            { key: 'admin', label: '后台地址', value: copyableValue(finalResult.admin_url) },
            { key: 'username', label: '管理员账号', value: copyableValue(finalResult.admin_username) },
            { key: 'password', label: '管理员密码', value: copyableValue(finalResult.admin_password, true) },
          ]}
        />
      </Result>
    )
  }

  function renderCurrentStep() {
    if (current === 0) return renderAgreement()
    if (current === 1) return renderEnvironment()
    if (current === 2) return renderDatabase()
    if (current === 3) return renderSystem()
    if (current === 4) return renderExecution()
    return renderResult()
  }

  if (loading && !bootstrap) {
    return <div className="installer-loading"><Spin size={40} /><Text>正在初始化安装器…</Text></div>
  }

  if (bootError || !bootstrap) {
    return <Card title="验证安装权限" style={{ maxWidth: 560, margin: '48px auto' }}>
      <Paragraph>在服务器的 backend 目录执行 <code>php webman install:key</code>，输入生成的一小时有效授权码后继续安装。</Paragraph>
      <Form layout="vertical" onSubmit={() => { const key = installationKey; setInstallationKey(''); void initialize(key) }}>
        <Form.Item label="安装授权码" field="key" rules={[{ required: true, message: '请输入安装授权码' }, { match: /^[a-f0-9]{64}$/, message: '请输入完整的安装授权码' }]}>
          <Input.Password value={installationKey} onChange={setInstallationKey} autoComplete="off" />
        </Form.Item>
        {bootError && <Alert type="warning" content={bootError} style={{ marginBottom: 16 }} />}
        <Button type="primary" htmlType="submit" loading={loading}>验证并继续</Button>
      </Form>
    </Card>
  }

  return (
    <div className="installer-shell">
      <header className="installer-topbar">
        <div className="installer-topbar-inner">
          <div className="installer-topbar-brand">
            <img className="topbar-logo" src={logoUrl} alt="皓量云擎" draggable={false} />
            <div className="topbar-brand-copy">
              <span className="topbar-brand-name">皓量云擎</span>
              <span className="topbar-brand-subtitle">云业务运营系统</span>
            </div>
          </div>
          <span className="topbar-slogan">让数据驱动业务 · 让管理更高效</span>
        </div>
      </header>

      <main className="installer-main">
        <Card
          className="installer-card"
          bordered={false}
          bodyStyle={{ padding: 0 }}
        >
          <div className="installer-layout">
            <aside className="installer-aside">
              <div>
                <div className="aside-brand-name">皓量云擎</div>
                <div className="aside-brand-subtitle">云业务运营系统</div>
                <div className="aside-divider" />
                <div className="aside-slogan">简单 · 高效 · 安全 · 可持续</div>
              </div>
              <div className="aside-version">v0.1.0</div>
            </aside>

            <section className="installer-content">
              <div className="installer-steps-wrap">
                <Steps
                  current={current + 1}
                  status={current === 5 ? installError ? 'error' : 'finish' : 'process'}
                  size="small"
                  labelPlacement="horizontal"
                  lineless={!screens.lg}
                  customDot={screens.lg ? undefined : (dot, { index }) => (
                    <div className="compact-step">
                      {isValidElement<{ style?: CSSProperties }>(dot)
                        ? cloneElement(dot, { style: { marginRight: 0 } }) : dot}
                      <span className="compact-step-title">{stepItems[index - 1].title}</span>
                    </div>
                  )}
                  className="installer-steps"
                >
                  {stepItems.map((step, index) => (
                    <Steps.Step
                      key={index}
                      title={screens.lg ? step.title : undefined}
                      {...(index === 4 && installError ? { status: 'error' as const } : {})}
                      style={screens.lg ? undefined : { flex: 1, minWidth: 0, marginRight: 0, textAlign: 'center' }}
                    />
                  ))}
                </Steps>
              </div>
              <Divider className="steps-divider" />
              <Form
                form={form}
                layout="vertical"
                autoComplete="off"
                disabled={testing}
                onValuesChange={(changedValues) => {
                  if (Object.keys(changedValues).some((field) => /^(mysql|redis)(\.|$)/.test(field))) {
                    setConnectionResult(null)
                  }
                }}
              >
                {renderCurrentStep()}
              </Form>

              {current < 4 && (
                <div className="step-actions">
                  <Button disabled={current === 0 || testing} onClick={() => setCurrent((value) => Math.max(0, value - 1))}>
                    上一步
                  </Button>
                  <Button
                    type="primary"
                    onClick={() => void next()}
                    disabled={
                      (current === 0 && !agreementAgreed)
                      || (current === 1 && !bootstrap.environment.passed)
                      || (current === 2 && !connectionResult)
                    }
                    loading={testing}
                  >
                    {current === 3 ? '开始安装' : '下一步'}
                  </Button>
                </div>
              )}
            </section>
          </div>
        </Card>
      </main>

      <footer className="installer-footer">
        <Text type="secondary">© 2026 宁波皓量云擎网络科技有限公司</Text>
      </footer>
    </div>
  )
}

/** 使用 Arco 中文组件提供独立的在线安装界面。 */
export default function InstallerApp() {
  return (
    <ConfigProvider locale={zhCN} componentConfig={{ Switch: { type: 'round' }, Modal: { maskClosable: true }, Drawer: { maskClosable: true } }}>
      <InstallerContent />
    </ConfigProvider>
  )
}

function phaseTagColor(status: PhaseStatus): string {
  if (status === 'finish') return 'green'
  if (status === 'process') return 'arcoblue'
  if (status === 'error') return 'red'
  return 'gray'
}

function phaseStatusText(status: PhaseStatus): string {
  if (status === 'finish') return '已完成'
  if (status === 'process') return '执行中'
  if (status === 'error') return '失败'
  return '等待中'
}
