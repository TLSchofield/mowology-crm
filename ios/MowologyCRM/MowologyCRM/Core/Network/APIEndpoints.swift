//
//  APIEndpoints.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import Foundation

private let baseURLString = "https://mowology.ca/api"

/// Enumeration of every API endpoint the app communicates with.
/// Each case resolves to a `URL` and declares whether it requires an
/// `Authorization: Bearer` header.
enum APIEndpoint {

    /// POST /api/auth/token.php — exchange credentials for a JWT.
    case tokenAuth

    /// GET /api/schedule/day?date=YYYY-MM-DD — stops for a specific date.
    case scheduleDay(date: String)

    /// GET /api/schedule/week?start=YYYY-MM-DD — weekly summary strip.
    case scheduleWeek(start: String)

    /// POST /api/schedule/timer — start or stop a job timer.
    case scheduleTimer

    /// POST /api/schedule/location — GPS ping for an active job visit.
    case scheduleLocation

    /// POST /api/schedule/clock — clock in or clock out.
    case scheduleClock

    /// GET /api/schedule/clock?action=status — current clock-in state.
    case scheduleClockStatus

    /// POST /api/expenses/receipt-upload — upload a receipt image and run OCR (JWT).
    case receiptUpload

    /// POST /api/expenses/expense-save — save a reviewed expense record (JWT).
    case expenseSave

    /// GET /api/expenses/expense-list — paginated list of the user's expenses (JWT).
    case expenseList(page: Int)


    /// POST /api/schedule/pow-actions — PoW visit lifecycle (start/end/notes).
    case powActions

    /// POST /api/schedule/pow-gps-sync — flush GPS breadcrumb batch for a PoW visit.
    case powGpsSync

    /// GET /api/schedule/jobs — paginated job list with status filter.
    case scheduleJobs(status: String, limit: Int, offset: Int)

    /// GET /api/schedule/invoices — paginated invoice list with status filter.
    case scheduleInvoices(status: String)

    /// GET /api/schedule/quotes — paginated quote list with status filter.
    case scheduleQuotes(status: String)

    /// POST /api/device/token — register APNs device token for push notifications.
    case deviceTokenRegister

    // MARK: - URL

    /// Builds the full URL for the endpoint. Returns `nil` only if the base
    /// URL string is somehow malformed (should never happen in production).
    var url: URL? {
        switch self {
        case .tokenAuth:
            return URL(string: "\(baseURLString)/auth/token.php")

        case .scheduleDay(let date):
            var components = URLComponents(string: "\(baseURLString)/schedule/day")
            components?.queryItems = [URLQueryItem(name: "date", value: date)]
            return components?.url

        case .jobPhoto:
            return URL(string: "\(baseURLString)/schedule/job-photo")

        case .deviceTokenRegister:
            return URL(string: "\(baseURLString)/auth/device-token.php")

        case .powActions:
            return URL(string: "https://mowology.ca/crm/api/pow-actions.php")

        case .powGpsSync:
            return URL(string: "https://mowology.ca/crm/api/pow-gps-sync.php")

        case .scheduleJobs(let status, let limit, let offset):
            var components = URLComponents(string: "\(baseURLString)/schedule/jobs")
            components?.queryItems = [
                URLQueryItem(name: "status", value: status),
                URLQueryItem(name: "limit",  value: "\(limit)"),
                URLQueryItem(name: "offset", value: "\(offset)"),
            ]
            return components?.url

        case .scheduleQuotes(let status):
            var components = URLComponents(string: "\(baseURLString)/schedule/quotes")
            components?.queryItems = [URLQueryItem(name: "status", value: status)]
            return components?.url

        case .scheduleInvoices(let status):
            var components = URLComponents(string: "\(baseURLString)/schedule/invoices")
            components?.queryItems = [URLQueryItem(name: "status", value: status)]
            return components?.url

        case .visitFlag:
            return URL(string: "\(baseURLString)/schedule/visit-flag")

        case .powActions:
            return URL(string: "\(baseURLString)/schedule/pow-actions")

        case .powGpsSync:
            return URL(string: "\(baseURLString)/schedule/pow-gps-sync")

        case .scheduleJobs(let status, let limit, let offset):
            var components = URLComponents(string: "\(baseURLString)/schedule/jobs")
            components?.queryItems = [
                URLQueryItem(name: "status", value: status),
                URLQueryItem(name: "limit",  value: "\(limit)"),
                URLQueryItem(name: "offset", value: "\(offset)"),
            ]
            return components?.url

        case .scheduleInvoices(let status):
            var components = URLComponents(string: "\(baseURLString)/schedule/invoices")
            components?.queryItems = [URLQueryItem(name: "status", value: status)]
            return components?.url

        case .scheduleQuotes(let status):
            var components = URLComponents(string: "\(baseURLString)/schedule/quotes")
            components?.queryItems = [URLQueryItem(name: "status", value: status)]
            return components?.url

        case .deviceTokenRegister:
            return URL(string: "\(baseURLString)/device/token")
        }
    }

    // MARK: - Auth

    /// Whether this endpoint requires an `Authorization: Bearer <token>` header.
    var requiresAuth: Bool {
        switch self {
        case .tokenAuth:    return false
        case .scheduleDay,
             .scheduleWeek,
             .scheduleTimer,
             .scheduleLocation,
             .scheduleClock,
             .scheduleClockStatus,
             .receiptUpload,
             .expenseSave,
             .expenseList,
             .visitFlag,
             .powActions,
             .powGpsSync,
             .scheduleJobs,
             .scheduleInvoices,
             .scheduleQuotes,
             .deviceTokenRegister: return true
        }
    }

    // MARK: - HTTP Method

    /// Default HTTP method for the endpoint.
    var httpMethod: String {
        switch self {
        case .tokenAuth:    return "POST"
        case .scheduleDay,
             .scheduleWeek,
             .scheduleClockStatus: return "GET"

        case .scheduleTimer,
             .scheduleLocation,
             .scheduleClock,
             .receiptUpload,
             .expenseSave,
             .visitFlag,
             .powActions,
             .powGpsSync,
             .deviceTokenRegister: return "POST"

        case .expenseList,
             .scheduleJobs,
             .scheduleInvoices,
             .scheduleQuotes: return "GET"
        }
    }
}
