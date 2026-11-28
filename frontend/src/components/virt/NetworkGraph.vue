<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ASPECT, ICON_PAD, iconCanvas, iconOf, RACK_ICONS, type NetIcon } from '../../lib/netIcons'
import { createLedCable, drawLedCable2D, hitCable2D, type LedCable3D } from '../../lib/ledCable'
import type { TopoLink, TopoNode, Topology } from '../../lib/virt'

// Grafo da rede (ADR-0017/0018). Cada nó é o desenho do equipamento (lib/netIcons):
// 3D (three.js) — equipamento de rack vira caixa com a frente desenhada, sempre em ¾ para a câmera; os demais são sprites.
// 2D (canvas) — o mesmo desenho, nítido em qualquer zoom. Ligações na cor real do cabo; partículas = cabo ativo.
const props = defineProps<{ data: Topology; dim: '3d' | '2d'; focus?: string | null; dark: boolean }>()
const emit = defineEmits<{ select: [node: TopoNode | null] }>()
const el = ref<HTMLDivElement | null>(null)
type G = Record<string, (...a: unknown[]) => G> & { _destructor?: () => void }
let graph: G | null = null
let ro: ResizeObserver | null = null
let raf = 0
let hover: TopoNode | null = null
let selected: TopoNode | null = null
const neighbors = new Map<string, Set<string>>()

const KIND_COLOR: Record<string, string> = { circuit: '#a855f7', cloud: '#0ea5e9', cluster: '#f97316', vm: '#a855f7' }
const LINK_COLOR: Record<string, string> = { power: '#facc15', console: '#94a3b8', member: '#f97316', virtual: '#c084fc', provider: '#38bdf8' }
const idOf = (x: string | TopoNode) => (typeof x === 'string' ? x : x.id)
const nodeColor = (n: TopoNode) => (n.kind === 'device' ? `#${n.color && /^[0-9a-f]{6}$/i.test(n.color) ? n.color : '64748b'}` : KIND_COLOR[n.kind] ?? '#64748b')
// cabo preto/marrom escuro some no fundo: LED usa um cinza-azulado no lugar
const visible = (hex: string) => { const n = parseInt(hex, 16); return 0.299 * (n >> 16) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255) < 45 ? '94a3b8' : hex }
const linkColor = (l: TopoLink) => LINK_COLOR[l.kind] ?? (l.color && /^[0-9a-f]{6}$/i.test(l.color) ? `#${visible(l.color)}` : '#60a5fa')
const icon = (n: TopoNode): NetIcon => iconOf(n.kind, n.icon, n.role)
/** altura do desenho em unidades do mapa */
function height(n: TopoNode, i = icon(n)): number {
  if (RACK_ICONS.has(i)) return i === 'server' || i === 'hypervisor' || i === 'storage' ? Math.max(2, n.u_height ?? 2) * 5 : 6
  return { power_panel: 17, ups: 14, ont: 9, ap: 10, workstation: 13, printer: 11, phone: 9, camera: 8, jack: 9, vm: 9, cluster: 15, circuit: 14, cloud: 18 }[i as string] ?? 10
}
const width = (n: TopoNode, i = icon(n)) => height(n, i) * ASPECT[i]
const dimmed = (id: string) => { const f = hover ?? selected; return !!f && f.id !== id && !neighbors.get(f.id)?.has(id) }
const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c] ?? c)
function label(n: TopoNode): string {
  const rows = [n.kind === 'device' ? [n.role, n.device_type].filter(Boolean).join(' · ') : { circuit: `Circuito ${n.provider ?? ''}`, cloud: 'Rede da operadora', cluster: `Cluster ${n.type ?? ''}`, vm: `VM · ${n.platform ?? ''}` }[n.kind],
    n.primary_ip, [n.branch, n.rack].filter(Boolean).join(' › ')]
  return `<div class="g-tip"><b>${esc(n.name)}</b>${rows.filter(Boolean).map((r) => `<span>${esc(String(r))}</span>`).join('')}</div>`
}
function linkTip(l: TopoLink): string {
  if (l.kind === 'member') return '<div class="g-tip">host do cluster</div>'
  if (l.kind === 'virtual') return '<div class="g-tip">VM roda neste host</div>'
  if (l.kind === 'provider') return '<div class="g-tip">rede da operadora</div>'
  const parts = [l.from_port && l.to_port ? `${l.from_port} ⇄ ${l.to_port}` : null, l.labels || null, l.type, l.via?.length ? `via ${l.via.join(' → ')}` : null,
    l.length_m ? `${String(+l.length_m.toFixed(1)).replace('.', ',')} m` : null, l.active === false ? 'trecho planejado' : null, l.complete === false ? 'caminho incompleto' : null]
  return `<div class="g-tip">${parts.filter(Boolean).map((p) => `<span>${esc(String(p))}</span>`).join('')}</div>`
}
function index(): void {
  neighbors.clear()
  for (const l of props.data.links) {
    const a = idOf(l.source); const b = idOf(l.target)
    if (!neighbors.has(a)) neighbors.set(a, new Set())
    if (!neighbors.has(b)) neighbors.set(b, new Set())
    neighbors.get(a)!.add(b); neighbors.get(b)!.add(a)
  }
}

