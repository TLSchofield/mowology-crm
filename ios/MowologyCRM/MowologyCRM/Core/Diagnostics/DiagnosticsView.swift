import SwiftUI

/// This screen and its export action re-check the current authenticated role.
struct DiagnosticsView: View {
    @EnvironmentObject private var authSession: AuthSession
    @Environment(\.itAppStyle) private var style
    @State private var rows: [DiagnosticsDisplay.Entry] = []
    @State private var exportURL: URL?
    @State private var error: String?
    @State private var loading = false

    var body: some View {
        Group {
            if authSession.user?.isAdmin == true {
                VStack(alignment: .leading, spacing: 12) {
                    Text("Errors are saved on this phone. Apple crash and hang reports appear when iOS supplies them; an empty log does not prove there were no crashes.")
                        .font(style.font(.footnote)).foregroundStyle(style.meta)
                    if let error {
                        Text(error).font(style.font(.footnote)).foregroundStyle(style.blocked)
                            .textSelection(.enabled)
                    }
                    if loading { ProgressView("Reading diagnostic log") }
                    // One row per entry: a crash payload is a single line of up to a megabyte, and drawing that as one
                    // selectable text view crashes the screen. Long rows are cut here; "Export log" has the full text.
                    ScrollView(.vertical) {
                        LazyVStack(alignment: .leading, spacing: 10) {
                            if rows.isEmpty {
                                Text("No diagnostic entries recorded on this phone.")
                                    .font(.system(.caption, design: .monospaced))
                            }
                            ForEach(rows) { row in
                                Text(row.omittedCharacters > 0
                                     ? row.text + " … [\(row.omittedCharacters) more characters: Export log has them]"
                                     : row.text)
                                    .font(.system(.caption, design: .monospaced))
                                    .frame(maxWidth: .infinity, alignment: .leading)
                            }
                        }
                        .padding(.horizontal, 8)
                    }
                    .background(style.sunk)
                }
                .padding(16)
                .toolbar {
                    ToolbarItemGroup(placement: .primaryAction) {
                        Button("Refresh") { Task { await refresh() } }.disabled(loading)
                        if let exportURL { ShareLink("Export log", item: exportURL) }
                    }
                }
                .task { await refresh() }
            } else {
                Text("Diagnostic logs require an administrator account.")
            }
        }
        .navigationTitle("Diagnostic log")
        .navigationBarTitleDisplayMode(.inline)
        .itAppScreen()
        .onChange(of: authSession.user?.isAdmin) { _, admin in
            if admin != true { rows = []; exportURL = nil; error = nil }
        }
    }

    @MainActor private func refresh() async {
        guard authSession.user?.isAdmin == true else { return }
        loading = true; error = nil; exportURL = nil
        defer { loading = false }
        do {
            let snapshot = try await AppDiagnostics.shared.snapshot()
            guard authSession.user?.isAdmin == true else { return }
            rows = DiagnosticsDisplay.entries(from: snapshot.text); exportURL = snapshot.url
        } catch { self.error = ErrorDetails.describe(error) }
    }
}
