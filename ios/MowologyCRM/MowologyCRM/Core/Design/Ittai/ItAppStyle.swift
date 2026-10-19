// Ittai styling for the existing field screens. This layer changes presentation only;
// the same views, state objects, navigation, and actions are used with either design.
import SwiftUI

struct ItAppStyle {
    var isEnabled = false
    var palette = ItPalette(.light)

    var page: Color { isEnabled ? palette.page : legacyPage }
    var surface: Color { isEnabled ? palette.surface : legacySurface }
    var sunk: Color { isEnabled ? palette.sunk : legacySunk }
    var line: Color { isEnabled ? palette.line : Color.secondary.opacity(0.2) }
    var text: Color { isEnabled ? palette.text : .primary }
    var meta: Color { isEnabled ? palette.meta : .secondary }
    var tenant: Color { isEnabled ? palette.tenant : Color.MW.green }
    var onTenant: Color { isEnabled ? palette.onTenant : .white }
    var attention: Color { isEnabled ? palette.color(.statusAttention) : Color.MW.orange }
    var blocked: Color { isEnabled ? palette.color(.statusBlocked) : .red }
    var progress: Color { isEnabled ? palette.color(.statusProgress) : .blue }
    var complete: Color { isEnabled ? palette.color(.statusComplete) : .green }
    var saved: Color { isEnabled ? palette.color(.syncSaved) : Color.MW.green }
    var sending: Color { isEnabled ? palette.color(.syncSending) : .blue }
    var sent: Color { isEnabled ? palette.color(.syncSent) : Color.MW.green }
    var failed: Color { isEnabled ? palette.color(.syncFailed) : .red }

    private var legacyPage: Color {
        #if canImport(UIKit)
        return Color(.systemGroupedBackground)
        #else
        return Color(.windowBackgroundColor)
        #endif
    }
    private var legacySurface: Color {
        #if canImport(UIKit)
        return Color(.systemBackground)
        #else
        return Color(.textBackgroundColor)
        #endif
    }
    private var legacySunk: Color {
        #if canImport(UIKit)
        return Color(.systemGray6)
        #else
        return Color(.controlBackgroundColor)
        #endif
    }

    func color(_ token: ItColorToken, legacy: Color) -> Color {
        isEnabled ? palette.color(token) : legacy
    }

    func font(_ style: Font.TextStyle, weight: Font.Weight? = nil) -> Font {
        guard isEnabled else {
            let original = Font.system(style)
            return weight.map { original.weight($0) } ?? original
        }
        let token: ItTextStyle
        switch style {
        case .largeTitle: token = .display36
        case .title: token = .title28
        case .title2: token = .title22
        case .title3: token = .heading18
        case .headline: token = .heading16
        case .body: token = .body16
        case .callout, .subheadline: token = .body14
        case .footnote: token = .meta13
        case .caption, .caption2: token = .caption12
        @unknown default: token = .body16
        }
        return .custom(ItFont.name(weight: weight.map(fontWeight) ?? token.weight),
                       size: token.size, relativeTo: style)
    }

    func font(size: CGFloat, weight: Font.Weight = .regular, design: Font.Design = .default) -> Font {
        guard isEnabled else { return .system(size: size, weight: weight, design: design) }
        return Font.custom(ItFont.name(weight: fontWeight(weight)), size: size, relativeTo: size >= 24 ? .title : .body)
    }

    private func fontWeight(_ weight: Font.Weight) -> Int {
        switch weight {
        case .bold, .semibold, .heavy, .black: return 600
        case .medium: return 500
        default: return 400
        }
    }

    func cornerRadius(_ legacy: CGFloat) -> CGFloat {
        guard isEnabled else { return legacy }
        return legacy > ItRadius.md ? ItRadius.lg : ItRadius.md
    }

    func shadow(_ legacyOpacity: Double) -> Color {
        isEnabled ? .clear : .black.opacity(legacyOpacity)
    }

