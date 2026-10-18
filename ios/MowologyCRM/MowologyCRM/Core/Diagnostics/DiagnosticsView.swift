import SwiftUI

/// This screen and its export action re-check the current authenticated role.
struct DiagnosticsView: View {
    @EnvironmentObject private var authSession: AuthSession
    @Environment(\.itAppStyle) private var style
    @State private var text = ""
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
                    ScrollView([.horizontal, .vertical]) {
                        Text(text.isEmpty ? "No diagnostic entries recorded on this phone." : text)
                            .font(.system(.caption, design: .monospaced))
                            .textSelection(.enabled).frame(maxWidth: .infinity, alignment: .leading)
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
            if admin != true { text = ""; exportURL = nil; error = nil }
        }
    }

    @MainActor private func refresh() async {
        guard authSession.user?.isAdmin == true else { return }
        loading = true; error = nil; exportURL = nil
        defer { loading = false }
        do {
            let snapshot = try await AppDiagnostics.shared.snapshot()
            guard authSession.user?.isAdmin == true else { return }
            text = snapshot.text; exportURL = snapshot.url
        } catch { self.error = ErrorDetails.describe(error) }
    }
}
