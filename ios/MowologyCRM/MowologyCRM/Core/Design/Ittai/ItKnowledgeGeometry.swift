import CoreGraphics

struct ItKnowledgeProjectedFace: Equatable {
    let faceIndex: Int
    let slot: Int?
    let centroid: CGPoint
    let points: [CGPoint]
    let depth: Double
    let front: Bool
}

enum ItKnowledgeGeometry {
    static let minimumHitDiameter: CGFloat = 44
    static let triangleInset: CGFloat = 0.10
    static let restingYaw = 0.0
    static let tilt = 0.45
    static let turnDuration = 11.0

    struct StageTallies: Equatable {
        let recognized, identified, understood, mastered: Int
        var total: Int { recognized + identified + understood + mastered }
        var known: Int { identified + understood + mastered }
        var understoodOrBetter: Int { understood + mastered }
    }

    /// Aggregate response counts are advisory; user-facing counts come from the items drawn.
    static func stageTallies(_ items: [KnowledgeVendor]) -> StageTallies {
        StageTallies(recognized: items.filter { $0.stage == .recognized }.count,
                     identified: items.filter { $0.stage == .identified }.count,
                     understood: items.filter { $0.stage == .understood }.count,
                     mastered: items.filter { $0.stage == .mastered }.count)
    }

    static func summary(_ items: [KnowledgeVendor]) -> String {
        let tally = stageTallies(items)
        return "Penny has met \(tally.total) vendors — she knows \(tally.known), understands \(tally.understoodOrBetter) and has mastered \(tally.mastered)."
    }

    /// Deterministic reduced-motion pose: choose the sampled yaw exposing the most owned faces.
    static func mostFrontYaw(count: Int) -> Double {
        (0..<72).map { Double($0) * 2 * .pi / 72 }.max { lhs, rhs in
            projectedFaces(count: count, in: CGSize(width: 300, height: 300), yaw: lhs).filter { $0.front && $0.slot != nil }.count <
            projectedFaces(count: count, in: CGSize(width: 300, height: 300), yaw: rhs).filter { $0.front && $0.slot != nil }.count
        } ?? restingYaw
    }

    /// Slot ownership is deliberately the shape's deterministic light sweep, never triangle array order.
    static func faceIndex(slot: Int, count: Int) -> Int? {
        guard slot >= 0, slot < count else { return nil }
        return BrainShape(max(1, count)).lightOrder[slot]
    }

    static func projectedFaces(count: Int, in size: CGSize, yaw: Double = restingYaw, pitch: Double = tilt) -> [ItKnowledgeProjectedFace] {
        let shape = BrainShape(max(1, count))
        let slots = Dictionary(uniqueKeysWithValues: shape.lightOrder.prefix(max(0, count)).enumerated().map { ($0.element, $0.offset) })
        let radius = Double(min(size.width, size.height)) * 0.36
        let cy = cos(yaw), sy = sin(yaw), cp = cos(pitch), sp = sin(pitch)
        func project(_ v: BrainShape.V) -> (point: CGPoint, depth: Double) {
            let x = v.x * cy + v.z * sy
            let z = -v.x * sy + v.z * cy
            let y = v.y * cp - z * sp
            let z2 = v.y * sp + z * cp
            let perspective = 3.2 / (3.2 - z2)
            return (CGPoint(x: size.width / 2 + CGFloat(x * radius * perspective), y: size.height / 2 - CGFloat(y * radius * perspective)), z2)
        }
        return shape.tris.enumerated().map { faceIndex, triangle in
            let raw = triangle.map(project)
            let centroid = CGPoint(x: raw.map(\.point.x).reduce(0, +) / 3, y: raw.map(\.point.y).reduce(0, +) / 3)
            let points = raw.map { p in CGPoint(x: p.point.x + (centroid.x - p.point.x) * triangleInset, y: p.point.y + (centroid.y - p.point.y) * triangleInset) }
            let cross = (raw[1].point.x - raw[0].point.x) * (raw[2].point.y - raw[0].point.y) - (raw[1].point.y - raw[0].point.y) * (raw[2].point.x - raw[0].point.x)
            return ItKnowledgeProjectedFace(faceIndex: faceIndex, slot: slots[faceIndex], centroid: centroid, points: points, depth: raw.map(\.depth).reduce(0, +) / 3, front: cross < 0)
        }.sorted { $0.depth < $1.depth }
    }

    /// Only visible vendor faces participate. The 22 pt radius is the 44 pt accessibility equivalent;
    /// VoiceOver intentionally uses the existing list action because dense 3D faces can overlap it.
    static func resolvedSlot(at point: CGPoint, faces: [ItKnowledgeProjectedFace], radius: CGFloat = minimumHitDiameter / 2) -> Int? {
        let candidates = faces.compactMap { face -> (slot: Int, distance: CGFloat)? in
            guard face.front, let slot = face.slot else { return nil }
            return (slot, hypot(face.centroid.x - point.x, face.centroid.y - point.y))
        }
        guard let nearest = candidates.min(by: { $0.distance < $1.distance }), nearest.distance <= max(22, radius) else { return nil }
        return nearest.slot
    }
}
