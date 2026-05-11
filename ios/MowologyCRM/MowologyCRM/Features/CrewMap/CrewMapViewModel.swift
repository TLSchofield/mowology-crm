//
//  CrewMapViewModel.swift
//  MowologyCRM
//

import Foundation
import MapKit

@MainActor
final class CrewMapViewModel: ObservableObject {

    @Published private(set) var crew: [CrewMapMember]    = []
    @Published private(set) var isLoading             = false
    @Published var errorMessage: String?              = nil
    @Published var selectedMember: CrewMapMember?        = nil

    /// Camera region centred on all visible crew, or Metro Vancouver as default.
    @Published var region: MKCoordinateRegion = MKCoordinateRegion(
        center: CLLocationCoordinate2D(latitude: 49.2827, longitude: -123.1207),
        span: MKCoordinateSpan(latitudeDelta: 0.15, longitudeDelta: 0.15)
    )

    private let apiClient: APIClient
    private var refreshTask: Task<Void, Never>?

    init(authSession: AuthSession) {
        self.apiClient = APIClient(authSession: authSession)
    }

    // MARK: - Load

    func load() async {
        isLoading    = true
        errorMessage = nil
        do {
            let response: CrewMapResponse = try await apiClient.request(.crewMapLive)
            crew = response.crew
            fitCamera()
        } catch {
            errorMessage = "Could not load crew locations. Check your connection."
        }
        isLoading = false
    }

    // MARK: - Auto-refresh every 30 s while view is on screen

    func startAutoRefresh() {
        stopAutoRefresh()
        refreshTask = Task { [weak self] in
            while !Task.isCancelled {
                try? await Task.sleep(nanoseconds: 30_000_000_000)
                guard !Task.isCancelled else { break }
                await self?.load()
            }
        }
    }

    func stopAutoRefresh() {
        refreshTask?.cancel()
        refreshTask = nil
    }

    // MARK: - Camera fit

    private func fitCamera() {
        guard !crew.isEmpty else { return }
        let lats = crew.map(\.lat)
        let lngs = crew.map(\.lng)
        let minLat = lats.min()!, maxLat = lats.max()!
        let minLng = lngs.min()!, maxLng = lngs.max()!
        let centre = CLLocationCoordinate2D(
            latitude:  (minLat + maxLat) / 2,
            longitude: (minLng + maxLng) / 2
        )
        let span = MKCoordinateSpan(
            latitudeDelta:  max(0.02, (maxLat - minLat) * 1.5),
            longitudeDelta: max(0.02, (maxLng - minLng) * 1.5)
        )
        region = MKCoordinateRegion(center: centre, span: span)
    }
}
