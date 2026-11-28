// Cabos do mapa da rede como "fita de LED" (ADR-0018): curva maleável com caimento e torção,
// LEDs ao longo do cabo e pulsos de tráfego nos dois sentidos, na cor real do cabo.
// 3D: tubo (TubeGeometry) com shader + halo aditivo. 2D: mesma curva e mesmos pulsos em canvas.
import type * as THREEType from 'three'
import type { TopoLink } from './virt'

type T = typeof THREEType

/** comportamento por tipo de ligação */
export function cableStyle(l: TopoLink): { radius: number; activity: number; speed: number; gap: number; sag: number } {
  const planned = l.active === false || l.complete === false
  switch (l.kind) {
    case 'power': return { radius: 0.4, activity: planned ? 0 : 0.55, speed: 0.12, gap: 2.6, sag: 0.2 }
    case 'console': return { radius: 0.3, activity: planned ? 0 : 0.35, speed: 0.1, gap: 3, sag: 0.18 }
    case 'member': return { radius: 0.22, activity: 0.25, speed: 0.08, gap: 3.4, sag: 0.08 }
    case 'virtual': return { radius: 0.22, activity: 0.5, speed: 0.16, gap: 3, sag: 0.1 }
    case 'provider': return { radius: 0.65, activity: 1, speed: 0.3, gap: 2, sag: 0.12 }
    default: return { radius: 0.5, activity: planned ? 0.08 : 1, speed: 0.24, gap: 2.2, sag: 0.16 }
  }
}

/** semente estável por ligação (forma da curva não muda a cada render) */
export function seedOf(key: string): [number, number] {
  let h = 2166136261
  for (let i = 0; i < key.length; i++) { h ^= key.charCodeAt(i); h = Math.imul(h, 16777619) }
  return [((h >>> 0) % 1000) / 1000, ((h >>> 10) % 1000) / 1000]
}

// ------------------------------------------------------------------ 3D

const VERT = /* glsl */ `
varying vec2 vUv;
void main() { vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }`
const FRAG = /* glsl */ `
uniform float uTime, uLen, uGap, uSpeed, uActivity, uOpacity, uBoost, uSeed;
uniform vec3 uColor;
varying vec2 vUv;
void main() {
  float d = vUv.x * uLen;                                  // distância ao longo do cabo
  float cell = fract(d / uGap);
  float led = smoothstep(0.34, 0.0, abs(cell - 0.5));      // ponto de LED
  // trens de pulso: ida (cor do cabo) e volta (mais claro), velocidades diferentes
  float t = uTime * uSpeed * 60.0;
  float f = fract((d - t) / 28.0 + uSeed);
  float b = fract((d + t * 0.75) / 37.0 + uSeed * 1.7);
  float go = pow(smoothstep(0.72, 1.0, f), 2.2);
  float back = pow(smoothstep(0.8, 1.0, b), 2.2) * 0.7;
  float pulse = (go + back) * uActivity;
  float breathe = 0.85 + 0.15 * sin(uTime * 1.7 + uSeed * 6.28);
  vec3 col = uColor * (0.16 + 0.1 * uBoost) * breathe
           + uColor * led * (0.35 + 0.25 * uBoost + 1.8 * pulse)
           + vec3(1.0) * led * (go * 0.55 + back * 0.35) * uActivity;
  float a = (0.38 + led * 0.62) * uOpacity;
  gl_FragColor = vec4(col, a);
}`

export interface LedCable3D {
  object: InstanceType<T['Group']>
  update: (a: { x: number; y: number; z: number }, b: { x: number; y: number; z: number }) => void
  setState: (opacity: number, boost: number) => void
}

export function createLedCable(THREE: T, l: TopoLink, color: string, key: string, time: { value: number }): LedCable3D {
  const st = cableStyle(l)
  const [s1, s2] = seedOf(key)
  const uniforms = {
    uTime: time, uLen: { value: 1 }, uGap: { value: st.gap }, uSpeed: { value: st.speed }, uActivity: { value: st.activity },
    uOpacity: { value: 1 }, uBoost: { value: 0 }, uSeed: { value: s1 }, uColor: { value: new THREE.Color(color) },
  }
  const core = new THREE.ShaderMaterial({ uniforms, vertexShader: VERT, fragmentShader: FRAG, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending })
  const glowMat = new THREE.MeshBasicMaterial({ color: new THREE.Color(color), transparent: true, opacity: 0.09, depthWrite: false, blending: THREE.AdditiveBlending })
  const group = new THREE.Group()
  const tube = new THREE.Mesh(new THREE.BufferGeometry(), core)
  const glow = new THREE.Mesh(new THREE.BufferGeometry(), glowMat)
  group.add(glow, tube)
  const A = new THREE.Vector3(); const B = new THREE.Vector3(); const dir = new THREE.Vector3(); const side = new THREE.Vector3(); const n2 = new THREE.Vector3()
  const up = new THREE.Vector3(0, 1, 0)
  return {
    object: group,
    update(a, b) {
      A.set(a.x, a.y, a.z ?? 0); B.set(b.x, b.y, b.z ?? 0)
      dir.subVectors(B, A)
      const len = Math.max(dir.length(), 0.001)
      side.crossVectors(dir, up); if (side.lengthSq() < 1e-6) side.set(1, 0, 0); side.normalize()
      n2.crossVectors(dir, side).normalize()
      // cabo de verdade: cai com o peso, desvia de lado e torce em profundidade (S suave)
      const sag = -len * st.sag
      const lat = len * 0.16 * (s1 - 0.5) * 2
      const dep = len * 0.14 * (s2 - 0.5) * 2
      const c1 = A.clone().addScaledVector(dir, 0.3).addScaledVector(up, sag).addScaledVector(side, lat).addScaledVector(n2, dep)
      const c2 = A.clone().addScaledVector(dir, 0.7).addScaledVector(up, sag * 0.85).addScaledVector(side, -lat * 0.6).addScaledVector(n2, -dep * 0.5)
      const curve = new THREE.CubicBezierCurve3(A.clone(), c1, c2, B.clone())
      const segs = Math.min(96, Math.max(24, Math.round(len / 1.5)))
      tube.geometry.dispose(); glow.geometry.dispose()
      tube.geometry = new THREE.TubeGeometry(curve, segs, st.radius, 8, false)
      glow.geometry = new THREE.TubeGeometry(curve, Math.max(16, segs >> 1), st.radius * 3.2, 8, false)
      uniforms.uLen.value = curve.getLength()
    },
    setState(opacity, boost) {
      uniforms.uOpacity.value = opacity
      uniforms.uBoost.value = boost
      glowMat.opacity = 0.09 * opacity * (1 + boost * 1.5)
    },
  }
}

