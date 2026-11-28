<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { TopoLink, TopoNode, Topology } from '../../lib/virt'

// Grafo da rede em 3D (three.js/WebGL via 3d-force-graph) ou 2D (canvas via force-graph).
// Cores: função do equipamento nos nós; cor real do cabo nas ligações; partículas = tráfego em cabo ativo.
const props = defineProps<{ data: Topology; dim: '3d' | '2d'; focus?: string | null; dark: boolean }>()
const emit = defineEmits<{ select: [node: TopoNode | null] }>()
const el = ref<HTMLDivElement | null>(null)
// biblioteca carregada sob demanda; tipagem mínima do que usamos
type G = Record<string, (...a: unknown[]) => G> & { _destructor?: () => void }
let graph: G | null = null
let ro: ResizeObserver | null = null
let hover: TopoNode | null = null
let selected: TopoNode | null = null
const neighbors = new Map<string, Set<string>>()

const KIND_COLOR: Record<string, string> = { circuit: '#a855f7', cloud: '#0ea5e9', cluster: '#f97316', vm: '#c084fc' }
const LINK_COLOR: Record<string, string> = { power: '#facc15', console: '#94a3b8', member: '#f97316', virtual: '#c084fc', provider: '#38bdf8' }
const idOf = (x: string | TopoNode) => (typeof x === 'string' ? x : x.id)
const nodeColor = (n: TopoNode) => (n.kind === 'device' ? `#${n.color && /^[0-9a-f]{6}$/i.test(n.color) ? n.color : '64748b'}` : KIND_COLOR[n.kind] ?? '#64748b')
const linkColor = (l: TopoLink) => LINK_COLOR[l.kind] ?? (l.color ? `#${l.color}` : '#60a5fa')
const nodeSize = (n: TopoNode) => (n.kind === 'cloud' ? 14 : n.kind === 'circuit' ? 9 : n.kind === 'cluster' ? 10 : n.kind === 'vm' ? 3.5 : n.passive ? 3.5 : 5 + Math.min(10, (n.connected ?? 0) / 3))
const dimmed = (id: string) => { const f = hover ?? selected; return !!f && f.id !== id && !neighbors.get(f.id)?.has(id) }

function label(n: TopoNode): string {
  const rows = [n.kind === 'device' ? n.role : { circuit: 'Circuito', cloud: 'Rede da operadora', cluster: `Cluster ${n.type ?? ''}`, vm: 'Máquina virtual' }[n.kind], n.primary_ip, n.branch]
  return `<div class="g-tip"><b>${esc(n.name)}</b>${rows.filter(Boolean).map((r) => `<span>${esc(String(r))}</span>`).join('')}</div>`
}
const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c] ?? c)

function index(): void {
  neighbors.clear()
  for (const l of props.data.links) {
    const a = idOf(l.source); const b = idOf(l.target)
    if (!neighbors.has(a)) neighbors.set(a, new Set())
    if (!neighbors.has(b)) neighbors.set(b, new Set())
    neighbors.get(a)!.add(b); neighbors.get(b)!.add(a)
  }
}