// --- 3D: objetos por nó, para destacar/apagar sem reconstruir
interface Obj3 { box: { quaternion: { copy: (q: unknown) => { multiply: (q: unknown) => void } } } | null; mats: { opacity: number; transparent: boolean }[]; halo: { visible: boolean; material: { opacity: number } } }
const objs = new Map<string, Obj3>()
const cables = new Map<TopoLink, LedCable3D>()
type KL = TopoLink & { __k: string }
const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches
/** estado do cabo conforme o foco: [opacidade, realce] */
function cableState(l: TopoLink): [number, number] {
  const f = hover ?? selected
  if (!f) return [1, 0]
  const a = idOf(l.source); const b = idOf(l.target)
  if (a === f.id || b === f.id) return [1, 1]
  return dimmed(a) && dimmed(b) ? [0.1, 0] : [0.45, 0]
}

async function build(): Promise<void> {
  destroy()
  if (!el.value) return
  index()
  // passivos sem nenhuma ligação (organizador de cabos, painel cego) só poluem o mapa
  const linked = new Set(props.data.links.flatMap((l) => [idOf(l.source), idOf(l.target)]))
  const data = { nodes: props.data.nodes.filter((n) => !n.passive || linked.has(n.id)).map((n) => ({ ...n })), links: props.data.links.map((l, i) => ({ ...l, __k: `${idOf(l.source)}>${idOf(l.target)}#${i}` })) }
  const { forceCollide, forceX, forceY, forceZ } = await import('d3-force-3d')
  const k = props.dim === '2d' ? 1.25 : 1
  const collide = forceCollide<TopoNode>((n) => width(n) * 0.55 * k + 4).strength(0.9)
  const gravity = (g: G) => { g.d3Force!('x', forceX(0).strength(0.04)); g.d3Force!('y', forceY(0).strength(0.04)); if (props.dim === '3d') g.d3Force!('z', forceZ(0).strength(0.04)) }
  const bg = 'rgba(0,0,0,0)'

  if (props.dim === '3d') {
    const [{ default: ForceGraph3D }, THREE] = await Promise.all([import('3d-force-graph'), import('three')])
    const texCache = new Map<string, InstanceType<typeof THREE.CanvasTexture>>()
    const tex = (i: NetIcon, color: string, crop: boolean) => {
      const key = `${i}|${color}|${crop}`
      let t = texCache.get(key)
      if (!t) {
        const cv = iconCanvas(i, color, RACK_ICONS.has(i) ? 128 : 192)
        t = new THREE.CanvasTexture(cv)
        t.colorSpace = THREE.SRGBColorSpace
        t.anisotropy = 8
        if (crop) { const p = ICON_PAD / (1 + 2 * ICON_PAD); const pw = (p * cv.height) / cv.width; t.repeat.set(1 - 2 * pw, 1 - 2 * p); t.offset.set(pw, p) }
        texCache.set(key, t)
      }
      return t
    }
    const textSprite = (text: string, sub: string | null) => {
      const c = document.createElement('canvas'); const ctx = c.getContext('2d')!
      const f1 = '600 40px Inter, system-ui, sans-serif'; const f2 = '500 28px Inter, system-ui, sans-serif'
      ctx.font = f1; const w1 = ctx.measureText(text).width; ctx.font = f2; const w2 = sub ? ctx.measureText(sub).width : 0
      c.width = Math.ceil(Math.max(w1, w2)) + 32; c.height = sub ? 92 : 58
      ctx.fillStyle = 'rgba(8,12,24,.78)'; ctx.beginPath(); ctx.roundRect(0, 0, c.width, c.height, 14); ctx.fill()
      ctx.strokeStyle = 'rgba(148,163,184,.25)'; ctx.lineWidth = 2; ctx.stroke()
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle'
      ctx.font = f1; ctx.fillStyle = '#f1f5f9'; ctx.fillText(text, c.width / 2, 30)
      if (sub) { ctx.font = f2; ctx.fillStyle = '#7dd3fc'; ctx.fillText(sub, c.width / 2, 68) }
      const t = new THREE.CanvasTexture(c); t.colorSpace = THREE.SRGBColorSpace
      const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: t, depthWrite: false, transparent: true }))
      s.scale.set(c.width / 14, c.height / 14, 1)
      return s
    }
    const haloTex = (() => {
      const c = document.createElement('canvas'); c.width = c.height = 128; const ctx = c.getContext('2d')!
      const g = ctx.createRadialGradient(64, 64, 4, 64, 64, 64); g.addColorStop(0, 'rgba(255,255,255,.9)'); g.addColorStop(0.35, 'rgba(255,255,255,.35)'); g.addColorStop(1, 'rgba(255,255,255,0)')
      ctx.fillStyle = g; ctx.fillRect(0, 0, 128, 128); return new THREE.CanvasTexture(c)
    })()
    const metal = new THREE.MeshStandardMaterial({ color: 0x2a3446, metalness: 0.35, roughness: 0.45 })
    const tilt = new THREE.Quaternion().setFromEuler(new THREE.Euler(0.32, -0.5, 0))

    const timeU = { value: 0 }
    const g = new ForceGraph3D(el.value, { controlType: 'orbit' }) as unknown as G
    g.backgroundColor!(bg)
      .showNavInfo!(false)
      .nodeLabel!((n: TopoNode) => label(n))
      .nodeThreeObjectExtend!(false)
      .nodeThreeObject!((n: TopoNode) => {
        const i = icon(n); const color = nodeColor(n); const h = height(n, i); const w = width(n, i)
        const group = new THREE.Group()
        const mats: { opacity: number; transparent: boolean }[] = []
        let box: Obj3['box'] = null
        if (RACK_ICONS.has(i)) {
          const front = new THREE.MeshStandardMaterial({ map: tex(i, color, true), emissive: 0xffffff, emissiveMap: tex(i, color, true), emissiveIntensity: 0.55, roughness: 0.6, metalness: 0.1 })
          const side = metal.clone()
          const mesh = new THREE.Mesh(new THREE.BoxGeometry(w, h, Math.min(w * 0.42, h * 5)), [side, side, side, side, front, side])
          group.add(mesh); box = mesh as unknown as Obj3['box']; mats.push(front, side)
        } else {
          const sm = new THREE.SpriteMaterial({ map: tex(i, color, false), transparent: true, depthWrite: false })
          const s = new THREE.Sprite(sm); s.scale.set(w * (1 + 2 * ICON_PAD * (h / w)), h * (1 + 2 * ICON_PAD), 1)
          group.add(s); mats.push(sm)
        }
        if (n.kind === 'cloud' || n.kind === 'cluster') {
          const ring = new THREE.Mesh(new THREE.TorusGeometry(w * 0.62, 0.5, 12, 64), new THREE.MeshBasicMaterial({ color, transparent: true, opacity: 0.45 }))
          ring.rotation.x = Math.PI / 2.3; group.add(ring); mats.push(ring.material as unknown as { opacity: number; transparent: boolean })
        }
        const halo = new THREE.Sprite(new THREE.SpriteMaterial({ map: haloTex, color, transparent: true, opacity: 0.85, depthWrite: false, blending: THREE.AdditiveBlending }))
        halo.scale.set(w * 1.6, h * 2.6 + 12, 1); halo.visible = false; halo.renderOrder = -1; group.add(halo)
        const lbl = textSprite(n.name, n.kind === 'device' ? n.primary_ip ?? null : n.kind === 'vm' ? n.primary_ip ?? null : n.kind === 'circuit' ? n.cid ?? null : null)
        lbl.position.set(0, -(h / 2 + (RACK_ICONS.has(i) ? Math.min(w * 0.42, h * 5) * 0.3 : 0) + 6), 0); group.add(lbl); mats.push(lbl.material as unknown as { opacity: number; transparent: boolean })
        objs.set(n.id, { box, mats, halo: halo as unknown as Obj3['halo'] })
        return group
      })
      // cabos: fita de LED em tubo curvo (lib/ledCable)
      .linkThreeObjectExtend!(false)
      .linkThreeObject!((l: KL) => { const c = createLedCable(THREE, l, linkColor(l), l.__k, timeU); cables.set(l, c); return c.object })
      .linkPositionUpdate!((_o: unknown, p: { start: { x: number; y: number; z: number }; end: { x: number; y: number; z: number } }, l: TopoLink) => { cables.get(l)?.update(p.start, p.end); return true })
      .linkLabel!((l: TopoLink) => linkTip(l))
      .onNodeHover!((n: TopoNode | null) => { hover = n; el.value!.style.cursor = n ? 'pointer' : 'grab'; paint3d() })
      .onNodeClick!((n: TopoNode & { x: number; y: number; z: number }) => { selected = n; emit('select', n); flyTo(n); paint3d() })
      .onBackgroundClick!(() => { selected = null; emit('select', null); paint3d() })
      .graphData!(data)
    g.d3Force!('charge')!.strength!(-140)
    g.d3Force!('link')!.distance!((l: TopoLink) => (l.kind === 'virtual' || l.kind === 'member' ? 24 : 40))
    g.d3Force!('collide', collide)
    gravity(g)
    const scene = g.scene!() as unknown as InstanceType<typeof THREE.Scene>
    scene.fog = new THREE.FogExp2(0x070b16, 0.0012)
    const key = new THREE.DirectionalLight(0xbfdbfe, 1.6); key.position.set(200, 300, 400); scene.add(key)
    const rim = new THREE.DirectionalLight(0xc084fc, 0.8); rim.position.set(-300, -100, -200); scene.add(rim)
    // fundo: grade "piso de datacenter" + estrelas, para dar profundidade sem pesar
    const grid = new THREE.GridHelper(1600, 64, 0x1e3a5f, 0x0f1b2e); grid.position.y = -160
    ;(grid.material as InstanceType<typeof THREE.Material>).transparent = true; (grid.material as InstanceType<typeof THREE.Material>).opacity = 0.45
    scene.add(grid)
    const pts = new Float32Array(1200 * 3); for (let k = 0; k < pts.length; k++) pts[k] = (Math.random() - 0.5) * 3000
    const geo = new THREE.BufferGeometry(); geo.setAttribute('position', new THREE.BufferAttribute(pts, 3))
    scene.add(new THREE.Points(geo, new THREE.PointsMaterial({ color: 0x475569, size: 1.6, transparent: true, opacity: 0.5 })))
    // caixas sempre em ¾ para a câmera: frente legível, topo e lateral visíveis
    const cam = g.camera!() as unknown as { quaternion: unknown }
    const t0 = performance.now()
    const loop = () => {
      for (const o of objs.values()) o.box?.quaternion.copy(cam.quaternion).multiply(tilt)
      if (!reduced) timeU.value = (performance.now() - t0) / 1000
      raf = requestAnimationFrame(loop)
    }
    loop()
    graph = g
  } else {
    const { default: ForceGraph } = await import('force-graph')
    const g = new ForceGraph(el.value) as unknown as G
    g.backgroundColor!(bg)
      .nodeLabel!((n: TopoNode) => label(n))
      .nodeCanvasObject!((n: TopoNode & { x: number; y: number }, ctx: CanvasRenderingContext2D, scale: number) => {
        const i = icon(n); const h = height(n, i) * 1.25; const w = h * ASPECT[i]; const color = nodeColor(n)
        const on = selected?.id === n.id || hover?.id === n.id
        ctx.globalAlpha = dimmed(n.id) ? 0.18 : 1
        if (on) {
          ctx.save(); ctx.shadowColor = color; ctx.shadowBlur = 30; ctx.fillStyle = color; ctx.globalAlpha *= 0.35
          ctx.beginPath(); ctx.roundRect(n.x - w / 2 - 2, n.y - h / 2 - 2, w + 4, h + 4, 3); ctx.fill(); ctx.restore()
        }
        const cv = iconCanvas(i, color, scale > 3 ? 256 : 128)
        const pad = h * ICON_PAD
        ctx.drawImage(cv, n.x - w / 2 - pad, n.y - h / 2 - pad, w + pad * 2, h + pad * 2)
        if (scale > 0.7 || n.kind !== 'vm') {
          const fs = Math.max(9 / scale, 2.6); ctx.font = `600 ${fs}px Inter, system-ui, sans-serif`
          const tw = ctx.measureText(n.name).width; const ty = n.y + h / 2 + 2.5
          ctx.fillStyle = props.dark ? 'rgba(8,12,24,.75)' : 'rgba(255,255,255,.85)'
          ctx.beginPath(); ctx.roundRect(n.x - tw / 2 - fs * 0.4, ty - fs * 0.15, tw + fs * 0.8, fs * 1.3, fs * 0.35); ctx.fill()
          ctx.textAlign = 'center'; ctx.textBaseline = 'top'; ctx.fillStyle = props.dark ? '#e2e8f0' : '#0f172a'
          ctx.fillText(n.name, n.x, ty)
          if (scale > 1.6 && n.primary_ip) { ctx.font = `500 ${fs * 0.8}px ui-monospace, monospace`; ctx.fillStyle = '#7dd3fc'; ctx.fillText(n.primary_ip, n.x, ty + fs * 1.3) }
        }
        ctx.globalAlpha = 1
      })
      .nodePointerAreaPaint!((n: TopoNode & { x: number; y: number }, c: string, ctx: CanvasRenderingContext2D) => {
        const h = height(n) * 1.25; const w = h * ASPECT[icon(n)]
        ctx.fillStyle = c; ctx.fillRect(n.x - w / 2, n.y - h / 2, w, h + 6)
      })
      .linkCanvasObjectMode!(() => 'replace')
      .linkCanvasObject!((l: KL & { source: TopoNode; target: TopoNode }, ctx: CanvasRenderingContext2D, scale: number) => {
        if (l.source.x === undefined || l.target.x === undefined) return
        const [o, b] = cableState(l)
        drawLedCable2D(ctx, l, l.__k, l.source.x, l.source.y!, l.target.x, l.target.y!, linkColor(l), scale, reduced ? 0 : performance.now() / 1000, o, b)
      })
      .linkPointerAreaPaint!((l: KL & { source: TopoNode; target: TopoNode }, c: string, ctx: CanvasRenderingContext2D, scale: number) => {
        if (l.source.x !== undefined && l.target.x !== undefined) hitCable2D(ctx, l, l.__k, l.source.x, l.source.y!, l.target.x, l.target.y!, c, scale)
      })
      .autoPauseRedraw!(reduced)
      .linkLabel!((l: TopoLink) => linkTip(l))
      .onNodeHover!((n: TopoNode | null) => { hover = n; el.value!.style.cursor = n ? 'pointer' : 'grab' })
      .onNodeClick!((n: TopoNode & { x: number; y: number }) => { selected = n; emit('select', n); g.centerAt!(n.x, n.y, 600); g.zoom!(3, 600) })
      .onBackgroundClick!(() => { selected = null; emit('select', null) })
      .graphData!(data)
    g.d3Force!('charge')!.strength!(-160)
    g.d3Force!('link')!.distance!((l: TopoLink) => (l.kind === 'virtual' || l.kind === 'member' ? 24 : 42))
    g.d3Force!('collide', collide)
    gravity(g)
    graph = g
  }
  resize()
  let fitted = false
  graph?.cooldownTime!(3500).onEngineStop!(() => { if (!fitted) { fitted = true; graph?.zoomToFit?.(700, props.dim === '3d' ? 20 : 40) } })
}

