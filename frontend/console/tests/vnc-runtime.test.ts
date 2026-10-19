import test from 'node:test'
import assert from 'node:assert/strict'
import { setImmediate } from 'node:timers/promises'
import { createVncSession, parseVncTicket, type RfbClient, type RfbConstructor, type VncTicket, type VncPhase } from '../../packages/vnc-runtime/index.ts'
const ticket: VncTicket = { websocket_url: 'wss://vnc.example.test/socket?token=fixture', password: 'secret', connect_timeout_seconds: 30, session_timeout_seconds: 900 }
class RfbMock extends EventTarget implements RfbClient {
  static last: RfbMock
  scaleViewport = false; clipViewport = false; resizeSession = true; dragViewport = false; viewOnly = true; focusOnClick = false
  closed = 0; focused = 0; cad = 0; keys: number[] = []; credentials: { password: string }
  constructor(_target: HTMLElement, _url: string, options: { credentials: { password: string } }) { super(); RfbMock.last = this; this.credentials = options.credentials }
  disconnect() { this.closed++ }
  focus() { this.focused++ }
  sendCtrlAltDel() { this.cad++ }
  sendKey(key: number) { this.keys.push(key) }
}
function fixture(check: (signal: AbortSignal) => Promise<void> = async () => {}, value = ticket) {
  const states: [VncPhase, string][] = [], surface = { cleared: 0, replaceChildren() { this.cleared++ } }
  const session = createVncSession(surface as unknown as HTMLElement, RfbMock as RfbConstructor, value, check, (phase, message) => states.push([phase, message]))
  return { session, states, surface, client: RfbMock.last }
}

test('VNC boundary rejects navigation URLs, userinfo, invalid durations and malformed secrets', () => {
  assert.deepEqual(parseVncTicket(ticket), ticket)
  for (const url of ['https://provider.example.test/page', 'ws://example.test/socket', 'wss://user:pass@example.test/x', 'wss://example.test/#secret', 'wss://example.test/\nsecret', 'wss://example.test\\evil']) assert.throws(() => parseVncTicket({ ...ticket, websocket_url: url }))
  for (const change of [{ session_timeout_seconds: 901 }, { connect_timeout_seconds: 0 }, { password: null }, { password: 'bad\0secret' }]) assert.throws(() => parseVncTicket({ ...ticket, ...change }))
})
test('RFB public controls, screen sizing, unicode keys, and closing release all resources', t => {
  t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
  const { session, states, surface, client } = fixture()
  assert.equal(client.credentials.password, 'secret'); assert.equal(client.resizeSession, false); assert.equal(client.scaleViewport, true)
  client.dispatchEvent(new Event('connect')); assert.equal(client.focused, 1)
  session.key('enter'); session.text('a中'); session.ctrlAltDel(); session.fit(false); session.readOnly(true)
  assert.deepEqual(client.keys, [0xff0d, 97, 0x01004e2d]); assert.equal(client.cad, 1); assert.equal(client.dragViewport, true); assert.equal(client.viewOnly, true)
  session.close(); session.close(); t.mock.timers.tick(1000000); client.dispatchEvent(new Event('connect'))
  assert.equal(client.closed, 1); assert.equal(surface.cleared, 2); assert.deepEqual(states.map(row => row[0]), ['connecting', 'connected'])
})
test('connection timeout closes RFB without returning upstream errors or credentials', t => {
  t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
  const { client, states } = fixture(); t.mock.timers.tick(30000)
  assert.equal(client.closed, 1); assert.equal(states.at(-1)?.[0], 'error'); assert.equal(JSON.stringify(states).includes('secret'), false)
})
test('subscription/session deadline disconnects the currently open display', t => {
  t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
  const { client, states } = fixture(async () => {}, { ...ticket, session_timeout_seconds: 10 })
  client.dispatchEvent(new Event('connect')); t.mock.timers.tick(10000)
  assert.equal(client.closed, 1); assert.equal(states.at(-1)?.[0], 'disconnected')
})
test('access revocation fails closed and clears polling rather than silently renewing tickets', async t => {
  t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
  let checks = 0
  const { client, states } = fixture(async () => { checks++; throw new Error('denied with secret') })
  client.dispatchEvent(new Event('connect')); t.mock.timers.tick(30000); await setImmediate()
  assert.equal(checks, 1); assert.equal(client.closed, 1); assert.equal(states.at(-1)?.[0], 'error')
  assert.equal(JSON.stringify(states).includes('secret'), false)
  t.mock.timers.tick(60000); await setImmediate(); assert.equal(checks, 1)
})
test('authentication rejection never renders a server-supplied message', () => {
  const { client, states } = fixture()
  client.dispatchEvent(Object.assign(new Event('securityfailure'), { detail: { reason: '<script>secret</script>' } }))
  assert.equal(client.closed, 1); assert.equal(states.at(-1)?.[0], 'error'); assert.equal(JSON.stringify(states).includes('script'), false)
})
