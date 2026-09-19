//
//  TrackingModels.swift
//  MowologyCRM
//
//  Wire types for the tracking contract:
//    POST /api/schedule/location   — batch of fixes in, per-fix verdicts + policy out
//    GET  /api/schedule/tracking   — ?mode=status | consent | geofences
//  The SERVER owns the work-hours boundary: `policy.trackingAllowed == false` means
//  stop capturing now (clocked out by the office, consent withdrawn, account off…).
//

import Foundation

// MARK: - Policy

enum TrackingTier: String, Decodable {
    case off, baseline, enhanced
}

struct TrackingTierSettings: Decodable {
    let intervalS: Int
    let distanceM: Int

    enum CodingKeys: String, CodingKey {
        case intervalS = "interval_s"
        case distanceM = "distance_m"
    }
}

struct TrackingPolicy: Decodable {
    let trackingAllowed: Bool
    /// account_inactive | tracking_disabled | consent_required | not_clocked_in
    let reason: String?
    let clockedIn: Bool
    let tier: TrackingTier
    let activeVisitId: Int?
    let baseline: TrackingTierSettings
    let enhanced: TrackingTierSettings
    let statusPollS: Int?

    enum CodingKeys: String, CodingKey {
        case trackingAllowed = "tracking_allowed"
        case reason
        case clockedIn       = "clocked_in"
        case tier
        case activeVisitId   = "active_visit_id"
        case baseline, enhanced
        case statusPollS     = "status_poll_s"
    }
}

struct TrackingStatusResponse: Decodable {
    let success: Bool
    let policy: TrackingPolicy?
}

// MARK: - Upload

struct FixVerdict: Decodable {
    let id: String?
    let reason: String?
    let retryable: Bool?
}

struct FixUploadResponse: Decodable {
    let success: Bool
    let stored: Int?
    let accepted: [FixVerdict]?
    let rejected: [FixVerdict]?
    let autoStarted: AutoStartedPayload?
    let policy: TrackingPolicy?

    enum CodingKeys: String, CodingKey {
        case success, stored, accepted, rejected, policy
        case autoStarted = "auto_started"
    }
}

// MARK: - Consent

struct TrackingDisclosureSection: Decodable, Identifiable {
    let heading: String
    let body: String
    var id: String { heading }
}

struct TrackingDisclosure: Decodable {
    let version: String
    let title: String
    let summary: String
    let sections: [TrackingDisclosureSection]
    let agreeLabel: String

    enum CodingKeys: String, CodingKey {
        case version, title, summary, sections
        case agreeLabel = "agree_label"
    }
}

struct TrackingConsentState: Decodable {
    let current: Bool
    let consentedAt: String?
    let required: Bool

    enum CodingKeys: String, CodingKey {
        case current, required
        case consentedAt = "consented_at"
    }
}

struct TrackingConsentResponse: Decodable {
    let success: Bool
    let disclosure: TrackingDisclosure?
    let consent: TrackingConsentState?
}

// MARK: - Geofences

struct TrackingGeofence: Decodable {
    let visitId: Int
    let lat: Double
    let lng: Double
    let radiusM: Int
    let status: String?
    let label: String?

    enum CodingKeys: String, CodingKey {
        case visitId = "visit_id"
        case lat, lng
        case radiusM = "radius_m"
        case status, label
    }
}

struct TrackingGeofencesResponse: Decodable {
    let success: Bool
    let geofences: [TrackingGeofence]?
}
