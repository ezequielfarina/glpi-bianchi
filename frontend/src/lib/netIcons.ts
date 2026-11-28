// Desenhos de equipamentos para o mapa da rede (ADR-0018): frente do equipamento em canvas,
// usada como textura da caixa 3D, como nó do mapa 2D, na legenda e no seletor de desenho da função.
// Tudo vetorial e proporcional à altura (u = h), sem imagens externas.

export type NetIcon =
  | 'switch' | 'router' | 'firewall' | 'server' | 'hypervisor' | 'storage' | 'patch_panel' | 'dio' | 'jack' | 'pdu' | 'power_panel' | 'ups'
  | 'ont' | 'ap' | 'workstation' | 'printer' | 'phone' | 'camera' | 'organizer' | 'vm' | 'cluster' | 'circuit' | 'cloud' | 'generic'

/** largura ÷ altura do desenho */
export const ASPECT: Record<NetIcon, number> = {
  switch: 4.4, router: 4, firewall: 4, patch_panel: 4.4, dio: 4.4, pdu: 4.6, organizer: 4.4, generic: 4,
  server: 2.7, hypervisor: 2.7, storage: 2.4, power_panel: 0.82, ups: 0.62, ont: 1.7, ap: 1.15, workstation: 1.45,
  printer: 1.3, phone: 1.1, camera: 1.5, jack: 0.85, vm: 1.35, cluster: 1.25, circuit: 1.25, cloud: 1.6,
}
/** equipamentos de rack: caixa 3D com profundidade; os demais têm perfil fino ou são sprites */
export const RACK_ICONS = new Set<NetIcon>(['switch', 'router', 'firewall', 'patch_panel', 'dio', 'pdu', 'organizer', 'generic', 'server', 'hypervisor', 'storage'])
export const SPRITE_ICONS = new Set<NetIcon>(['cloud', 'ap', 'circuit', 'cluster', 'camera', 'phone'])

const BY_NAME: [RegExp, NetIcon][] = [
  [/host de virtualiza|hypervisor|proxmox|esxi/i, 'hypervisor'], [/m[aá]quina virtual|\bvm\b/i, 'vm'], [/switch/i, 'switch'], [/roteador|router/i, 'router'],
  [/firewall|utm|fortigate/i, 'firewall'], [/storage|nas|san\b/i, 'storage'], [/servidor|server/i, 'server'], [/patch/i, 'patch_panel'], [/dio|fibra/i, 'dio'],
  [/ponto de rede|tomada|keystone/i, 'jack'], [/pdu|r[eé]gua/i, 'pdu'], [/quadro|disjuntor/i, 'power_panel'], [/nobreak|ups/i, 'ups'],
  [/modem|ont\b|onu\b/i, 'ont'], [/access point|\bap\b|wi-?fi/i, 'ap'], [/esta[cç][aã]o|desktop|notebook|computador|pc\b/i, 'workstation'],
  [/impressora|printer/i, 'printer'], [/telefone|phone|voip/i, 'phone'], [/c[aâ]mera|nvr|dvr|cftv/i, 'camera'], [/organizador/i, 'organizer'],
]

/** desenho do nó: o configurado na função; senão, deduzido pelo nome da função */
export function iconOf(kind: string, icon?: string | null, role?: string | null): NetIcon {
  if (kind === 'vm') return 'vm'
  if (kind === 'cluster') return 'cluster'
  if (kind === 'circuit') return 'circuit'
  if (kind === 'cloud') return 'cloud'
  if (icon && icon in ASPECT) return icon as NetIcon
  for (const [re, k] of BY_NAME) if (role && re.test(role)) return k
  return 'generic'
}

type C = CanvasRenderingContext2D
const rnd = (i: number) => { const x = Math.sin(i * 12.9898 + 78.233) * 43758.5453; return x - Math.floor(x) }

