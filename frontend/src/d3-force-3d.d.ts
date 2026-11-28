declare module 'd3-force-3d' {
  // só o que o mapa usa: colisão entre nós e gravidade leve ao centro (nós isolados não fogem)
  interface Force { strength(s: number): Force; iterations(n: number): Force }
  export function forceCollide<N = unknown>(radius?: number | ((n: N) => number)): Force
  export function forceX(x?: number): Force
  export function forceY(y?: number): Force
  export function forceZ(z?: number): Force
}
