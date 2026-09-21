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

    /// GET /api/schedule/timer?mode=active — the job timer currently running for this user, if any.
    case scheduleTimerActive

    /// POST /api/schedule/location — GPS ping for an active job visit.
    case scheduleLocation

    /// GET /api/schedule/crew-trails?date=YYYY-MM-DD — GPS trail polylines + live
    /// positions for crew. Self-only for crew, all crew for admin/manager.
    case scheduleCrewTrails(date: String)

    /// POST /api/schedule/clock — clock in or clock out.
    case scheduleClock

    /// GET /api/schedule/clock?action=status — current clock-in state.
    case scheduleClockStatus

    /// GET /api/schedule/clock?mode=week&start=YYYY-MM-DD — caller's own timesheet week.
    /// Uses `mode`, not `action`: the /api/ rewrite owns the `action` query param.
    case scheduleTimesheetWeek(start: String)

    /// GET /api/schedule/visit-work?visit_id= — checklist, materials and notes for a visit.
    case visitWork(visitId: Int)

    /// POST /api/schedule/visit-work — save_checklist / save_materials / add_note.
    case visitWorkAction

    /// GET /api/schedule/tracking?mode=status — the server's tracking policy (am I still on the clock?).
    case trackingStatus

    /// GET /api/schedule/tracking?mode=consent — disclosure text + whether it has been agreed.
    case trackingConsent

    /// GET /api/schedule/tracking?mode=geofences — the caller's OWN stops today, for OS geofencing.
    case trackingGeofences

    /// POST /api/schedule/tracking — {action: consent | withdraw}.
    case trackingAction

    /// GET /api/schedule/trip-report — vehicle log status for this shift (am I the driver? open trip?).
    case tripReportStatus

    /// POST /api/schedule/trip-report — {action: declare | pre_trip | post_trip}.
    case tripReportAction

    /// POST /api/schedule/visit-flag — toggle crew endorsement heart on a visit.
    case visitFlag

    /// GET /api/schedule/recommendation?mode=options — services crew may offer.
    case recommendationOptions

    /// POST /api/schedule/recommendation — log a crew service recommendation.
    case recommendationCreate

    /// POST /crm/api/pow-actions.php — PoW visit lifecycle (start/end/notes).
    case powActions

    /// POST /crm/api/pow-gps-sync.php — flush GPS breadcrumb batch for a PoW visit.
    case powGpsSync

    /// GET /api/schedule/jobs — paginated job list with status filter.
    case scheduleJobs(status: String, limit: Int, offset: Int)

    /// GET /api/schedule/invoices — paginated invoice list with status filter.
    case scheduleInvoices(status: String)

    /// GET /api/schedule/quotes — paginated quote list with status filter.
    case scheduleQuotes(status: String)

    /// POST /api/expenses/receipt-upload — upload a receipt image and run OCR (JWT).
    case receiptUpload

    /// POST /api/expenses/expense-save — save a reviewed expense record (JWT).
    case expenseSave

    /// GET /api/expenses/expense-list — paginated list of the user's expenses (JWT).
    case expenseList(page: Int)

    /// POST /api/expenses/expense-update — edit an existing expense's fields (JWT).
    case expenseUpdate

    /// POST /api/expenses/receipt-actions — approve, reject, or send a receipt to
    /// accounting (JWT). Body: { action: "approve"|"reject"|"send", expense_id, rejection_reason? }
    case receiptAction

    /// GET /api/expenses/expense-lookup?type=vendors|jobs|categories|duplicates&… —
    /// review-form lookups shared with the Android review card (JWT). Uses `type=`
    /// because the /api/ router's rewrite appends its own `action` param.
    case expenseLookup(query: [URLQueryItem])

    /// POST /api/expenses/expense-delete — delete an own draft expense (JWT). Body: { id }
    case expenseDelete

    /// GET /api/expenses/expense-line-items?expense_id=N — a saved expense's line items (JWT).
    case expenseLineItems(expenseId: Int)

    /// POST /api/expenses/expense-line-items — { op: update|add|delete|link, ... } (JWT).
    /// Every correction here is a learning signal for the receipt parser.
    case expenseLineItemAction

    /// POST /api/device/token — register APNs device token for push notifications.
    case deviceTokenRegister

    /// POST /api/schedule/quiz — start, answer, or finish a quiz session.
    case quizAction

    /// GET /api/schedule/quiz?session_id=N&q=N — fetch a single quiz question.
    case quizQuestion(sessionId: Int, q: Int)

    /// POST /api/schedule/job-photo — upload a before/after job photo for a visit.
    /// GET /api/schedule/field-job?mode=nearby — properties near a GPS fix, with their active plans.
    case fieldJobNearby(lat: Double, lng: Double)

    /// POST /api/schedule/field-job — add_visit | create_job | create_client_job.
    case fieldJobAction

    case scheduleJobPhoto

    /// GET /api/schedule/visit-photos?visit_id=N — proof photos already taken on a visit.
    case scheduleVisitPhotos(visitId: Int)

    /// POST /api/schedule/invoice — invoice a completed visit (timed extras + invoice).
    /// Body: { action: "preview"|"create"|"send", ... }
    case scheduleInvoice

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

        case .scheduleTimerActive:
            return URL(string: "\(baseURLString)/schedule/timer?mode=active")

        case .scheduleLocation:
            return URL(string: "\(baseURLString)/schedule/location")

        case .scheduleCrewTrails(let date):
            var components = URLComponents(string: "\(baseURLString)/schedule/crew-trails")
            components?.queryItems = [URLQueryItem(name: "date", value: date)]
            return components?.url

        case .scheduleClock:
            return URL(string: "\(baseURLString)/schedule/clock")

        case .scheduleClockStatus:
            var components = URLComponents(string: "\(baseURLString)/schedule/clock")
            components?.queryItems = [URLQueryItem(name: "action", value: "status")]
            return components?.url

        case .scheduleTimesheetWeek(let start):
            var components = URLComponents(string: "\(baseURLString)/schedule/clock")
            components?.queryItems = [
                URLQueryItem(name: "mode",  value: "week"),
                URLQueryItem(name: "start", value: start)
            ]
            return components?.url

        case .visitWork(let visitId):
            var components = URLComponents(string: "\(baseURLString)/schedule/visit-work")
            components?.queryItems = [URLQueryItem(name: "visit_id", value: String(visitId))]
            return components?.url

        case .visitWorkAction:
            return URL(string: "\(baseURLString)/schedule/visit-work")

        case .trackingStatus, .trackingConsent, .trackingGeofences:
            let mode: String
            switch self {
            case .trackingConsent:   mode = "consent"
            case .trackingGeofences: mode = "geofences"
            default:                 mode = "status"
            }
            var components = URLComponents(string: "\(baseURLString)/schedule/tracking")
            components?.queryItems = [URLQueryItem(name: "mode", value: mode)]
            return components?.url

        case .trackingAction:
            return URL(string: "\(baseURLString)/schedule/tracking")

        case .tripReportStatus, .tripReportAction:
            return URL(string: "\(baseURLString)/schedule/trip-report")

        case .visitFlag:
            return URL(string: "\(baseURLString)/schedule/visit-flag")

        case .recommendationOptions:
            var components = URLComponents(string: "\(baseURLString)/schedule/recommendation")
            components?.queryItems = [URLQueryItem(name: "mode", value: "options")]
            return components?.url

        case .recommendationCreate:
            return URL(string: "\(baseURLString)/schedule/recommendation")

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

        case .scheduleInvoices(let status):
            var components = URLComponents(string: "\(baseURLString)/schedule/invoices")
            components?.queryItems = [URLQueryItem(name: "status", value: status)]
            return components?.url

        case .scheduleQuotes(let status):
            var components = URLComponents(string: "\(baseURLString)/schedule/quotes")
            components?.queryItems = [URLQueryItem(name: "status", value: status)]
            return components?.url

        case .receiptUpload:
            return URL(string: "\(baseURLString)/expenses/receipt-upload")

        case .expenseSave:
            return URL(string: "\(baseURLString)/expenses/expense-save")

        case .expenseList(let page):
            var components = URLComponents(string: "\(baseURLString)/expenses/expense-list")
            components?.queryItems = [URLQueryItem(name: "page", value: "\(page)")]
            return components?.url

        case .expenseUpdate:
            return URL(string: "\(baseURLString)/expenses/expense-update")

        case .receiptAction:
            return URL(string: "\(baseURLString)/expenses/receipt-actions")

        case .expenseLookup(let query):
            var components = URLComponents(string: "\(baseURLString)/expenses/expense-lookup")
            components?.queryItems = query
            return components?.url

        case .expenseDelete:
            return URL(string: "\(baseURLString)/expenses/expense-delete")

        case .expenseLineItems(let expenseId):
            var components = URLComponents(string: "\(baseURLString)/expenses/expense-line-items")
            components?.queryItems = [URLQueryItem(name: "expense_id", value: "\(expenseId)")]
            return components?.url

        case .expenseLineItemAction:
            return URL(string: "\(baseURLString)/expenses/expense-line-items")

        case .deviceTokenRegister:
            return URL(string: "\(baseURLString)/device/token")

        case .quizAction:
            return URL(string: "\(baseURLString)/schedule/quiz")

        case .quizQuestion(let sessionId, let q):
            var components = URLComponents(string: "\(baseURLString)/schedule/quiz")
            components?.queryItems = [
                URLQueryItem(name: "session_id", value: "\(sessionId)"),
                URLQueryItem(name: "q",          value: "\(q)"),
            ]
            return components?.url

        case .fieldJobNearby(let lat, let lng):
            var components = URLComponents(string: "\(baseURLString)/schedule/field-job")
            components?.queryItems = [
                URLQueryItem(name: "mode", value: "nearby"),
                URLQueryItem(name: "lat",  value: "\(lat)"),
                URLQueryItem(name: "lng",  value: "\(lng)"),
            ]
            return components?.url

        case .fieldJobAction:
            return URL(string: "\(baseURLString)/schedule/field-job")

        case .scheduleJobPhoto:
            return URL(string: "\(baseURLString)/schedule/job-photo")

        case .scheduleVisitPhotos(let visitId):
            var components = URLComponents(string: "\(baseURLString)/schedule/visit-photos")
            components?.queryItems = [URLQueryItem(name: "visit_id", value: "\(visitId)")]
            return components?.url

        case .scheduleInvoice:
            return URL(string: "\(baseURLString)/schedule/invoice")
        }
    }

    // MARK: - Auth

    /// Whether this endpoint requires an `Authorization: Bearer <token>` header.
    var requiresAuth: Bool {
        switch self {
        case .tokenAuth: return false
        case .scheduleDay,
             .scheduleWeek,
             .scheduleTimer,
             .scheduleTimerActive,
             .scheduleLocation,
             .scheduleCrewTrails,
             .scheduleClock,
             .scheduleClockStatus,
             .scheduleTimesheetWeek,
             .visitWork,
             .visitWorkAction,
             .trackingStatus,
             .trackingConsent,
             .trackingGeofences,
             .trackingAction,
             .tripReportStatus,
             .tripReportAction,
             .visitFlag,
             .recommendationOptions,
             .recommendationCreate,
             .powActions,
             .powGpsSync,
             .scheduleJobs,
             .scheduleInvoices,
             .scheduleQuotes,
             .receiptUpload,
             .expenseSave,
             .expenseList,
             .expenseUpdate,
             .receiptAction,
             .expenseLookup,
             .expenseDelete,
             .expenseLineItems,
             .expenseLineItemAction,
             .deviceTokenRegister,
             .quizAction,
             .quizQuestion,
             .scheduleJobPhoto,
             .scheduleVisitPhotos,
             .fieldJobNearby,
             .fieldJobAction,
             .scheduleInvoice: return true
        }
    }

    // MARK: - HTTP Method

    /// Default HTTP method for the endpoint.
    var httpMethod: String {
        switch self {
        case .tokenAuth:    return "POST"
        case .scheduleDay,
             .scheduleWeek,
             .scheduleCrewTrails,
             .scheduleClockStatus,
             .scheduleTimesheetWeek,
             .visitWork,
             .trackingStatus,
             .trackingConsent,
             .trackingGeofences,
             .tripReportStatus: return "GET"

        case .scheduleTimer,
             .scheduleLocation,
             .scheduleClock,
             .visitFlag,
             .visitWorkAction,
             .trackingAction,
             .tripReportAction,
             .powActions,
             .powGpsSync,
             .receiptUpload,
             .expenseSave,
             .expenseUpdate,
             .receiptAction,
             .expenseDelete,
             .expenseLineItemAction,
             .deviceTokenRegister: return "POST"

        case .expenseList,
             .expenseLookup,
             .expenseLineItems,
             .scheduleJobs,
             .scheduleInvoices,
             .scheduleQuotes,
             .recommendationOptions,
             .scheduleVisitPhotos,
             .scheduleTimerActive,
             .fieldJobNearby,
             .quizQuestion: return "GET"

        case .quizAction,
             .recommendationCreate,
             .scheduleJobPhoto,
             .fieldJobAction,
             .scheduleInvoice: return "POST"
        }
    }
}
