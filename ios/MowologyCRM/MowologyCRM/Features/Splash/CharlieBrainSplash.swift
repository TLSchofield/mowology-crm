//
//  CharlieBrainSplash.swift
//  MowologyCRM
//
//  The opening screen: Charlie's brain — everything the department heads have learned —
//  turning slowly while the app starts (owner, 2026-10-08: the brain graphic, nothing else).
//
//  A native port of public/crm/js/head-brain.js: the same 500 deterministic shapes (same
//  seeded random numbers, so shape N here is shape N on the web), the same tier materials,
//  one lit triangle per thing learned. The web places keyed items on "their" triangle by
//  key hash; the phone only gets tier counts, so it lights the same number of triangles,
//  best tiers first, in the web's sweep order.
//
//  Shown from the last-known brain (UserDefaults) so it appears instantly, offline too;
//  refreshed in the background from Charlie's card whenever the app is signed in.
//

import SwiftUI
import simd

// MARK: - Shape (port of head-brain.js shape / geodesic / pyramids)

struct BrainShape {
    typealias V = SIMD3<Double>
    let k: Int
    let tris: [[V]]

    static let shapes = 500

    /// head-brain.js rng(): mulberry32 with 32-bit wraparound — must match the JS exactly.
    private struct RNG {
        var s: UInt32
        init(seed: Int) { s = UInt32(truncatingIfNeeded: seed) }
        mutating func next() -> Double {
            s = s &+ 0x6D2B79F5
            var t = (s ^ (s >> 15)) &* (1 | s)
            t = (t &+ ((t ^ (t >> 7)) &* (61 | t))) ^ t
            return Double(t ^ (t >> 14)) / 4294967296.0
        }
    }

    private static func norm(_ v: V) -> V {
        let l = (v.x * v.x + v.y * v.y + v.z * v.z).squareRoot()
        return l == 0 ? v : v / l
    }

    private static func bipyramid(_ n: Int) -> [[V]] {
        let top = V(0, 1.15, 0), bot = V(0, -1.15, 0)
        let ring = (0..<n).map { i -> V in let a = Double(i) / Double(n) * 2 * .pi; return V(cos(a), 0, sin(a)) }
        var tris: [[V]] = []
        for i in 0..<n { let p = ring[i], q = ring[(i + 1) % n]; tris.append([top, q, p]); tris.append([bot, p, q]) }
        return tris
    }

    private static func gyrobipyramid(_ n: Int) -> [[V]] {
        let top = V(0, 1.25, 0), bot = V(0, -1.25, 0)
        var up: [V] = [], dn: [V] = []
        for i in 0..<n {
            let a = Double(i) / Double(n) * 2 * .pi, b = a + .pi / Double(n)
            up.append(V(cos(a), 0.38, sin(a))); dn.append(V(cos(b), -0.38, sin(b)))
        }
        var tris: [[V]] = []
        for i in 0..<n {
            let j = (i + 1) % n
            tris += [[top, up[j], up[i]], [up[i], up[j], dn[i]], [up[j], dn[j], dn[i]], [bot, dn[i], dn[j]]]
        }
        return tris
    }

    private static let baseFaces = ["tetra": 4, "octa": 8, "ico": 20]

    private static func base(_ name: String) -> [[V]] {
        switch name {
        case "tetra":
            let v = [V(1, 1, 1), V(-1, -1, 1), V(-1, 1, -1), V(1, -1, -1)].map(norm)
            return [[0, 1, 2], [0, 3, 1], [0, 2, 3], [1, 3, 2]].map { [v[$0[0]], v[$0[1]], v[$0[2]]] }
        case "octa":
            let v = [V(1, 0, 0), V(-1, 0, 0), V(0, 1, 0), V(0, -1, 0), V(0, 0, 1), V(0, 0, -1)]
            return [[0, 2, 4], [2, 1, 4], [1, 3, 4], [3, 0, 4], [2, 0, 5], [1, 2, 5], [3, 1, 5], [0, 3, 5]].map { [v[$0[0]], v[$0[1]], v[$0[2]]] }
        default:
            let t = (1 + 5.0.squareRoot()) / 2
            let v = [V(-1, t, 0), V(1, t, 0), V(-1, -t, 0), V(1, -t, 0), V(0, -1, t), V(0, 1, t), V(0, -1, -t), V(0, 1, -t),
                     V(t, 0, -1), V(t, 0, 1), V(-t, 0, -1), V(-t, 0, 1)].map(norm)
            let f = [[0, 11, 5], [0, 5, 1], [0, 1, 7], [0, 7, 10], [0, 10, 11], [1, 5, 9], [5, 11, 4], [11, 10, 2], [10, 7, 6], [7, 1, 8],
                     [3, 9, 4], [3, 4, 2], [3, 2, 6], [3, 6, 8], [3, 8, 9], [4, 9, 5], [2, 4, 11], [6, 2, 10], [8, 6, 7], [9, 8, 1]]
            return f.map { [v[$0[0]], v[$0[1]], v[$0[2]]] }
        }
    }