async function build(): Promise<void> {
  destroy()
  if (!el.value) return
  index()
  // passivos sem nenhuma ligação (organizador de cabos, painel cego) só poluem o mapa
  const linked = new Set(props.data.links.flatMap((l) => [idOf(l.source), idOf(l.target)]))
  const data = { nodes: props.data.nodes.filter((n) => !n.passive || linked.has(n.id)).map((n) => ({ ...n })), links: props.data.links.map((l) => ({ ...l })) }
  const bg = 'rgba(0,0,0,0)'
  if (props.dim === '3d') {
    const [{ default: ForceGraph3D }, THREE] = await Promise.all([import('3d-force-graph'), import('three')])
    const textSprite = (text: string, color: string): InstanceType<typeof THREE.Sprite> => {
      const c = document.createElement('canvas'); const ctx = c.getContext('2d')!
      const font = '600 44px Inter, system-ui, sans-serif'; ctx.font = font
      c.width = Math.ceil(ctx.measureText(text).width) + 24; c.height = 64
      ctx.font = font; ctx.fillStyle = 'rgba(8,12,24,.72)'; ctx.beginPath(); ctx.roundRect(0, 4, c.width, 56, 16); ctx.fill()
      ctx.fillStyle = color; ctx.textBaseline = 'middle'; ctx.fillText(text, 12, 33)
      const tex = new THREE.CanvasTexture(c); tex.colorSpace = THREE.SRGBColorSpace
      const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: tex, depthWrite: false, transparent: true }))
      s.scale.set(c.width / 9, c.height / 9, 1)
      return s
    }
    const g = new ForceGraph3D(el.value, { controlType: 'orbit' }) as unknown as G
    g.backgroundColor!(bg)
      .showNavInfo!(false)
      .nodeLabel!((n: TopoNode) => label(n))
      .nodeVal!((n: TopoNode) => nodeSize(n))
      .nodeColor!((n: TopoNode) => (dimmed(n.id) ? 'rgba(100,116,139,.25)' : nodeColor(n)))
      .nodeOpacity!(0.95)
      .nodeResolution!(20)
      .nodeThreeObjectExtend!(true)
      .nodeThreeObject!((n: TopoNode) => {
        const group = new THREE.Group()
        if (n.kind === 'cloud' || n.kind === 'cluster') {
          const ring = new THREE.Mesh(new THREE.TorusGeometry(n.kind === 'cloud' ? 16 : 11, 0.9, 12, 48), new THREE.MeshBasicMaterial({ color: nodeColor(n), transparent: true, opacity: 0.55 }))
          ring.rotation.x = Math.PI / 2.4
          group.add(ring)
        }
        if (n.kind !== 'vm' || data.nodes.length < 80) {
          const s = textSprite(n.name, props.dark ? '#e2e8f0' : '#f8fafc')
          s.position.set(0, Math.cbrt(nodeSize(n)) * 4 + 6, 0)
          group.add(s)
        }
        return group
      })
      .linkColor!((l: TopoLink) => (dimmed(idOf(l.source)) && dimmed(idOf(l.target)) ? 'rgba(100,116,139,.12)' : linkColor(l)))
      .linkWidth!((l: TopoLink) => (l.kind === 'member' || l.kind === 'virtual' ? 0.4 : l.kind === 'provider' ? 1.2 : 1.4))
      .linkOpacity!(0.75)
      .linkCurvature!((l: TopoLink) => (l.kind === 'virtual' ? 0.25 : 0.08))
      .linkDirectionalParticles!((l: TopoLink) => (l.kind === 'data' && l.active ? 3 : l.kind === 'provider' ? 4 : 0))
      .linkDirectionalParticleWidth!(2.2)
      .linkDirectionalParticleSpeed!(0.006)
      .linkDirectionalParticleColor!((l: TopoLink) => linkColor(l))
      .linkLabel!((l: TopoLink) => linkTip(l))
      .onNodeHover!((n: TopoNode | null) => { hover = n; el.value!.style.cursor = n ? 'pointer' : 'grab'; refresh() })
      .onNodeClick!((n: TopoNode & { x: number; y: number; z: number }) => { selected = n; emit('select', n); flyTo(n); refresh() })
      .onBackgroundClick!(() => { selected = null; emit('select', null); refresh() })
      .graphData!(data)
    g.d3Force!('charge')!.strength!(-160)
    const scene = g.scene!() as unknown as InstanceType<typeof THREE.Scene>
    scene.fog = new THREE.FogExp2(props.dark ? 0x0b1020 : 0x0f172a, 0.0011)
    // estrelas de fundo: profundidade sem pesar
    const pts = new Float32Array(1500 * 3); for (let i = 0; i < pts.length; i++) pts[i] = (Math.random() - 0.5) * 4000
    const geo = new THREE.BufferGeometry(); geo.setAttribute('position', new THREE.BufferAttribute(pts, 3))
    scene.add(new THREE.Points(geo, new THREE.PointsMaterial({ color: 0x64748b, size: 2, transparent: true, opacity: 0.5 })))
    graph = g
  } else {
    const { default: ForceGraph } = await import('force-graph')
    const g = new ForceGraph(el.value) as unknown as G
    g.backgroundColor!(bg)
      .nodeLabel!((n: TopoNode) => label(n))
      .nodeRelSize!(4)
      .nodeVal!((n: TopoNode) => nodeSize(n))
      .nodeCanvasObject!((n: TopoNode & { x: number; y: number }, ctx: CanvasRenderingContext2D, scale: number) => {
        const r = Math.sqrt(nodeSize(n)) * 4
        const dim = dimmed(n.id)
        ctx.globalAlpha = dim ? 0.2 : 1
        ctx.shadowColor = nodeColor(n); ctx.shadowBlur = selected?.id === n.id ? 24 : 10
        ctx.beginPath()
        if (n.kind === 'cluster' || n.kind === 'circuit') { ctx.roundRect(n.x - r, n.y - r, r * 2, r * 2, r / 2) } else ctx.arc(n.x, n.y, r, 0, 2 * Math.PI)
        ctx.fillStyle = nodeColor(n); ctx.fill(); ctx.shadowBlur = 0
        if (selected?.id === n.id) { ctx.lineWidth = 2 / scale; ctx.strokeStyle = '#fff'; ctx.stroke() }
        if (scale > 0.9 || n.kind !== 'vm') {
          const fs = Math.max(10 / scale, 3); ctx.font = `600 ${fs}px Inter, system-ui, sans-serif`
          ctx.textAlign = 'center'; ctx.textBaseline = 'top'; ctx.fillStyle = props.dark ? '#cbd5e1' : '#1e293b'
          ctx.fillText(n.name, n.x, n.y + r + 2 / scale)
        }
        ctx.globalAlpha = 1
      })
      .nodePointerAreaPaint!((n: TopoNode & { x: number; y: number }, color: string, ctx: CanvasRenderingContext2D) => { ctx.fillStyle = color; ctx.beginPath(); ctx.arc(n.x, n.y, Math.sqrt(nodeSize(n)) * 4 + 2, 0, 2 * Math.PI); ctx.fill() })
      .linkColor!((l: TopoLink) => (dimmed(idOf(l.source)) && dimmed(idOf(l.target)) ? 'rgba(100,116,139,.15)' : linkColor(l)))
      .linkWidth!((l: TopoLink) => (l.kind === 'member' || l.kind === 'virtual' ? 0.6 : 2))
      .linkLineDash!((l: TopoLink) => (l.kind === 'virtual' || l.kind === 'member' || l.active === false || l.complete === false ? [4, 3] : null))
      .linkCurvature!((l: TopoLink) => (l.kind === 'virtual' ? 0.2 : 0.05))
      .linkDirectionalParticles!((l: TopoLink) => (l.kind === 'data' && l.active ? 2 : l.kind === 'provider' ? 3 : 0))
      .linkDirectionalParticleWidth!(3)
      .linkDirectionalParticleSpeed!(0.006)
      .linkLabel!((l: TopoLink) => linkTip(l))
      .onNodeHover!((n: TopoNode | null) => { hover = n; el.value!.style.cursor = n ? 'pointer' : 'grab' })
      .onNodeClick!((n: TopoNode & { x: number; y: number }) => { selected = n; emit('select', n); g.centerAt!(n.x, n.y, 600); g.zoom!(2.4, 600) })
      .onBackgroundClick!(() => { selected = null; emit('select', null) })
      .graphData!(data)
    g.d3Force!('charge')!.strength!(-220)
    graph = g
  }
  resize()
  // enquadra quando a simulação assenta (uma vez por montagem)
  let fitted = false
  graph?.cooldownTime!(3000).onEngineStop!(() => { if (!fitted) { fitted = true; graph?.zoomToFit?.(700, 50) } })
}