// ------------------------------------------------------------------ 2D

/** pontos de controle da curva 2D (cúbica) com caimento para baixo e desvio lateral */
export function curve2D(l: TopoLink, key: string, ax: number, ay: number, bx: number, by: number): [number, number, number, number, number, number, number, number] {
  const st = cableStyle(l)
  const [s1] = seedOf(key)
  const dx = bx - ax; const dy = by - ay
  const len = Math.hypot(dx, dy) || 1
  const nx = -dy / len; const ny = dx / len
  const lat = len * 0.14 * (s1 - 0.5) * 2
  const sag = len * st.sag * 0.7
  return [ax, ay, ax + dx * 0.3 + nx * lat, ay + dy * 0.3 + ny * lat + sag, ax + dx * 0.7 - nx * lat * 0.6, ay + dy * 0.7 - ny * lat * 0.6 + sag * 0.85, bx, by]
}
function bez(p: number[], t: number): [number, number] {
  const u = 1 - t
  const a = u * u * u; const b = 3 * u * u * t; const c = 3 * u * t * t; const d = t * t * t
  return [a * p[0]! + b * p[2]! + c * p[4]! + d * p[6]!, a * p[1]! + b * p[3]! + c * p[5]! + d * p[7]!]
}
function rgba(hex: string, a: number): string {
  const n = parseInt(hex.replace('#', ''), 16)
  return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`
}

export function drawLedCable2D(ctx: CanvasRenderingContext2D, l: TopoLink, key: string, ax: number, ay: number, bx: number, by: number,
  color: string, scale: number, time: number, opacity: number, boost: number): void {
  const st = cableStyle(l)
  const [s1] = seedOf(key)
  const p = curve2D(l, key, ax, ay, bx, by)
  ctx.save()
  ctx.globalAlpha = opacity
  ctx.lineCap = 'round'
  // capa do cabo: halo + corpo translúcido
  const w = (st.radius * 4.2) / Math.sqrt(scale) + 0.6 / scale
  ctx.beginPath(); ctx.moveTo(p[0], p[1]); ctx.bezierCurveTo(p[2], p[3], p[4], p[5], p[6], p[7])
  ctx.strokeStyle = rgba(color, 0.12 + 0.12 * boost); ctx.lineWidth = w * 3.2; ctx.stroke()
  ctx.strokeStyle = rgba(color, 0.32 + 0.2 * boost); ctx.lineWidth = w; ctx.stroke()
  // LEDs: espaçamento constante na tela, brilho pelos trens de pulso
  let length = 0; let [px, py] = bez(p, 0)
  for (let i = 1; i <= 24; i++) { const [x, y] = bez(p, i / 24); length += Math.hypot(x - px, y - py); px = x; py = y }
  const gap = Math.max(1.4, 6.5 / scale)
  const n = Math.max(2, Math.floor(length / gap))
  const tt = time * st.speed * 60
  const r = Math.max(0.35, 1.25 / scale) * (st.radius / 0.5) ** 0.5
  for (let i = 0; i <= n; i++) {
    const d = (i / n) * length
    const f = ((((d - tt) / 28 + s1) % 1) + 1) % 1
    const bk = ((((d + tt * 0.75) / 37 + s1 * 1.7) % 1) + 1) % 1
    const go = Math.pow(Math.max(0, (f - 0.72) / 0.28), 2.2)
    const back = Math.pow(Math.max(0, (bk - 0.8) / 0.2), 2.2) * 0.7
    const pulse = (go + back) * st.activity
    const [x, y] = bez(p, i / n)
    const a = Math.min(1, 0.3 + 0.2 * boost + pulse * 1.2)
    if (pulse > 0.25) { ctx.fillStyle = rgba(color, 0.25 * a); ctx.beginPath(); ctx.arc(x, y, r * 3, 0, Math.PI * 2); ctx.fill() }
    ctx.fillStyle = pulse > 0.5 ? `rgba(255,255,255,${a})` : rgba(color, a)
    ctx.beginPath(); ctx.arc(x, y, r * (1 + pulse * 0.6), 0, Math.PI * 2); ctx.fill()
  }
  ctx.restore()
}

/** área de clique/hover da curva no 2D */
export function hitCable2D(ctx: CanvasRenderingContext2D, l: TopoLink, key: string, ax: number, ay: number, bx: number, by: number, color: string, scale: number): void {
  const p = curve2D(l, key, ax, ay, bx, by)
  ctx.strokeStyle = color; ctx.lineWidth = 8 / scale
  ctx.beginPath(); ctx.moveTo(p[0], p[1]); ctx.bezierCurveTo(p[2], p[3], p[4], p[5], p[6], p[7]); ctx.stroke()
}
