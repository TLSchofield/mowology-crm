//
//  AppExtrasConfig.swift
//  MowologyCRM
//
//  Small process-wide cache for the timed-extras billing rate.
//  The /api/schedule/day response carries `extras_rate_per_5min` (admin-
//  configurable in ops_settings). We stash it here on each day load so the
//  visit-completion sheet can show a live dollar total while crew accrue
//  extra-work minutes — without threading the value through every view layer.
//
//  The authoritative amount always comes from the server on invoice create;
//  this value only drives the on-screen preview.
//

import Foundation

@MainActor
final class AppExtrasConfig {
    static let shared = AppExtrasConfig()

    /// Dollars per 5-minute block. Defaults to $5.00 until the first day load.
    private(set) var ratePer5Min: Double = 5.00

    private init() {}

    func update(ratePer5Min: Double?) {
        if let rate = ratePer5Min, rate > 0 {
            self.ratePer5Min = rate
        }
    }
}
