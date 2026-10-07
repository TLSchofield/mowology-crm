import Foundation

/// A service the office has published to the field — one tappable chip on the
/// job card. Curated in the CRM under Products › Field Recommendations.
///
/// Explicit CodingKeys throughout: the decoder deliberately does NOT use
/// `.convertFromSnakeCase` (see APIClient).
struct RecommendationOption: Codable, Identifiable, Hashable {
    let productId: Int
    let label: String
    let description: String
    let price: Double

    /// Fixed-price package — tapping this emails the client a quote immediately.
    let autoSend: Bool

    /// Price does not depend on measuring the property.
    let fixedPrice: Bool

    /// Can be quoted without someone pricing it first (flat price > 0, or a size rule).
    let hasPrice: Bool

    /// "$450.00", "Priced by size" or "Price TBC" — never "$0.00".
    let priceLabel: String

    /// The server has migration 1180, so "Ask first" is offered.
    let canAsk: Bool

    var id: Int { productId }

    /// What the chip shows under the service name.
    var formattedPrice: String {
        if !priceLabel.isEmpty { return priceLabel }
        return price > 0 ? String(format: "$%.2f", price) : "Price TBC"
    }

    enum CodingKeys: String, CodingKey {
        case productId   = "product_id"
        case label
        case description
        case price
        case autoSend    = "auto_send"
        case fixedPrice  = "fixed_price"
        case hasPrice    = "has_price"
        case priceLabel  = "price_label"
        case canAsk      = "can_ask"
    }

    /// Every optional field decodes with `try?` so an older server payload still
    /// yields a usable chip rather than failing the whole list (same approach as Visit).
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        productId   = (try? c.decode(Int.self, forKey: .productId)) ?? 0
        label       = (try? c.decode(String.self, forKey: .label)) ?? "Service"
        description = (try? c.decode(String.self, forKey: .description)) ?? ""
        price       = (try? c.decode(Double.self, forKey: .price)) ?? 0
        autoSend    = (try? c.decode(Bool.self, forKey: .autoSend)) ?? false
        fixedPrice  = (try? c.decode(Bool.self, forKey: .fixedPrice)) ?? false
        hasPrice    = (try? c.decode(Bool.self, forKey: .hasPrice)) ?? (price > 0)
        priceLabel  = (try? c.decode(String.self, forKey: .priceLabel)) ?? ""
        canAsk      = (try? c.decode(Bool.self, forKey: .canAsk)) ?? false
    }
}

/// One person a recommendation can go to.
struct RecommendationPerson: Decodable, Hashable {
    let contactId: Int
    let name: String
    let firstName: String
    let email: String?
    /// "onsite" (property_contacts site supervisor) or "site" (properties.site_contact_id).
    let role: String
    let consentOk: Bool
    let consent: String

    enum CodingKeys: String, CodingKey {
        case contactId = "contact_id"
        case name
        case firstName = "first_name"
        case email
        case role
        case consentOk = "consent_ok"
        case consent
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        contactId = (try? c.decode(Int.self, forKey: .contactId)) ?? 0
        name      = (try? c.decode(String.self, forKey: .name)) ?? ""
        firstName = (try? c.decode(String.self, forKey: .firstName)) ?? ""
        email     = try? c.decode(String.self, forKey: .email)
        role      = (try? c.decode(String.self, forKey: .role)) ?? "site"
        // Only the ask recipient carries consent; the quote recipient defaults to OK.
        consentOk = (try? c.decode(Bool.self, forKey: .consentOk)) ?? true
        consent   = (try? c.decode(String.self, forKey: .consent)) ?? ""
    }

    var roleLabel: String { role == "onsite" ? "on-site contact" : "site contact" }
}

/// GET /api/schedule/recommendation?mode=recipients
struct RecommendationRecipientsResponse: Decodable {
    let canSend: Bool
    let askReady: Bool
    let ask: RecommendationPerson?
    let quote: RecommendationPerson?

    enum CodingKeys: String, CodingKey {
        case canSend  = "can_send"
        case askReady = "ask_ready"
        case ask
        case quote
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        canSend  = (try? c.decode(Bool.self, forKey: .canSend)) ?? false
        askReady = (try? c.decode(Bool.self, forKey: .askReady)) ?? false
        ask      = try? c.decode(RecommendationPerson.self, forKey: .ask)
        quote    = try? c.decode(RecommendationPerson.self, forKey: .quote)
    }

