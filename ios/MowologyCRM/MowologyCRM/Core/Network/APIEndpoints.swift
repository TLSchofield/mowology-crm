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

    /// GET /api/expenses/expense-meta — accounting categories + payment methods (JWT).
    case expenseMeta

    /// POST /api/expenses/expense-save — save a reviewed expense record (JWT).
    case expenseSave

    /// GET /api/expenses/expense-list — paginated list of the user's expenses (JWT).
    case expenseList(page: Int)

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

        case .scheduleWeek(let start):
            var components = URLComponents(string: "\(baseURLString)/schedule/week")
            components?.queryItems = [URLQueryItem(name: "start", value: start)]
            return components?.url

        case .scheduleTimer:
            return URL(string: "\(baseURLString)/schedule/timer")

        case .scheduleLocation:
            return URL(string: "\(baseURLString)/schedule/location")

        case .scheduleClock:
            return URL(string: "\(baseURLString)/schedule/clock")

        case .scheduleClockStatus:
            var components = URLComponents(string: "\(baseURLString)/schedule/clock")
            components?.queryItems = [URLQueryItem(name: "action", value: "status")]
            return components?.url

        case .receiptUpload:
            return URL(string: "\(baseURLString)/expenses/receipt-upload")

        case .expenseMeta:
            return URL(string: "\(baseURLString)/expenses/expense-meta")

        case .expenseSave:
            return URL(string: "\(baseURLString)/expenses/expense-save")

        case .expenseList(let page):
            var components = URLComponents(string: "\(baseURLString)/expenses/expense-list")
            components?.queryItems = [URLQueryItem(name: "page", value: "\(page)")]
            return components?.url
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
             .expenseMeta,
             .expenseSave,
             .expenseList: return true
        }
    }

    // MARK: - HTTP Method

    /// Default HTTP method for the endpoint.
    var httpMethod: String {
        switch self {
        case .tokenAuth:    return "POST"
        case .scheduleDay,
             .scheduleWeek,
             .scheduleClockStatus,
             .expenseMeta,
             .expenseList:         return "GET"

        case .scheduleTimer,
             .scheduleLocation,
             .scheduleClock,
             .receiptUpload,
             .expenseSave:         return "POST"
        }
    }
}