    private static func geodesic(_ b: String, _ f: Int) -> [[V]] {
        var out: [[V]] = []
        for T in base(b) {
            let A = T[0], B = T[1], C = T[2]
            func pt(_ i: Int, _ j: Int) -> V {
                let a = Double(f - i - j) / Double(f), bb = Double(i) / Double(f), c = Double(j) / Double(f)
                return norm(A * a + B * bb + C * c)
            }
            for i in 0..<f {
                for j in 0..<(f - i) {
                    out.append([pt(i, j), pt(i + 1, j), pt(i, j + 1)])
                    if i + j < f - 1 { out.append([pt(i + 1, j), pt(i + 1, j + 1), pt(i, j + 1)]) }
                }
            }
        }
        return out
    }

    init(_ kIn: Int) {
        let k = max(1, min(Self.shapes, kIn))
        self.k = k
        let need = k + 3
        var r = RNG(seed: k * 7919)
        var tris: [[V]]
        if k == 1 {
            tris = Self.geodesic("tetra", 1)
        } else if k <= 90 && k % 3 != 0 {
            if k % 3 == 1 { tris = Self.bipyramid(max(3, Int((Double(need) / 2).rounded(.up)))) }
            else { tris = Self.gyrobipyramid(max(3, Int((Double(need) / 4).rounded(.up)))) }
        } else {
            var best: (b: String, f: Int, score: Double)?
            for (idx, b) in ["tetra", "octa", "ico"].enumerated() {
                let bf = Double(Self.baseFaces[b]!)
                let f = max(1, Int((Double(need) / bf).squareRoot().rounded(.up)))
                let score = bf * Double(f * f) + Double((idx + k) % 3) * 0.5
                if best == nil || score < best!.score { best = (b, f, score) }
            }
            tris = Self.geodesic(best!.b, best!.f)
        }
        // Brain folds — same seeded waves as the web.
        let depth = 0.02 + 0.16 * (Double(k) / Double(Self.shapes))
        var waves: [(d: V, k: Double, ph: Double)] = []
        for _ in 0..<3 {
            let d = Self.norm(V(r.next() - 0.5, r.next() - 0.5, r.next() - 0.5))
            let wk = Double(2 + Int((r.next() * (2 + Double(k) / 60)).rounded(.down)))
            waves.append((d, wk, r.next() * 6.283))
        }
        let ax = r.next() * 6.283, ay = r.next() * 6.283
        let cx = cos(ax), sx = sin(ax), cy = cos(ay), sy = sin(ay)
        func fold(_ v: V) -> V {
            var s = 0.0
            for w in waves { s += sin(w.k * (v.x * w.d.x + v.y * w.d.y + v.z * w.d.z) * .pi + w.ph) }
            let p = v * (1 + depth * s / 3)
            let y = p.y * cx - p.z * sx, z = p.y * sx + p.z * cx
            return V(p.x * cy + z * sy, y, -p.x * sy + z * cy)
        }
        self.tris = tris.map { $0.map(fold) }
    }

    /// head-brain.js lightOrder(): front-top first, like a thought spreading.
    var lightOrder: [Int] {
        tris.enumerated().map { (i, t) -> (Int, Double) in
            let c = (t[0] + t[1] + t[2]) / 3
            return (i, -(c.y * 0.8 + c.z * 0.5 + c.x * 0.2))
        }.sorted { $0.1 < $1.1 }.map { $0.0 }
    }
}

// MARK: - Tier materials (head-brain.js TIERS)

private struct BrainTier {
    let base: SIMD3<Double>, hi: SIMD3<Double>
    let shin: Double, spec: Double, amb: Double, metal: Double
    let glass: Bool, irid: Bool

