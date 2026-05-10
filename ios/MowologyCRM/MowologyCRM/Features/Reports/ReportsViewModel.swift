//
//  ReportsViewModel.swift
//  MowologyCRM
//

import Foundation

@MainActor
final class ReportsViewModel: ObservableObject {

    // MARK: - Published

    @Published var selectedReport: ReportType = .revenueByMonth
    @Published private(set) var reportData: ReportResponse?
    @Published private(set) var isLoading = false
    @Published var errorMessage: String?

    // MARK: - Date range (default: current year)

    @Published var startDate: Date = Calendar.current.date(from: DateComponents(year: Calendar.current.component(.year, from: .now), month: 1, day: 1)) ?? .now
    @Published var endDate: Date   = Calendar.current.date(from: DateComponents(year: Calendar.current.component(.year, from: .now), month: 12, day: 31)) ?? .now

    // MARK: - Private

    private let apiClient: APIClient

    private let dateFormatter: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withFullDate]
        return f
    }()

    // MARK: - Init

    init(authSession: AuthSession) {
        self.apiClient = APIClient(authSession: authSession)
    }

    // MARK: - Public

    func load() async {
        isLoading    = true
        errorMessage = nil
        reportData   = nil

        let start = dateFormatter.string(from: startDate)
        let end   = dateFormatter.string(from: endDate)

        do {
            let response: ReportResponse = try await apiClient.request(
                .reportsData(report: selectedReport.rawValue, start: start, end: end)
            )
            reportData = response
        } catch {
            errorMessage = "Could not load \(selectedReport.displayName). Check your connection."
        }

        isLoading = false
    }

    func selectReport(_ type: ReportType) async {
        selectedReport = type
        await load()
    }
}