function linkTip(l: TopoLink): string {
  if (l.kind === 'member') return '<div class="g-tip">host do cluster</div>'
  if (l.kind === 'virtual') return '<div class="g-tip">VM roda neste host</div>'
  if (l.kind === 'provider') return '<div class="g-tip">rede da operadora</div>'
  const parts = [l.from_port && l.to_port ? `${l.from_port} ⇄ ${l.to_port}` : null, l.labels || null, l.type, l.via?.length ? `via ${l.via.join(' → ')}` : null,
    l.length_m ? `${String(+l.length_m.toFixed(1)).replace('.', ',')} m` : null, l.active === false ? 'trecho planejado' : null, l.complete === false ? 'caminho incompleto' : null]
  return `<div class="g-tip">${parts.filter(Boolean).map((p) => `<span>${esc(String(p))}</span>`).join('')}</div>`
}
function refresh(): void {
  if (!graph) return
  if (props.dim === '3d') { graph.nodeColor!(graph.nodeColor!()); graph.linkColor!(graph.linkColor!()) }
}
function flyTo(n: { x?: number; y?: number; z?: number }): void {
  if (!graph || props.dim !== '3d') return
  const d = 140; const r = 1 + d / Math.hypot(n.x ?? 1, n.y ?? 1, n.z ?? 1)
  graph.cameraPosition!({ x: (n.x ?? 0) * r, y: (n.y ?? 0) * r, z: (n.z ?? 0) * r }, { x: n.x, y: n.y, z: n.z }, 1200)
}
function resize(): void {
  if (!graph || !el.value) return
  graph.width!(el.value.clientWidth).height!(el.value.clientHeight)
}
function destroy(): void {
  graph?._destructor?.()
  graph = null
  if (el.value) el.value.innerHTML = ''
}
function focusId(id: string | null | undefined): void {
  if (!graph || !id) return
  const n = (graph.graphData!() as unknown as { nodes: (TopoNode & { x: number; y: number; z: number })[] }).nodes.find((x) => x.id === id)
  if (!n) return
  selected = n; emit('select', n)
  if (props.dim === '3d') flyTo(n); else { graph.centerAt!(n.x, n.y, 700); graph.zoom!(2.6, 700) }
  refresh()
}
defineExpose({ fit: () => graph?.zoomToFit?.(800, 60), focus: focusId })
onMounted(() => {
  void build()
  ro = new ResizeObserver(resize)
  if (el.value) ro.observe(el.value)
})
watch(() => [props.data, props.dim, props.dark], () => { selected = null; void build() })
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
  background: rgba(15, 23, 42, .88); border: 1px solid rgba(148, 163, 184, .25); backdrop-filter: blur(8px); box-shadow: 0 10px 30px -10px #000; }
.g-tip b { font-size: 13px; color: #fff; }
.g-tip span { color: #94a3b8; }
.scene-tooltip, .float-tooltip-kap { padding: 0 !important; background: transparent !important; }
</style>
