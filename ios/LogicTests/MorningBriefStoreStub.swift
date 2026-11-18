import Foundation

/// Stand-in for the app's `MorningBriefStore` (a SwiftUI file the logic tests do not compile): APIEndpoints.swift reads
/// `shownQueryItems` for the ready-brief request, and these tests send nothing shown.
enum MorningBriefStore {
    static var shownQueryItems: [URLQueryItem] { [] }
}
