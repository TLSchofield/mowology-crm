//
//  AddressSuggest.swift
//  MowologyCRM
//
//  Address suggestions as the crew types a new client's street address (Tim, 2026-11-20), using Apple's
//  on-device-assisted address search. Picking a suggestion also gives the property's true coordinates, so the
//  map pin (and Otto's lot border) is the typed address, not wherever the phone happens to be.
//

import Foundation
import MapKit

struct PickedAddress: Equatable {
    var street: String
    var city: String
    var postalCode: String
    var latitude: Double
    var longitude: Double
}

enum AddressSuggestRules {
    /// "123" + "Main St" → "123 Main St"; either half may be missing.
    static func street(number: String?, name: String?) -> String {
        [number, name].compactMap { $0?.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty }.joined(separator: " ")
    }

    /// A pin of (0, 0) or outside the real lat/lng range is a failed lookup, never a property.
    static func isUsable(latitude: Double, longitude: Double) -> Bool {
        abs(latitude) <= 90 && abs(longitude) <= 180 && !(latitude == 0 && longitude == 0)
    }
}

@MainActor
final class AddressSuggester: NSObject, ObservableObject, MKLocalSearchCompleterDelegate {
    @Published private(set) var suggestions: [MKLocalSearchCompletion] = []
    @Published private(set) var isResolving = false
    @Published var lookupError: String?

    private let completer = MKLocalSearchCompleter()

    override init() {
        super.init()
        completer.delegate = self
        completer.resultTypes = .address
    }

    /// Bias suggestions to where the crew works (the phone's fix when there is one).
    func setRegion(near coordinate: CLLocationCoordinate2D?) {
        guard let coordinate else { return }
        completer.region = MKCoordinateRegion(center: coordinate, latitudinalMeters: 30_000, longitudinalMeters: 30_000)
    }

    func update(query: String) {
        let q = query.trimmingCharacters(in: .whitespacesAndNewlines)
        if q.count < 3 { suggestions = []; completer.cancel(); return }
        completer.queryFragment = q
    }

    func clear() { suggestions = [] }

    func resolve(_ completion: MKLocalSearchCompletion) async -> PickedAddress? {
        isResolving = true
        lookupError = nil
        defer { isResolving = false }
        do {
            let response = try await MKLocalSearch(request: MKLocalSearch.Request(completion: completion)).start()
            guard let item = response.mapItems.first else {
                lookupError = "Apple Maps found no match for that address."
                return nil
            }
            let p = item.placemark
            let c = p.coordinate
            guard AddressSuggestRules.isUsable(latitude: c.latitude, longitude: c.longitude) else {
                lookupError = "Apple Maps returned no usable location for that address."
                return nil
            }
            let street = AddressSuggestRules.street(number: p.subThoroughfare, name: p.thoroughfare)
            return PickedAddress(street: street.isEmpty ? completion.title : street,
                                 city: p.locality ?? "", postalCode: p.postalCode ?? "",
                                 latitude: c.latitude, longitude: c.longitude)
        } catch {
            AppDiagnostics.shared.record(category: "AddressSuggest", message: ErrorDetails.describe(error))
            lookupError = ErrorDetails.describe(error)
            return nil
        }
    }

    nonisolated func completerDidUpdateResults(_ completer: MKLocalSearchCompleter) {
        let results = completer.results
        Task { @MainActor in self.suggestions = Array(results.prefix(5)) }
    }

    nonisolated func completer(_ completer: MKLocalSearchCompleter, didFailWithError error: Error) {
        Task { @MainActor in
            AppDiagnostics.shared.record(category: "AddressSuggest", message: ErrorDetails.describe(error))
        }
    }
}
