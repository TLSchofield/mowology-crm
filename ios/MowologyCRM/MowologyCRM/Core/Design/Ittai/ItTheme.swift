//
//  ItTheme.swift
//  MowologyCRM
//
//  Ittai Design System v1.2 (frozen baseline, 2026-10-17) — the theme layer.
//  Values come from ItTokens.swift (generated from docs/design/ittai-v1.2/tokens.json).
//  Precedence, highest first: field mode > the theme chosen in the app > the phone's setting > light.
//  Pure SwiftUI on purpose: every Ittai view can be rendered on the Mac for review (scripts/ittai-ios-render).
//

import SwiftUI

/// The three Ittai themes. Field is for outdoors: opaque white surfaces, darker ink, AAA status colours.
enum ItMode: String, CaseIterable {
    case light, dark, field

    /// field > chosen > OS > light. `chosen` is nil when the app follows the phone.
    static func resolve(field: Bool, chosen: ItMode?, system: ColorScheme) -> ItMode {
        if field { return .field }
        if let chosen, chosen != .field { return chosen }
        return system == .dark ? .dark : .light
    }
}

/// Every colour a view may use, already resolved for one mode. Views never hold hex values.
struct ItPalette: Equatable {
    let mode: ItMode
    private let values: [ItColorToken: UInt32]

    init(_ mode: ItMode) {
        self.mode = mode
        switch mode {
        case .light: values = ItTokenHex.light
        case .dark:  values = ItTokenHex.dark
        case .field: values = ItTokenHex.field
        }
    }

    func color(_ token: ItColorToken) -> Color { Color(itHex: values[token] ?? 0xFF00FF) }
    func hex(_ token: ItColorToken) -> UInt32 { values[token] ?? 0xFF00FF }

    var page: Color { color(.page) }
    var surface: Color { color(.surface) }
    var sunk: Color { color(.sunk) }
    var selected: Color { color(.selected) }
    var line: Color { color(.line) }
    var text: Color { color(.text) }
    var meta: Color { color(.meta) }
    var tenant: Color { color(.tenant) }
    var onTenant: Color { color(.onTenant) }
    var brand: Color { color(.brand) }
    var focus: Color { color(.focus) }
    var tenantSoft: Color { color(.tenantSoft) }
    var tenantMark: Color { color(.tenantMark) }

    /// The SwiftUI colour scheme that matches (field is a light scheme with stronger values).
    var colorScheme: ColorScheme { mode == .dark ? .dark : .light }
}

private struct ItPaletteKey: EnvironmentKey { static let defaultValue = ItPalette(.light) }

extension EnvironmentValues {
    /// The resolved Ittai palette. Set once near the root with `.ittaiTheme(field:chosen:)`.
    var it: ItPalette {
        get { self[ItPaletteKey.self] }
        set { self[ItPaletteKey.self] = newValue }
    }
}

/// Resolves the palette from the phone's colour scheme plus the app's field / theme settings.
struct ItThemeModifier: ViewModifier {
    let field: Bool
    let chosen: ItMode?
    @Environment(\.colorScheme) private var system

    func body(content: Content) -> some View {
        let palette = ItPalette(ItMode.resolve(field: field, chosen: chosen, system: system))
        return content
            .environment(\.it, palette)
            .environment(\.colorScheme, palette.colorScheme)
    }
}

extension View {
    func ittaiTheme(field: Bool, chosen: ItMode? = nil) -> some View {
        modifier(ItThemeModifier(field: field, chosen: chosen))
    }
}

// MARK: - Type

/// Inter at 400 / 500 / 600 only (design rule). The fonts ship in the asset catalog and are registered
/// at launch (ItFontRegistry); if that ever fails, Font.custom falls back to the system font.
enum ItFont {
    static func name(weight: Int) -> String {
        switch weight {
        case ..<450: return "Inter-Regular"
        case ..<550: return "Inter-Medium"
        default:     return "Inter-SemiBold"
        }
    }

    /// A style from the scale, scaling with Dynamic Type relative to the nearest iOS text style.
    static func style(_ s: ItTextStyle, weight: Int? = nil) -> Font {
        .custom(name(weight: weight ?? s.weight), size: s.size, relativeTo: s.relative)
    }
}

extension ItTextStyle {
    var relative: Font.TextStyle {
        switch self {
        case .display36, .title28: return .largeTitle
        case .title22:             return .title2
        case .heading18:           return .title3
        case .heading16:           return .headline
        case .body16:              return .body
        case .body14:              return .subheadline
        case .meta13:              return .footnote
        case .caption12:           return .caption
        case .wordmark:            return .title3
        }
    }
}

extension View {
    /// Apply an Ittai text style: font plus the line height from the scale.
    func itText(_ s: ItTextStyle, weight: Int? = nil) -> some View {
        font(ItFont.style(s, weight: weight))
            .lineSpacing(max(0, s.lineHeight - s.size * 1.21))
    }
}

extension Color {
    init(itHex hex: UInt32) {
        self.init(.sRGB,
                  red: Double((hex >> 16) & 0xFF) / 255,
                  green: Double((hex >> 8) & 0xFF) / 255,
                  blue: Double(hex & 0xFF) / 255,
                  opacity: 1)
    }
}