    /// Why Ask first can't be used right now, or nil if it can.
    var askBlockedReason: String? {
        if !askReady { return "Ask first isn't switched on yet" }
        guard let ask else { return "Nobody at this property has an email address" }
        if !ask.consentOk { return ask.consent.isEmpty ? "No email consent on file" : ask.consent }
        return nil
    }
}

/// The Ask-first email as it would go — returned by create when this user may send it.
struct AskDraft: Decodable, Identifiable {
    struct Photo: Decodable, Hashable {
        let id: Int
        let url: String
    }
    struct Consent: Decodable {
        let ok: Bool
        let reason: String
        init(from decoder: Decoder) throws {
            let c = try decoder.container(keyedBy: CodingKeys.self)
            ok     = (try? c.decode(Bool.self, forKey: .ok)) ?? false
            reason = (try? c.decode(String.self, forKey: .reason)) ?? ""
        }
        enum CodingKeys: String, CodingKey { case ok, reason }
    }

    let observationId: Int
    let to: RecommendationPerson?
    let consent: Consent?
    let subject: String
    let body: String
    let draftedBy: String
    let photos: [Photo]

    var id: Int { observationId }

    enum CodingKeys: String, CodingKey {
        case observationId = "observation_id"
        case to
        case consent
        case subject
        case body
        case draftedBy = "drafted_by"
        case photos
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        observationId = (try? c.decode(Int.self, forKey: .observationId)) ?? 0
        to            = try? c.decode(RecommendationPerson.self, forKey: .to)
        consent       = try? c.decode(Consent.self, forKey: .consent)
        subject       = (try? c.decode(String.self, forKey: .subject)) ?? ""
        body          = (try? c.decode(String.self, forKey: .body)) ?? ""
        draftedBy     = (try? c.decode(String.self, forKey: .draftedBy)) ?? "template"
        photos        = (try? c.decode([Photo].self, forKey: .photos)) ?? []
    }
}

/// POST /api/schedule/recommendation {action: ask_send}
struct AskSendResponse: Decodable {
    let success: Bool
    let message: String

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        success = (try? c.decode(Bool.self, forKey: .success)) ?? false
        message = (try? c.decode(String.self, forKey: .message)) ?? ""
    }
    enum CodingKeys: String, CodingKey { case success, message }
}

/// GET /api/schedule/recommendation?mode=options
struct RecommendationOptionsResponse: Decodable {
    let success: Bool
    let options: [RecommendationOption]

    enum CodingKeys: String, CodingKey {
        case success, options
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        success = (try? c.decode(Bool.self, forKey: .success)) ?? false
        options = (try? c.decode([RecommendationOption].self, forKey: .options)) ?? []
    }
}

/// POST /api/schedule/recommendation
struct RecommendationCreateResponse: Decodable {
    let success: Bool
    let observationId: Int
    let status: String
    let duplicate: Bool
    let quoteId: Int?
    let autoSent: Bool
    let message: String
    /// "ask" or "quote".
    let intent: String
    /// Present when this user (admin/manager) may send the ask straight away.
    let draft: AskDraft?

    enum CodingKeys: String, CodingKey {
        case success
        case observationId = "observation_id"
        case status
        case duplicate
        case quoteId       = "quote_id"
        case autoSent      = "auto_sent"
        case message
        case intent
        case draft
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        success       = (try? c.decode(Bool.self, forKey: .success)) ?? false
        observationId = (try? c.decode(Int.self, forKey: .observationId)) ?? 0
        status        = (try? c.decode(String.self, forKey: .status)) ?? "pending"
        duplicate     = (try? c.decode(Bool.self, forKey: .duplicate)) ?? false
        quoteId       = try? c.decode(Int.self, forKey: .quoteId)
        autoSent      = (try? c.decode(Bool.self, forKey: .autoSent)) ?? false
        message       = (try? c.decode(String.self, forKey: .message)) ?? "Saved"
        intent        = (try? c.decode(String.self, forKey: .intent)) ?? "quote"
        draft         = try? c.decode(AskDraft.self, forKey: .draft)
    }
}
