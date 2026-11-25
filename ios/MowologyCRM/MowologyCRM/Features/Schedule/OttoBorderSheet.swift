//
//  OttoBorderSheet.swift
//  MowologyCRM
//
//  After a new client's address is saved, Otto checks the lot and draws its arrival border while you watch
//  (Tim, 2026-11-20). One tap says "Looks right" (the border is kept) or "Not right" (removed; draw it in the
//  office zone editor). Approving is a quality step only: an unapproved border already counts for arrival
//  detection, so closing this sheet loses nothing.
//

import SwiftUI
import MapKit

/// What Otto is doing, in order: checking → drawing → drawn → (approved | removed); or couldNotDraw.
enum OttoBorderStage: Equatable {
    case checking
    case drawing(ring: [CLLocationCoordinate2D], shown: Int)
    case drawn(ring: [CLLocationCoordinate2D], note: String)
    case approved(ring: [CLLocationCoordinate2D])
    case removed
    case couldNotDraw(String)

    static func == (a: OttoBorderStage, b: OttoBorderStage) -> Bool {
        switch (a, b) {
        case (.checking, .checking), (.removed, .removed): return true
        case let (.drawing(r1, s1), .drawing(r2, s2)): return s1 == s2 && r1.count == r2.count
        case let (.drawn(r1, n1), .drawn(r2, n2)): return n1 == n2 && r1.count == r2.count
        case let (.approved(r1), .approved(r2)): return r1.count == r2.count
        case let (.couldNotDraw(m1), .couldNotDraw(m2)): return m1 == m2
        default: return false
        }
    }
}

/// Pure rules, kept out of the view so they can be reasoned about on their own.
enum OttoBorderRules {
    /// Server ring ([[lat, lng], …], open) → map coordinates. Rejects rings that cannot make a polygon.
    static func coordinates(from ring: [[Double]]?) -> [CLLocationCoordinate2D]? {
        guard let ring else { return nil }
        let pts = ring.compactMap { p -> CLLocationCoordinate2D? in
            guard p.count >= 2, abs(p[0]) <= 90, abs(p[1]) <= 180 else { return nil }
            return CLLocationCoordinate2D(latitude: p[0], longitude: p[1])
        }
        return pts.count >= 3 ? pts : nil
    }

    /// A region that frames the ring with a little air round it.
    static func region(for ring: [CLLocationCoordinate2D]) -> MKCoordinateRegion {
        let lats = ring.map(\.latitude), lngs = ring.map(\.longitude)
        let minLat = lats.min() ?? 0, maxLat = lats.max() ?? 0, minLng = lngs.min() ?? 0, maxLng = lngs.max() ?? 0
        return MKCoordinateRegion(
            center: CLLocationCoordinate2D(latitude: (minLat + maxLat) / 2, longitude: (minLng + maxLng) / 2),
            span: MKCoordinateSpan(latitudeDelta: max((maxLat - minLat) * 2.2, 0.0006),
                                   longitudeDelta: max((maxLng - minLng) * 2.2, 0.0006)))
    }
}

@MainActor
final class OttoBorderViewModel: ObservableObject {
    @Published var stage: OttoBorderStage = .checking
    @Published var isWorking = false
    @Published var errorMessage: String?

    private let propertyId: Int
    private let send: (Int, String?) async throws -> OttoBorderResponse
    private var started = false

    init(propertyId: Int, send: @escaping (Int, String?) async throws -> OttoBorderResponse) {
        self.propertyId = propertyId
        self.send = send
    }

    /// Asks Otto once; a second call (view re-appearing) does nothing.
    func start() async {
        guard !started else { return }
        started = true
        do {
            let r = try await send(propertyId, nil)
            guard r.ok, let ring = OttoBorderRules.coordinates(from: r.ring) else {
                stage = .couldNotDraw(r.message ?? "Otto could not find the lot for this address.")
                return
            }
            // Pins land one corner at a time, then the lot fills in.
            for n in 1...ring.count {
                stage = .drawing(ring: ring, shown: n)
                try? await Task.sleep(nanoseconds: 220_000_000)
                // The border is already saved: if the view's task was cancelled, skip the show, never strand on .drawing.
                if Task.isCancelled { break }
            }
            stage = .drawn(ring: ring, note: r.message ?? "Check it on the map.")
        } catch {
            AppDiagnostics.shared.record(category: "OttoBorderSheet", message: ErrorDetails.describe(error))
            stage = .couldNotDraw(ErrorDetails.describe(error))
        }
    }

    func approve() async { await decide("approve") }
    func remove() async { await decide("remove") }

