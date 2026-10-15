/** 将整数分转换为精确的元文本，保留两位小数。 */
export function minorToYuanText(minor: number): string {
  if (!Number.isSafeInteger(minor)) throw new RangeError('金额必须为安全范围内的整数分')
  const amount = BigInt(minor)
  const absolute = amount < 0n ? -amount : amount
  return `${amount < 0n ? '-' : ''}${absolute / 100n}.${String(absolute % 100n).padStart(2, '0')}`
}

/**
 * 将最多两位小数的元转换为整数分，避免浮点乘法的金额误差。
 * @throws RangeError 输入格式错误、超过两位小数或超出安全整数范围时抛出。
 */
export function yuanToMinor(yuan: string | number): number {
  const match = /^(-?)(\d+)(?:\.(\d{0,2}))?$/.exec(String(yuan).trim())
  if (!match) throw new RangeError('金额须为最多两位小数的元')
  const absolute = BigInt(match[2]) * 100n + BigInt((match[3] || '').padEnd(2, '0'))
  const minor = match[1] ? -absolute : absolute
  if (minor > BigInt(Number.MAX_SAFE_INTEGER) || minor < BigInt(Number.MIN_SAFE_INTEGER)) {
    throw new RangeError('金额超出安全整数范围')
  }
  return Number(minor)
}

/** 显示接口整数金额；人民币使用 ¥ 前缀与元后缀，其他币种保留原币种。 */
export function formatMoneyMinor(minor: number, currency = 'CNY'): string {
  if (currency === 'CNY') return `¥${minorToYuanText(minor)} 元`
  try { return new Intl.NumberFormat('zh-CN', { style: 'currency', currency }).format(minor / 100) }
  catch { return `${currency} ${minorToYuanText(minor)}` }
}