function rr(c: C, x: number, y: number, w: number, h: number, r: number): void {
  c.beginPath(); c.roundRect(x, y, w, h, Math.min(r, w / 2, h / 2))
}
function metal(c: C, y: number, h: number, light = false): CanvasGradient {
  const g = c.createLinearGradient(0, y, 0, y + h)
  if (light) { g.addColorStop(0, '#e2e8f0'); g.addColorStop(0.15, '#cbd5e1'); g.addColorStop(1, '#94a3b8') }
  else { g.addColorStop(0, '#465569'); g.addColorStop(0.1, '#2c3848'); g.addColorStop(1, '#141b26') }
  return g
}
function led(c: C, x: number, y: number, r: number, color: string, glow = true): void {
  c.save()
  if (glow) { c.shadowColor = color; c.shadowBlur = r * 4 }
  c.fillStyle = color; c.beginPath(); c.arc(x, y, r, 0, Math.PI * 2); c.fill()
  c.restore()
}
/** chassi de rack: corpo, orelhas com parafusos e faixa na cor da função */
function chassis(c: C, w: number, h: number, color: string, opts: { light?: boolean; ears?: boolean } = {}): { x0: number; x1: number } {
  const ear = opts.ears === false ? 0 : h * 0.24
  rr(c, 0, 0, w, h, h * 0.07); c.fillStyle = metal(c, 0, h, opts.light); c.fill()
  c.lineWidth = Math.max(0.5, h * 0.025); c.strokeStyle = 'rgba(255,255,255,.14)'; c.stroke()
  if (ear) {
    for (const ex of [0, w - ear]) {
      rr(c, ex, 0, ear, h, h * 0.07); c.fillStyle = opts.light ? '#a3b0c2' : '#222c3a'; c.fill()
      for (const sy of h > 0.6 * w / 2 ? [0.25, 0.75] : [0.5]) { c.fillStyle = '#0b1018'; c.beginPath(); c.arc(ex + ear / 2, h * sy, h * 0.07, 0, Math.PI * 2); c.fill() }
    }
  }
  c.fillStyle = color; rr(c, ear + h * 0.08, h * 0.16, h * 0.1, h * 0.68, h * 0.04); c.fill()
  // brilho superior
  c.fillStyle = 'rgba(255,255,255,.08)'; c.fillRect(ear, h * 0.03, w - 2 * ear, h * 0.05)
  return { x0: ear + h * 0.22, x1: w - ear - h * 0.08 }
}
function rj45(c: C, x: number, y: number, s: number, lit: boolean | null): void {
  rr(c, x, y, s, s * 0.82, s * 0.08); c.fillStyle = '#05080d'; c.fill()
  c.lineWidth = s * 0.07; c.strokeStyle = '#5b6b80'; c.stroke()
  c.fillStyle = '#1b2533'; c.fillRect(x + s * 0.3, y + s * 0.62, s * 0.4, s * 0.2)
  if (lit !== null) { led(c, x + s * 0.18, y - s * 0.2, s * 0.1, lit ? '#22c55e' : '#334155', lit); led(c, x + s * 0.82, y - s * 0.2, s * 0.1, lit && rnd(x) > 0.5 ? '#f59e0b' : '#334155', false) }
}
function portGrid(c: C, x0: number, x1: number, h: number, n: number, rows: number, seed: number, groupEvery = 6): void {
  const cols = Math.ceil(n / rows)
  const s = Math.min(h * 0.3, ((x1 - x0) / (cols * 1.16 + (cols / groupEvery) * 0.5)) * 0.98)
  const gap = s * 0.16
  const total = cols * (s + gap) + Math.floor((cols - 1) / groupEvery) * s * 0.5
  let x = x0 + Math.max(0, (x1 - x0 - total) / 2)
  const top = rows === 1 ? h * 0.42 : h * 0.17
  for (let i = 0; i < cols; i++) {
    for (let r = 0; r < rows; r++) rj45(c, x, top + r * (s * 0.82 + h * 0.13), s, rnd(seed + i * 3 + r) > 0.35)
    x += s + gap + ((i + 1) % groupEvery === 0 ? s * 0.5 : 0)
  }
}
function glyphBox(c: C, x: number, y: number, s: number, color: string, draw: (cx: number, cy: number, r: number) => void): void {
  rr(c, x, y, s, s, s * 0.18)
  const g = c.createLinearGradient(x, y, x + s, y + s); g.addColorStop(0, color); g.addColorStop(1, shade(color, -0.35))
  c.fillStyle = g; c.fill()
  c.save(); c.strokeStyle = '#fff'; c.fillStyle = '#fff'; c.lineWidth = s * 0.08; c.lineCap = 'round'; c.lineJoin = 'round'
  draw(x + s / 2, y + s / 2, s * 0.32); c.restore()
}
export function shade(hex: string, amt: number): string {
  const n = parseInt(hex.replace('#', ''), 16)
  const f = (v: number) => Math.max(0, Math.min(255, Math.round(amt < 0 ? v * (1 + amt) : v + (255 - v) * amt)))
  return `rgb(${f((n >> 16) & 255)},${f((n >> 8) & 255)},${f(n & 255)})`
}
function arrow(c: C, x1: number, y1: number, x2: number, y2: number, hd: number): void {
  const a = Math.atan2(y2 - y1, x2 - x1)
  c.beginPath(); c.moveTo(x1, y1); c.lineTo(x2, y2)
  c.moveTo(x2 - hd * Math.cos(a - 0.6), y2 - hd * Math.sin(a - 0.6)); c.lineTo(x2, y2); c.lineTo(x2 - hd * Math.cos(a + 0.6), y2 - hd * Math.sin(a + 0.6)); c.stroke()
}
function vents(c: C, x: number, y: number, w: number, h: number): void {
  c.fillStyle = 'rgba(0,0,0,.45)'
  for (let i = 0; i < 6; i++) rr(c, x + (i * w) / 6 + w * 0.02, y, w / 9, h, w * 0.02), c.fill()
}
function drive(c: C, x: number, y: number, w: number, h: number, ledColor: string, i: number): void {
  rr(c, x, y, w, h, h * 0.1); c.fillStyle = '#0d131c'; c.fill(); c.lineWidth = h * 0.05; c.strokeStyle = '#4b5a6e'; c.stroke()
  c.fillStyle = '#334155'; c.fillRect(x + w * 0.1, y + h * 0.3, w * 0.62, h * 0.4)
  led(c, x + w * 0.85, y + h * 0.5, h * 0.12, rnd(i) > 0.2 ? ledColor : '#334155', true)
}

