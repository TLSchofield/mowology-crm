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
    var tenant: Color { isEnabled ? palette.tenant : (ItTenantColours.enabled ? Color.MW.green : Color(itHex: ItTokenHex.light[.brand] ?? 0x233A5E)) }
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
    @AppStorage(ItIttaiBrandHex.defaultsKey) private var brandIttai = false

    func body(content: Content) -> some View {
        let palette = ItPalette(ItMode.resolve(field: field, chosen: nil, system: system), brandIttai: brandIttai)
        let style = ItAppStyle(isEnabled: enabled, palette: palette)
        // Keep the subtree's identity stable when the setting changes: a visual toggle
        // must not recreate time-clock, receipt, or schedule state objects.
        content
            .environment(\.colorScheme, enabled ? palette.colorScheme : system)
            .font(enabled ? style.font(.body) : nil)
            // No blanket .foregroundStyle(style.text) here: it overrode the label of every filled button
            // (.borderedProminent), giving dark text on green — "Snap it", Submit, Confirm (owner, AAA, 2026-10-19).
            // Text takes the scheme's primary colour from .colorScheme above; views set meta/tenant themselves.
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
    // Ittai is the only design from 1.3.56; what's left to choose is field mode (high contrast, always light).
    @AppStorage("ittai.design.field") private var field = false
    @AppStorage(ItIttaiBrandHex.defaultsKey) private var brandIttai = false
    @AppStorage(ScheduleTimelineSwitch.defaultsKey) private var timelineSetting = 0
    @AppStorage(ItBarLab.hapticsKey) private var barHaptics = false
    @AppStorage(ItBarLab.scrollTopKey) private var barScrollTop = false
    @AppStorage(ItBarLab.keyboardCurveKey) private var barKeyboardCurve = false
    @AppStorage(ItBarLab.largeLabelsKey) private var barLargeLabels = false
    @AppStorage(ItBarLab.searchDrawerKey) private var barSearchDrawer = false
    @Environment(\.itAppStyle) private var style

    var body: some View {
        Toggle("Field mode (outdoors)", isOn: $field)
            .font(style.font(.body)).tint(style.tenant)
            .frame(minHeight: ItSize.targetPhone)
        // Preview only: this phone shows Ittai's navy instead of the business colour.
        Toggle("Ittai colours (preview)", isOn: $brandIttai)
            .font(style.font(.body)).tint(style.tenant)
            .frame(minHeight: ItSize.targetPhone)
        // The schedule as a timeline (2026-11-05). Automatic = on for admins, cards for crew.
        Picker("Schedule layout", selection: $timelineSetting) {
            Text("Automatic").tag(0)
            Text("Timeline").tag(1)
            Text("Cards").tag(2)
        }
        .pickerStyle(.menu)
        .font(style.font(.body)).tint(style.tenant)
        .frame(minHeight: ItSize.targetPhone)
        VStack(alignment: .leading, spacing: ItSpace.s2) {
            Text("Bar lab (testing)")
                .font(style.font(.headline))
            labToggle("Haptic ticks", caption: "Ticks once when you change tabs.", isOn: $barHaptics)
            labToggle("Selected tab to top", caption: "Search and Account return to their top.", isOn: $barScrollTop)
            labToggle("Keyboard curve", caption: "The bar follows the keyboard frame and timing.", isOn: $barKeyboardCurve)
            labToggle("Larger labels", caption: "Tab labels follow capped Dynamic Type.", isOn: $barLargeLabels)
            labToggle("System search drawer", caption: "Off: Search uses a plain field. On: the old drawer, which may freeze the app.", isOn: $barSearchDrawer)
        }
    }

    private func labToggle(_ title: String, caption: String, isOn: Binding<Bool>) -> some View {
        VStack(alignment: .leading, spacing: ItSpace.s1) {
            Toggle(title, isOn: isOn)
                .font(style.font(.body)).tint(style.tenant)
                .frame(minHeight: ItSize.targetPhone)
            Text(caption)
                .font(style.font(.caption))
                .foregroundStyle(style.meta)
        }
    }
}
