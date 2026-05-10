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

    /// GET /api/expenses/receipt-image?id=<mediaId> — stream an auth-gated receipt image (JWT).
    case receiptImage(mediaId: Int)

    /// POST /api/schedule/job-photo — upload a before/after job site photo (JWT).
    case jobPhoto(visitId: Int)

    /// POST /api/auth/device-token — register APNs device token for push notifications.
    case deviceTokenRegister

    /// POST /crm/api/pow-actions.php — PoW visit state transitions (start, end, notes, GPS, etc.)
    case powActions

    /// POST /crm/api/pow-gps-sync.php — batch GPS breadcrumb upload for an active visit.
    case powGpsSync

    /// GET /api/schedule/jobs — paginated job visit history (role-scoped: crew → own, admin → all).
    case scheduleJobs(status: String, limit: Int, offset: Int)

    /// GET /api/schedule/quotes — admin-only quote list.
    case scheduleQuotes(status: String)

    /// GET /api/schedule/invoices — admin-only invoice list.
    case scheduleInvoices(status: String)

    /// POST /api/schedule/quiz — start a quiz session, submit an answer, or finish.
    case quizAction

    /// GET /api/schedule/quiz?session_id=N&q=N — fetch one question.
    case quizQuestion(sessionId: Int, q: Int)

    /// GET /api/reports/data?report=<type>&start=...&end=... — admin reports data.
    case reportsData(report: String, start: String, end: String)

    /// GET /api/messages/inbox?action=inbox[&page=N] — paginated inbox.
    case messagesInbox(page: Int)

    /// GET /api/messages/inbox?action=unread-count — unread message count.
    case messagesUnreadCount

    /// GET /api/messages/inbox?action=users — active users list for compose.
    case messagesUsers

    /// POST /api/messages/inbox — mark-read or compose.
    case messagesAction

    /// GET /api/gamification/leaderboard[?week=YYYY-MM-DD] — weekly leaderboard.
    case leaderboard(week: String?)

    /// GET /api/team/crew-map — live crew positions (admin only).
    case crewMapLive

    /// GET /api/schedule/invoice-prefill?visit_id=N — prefill data for the iOS invoice compose sheet.
    case scheduleInvoicePrefill(visitId: Int)

    /// POST /api/schedule/invoice-create-send — create and optionally send an invoice from a completed visit.
    case scheduleInvoiceCreateSend

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

        case .receiptImage(let mediaId):
            var components = URLComponents(string: "\(baseURLString)/expenses/receipt-image")
            components?.queryItems = [URLQueryItem(name: "id", value: "\(mediaId)")]
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

        case .quizAction:
            return URL(string: "\(baseURLString)/schedule/quiz")

        case .quizQuestion(let sessionId, let q):
            var components = URLComponents(string: "\(baseURLString)/schedule/quiz")
            components?.queryItems = [
                URLQueryItem(name: "session_id", value: "\(sessionId)"),
                URLQueryItem(name: "q",          value: "\(q)"),
            ]
            return components?.url

        case .reportsData(let report, let start, let end):
            var components = URLComponents(string: "\(baseURLString)/reports/data")
            components?.queryItems = [
                URLQueryItem(name: "report", value: report),
                URLQueryItem(name: "start",  value: start),
                URLQueryItem(name: "end",    value: end),
            ]
            return components?.url

        case .messagesInbox(let page):
            var components = URLComponents(string: "\(baseURLString)/messages/inbox")
            components?.queryItems = [
                URLQueryItem(name: "action", value: "inbox"),
                URLQueryItem(name: "page",   value: "\(page)"),
            ]
            return components?.url

        case .messagesUnreadCount:
            var components = URLComponents(string: "\(baseURLString)/messages/inbox")
            components?.queryItems = [URLQueryItem(name: "action", value: "unread-count")]
            return components?.url

        case .messagesUsers:
            var components = URLComponents(string: "\(baseURLString)/messages/inbox")
            components?.queryItems = [URLQueryItem(name: "action", value: "users")]
            return components?.url

        case .messagesAction:
            return URL(string: "\(baseURLString)/messages/inbox")

        case .leaderboard(let week):
            var components = URLComponents(string: "\(baseURLString)/gamification/leaderboard")
            if let w = week {
                components?.queryItems = [URLQueryItem(name: "week", value: w)]
            }
            return components?.url

        case .crewMapLive:
            return URL(string: "\(baseURLString)/team/crew-map")

        case .scheduleInvoicePrefill(let visitId):
            var components = URLComponents(string: "\(baseURLString)/schedule/invoice-prefill")
            components?.queryItems = [URLQueryItem(name: "visit_id", value: "\(visitId)")]
            return components?.url

        case .scheduleInvoiceCreateSend:
            return URL(string: "\(baseURLString)/schedule/invoice-create-send")
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
             .expenseList,
             .receiptImage,
             .jobPhoto,
             .deviceTokenRegister,
             .powActions,
             .powGpsSync,
             .scheduleJobs,
             .scheduleQuotes,
             .scheduleInvoices,
             .quizAction,
             .quizQuestion,
             .reportsData,
             .messagesInbox,
             .messagesUnreadCount,
             .messagesUsers,
             .messagesAction,
             .leaderboard,
             .crewMapLive,
             .scheduleInvoicePrefill,
             .scheduleInvoiceCreateSend: return true
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
             .expenseList,
             .scheduleJobs,
             .scheduleQuotes,
             .scheduleInvoices,
             .quizQuestion,
             .reportsData,
             .scheduleInvoicePrefill: return "GET"

        case .scheduleTimer,
             .scheduleLocation,
             .scheduleClock,
             .receiptUpload,
             .expenseSave,
             .jobPhoto,
             .deviceTokenRegister,
             .powActions,
             .powGpsSync,
             .quizAction,
             .messagesAction,
             .scheduleInvoiceCreateSend: return "POST"

        case .receiptImage,
             .messagesInbox,
             .messagesUnreadCount,
             .messagesUsers,
             .leaderboard,
             .crewMapLive:         return "GET"
        }
    }
}