/** Desenha o equipamento ocupando (0,0)-(w,h). */
export function drawDevice(c: C, icon: NetIcon, w: number, h: number, color: string, seed = 1): void {
  c.save()
  switch (icon) {
    case 'switch': {
      const { x0, x1 } = chassis(c, w, h, color)
      const sfp = h * 0.9
      portGrid(c, x0 + h * 0.05, x1 - sfp - h * 0.08, h, 24, 2, seed)
      for (let i = 0; i < 4; i++) {
        const sx = x1 - sfp + (i % 2) * h * 0.45; const sy = h * (i < 2 ? 0.22 : 0.56)
        rr(c, sx, sy, h * 0.38, h * 0.22, h * 0.03); c.fillStyle = '#9aa7b8'; c.fill(); c.fillStyle = '#05080d'; c.fillRect(sx + h * 0.05, sy + h * 0.05, h * 0.28, h * 0.12)
      }
      break
    }
    case 'patch_panel': {
      const { x0, x1 } = chassis(c, w, h, color)
      c.fillStyle = '#e5e7eb'; rr(c, x0 + h * 0.3, h * 0.12, x1 - x0 - h * 0.4, h * 0.18, h * 0.03); c.fill()
      c.fillStyle = '#64748b'; for (let i = 0; i < 24; i++) c.fillRect(x0 + h * 0.4 + (i * (x1 - x0 - h * 0.6)) / 24, h * 0.17, h * 0.06, h * 0.08)
      portGrid(c, x0 + h * 0.3, x1 - h * 0.1, h, 24, 1, seed + 7)
      break
    }
    case 'dio': {
      const { x0, x1 } = chassis(c, w, h, color)
      rr(c, x0 + h * 0.2, h * 0.14, x1 - x0 - h * 0.25, h * 0.72, h * 0.06); c.fillStyle = 'rgba(0,0,0,.35)'; c.fill()
      for (let i = 0; i < 12; i++) {
        const x = x0 + h * 0.45 + (i * (x1 - x0 - h * 0.7)) / 12
        for (const dx of [0, h * 0.2]) { rr(c, x + dx, h * 0.35, h * 0.16, h * 0.3, h * 0.03); c.fillStyle = '#1d4ed8'; c.fill(); led(c, x + dx + h * 0.08, h * 0.5, h * 0.035, '#e0f2fe', false) }
      }
      break
    }
    case 'firewall': case 'router': {
      const { x0, x1 } = chassis(c, w, h, color)
      glyphBox(c, x0 + h * 0.15, h * 0.14, h * 0.72, color, icon === 'firewall' ? (cx, cy, r) => {
        c.lineWidth = r * 0.22
        for (let row = 0; row < 3; row++) {
          const y = cy - r + row * r * 0.68
          c.strokeRect(cx - r, y, r * 2, r * 0.6)
          const off = row % 2 ? r * 0.5 : r; c.beginPath(); c.moveTo(cx - r + off, y); c.lineTo(cx - r + off, y + r * 0.6); c.stroke()
          if (!(row % 2)) { c.beginPath(); c.moveTo(cx + r * 0.0, y); c.stroke() }
        }
      } : (cx, cy, r) => {
        c.lineWidth = r * 0.2; c.beginPath(); c.arc(cx, cy, r * 1.05, 0, Math.PI * 2); c.stroke()
        arrow(c, cx - r * 0.6, cy - r * 0.3, cx + r * 0.6, cy - r * 0.3, r * 0.3); arrow(c, cx + r * 0.6, cy + r * 0.3, cx - r * 0.6, cy + r * 0.3, r * 0.3)
      })
      const px = x0 + h * 0.95
      portGrid(c, px, x1 - h * 1.2, h, icon === 'firewall' ? 16 : 8, 2, seed, 4)
      for (let i = 0; i < 2; i++) { rr(c, x1 - h * 1.15 + i * h * 0.55, h * 0.36, h * 0.45, h * 0.3, h * 0.04); c.fillStyle = i ? '#9aa7b8' : shade(color, 0.2); c.fill() }
      for (let i = 0; i < 3; i++) led(c, x1 - h * 1.05 + i * h * 0.3, h * 0.2, h * 0.05, i === 0 ? '#22c55e' : i === 1 ? '#38bdf8' : '#22c55e')
      break
    }
    case 'server': case 'hypervisor': case 'storage': {
      const { x0, x1 } = chassis(c, w, h, color)
      const rows = icon === 'storage' ? 3 : 2
      const cols = icon === 'storage' ? 4 : 5
      const left = x0 + h * 0.42
      const bw = (x1 - left - h * 0.55) / cols
      const bh = (h * 0.76) / rows
      for (let r = 0; r < rows; r++) for (let i = 0; i < cols; i++) drive(c, left + i * bw + bw * 0.05, h * 0.12 + r * bh, bw * 0.9, bh * 0.82, icon === 'storage' ? '#38bdf8' : '#22c55e', seed + r * 9 + i)
      // painel de controle
      c.fillStyle = 'rgba(0,0,0,.35)'; rr(c, x0 + h * 0.02, h * 0.12, h * 0.32, h * 0.76, h * 0.05); c.fill()
      led(c, x0 + h * 0.18, h * 0.3, h * 0.08, color); led(c, x0 + h * 0.18, h * 0.55, h * 0.035, '#22c55e'); led(c, x0 + h * 0.18, h * 0.7, h * 0.035, '#f59e0b', false)
      vents(c, x1 - h * 0.5, h * 0.16, h * 0.45, h * 0.68)
      if (icon === 'hypervisor') {
        const s = h * 0.36; const bx = x1 - h * 0.48; const by = h * 0.2
        for (let k = 2; k >= 0; k--) { rr(c, bx + k * s * 0.18, by + k * s * 0.18, s, s * 0.75, s * 0.12); c.fillStyle = k ? shade(color, -0.3 + k * 0.1) : color; c.fill(); c.strokeStyle = '#fff'; c.lineWidth = s * 0.06; c.stroke() }
      }
      break
    }
    case 'pdu': {
      const { x0, x1 } = chassis(c, w, h, color)
      rr(c, x0 + h * 0.2, h * 0.28, h * 0.7, h * 0.44, h * 0.05); c.fillStyle = '#05080d'; c.fill()
      c.fillStyle = '#ef4444'; c.font = `700 ${h * 0.32}px ui-monospace, monospace`; c.textBaseline = 'middle'; c.fillText(`${(2 + rnd(seed) * 6).toFixed(1)}A`, x0 + h * 0.26, h * 0.51)
      const n = 8; const sx = x0 + h * 1.05; const sw = (x1 - sx) / n
      for (let i = 0; i < n; i++) {
        const cx = sx + sw * (i + 0.5); const r = Math.min(sw * 0.38, h * 0.32)
        c.beginPath(); c.arc(cx, h / 2, r, 0, Math.PI * 2); c.fillStyle = '#e2e8f0'; c.fill()
        c.fillStyle = '#111827'; for (const [dx, dy] of [[-0.35, 0.05], [0.35, 0.05], [0, -0.38]]) { c.beginPath(); c.arc(cx + dx! * r, h / 2 + dy! * r, r * 0.13, 0, Math.PI * 2); c.fill() }
      }
      break
    }
    case 'organizer': {
      const { x0, x1 } = chassis(c, w, h, color)
      for (let i = 0; i < 5; i++) { const cx = x0 + ((i + 0.5) * (x1 - x0)) / 5; c.lineWidth = h * 0.12; c.strokeStyle = '#94a3b8'; c.beginPath(); c.arc(cx, h * 0.62, h * 0.3, Math.PI, 0); c.stroke() }
      break
    }
    case 'power_panel': {
      rr(c, 0, 0, w, h, w * 0.06); c.fillStyle = metal(c, 0, h, true); c.fill(); c.lineWidth = w * 0.02; c.strokeStyle = '#64748b'; c.stroke()
      rr(c, w * 0.08, h * 0.06, w * 0.84, h * 0.88, w * 0.04); c.strokeStyle = '#94a3b8'; c.stroke()
      for (let r = 0; r < 2; r++) for (let i = 0; i < 6; i++) {
        const bx = w * 0.15 + i * w * 0.12; const by = h * (0.24 + r * 0.32)
        rr(c, bx, by, w * 0.1, h * 0.2, w * 0.015); c.fillStyle = '#f8fafc'; c.fill(); c.strokeStyle = '#64748b'; c.lineWidth = w * 0.01; c.stroke()
        c.fillStyle = rnd(seed + i + r * 7) > 0.15 ? '#1f2937' : '#ef4444'; c.fillRect(bx + w * 0.025, by + h * 0.04, w * 0.05, h * 0.07)
      }
      c.fillStyle = '#facc15'; c.beginPath(); c.moveTo(w * 0.5, h * 0.82); c.lineTo(w * 0.58, h * 0.95); c.lineTo(w * 0.42, h * 0.95); c.closePath(); c.fill()
      c.fillStyle = color; c.fillRect(w * 0.08, h * 0.06, w * 0.84, h * 0.06)
      break
    }
    case 'ups': {
      rr(c, 0, 0, w, h, w * 0.1); c.fillStyle = metal(c, 0, h); c.fill(); c.lineWidth = w * 0.025; c.strokeStyle = 'rgba(255,255,255,.15)'; c.stroke()
      rr(c, w * 0.15, h * 0.1, w * 0.7, h * 0.2, w * 0.05); c.fillStyle = '#0c4a6e'; c.fill()
      c.fillStyle = '#7dd3fc'; c.font = `700 ${h * 0.09}px ui-monospace, monospace`; c.textAlign = 'center'; c.textBaseline = 'middle'; c.fillText('100%', w / 2, h * 0.2)
      led(c, w / 2, h * 0.42, w * 0.1, color); vents(c, w * 0.15, h * 0.58, w * 0.7, h * 0.32)
      break
    }
    case 'ont': {
      rr(c, 0, h * 0.18, w, h * 0.82, h * 0.12); const g = c.createLinearGradient(0, 0, 0, h); g.addColorStop(0, '#f8fafc'); g.addColorStop(1, '#cbd5e1'); c.fillStyle = g; c.fill()
      c.lineWidth = h * 0.03; c.strokeStyle = '#94a3b8'; c.stroke()
      c.strokeStyle = '#334155'; c.lineWidth = h * 0.06; for (const x of [w * 0.15, w * 0.85]) { c.beginPath(); c.moveTo(x, h * 0.2); c.lineTo(x + (x < w / 2 ? -1 : 1) * w * 0.04, -h * 0.1); c.stroke() }
      const lbl = ['PON', 'LOS', 'LAN', 'WiFi']
      for (let i = 0; i < 4; i++) { const x = w * 0.22 + i * w * 0.19; led(c, x, h * 0.52, h * 0.06, i === 1 ? '#334155' : '#22c55e', i !== 1); c.fillStyle = '#475569'; c.font = `600 ${h * 0.11}px system-ui, sans-serif`; c.textAlign = 'center'; c.fillText(lbl[i]!, x, h * 0.74) }
      c.fillStyle = color; rr(c, w * 0.08, h * 0.86, w * 0.84, h * 0.06, h * 0.03); c.fill()
      break
    }
    case 'ap': {
      const cx = w / 2; const cy = h * 0.62; const r = Math.min(w, h) * 0.36
      c.save(); c.shadowColor = 'rgba(0,0,0,.5)'; c.shadowBlur = r * 0.4; c.shadowOffsetY = r * 0.12
      c.beginPath(); c.ellipse(cx, cy, r, r * 0.92, 0, 0, Math.PI * 2); const g = c.createRadialGradient(cx - r * 0.3, cy - r * 0.3, r * 0.1, cx, cy, r); g.addColorStop(0, '#ffffff'); g.addColorStop(1, '#cbd5e1'); c.fillStyle = g; c.fill(); c.restore()
      c.lineWidth = r * 0.12; c.strokeStyle = color; c.beginPath(); c.arc(cx, cy, r * 0.3, 0, Math.PI * 2); c.stroke(); led(c, cx, cy, r * 0.12, color)
      c.lineCap = 'round'; c.lineWidth = r * 0.1; for (let i = 1; i <= 2; i++) { c.strokeStyle = shade(color, 0.2 * i); c.beginPath(); c.arc(cx, cy - r * 0.95, r * 0.22 * i, Math.PI * 1.2, Math.PI * 1.8); c.stroke() }
      break
    }
    case 'workstation': {
      const mw = w * 0.72; const mh = h * 0.7
      rr(c, 0, 0, mw, mh, h * 0.04); c.fillStyle = '#0b1018'; c.fill(); c.lineWidth = h * 0.02; c.strokeStyle = '#475569'; c.stroke()
      const g = c.createLinearGradient(0, 0, mw, mh); g.addColorStop(0, shade(color, 0.25)); g.addColorStop(1, shade(color, -0.45))
      c.fillStyle = g; c.fillRect(mw * 0.05, mh * 0.07, mw * 0.9, mh * 0.78)
      c.fillStyle = 'rgba(255,255,255,.18)'; for (let i = 0; i < 3; i++) c.fillRect(mw * 0.1, mh * (0.15 + i * 0.13), mw * 0.12, mh * 0.08)
      c.fillStyle = 'rgba(0,0,0,.35)'; c.fillRect(mw * 0.05, mh * 0.77, mw * 0.9, mh * 0.08)
      c.fillStyle = '#475569'; c.fillRect(mw * 0.45, mh, mw * 0.1, h * 0.17); rr(c, mw * 0.3, h * 0.86, mw * 0.4, h * 0.08, h * 0.03); c.fill()
      rr(c, w * 0.78, h * 0.08, w * 0.22, h * 0.86, h * 0.04); c.fillStyle = metal(c, 0, h); c.fill(); c.strokeStyle = 'rgba(255,255,255,.15)'; c.stroke()
      led(c, w * 0.89, h * 0.2, h * 0.035, '#22c55e'); c.fillStyle = 'rgba(0,0,0,.4)'; c.fillRect(w * 0.81, h * 0.32, w * 0.16, h * 0.04)
      break
    }
    case 'printer': {
      c.fillStyle = '#f8fafc'; c.fillRect(w * 0.25, 0, w * 0.5, h * 0.3)
      rr(c, 0, h * 0.22, w, h * 0.58, h * 0.08); c.fillStyle = metal(c, h * 0.22, h * 0.58, true); c.fill()
      rr(c, w * 0.08, h * 0.32, w * 0.3, h * 0.14, h * 0.03); c.fillStyle = '#0c4a6e'; c.fill(); led(c, w * 0.85, h * 0.39, h * 0.04, '#22c55e')
      c.fillStyle = '#334155'; rr(c, w * 0.1, h * 0.8, w * 0.8, h * 0.18, h * 0.04); c.fill(); c.fillStyle = color; c.fillRect(w * 0.1, h * 0.6, w * 0.8, h * 0.05)
      break
    }
    case 'phone': {
      c.fillStyle = metal(c, 0, h); c.beginPath(); c.moveTo(w * 0.05, h * 0.95); c.lineTo(w * 0.15, h * 0.15); c.lineTo(w * 0.95, h * 0.15); c.lineTo(w * 0.98, h * 0.95); c.closePath(); c.fill()
      rr(c, w * 0.03, h * 0.05, w * 0.18, h * 0.85, w * 0.08); c.fillStyle = '#1f2937'; c.fill(); c.strokeStyle = '#475569'; c.lineWidth = w * 0.02; c.stroke()
      rr(c, w * 0.35, h * 0.25, w * 0.5, h * 0.25, w * 0.03); c.fillStyle = shade(color, -0.2); c.fill()
      c.fillStyle = '#94a3b8'; for (let r = 0; r < 3; r++) for (let i = 0; i < 3; i++) { c.beginPath(); c.arc(w * (0.45 + i * 0.14), h * (0.62 + r * 0.11), w * 0.03, 0, Math.PI * 2); c.fill() }
      break
    }
    case 'camera': {
      c.fillStyle = '#94a3b8'; c.fillRect(w * 0.7, h * 0.55, w * 0.08, h * 0.35); c.fillRect(w * 0.55, h * 0.85, w * 0.4, h * 0.08)
      rr(c, w * 0.05, h * 0.15, w * 0.8, h * 0.42, h * 0.18); c.fillStyle = metal(c, h * 0.15, h * 0.42, true); c.fill()
      c.beginPath(); c.arc(w * 0.12, h * 0.36, h * 0.17, 0, Math.PI * 2); c.fillStyle = '#0b1018'; c.fill(); led(c, w * 0.12, h * 0.36, h * 0.06, color)
      break
    }
    case 'jack': {
      rr(c, 0, 0, w, h, w * 0.12); const g = c.createLinearGradient(0, 0, w, h); g.addColorStop(0, '#ffffff'); g.addColorStop(1, '#cbd5e1'); c.fillStyle = g; c.fill()
      c.lineWidth = w * 0.03; c.strokeStyle = '#94a3b8'; c.stroke()
      for (const y of [0.08, 0.92]) { c.fillStyle = '#94a3b8'; c.beginPath(); c.arc(w / 2, h * y, w * 0.04, 0, Math.PI * 2); c.fill() }
      rr(c, w * 0.22, h * 0.3, w * 0.56, h * 0.4, w * 0.05); c.fillStyle = color; c.fill()
      rr(c, w * 0.3, h * 0.38, w * 0.4, h * 0.26, w * 0.03); c.fillStyle = '#05080d'; c.fill(); c.fillStyle = '#1b2533'; c.fillRect(w * 0.42, h * 0.55, w * 0.16, h * 0.08)
      break
    }
    case 'vm': {
      rr(c, 0, 0, w, h, h * 0.1)
      const g = c.createLinearGradient(0, 0, w, h); g.addColorStop(0, 'rgba(192,132,252,.55)'); g.addColorStop(1, 'rgba(76,29,149,.75)'); c.fillStyle = g; c.fill()
      c.lineWidth = h * 0.03; c.strokeStyle = 'rgba(233,213,255,.8)'; c.stroke()
      c.fillStyle = 'rgba(255,255,255,.14)'; rr(c, 0, 0, w, h * 0.2, h * 0.1); c.fill()
      for (let i = 0; i < 3; i++) led(c, h * (0.12 + i * 0.12), h * 0.1, h * 0.035, ['#f87171', '#fbbf24', '#4ade80'][i]!, false)
      c.strokeStyle = '#fff'; c.lineWidth = h * 0.05; c.lineJoin = 'round'
      const cx = w / 2; const cy = h * 0.6; const s = h * 0.24
      c.beginPath(); c.moveTo(cx, cy - s); c.lineTo(cx + s, cy - s / 2); c.lineTo(cx + s, cy + s / 2); c.lineTo(cx, cy + s); c.lineTo(cx - s, cy + s / 2); c.lineTo(cx - s, cy - s / 2); c.closePath()
      c.moveTo(cx - s, cy - s / 2); c.lineTo(cx, cy); c.lineTo(cx + s, cy - s / 2); c.moveTo(cx, cy); c.lineTo(cx, cy + s); c.stroke()
      break
    }
    case 'cluster': {
      const uh = h * 0.24
      for (let i = 0; i < 3; i++) {
        c.save(); c.translate(w * 0.04 * (2 - i), h * 0.12 + i * (uh + h * 0.06))
        const cw = w * 0.88
        rr(c, 0, 0, cw, uh, uh * 0.15); c.fillStyle = metal(c, 0, uh); c.fill(); c.lineWidth = uh * 0.05; c.strokeStyle = 'rgba(255,255,255,.18)'; c.stroke()
        c.fillStyle = color; c.fillRect(uh * 0.2, uh * 0.2, uh * 0.15, uh * 0.6)
        for (let k = 0; k < 6; k++) { c.fillStyle = '#0d131c'; c.fillRect(uh * 0.6 + k * cw * 0.12, uh * 0.25, cw * 0.09, uh * 0.5); led(c, uh * 0.6 + k * cw * 0.12 + cw * 0.075, uh * 0.5, uh * 0.06, '#22c55e', false) }
        c.restore()
      }
      break
    }
    case 'circuit': {
      const cx = w / 2; const cy = h / 2; const r = h * 0.4
      c.save(); c.shadowColor = color; c.shadowBlur = r * 0.6
      c.beginPath(); for (let i = 0; i < 6; i++) { const a = Math.PI / 6 + (i * Math.PI) / 3; c[i ? 'lineTo' : 'moveTo'](cx + r * 1.1 * Math.cos(a), cy + r * 1.1 * Math.sin(a)) } c.closePath()
      const g = c.createLinearGradient(0, 0, w, h); g.addColorStop(0, shade(color, 0.15)); g.addColorStop(1, shade(color, -0.4)); c.fillStyle = g; c.fill(); c.restore()
      c.strokeStyle = '#fff'; c.lineWidth = r * 0.1
      c.beginPath(); c.arc(cx, cy, r * 0.55, 0, Math.PI * 2); c.stroke(); c.beginPath(); c.ellipse(cx, cy, r * 0.25, r * 0.55, 0, 0, Math.PI * 2); c.stroke()
      c.beginPath(); c.moveTo(cx - r * 0.55, cy); c.lineTo(cx + r * 0.55, cy); c.stroke()
      break
    }
    case 'cloud': {
      c.save(); c.shadowColor = color; c.shadowBlur = h * 0.25
      c.beginPath(); c.arc(w * 0.3, h * 0.62, h * 0.24, Math.PI * 0.5, Math.PI * 1.5); c.arc(w * 0.45, h * 0.38, h * 0.26, Math.PI, Math.PI * 1.9); c.arc(w * 0.68, h * 0.45, h * 0.22, Math.PI * 1.3, Math.PI * 0.2); c.arc(w * 0.74, h * 0.66, h * 0.2, Math.PI * 1.6, Math.PI * 0.5); c.closePath()
      const g = c.createLinearGradient(0, 0, 0, h); g.addColorStop(0, shade(color, 0.35)); g.addColorStop(1, shade(color, -0.3)); c.fillStyle = g; c.fill(); c.restore()
      c.strokeStyle = 'rgba(255,255,255,.85)'; c.lineWidth = h * 0.045; c.lineCap = 'round'
      arrow(c, w * 0.42, h * 0.72, w * 0.42, h * 0.5, h * 0.08); arrow(c, w * 0.58, h * 0.5, w * 0.58, h * 0.72, h * 0.08)
      break
    }
    default: {
      const { x0, x1 } = chassis(c, w, h, color)
      portGrid(c, x0 + h * 0.3, x1 - h * 0.4, h, 8, 1, seed, 4)
      led(c, x1 - h * 0.2, h / 2, h * 0.06, color)
    }
  }
  c.restore()
}

