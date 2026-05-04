//
//  DevErrorBus.swift
//  MowologyCRM
//
//  Development-only global error surface. All APIClient errors are forwarded
//  here so RootView can show them regardless of which tab or sheet is active.
//  Wrapped in #if DEBUG — zero overhead in Release builds.
//

#if DEBUG
import Foundation

@MainActor
final class DevErrorBus: ObservableObject {

    static let shared = DevErrorBus()
    private init() {}

    @Published var pendingError: String?

    func post(_ error: Error) {
        pendingError = error.localizedDescription
    }
}
#endif
