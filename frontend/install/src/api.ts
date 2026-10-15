import type {
  BootstrapData,
  ConnectionResult,
  InstallationConfiguration,
  InstallPhase,
  PhaseResult,
  RandomDefaults,
} from './types'

interface ApiResponse<T> {
  code: number
  message: string
  data: T
}

let installToken = ''

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(path, {
    credentials: 'same-origin',
    ...init,
    headers: {
      Accept: 'application/json',
      ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
      ...(installToken ? { 'X-Install-Token': installToken } : {}),
      ...init?.headers,
    },
  })

  let payload: ApiResponse<T>
  try {
    payload = (await response.json()) as ApiResponse<T>
  } catch {
    throw new Error(`安装服务返回了无法识别的响应（HTTP ${response.status}）`)
  }

  if (!response.ok || payload.code !== 0) {
    throw new Error(payload.message || `请求失败（HTTP ${response.status}）`)
  }

  return payload.data
}

export async function loadBootstrap(key = ''): Promise<BootstrapData> {
  const data = await request<BootstrapData>('/api/install/bootstrap', { headers: key ? { 'X-Install-Key': key } : {} })
  installToken = data.token
  return data
}

export function loadRandomDefaults(): Promise<RandomDefaults> {
  return request<RandomDefaults>('/api/install/random-defaults')
}

export function testConnections(
  configuration: Pick<InstallationConfiguration, 'mysql' | 'redis'>,
): Promise<ConnectionResult> {
  return request<ConnectionResult>('/api/install/test-connections', {
    method: 'POST',
    body: JSON.stringify(configuration),
  })
}

export function executePhase(
  phase: InstallPhase,
  configuration: InstallationConfiguration,
): Promise<PhaseResult> {
  return request<PhaseResult>('/api/install/execute', {
    method: 'POST',
    body: JSON.stringify({ phase, configuration }),
  })
}
