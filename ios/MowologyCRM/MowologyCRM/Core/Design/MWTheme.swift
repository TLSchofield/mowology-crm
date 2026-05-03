//
//  MWTheme.swift
//  MowologyCRM
//
//  Single source of truth for Mowology brand design tokens.
//  Mirrors --mw-* in mowology-brand.css (CRM) and --mowology-* in variables.css (public site).
//
//  Usage:  .foregroundStyle(Color.MW.green)
//          .background(Color.MW.orange)
//

import SwiftUI

extension Color {

    /// Mowology brand palette. Matches --mw-* in mowology-brand.css.
    enum MW {
        /// #2D8659  --mw-green  — primary buttons, links, selected state
        static let green  = Color(mwHex: 0x2D8659)
        /// #1A5F4A  --mw-dark   — hover states, gradients
        static let dark   = Color(mwHex: 0x1A5F4A)
        /// #7FD858  --mw-lime   — active nav items, success accents
        static let lime   = Color(mwHex: 0x7FD858)
        /// #E8F3F0  --mw-light  — light backgrounds, subtle borders
        static let light  = Color(mwHex: 0xE8F3F0)
        /// #0D3B2E  --mw-forest — sidebar background, deepest dark
        static let forest = Color(mwHex: 0x0D3B2E)
        /// #E85D04  --mw-orange — CTA accent (secondary)
        static let orange = Color(mwHex: 0xE85D04)
    }
}

private extension Color {
    init(mwHex hex: UInt32) {
        self.init(
            red:   Double((hex >> 16) & 0xFF) / 255,
            green: Double((hex >>  8) & 0xFF) / 255,
            blue:  Double( hex        & 0xFF) / 255
        )
    }
}