const urlCache = new Map<string, string>()
/** PNG (data URL) do desenho, para legenda e seletor */
export function iconUrl(icon: NetIcon, color: string, height = 40): string {
  const key = `${icon}|${color}|${height}`
  const hit = urlCache.get(key)
  if (hit) return hit
  const dpr = 2
  const h = height; const w = Math.round(h * ASPECT[icon])
  const cv = document.createElement('canvas'); cv.width = w * dpr; cv.height = (h + 4) * dpr
  const c = cv.getContext('2d')!; c.scale(dpr, dpr); c.translate(0, 2)
  drawDevice(c, icon, w, h, color)
  const url = cv.toDataURL('image/png')
  urlCache.set(key, url)
  return url
}

const cvCache = new Map<string, HTMLCanvasElement>()
/** canvas do desenho em alta resolução (fonte da textura 3D e do drawImage do 2D); altura em px */
export function iconCanvas(icon: NetIcon, color: string, px = 128): HTMLCanvasElement {
  const key = `${icon}|${color}|${px}`
  const hit = cvCache.get(key)
  if (hit) return hit
  const h = px; const w = Math.round(h * ASPECT[icon])
  const pad = Math.round(h * 0.12) // folga para brilho e antenas
  const cv = document.createElement('canvas'); cv.width = w + pad * 2; cv.height = h + pad * 2
  const c = cv.getContext('2d')!; c.translate(pad, pad)
  drawDevice(c, icon, w, h, color)
  cvCache.set(key, cv)
  return cv
}
/** fração da folga em relação à altura (para posicionar o canvas com borda) */
export const ICON_PAD = 0.12