function paint3d(): void {
  for (const [id, o] of objs) {
    const d = dimmed(id)
    for (const m of o.mats) { m.transparent = true; m.opacity = d ? 0.14 : 1 }
    o.halo.visible = selected?.id === id || hover?.id === id
    o.halo.material.opacity = selected?.id === id ? 0.9 : 0.55
  }
  for (const [l, c] of cables) c.setState(...cableState(l))
}
function flyTo(n: { x?: number; y?: number; z?: number }): void {
  if (!graph || props.dim !== '3d') return
  const d = 160; const r = 1 + d / Math.max(1, Math.hypot(n.x ?? 1, n.y ?? 1, n.z ?? 1))
  graph.cameraPosition!({ x: (n.x ?? 0) * r, y: (n.y ?? 0) * r, z: (n.z ?? 0) * r }, { x: n.x, y: n.y, z: n.z }, 1200)
}
function resize(): void {
  if (!graph || !el.value) return
  graph.width!(el.value.clientWidth).height!(el.value.clientHeight)
}
function destroy(): void {
  cancelAnimationFrame(raf)
  objs.clear()
  cables.clear()
  graph?._destructor?.()
  graph = null
  if (el.value) el.value.innerHTML = ''
}
function focusId(id: string | null | undefined): void {
  if (!graph || !id) return
  const n = (graph.graphData!() as unknown as { nodes: (TopoNode & { x: number; y: number; z: number })[] }).nodes.find((x) => x.id === id)
  if (!n) return
  selected = n; emit('select', n)
  if (props.dim === '3d') { flyTo(n); paint3d() } else { graph.centerAt!(n.x, n.y, 700); graph.zoom!(3, 700) }
}
defineExpose({ fit: () => graph?.zoomToFit?.(800, 50), focus: focusId })
onMounted(() => {
  void build()
  ro = new ResizeObserver(resize)
  if (el.value) ro.observe(el.value)
})
watch(() => [props.data, props.dim, props.dark], () => { selected = null; hover = null; void build() })
watch(() => props.focus, (f) => focusId(f))
onBeforeUnmount(() => { ro?.disconnect(); destroy() })
</script>

<template>
  <div ref="el" class="graph" role="img" aria-label="Mapa interativo da rede: arraste para girar, role para aproximar, clique num equipamento para ver os detalhes" />
</template>

<style scoped>
.graph { position: absolute; inset: 0; }
</style>
<style>
.g-tip { display: flex; flex-direction: column; gap: 2px; padding: 8px 10px; border-radius: 10px; font: 12px Inter, system-ui, sans-serif; color: #e2e8f0;
  background: rgba(15, 23, 42, .9); border: 1px solid rgba(148, 163, 184, .25); backdrop-filter: blur(8px); box-shadow: 0 10px 30px -10px #000; }
.g-tip b { font-size: 13px; color: #fff; }
.g-tip span { color: #94a3b8; }
.scene-tooltip, .float-tooltip-kap { padding: 0 !important; background: transparent !important; }
</style>
