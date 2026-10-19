import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { pathToFileURL } from 'node:url'
const require = createRequire(new URL('../../../admin/package.json', import.meta.url))
const { DESECBCipher } = await import(new URL('./crypto/des.js', pathToFileURL(require.resolve('@novnc/novnc'))).href)

/** 模拟供应商WebSocket，使用RFB 3.8和VNC challenge-response；实际浏览器运行未替换的noVNC。 */
export async function installRfbFixture(context) {
  const challenge = Uint8Array.from({ length: 16 }, (_, i) => i + 1)
  const password = 'vncSecret'
  const expected = Buffer.from(DESECBCipher.importKey(Array.from(password, c => c.charCodeAt(0))).encrypt({}, challenge))
  const state = { authenticated: 0, closed: 0, frames: [], phases: [], urls: [], sockets: [] }
  await context.routeWebSocket('wss://vnc.example.test/**', socket => {
    state.sockets.push(socket); state.urls.push(socket.url())
    let phase = 'version', rendered = false
    socket.onClose(() => { state.closed++ })
    socket.onMessage(raw => {
      state.phases.push(phase)
      const bytes = typeof raw === 'string' ? Buffer.from(raw) : Buffer.from(raw)
      if (phase === 'version') {
        assert.equal(bytes.toString(), 'RFB 003.008\n'); socket.send(Buffer.from([1, 2])); phase = 'security'
      } else if (phase === 'security') {
        assert.equal(bytes[0], 2); socket.send(Buffer.from(challenge)); phase = 'auth'
      } else if (phase === 'auth') {
        assert.deepEqual(bytes, expected); state.authenticated++; socket.send(Buffer.alloc(4)); phase = 'init'
      } else if (phase === 'init') {
        assert.equal(bytes.length, 1)
        const name = Buffer.from('Test VNC Desktop'), init = Buffer.alloc(24 + name.length)
        init.writeUInt16BE(640, 0); init.writeUInt16BE(360, 2); init[4] = 32; init[5] = 24; init[7] = 1
        init.writeUInt16BE(255, 8); init.writeUInt16BE(255, 10); init.writeUInt16BE(255, 12)
        init[14] = 16; init[15] = 8; init[16] = 0; init.writeUInt32BE(name.length, 20); name.copy(init, 24)
        socket.send(init); phase = 'connected'
      } else {
        state.frames.push(bytes)
        if (!rendered) {
          rendered = true
          const framebuffer = Buffer.alloc(16 + 640 * 360 * 4)
          framebuffer.writeUInt16BE(1, 2); framebuffer.writeUInt16BE(640, 8); framebuffer.writeUInt16BE(360, 10)
          // Honor the client's SetPixelFormat rather than assuming the initial server format.
          const redShift = bytes[0] === 0 ? bytes[14] : 16, greenShift = bytes[0] === 0 ? bytes[15] : 8, blueShift = bytes[0] === 0 ? bytes[16] : 0
          const color = (25 << redShift) | (43 << greenShift) | (71 << blueShift)
          for (let i = 16; i < framebuffer.length; i += 4) { if (bytes[0] === 0 && bytes[6]) framebuffer.writeUInt32BE(color, i); else framebuffer.writeUInt32LE(color, i) }
          socket.send(framebuffer)
        }
      }
    })
    socket.send(Buffer.from('RFB 003.008\n'))
  })
  return state
}

export function sawKey(state, keysym) {
  // RFB key messages: type=4, down flag, two padding bytes, big-endian keysym.
  return state.frames.some(bytes => {
    for (let i = 0; i + 7 < bytes.length; i++) if (bytes[i] === 4 && bytes[i + 1] === 1 && bytes[i + 2] === 0 && bytes[i + 3] === 0 && bytes.readUInt32BE(i + 4) === keysym) return true
    return false
  })
}

/** noVNC validates public properties on the immediate prototype; flatten the Playwright WS shim only after page initialization. */
export async function prepareRfbTransport(page) {
  await page.evaluate(() => {
    window.WebSocket = new Proxy(window.WebSocket, {
      construct(Target, args) {
        const socket = Reflect.construct(Target, args)
        for (const name of ['send', 'close', 'binaryType', 'onmessage', 'onopen', 'onclose', 'onerror', 'readyState', 'protocol']) {
          let descriptor, proto = socket
          while (proto && !descriptor) { descriptor = Object.getOwnPropertyDescriptor(proto, name); proto = Object.getPrototypeOf(proto) }
          if (typeof socket[name] === 'function') Object.defineProperty(socket, name, { value: socket[name].bind(socket), enumerable: true, configurable: true })
          else if (descriptor) Object.defineProperty(socket, name, { ...descriptor, enumerable: true, configurable: true })
        }
        return socket
      },
    })
  })
}