    static let all: [String: (rank: Int, tier: BrainTier)] = [
        "obsidian": (0, BrainTier(base: [16, 13, 22], hi: [190, 150, 255], shin: 40, spec: 1.0, amb: 0.75, metal: 0, glass: true, irid: false)),
        "black":    (1, BrainTier(base: [58, 60, 64], hi: [150, 152, 156], shin: 4, spec: 0.12, amb: 0.7, metal: 0, glass: false, irid: false)),
        "bronze":   (2, BrainTier(base: [150, 86, 40], hi: [255, 196, 140], shin: 22, spec: 0.95, amb: 0.5, metal: 0.35, glass: false, irid: false)),
        "silver":   (3, BrainTier(base: [128, 138, 152], hi: [255, 255, 255], shin: 30, spec: 1.1, amb: 0.45, metal: 0.45, glass: false, irid: false)),
        "gold":     (4, BrainTier(base: [214, 164, 34], hi: [255, 248, 196], shin: 26, spec: 1.1, amb: 0.45, metal: 0.4, glass: false, irid: false)),
        "white":    (5, BrainTier(base: [246, 242, 232], hi: [255, 255, 255], shin: 70, spec: 0.6, amb: 0.88, metal: 0, glass: false, irid: false)),
        "platinum": (6, BrainTier(base: [196, 208, 228], hi: [240, 248, 255], shin: 18, spec: 1.2, amb: 0.6, metal: 0.5, glass: false, irid: true)),
    ]
    static let bronze = all["bronze"]!.tier

    private static let light = simd_normalize(SIMD3<Double>(-0.45, 0.65, 0.62))
    private static let half = simd_normalize(SIMD3<Double>(light.x, light.y, light.z + 1))

    func shade(_ n: SIMD3<Double>, t: Double) -> SIMD3<Double> {
        let d = max(0, simd_dot(n, Self.light))
        let sp = pow(max(0, simd_dot(n, Self.half)), shin) * spec
        var h = hi
        if irid { h = Self.hsl(200 + n.x * 140 + n.y * 90 + t * 0.012, 0.75, 0.78) }
        if glass { h = n.x > 0 ? [160, 110, 240] : [70, 210, 150] }
        let k = amb + (1 - amb) * d
        var c = base * k + h * sp
        if metal > 0 {
            let env = SIMD3<Double>(40, 44, 52) + (hi - SIMD3<Double>(40, 44, 52)) * max(0, min(1, (n.y + 1) / 2))
            c = c + (env - c) * metal
        }
        return simd_clamp(c, SIMD3(repeating: 0), SIMD3(repeating: 255))
    }

    func edge(_ n: SIMD3<Double>) -> SIMD3<Double> {
        if glass { return n.x > 0 ? [150, 100, 220] : [70, 190, 140] }
        return base + (SIMD3<Double>(255, 255, 255) - base) * 0.4
    }

    private static func hsl(_ hIn: Double, _ s: Double, _ l: Double) -> SIMD3<Double> {
        let h = (hIn.truncatingRemainder(dividingBy: 360) + 360).truncatingRemainder(dividingBy: 360) / 360
        func f(_ n: Double) -> Double {
            let k = (n + h * 12).truncatingRemainder(dividingBy: 12), a = s * min(l, 1 - l)
            return 255 * (l - a * max(-1, min(k - 3, 9 - k, 1)))
        }
        return [f(0), f(8), f(4)]
    }
}

// MARK: - Last-known brain

struct CharlieBrainCache {
    private static let key = "mw.charlieBrain.v1"

    struct Snapshot: Codable, Equatable {
        var units: Int
        var tiers: [String: Int]   // slug → count
    }

    static func load() -> Snapshot? {
        guard let d = UserDefaults.standard.data(forKey: key) else { return nil }
        return try? JSONDecoder().decode(Snapshot.self, from: d)
    }

    static func save(_ b: HeadBrainSummary) {
        var tiers: [String: Int] = [:]
        for t in b.tiers { tiers[t.slug] = t.n }
        if let d = try? JSONEncoder().encode(Snapshot(units: b.units, tiers: tiers)) {
            UserDefaults.standard.set(d, forKey: key)
        }
    }
}

// MARK: - The turning brain

struct BrainView: View {
    let units: Int
    let tiers: [String: Int]

    private struct Prepared {
        let shape: BrainShape
        let owner: [BrainTier?]
        let newest: Int
    }

    private let prepared: Prepared

    init(units: Int, tiers: [String: Int]) {
        self.units = units
        self.tiers = tiers
        let shape = BrainShape(units + 1)
        var owner = [BrainTier?](repeating: nil, count: shape.tris.count)
        // Best tiers first along the web's sweep order; the rest of the units as Bronze.
        var queue: [BrainTier] = []
        for (slug, n) in tiers.sorted(by: { (BrainTier.all[$0.key]?.rank ?? 2) > (BrainTier.all[$1.key]?.rank ?? 2) }) {
            if let t = BrainTier.all[slug]?.tier { queue += Array(repeating: t, count: max(0, n)) }
        }
        let total = max(units, queue.count)
        queue += Array(repeating: BrainTier.bronze, count: max(0, total - queue.count))
        var newest = -1
        for (n, i) in shape.lightOrder.enumerated() where n < queue.count {
            owner[i] = queue[n]
            newest = i
        }
        prepared = Prepared(shape: shape, owner: owner, newest: newest)
    }

