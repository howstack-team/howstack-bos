export interface EnvironmentItem {
  key: string
  name: string
  value: string
  passed: boolean
  required: boolean
  help: string
}

export interface EnvironmentResult {
  passed: boolean
  items: EnvironmentItem[]
}

export interface RandomDefaults {
  admin_path: string
  admin_username: string
  admin_password: string
}

/** 安装器当前要求用户确认的软件使用协议。 */
export interface SoftwareAgreement {
  title: string
  version: string
  content: string
}

export interface BootstrapData {
  token: string
  environment: EnvironmentResult
  defaults: RandomDefaults
  agreement: SoftwareAgreement
}

export interface InstallationConfiguration {
  /** 仅当用户主动勾选软件使用协议后才允许执行安装。 */
  agreement_agreed: boolean
  mysql: {
    host: string
    port: number
    /** 必须由安装人填写，安装器不提供默认数据库名称。 */
    database: string
    username: string
    password: string
  }
  redis: {
    host: string
    port: number
    password: string
    database: number
  }
  system: {
    site_title: string
    site_url: string
    admin_path: string
  }
  admin: {
    nickname: string
    username: string
    password: string
  }
}

export interface ConnectionResult {
  mysql: { version: string }
  redis: { version: string }
}

export type InstallPhase = 'database' | 'system' | 'configuration' | 'finalize'

export interface PhaseResult {
  progress: number
  message: string
  reload_scheduled?: boolean
  versions?: Record<string, string>
  result?: {
    home_url: string
    admin_url: string
    admin_path: string
    admin_username: string
    admin_nickname: string
  }
}

export interface FinalResult {
  home_url: string
  admin_url: string
  admin_path: string
  admin_username: string
  admin_nickname: string
  admin_password: string
}