    private func decide(_ action: String) async {
        guard case let .drawn(ring, _) = stage, !isWorking else { return }
        isWorking = true
        errorMessage = nil
        defer { isWorking = false }
        do {
            let r = try await send(propertyId, action)
            guard r.ok else { errorMessage = r.message ?? "Otto could not do that."; return }
            stage = action == "approve" ? .approved(ring: ring) : .removed
        } catch {
            AppDiagnostics.shared.record(category: "OttoBorderSheet", message: ErrorDetails.describe(error))
            errorMessage = ErrorDetails.describe(error)
        }
    }
}

struct OttoBorderSheet: View {
    @Environment(\.itAppStyle) private var appStyle
    @StateObject private var vm: OttoBorderViewModel
    let address: String
    let onDone: () -> Void

    init(propertyId: Int, address: String, send: @escaping (Int, String?) async throws -> OttoBorderResponse,
         onDone: @escaping () -> Void) {
        _vm = StateObject(wrappedValue: OttoBorderViewModel(propertyId: propertyId, send: send))
        self.address = address
        self.onDone = onDone
    }

    private var title: String {
        switch vm.stage {
        case .checking:       return "Otto is checking the lot…"
        case .drawing:        return "Otto is drawing the border…"
        case .drawn:          return "Does this look right?"
        case .approved:       return "Border kept"
        case .removed:        return "Border removed"
        case .couldNotDraw:   return "Otto couldn't draw this one"
        }
    }

    private var ringToShow: (ring: [CLLocationCoordinate2D], shown: Int)? {
        switch vm.stage {
        case let .drawing(ring, shown): return (ring, shown)
        case let .drawn(ring, _), let .approved(ring): return (ring, ring.count)
        default: return nil
        }
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            HStack(spacing: 12) {
                HeadFaceView(slug: "otto", name: "Otto", size: 44)
                VStack(alignment: .leading, spacing: 2) {
                    Text(title).font(appStyle.font(.headline))
                    Text(address).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta).lineLimit(1)
                }
                Spacer()
                if case .checking = vm.stage { ProgressView() }
            }

            if let drawn = ringToShow {
                OttoBorderMap(ring: drawn.ring, shown: drawn.shown)
                    .frame(height: 260)
                    .clipShape(RoundedRectangle(cornerRadius: 12))
            } else if case .checking = vm.stage {
                RoundedRectangle(cornerRadius: 12).fill(appStyle.sunk).frame(height: 260)
                    .overlay(Text("Looking up the lot map").font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta))
            }

            switch vm.stage {
            case let .drawn(_, note):
                Text(note).font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta)
                Text("Arrival detection already uses this border. Checking it just makes sure it is the right lot.")
                    .font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                if let e = vm.errorMessage { Text(e).font(appStyle.font(.caption)).foregroundStyle(.red) }
                Button { Task { await vm.approve() } } label: { Text("Looks right").frame(maxWidth: .infinity) }
                    .buttonStyle(ItButtonStyle(kind: .primary, fullWidth: true))
                    .disabled(vm.isWorking)
                Button { Task { await vm.remove() } } label: { Text("Not right — remove it").frame(maxWidth: .infinity) }
                    .buttonStyle(ItButtonStyle(kind: .outline, fullWidth: true))
                    .disabled(vm.isWorking)
            case .approved:
                Text("Otto will use it to spot when a crew arrives.").font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta)
                doneButton
            case .removed:
                Text("The office can draw this one in the zone editor.").font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta)
                doneButton
            case let .couldNotDraw(message):
                Text(message).font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta)
                Text("The client is saved. The office can draw the border in the zone editor.")
                    .font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                doneButton
            default:
                EmptyView()
            }
            Spacer(minLength: 0)
        }
        .padding(16)
        .interactiveDismissDisabled(vm.stage == .checking)
        .task { await vm.start() }
    }

    private var doneButton: some View {
        Button { onDone() } label: { Text("Done").frame(maxWidth: .infinity) }
            .buttonStyle(ItButtonStyle(kind: .primary, fullWidth: true))
    }
}

/// Corner pins appear one by one; the finished lot fills in.
private struct OttoBorderMap: View {
    @Environment(\.itAppStyle) private var appStyle
    let ring: [CLLocationCoordinate2D]
    let shown: Int

    var body: some View {
        Map(initialPosition: .region(OttoBorderRules.region(for: ring)), interactionModes: [.zoom, .pan]) {
            if shown >= ring.count {
                MapPolygon(coordinates: ring)
                    .foregroundStyle(appStyle.tenant.opacity(0.25))
                    .stroke(appStyle.tenant, lineWidth: 3)
            } else {
                MapPolyline(coordinates: Array(ring.prefix(shown)))
                    .stroke(appStyle.tenant, lineWidth: 3)
                ForEach(0..<min(shown, ring.count), id: \.self) { i in
                    Annotation("", coordinate: ring[i]) {
                        Circle().fill(appStyle.tenant).frame(width: 9, height: 9)
                    }
                }
            }
        }
        .mapStyle(.hybrid)
    }
}