    var body: some View {
        TimelineView(.animation) { tl in
            Canvas { g, size in
                draw(g, size: size, time: tl.date.timeIntervalSinceReferenceDate)
            }
        }
        .accessibilityLabel("Charlie's brain")
    }

    private func draw(_ g: GraphicsContext, size: CGSize, time: Double) {
        let W = size.width, H = size.height, R = min(W, H) * 0.36
        let a = time / 11 * 2 * .pi, tilt = 0.45
        let ca = cos(a), sa = sin(a), ct = cos(tilt), st = sin(tilt)
        func P(_ v: BrainShape.V) -> (x: Double, y: Double, z: Double, vx: Double, vy: Double) {
            let x = v.x * ca + v.z * sa, z = -v.x * sa + v.z * ca, y = v.y
            let y2 = y * ct - z * st, z2 = y * st + z * ct, s = 3.2 / (3.2 - z2)
            return (W / 2 + x * R * s, H / 2 - y2 * R * s, z2, x, y2)
        }
        let tris = prepared.shape.tris
        var faces: [(i: Int, p: [(x: Double, y: Double, z: Double, vx: Double, vy: Double)], z: Double, front: Bool)] = []
        faces.reserveCapacity(tris.count)
        for (i, t) in tris.enumerated() {
            let p0 = P(t[0]), p1 = P(t[1]), p2 = P(t[2])
            let nz = (p1.x - p0.x) * (p2.y - p0.y) - (p1.y - p0.y) * (p2.x - p0.x)
            faces.append((i, [p0, p1, p2], (p0.z + p1.z + p2.z) / 3, nz < 0))
        }
        faces.sort { $0.z < $1.z }
        let pulse = 0.5 + 0.5 * sin(time * 1000 / 380)
        let thin: CGFloat = tris.count > 300 ? 0.5 : 0.8
        let tms = time * 1000
        for f in faces {
            var path = Path()
            path.move(to: CGPoint(x: f.p[0].x, y: f.p[0].y))
            path.addLine(to: CGPoint(x: f.p[1].x, y: f.p[1].y))
            path.addLine(to: CGPoint(x: f.p[2].x, y: f.p[2].y))
            path.closeSubpath()
            if let T = prepared.owner[f.i] {
                let u = SIMD3(f.p[1].vx - f.p[0].vx, f.p[1].vy - f.p[0].vy, f.p[1].z - f.p[0].z)
                let v = SIMD3(f.p[2].vx - f.p[0].vx, f.p[2].vy - f.p[0].vy, f.p[2].z - f.p[0].z)
                var n = simd_normalize(simd_cross(u, v))
                if n.z < 0 { n = -n }
                let col = T.shade(n, t: tms)
                if f.front {
                    g.fill(path, with: .color(Self.c(col, 0.95)))
                    if f.i == prepared.newest {
                        g.fill(path, with: .color(Color(red: 127 / 255, green: 216 / 255, blue: 88 / 255).opacity(0.55 * pulse)))
                        g.stroke(path, with: .color(Color(red: 200 / 255, green: 245 / 255, blue: 180 / 255).opacity(0.9)), lineWidth: thin)
                    } else {
                        g.stroke(path, with: .color(Self.c(T.edge(n), 0.8)), lineWidth: thin)
                    }
                } else {
                    g.fill(path, with: .color(Self.c(col, 0.14)))
                    g.stroke(path, with: .color(Self.c(T.edge(n), 0.16)), lineWidth: thin)
                }
            } else {
                g.stroke(path, with: .color(Color(red: 232 / 255, green: 243 / 255, blue: 240 / 255).opacity(f.front ? 0.26 : 0.07)), lineWidth: thin)
            }
        }
    }

    private static func c(_ v: SIMD3<Double>, _ a: Double) -> Color {
        Color(red: v.x / 255, green: v.y / 255, blue: v.z / 255).opacity(a)
    }
}

// MARK: - Opening screen

struct CharlieBrainSplash: View {
    private let snap = CharlieBrainCache.load() ?? .init(units: 0, tiers: [:])

    var body: some View {
        ZStack {
            Color.MW.forest.ignoresSafeArea()
            BrainView(units: snap.units, tiers: snap.tiers)
                .frame(width: 300, height: 300)
        }
    }
}