    /// Display mapping only. Unknown/new server statuses retain their original colour.
    func statusColor(_ raw: String, legacy: Color) -> Color {
        guard isEnabled else { return legacy }
        switch raw.lowercased() {
        case "completed", "complete", "approved", "paid": return palette.color(.statusComplete)
        case "in_progress", "in-progress", "active", "processing": return palette.color(.statusProgress)
        case "pending_approval", "pending", "draft", "overdue", "needs_review": return palette.color(.statusAttention)
        case "rejected", "failed", "error": return palette.color(.statusBlocked)
        case "forwarded", "sent": return palette.color(.syncSent)
        case "scheduled", "cancelled", "canceled", "skipped": return palette.meta
        default: return legacy
        }
    }
}

private struct ItAppStyleKey: EnvironmentKey { static let defaultValue = ItAppStyle() }
extension EnvironmentValues {
    var itAppStyle: ItAppStyle {
        get { self[ItAppStyleKey.self] }
        set { self[ItAppStyleKey.self] = newValue }
    }
}

/// Applied above the tab view: sheets and pushed destinations inherit the same palette.
struct ItAppThemeModifier: ViewModifier {
    let enabled: Bool
    let field: Bool
    @Environment(\.colorScheme) private var system

    func body(content: Content) -> some View {
        let palette = ItPalette(ItMode.resolve(field: field, chosen: nil, system: system))
        let style = ItAppStyle(isEnabled: enabled, palette: palette)
        // Keep the subtree's identity stable when the setting changes: a visual toggle
        // must not recreate time-clock, receipt, or schedule state objects.
        content
            .environment(\.colorScheme, enabled ? palette.colorScheme : system)
            .font(enabled ? style.font(.body) : nil)
            .foregroundStyle(style.text)
            .tint(style.tenant)
            // Field mode is always light. Sheets take their background and button fills from the
            // window, not from the colorScheme above, so a phone in dark mode drew dark sheets with
            // the field palette's dark ink (unreadable, 2026-10-19). preferredColorScheme reaches the
            // window and every presentation; nil hands control back to the phone when field is off.
            .preferredColorScheme(enabled && palette.mode == .field ? palette.colorScheme : nil)
        .environment(\.itAppStyle, style)
        .environment(\.it, palette)
    }
}

private struct ItAppScreenModifier: ViewModifier {
    @Environment(\.itAppStyle) private var style
    func body(content: Content) -> some View {
        #if canImport(UIKit)
        content.background(style.isEnabled ? style.page : Color.clear)
            .toolbarBackground(style.isEnabled ? AnyShapeStyle(style.surface) : AnyShapeStyle(.bar), for: .navigationBar, .tabBar)
            .toolbarBackground(style.isEnabled ? .visible : .automatic, for: .navigationBar, .tabBar)
            .toolbarColorScheme(style.isEnabled ? style.palette.colorScheme : nil, for: .navigationBar, .tabBar)
        #else
        content.background(style.isEnabled ? style.page : Color.clear)
        #endif
    }
}

private struct ItAppFormModifier: ViewModifier {
    @Environment(\.itAppStyle) private var style
    func body(content: Content) -> some View {
        content.scrollContentBackground(style.isEnabled ? .hidden : .automatic)
            .background(style.isEnabled ? style.page : Color.clear)
            .tint(style.tenant)
    }
}

extension View {
    func itAppTheme(enabled: Bool, field: Bool) -> some View {
        modifier(ItAppThemeModifier(enabled: enabled, field: field))
    }
    func itAppScreen() -> some View { modifier(ItAppScreenModifier()) }
    func itAppForm() -> some View { modifier(ItAppFormModifier()) }
}


/// Shared by crew Account and the admin AI Office, using the existing preferences.
struct ItDesignSettingsRows: View {
    @AppStorage("ittai.design.enabled") private var enabled = false
    @AppStorage("ittai.design.field") private var field = false
    @Environment(\.itAppStyle) private var style

    var body: some View {
        Toggle("Try the new design", isOn: $enabled)
            .font(style.font(.body)).tint(style.tenant)
            .frame(minHeight: style.isEnabled ? ItSize.targetPhone : nil)
        if enabled {
            Toggle("Field mode (outdoors)", isOn: $field)
                .font(style.font(.body)).tint(style.tenant)
                .frame(minHeight: ItSize.targetPhone)
        }
    }
}
