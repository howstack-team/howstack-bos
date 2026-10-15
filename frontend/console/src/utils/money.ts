/** 将人民币整数分转为精确的两位元字符串，避免浮点除法丢失末位分。 */
export function minorToYuanText(minor: number): string {
  if (!Number.isSafeInteger(minor) || minor < 0) throw new RangeError('余额必须为非负安全整数分')
  return `${Math.floor(minor / 100)}.${String(minor % 100).padStart(2, '0')}`
}
