//
//  OnboardingBarLogic.swift
//  MowologyCRM
//
//  Pure logic for the bottom bar during a new owner's onboarding (owner 2026-11-13, plan finding 26): the meniscus
//  bar shows ONLY Kaname's tab, selected, and goes nowhere. The signed-out screens (before the account exists) have
//  no bar at all. When onboarding ends the app opens on Kaname, once. No SwiftUI: compiled by the logic checks.
//

import Foundation

enum OnboardingBarLogic {
    enum Tab: Equatable { case kaname }

    /// The bar's tabs while the account is being set up: Kaname alone.
    static func tabs() -> [Tab] { [.kaname] }
    /// The slot the bead sits on: the only one.
    static let selectedIndex = 0
    /// A tap on the lone tab goes nowhere (there is no other screen to leave to).
    static func tapMovesAway(_ index: Int) -> Bool { false }
    /// The signed-out screens never show the bar.
    static func showsBar(accountExists: Bool) -> Bool { accountExists }

    
    // The bar the lone bead rolls into when onboarding ends: the real admin bar, Kaname in the centre slot (where the lone
    // bead already sits), landing on Receipts one slot to its right.
    static let rollTitles = ["Schedule", "Search", "Kaname", "Receipts", "Add"]
    static let rollFromSlot = 2
    static let rollToSlot = 3
    static let rollSeconds = 0.6

    /// The lone bead pulses and glows only on the Done step, until it is tapped.
    static func pulses(screenKey: String, rolling: Bool) -> Bool { screenKey == "done" && !rolling }
    /// Reduce Motion: a static glow, no pulse.
    static func pulseScale(reduceMotion: Bool, high: Bool) -> Double { reduceMotion ? 1.0 : (high ? 1.07 : 1.0) }
    static let pulseSeconds = 1.6

    // Where the main app lands after onboarding: Receipts (the owner: "navbar starts at Receipts"); Kaname only when
    // a business has no Receipts tab. Kept as a string in UserDefaults until the app has used it once.
    enum Landing: String { case receipts, kaname }
    static let landingKey = "ittai.onboarding.landingTab"
    static func landing(stored: String?, isAdmin: Bool, hasReceipts: Bool) -> Landing? {
        guard stored != nil else { return nil }
        if hasReceipts { return .receipts }
        return isAdmin ? .kaname : nil
    }
}
