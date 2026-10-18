import Foundation

enum ItPennyOnboardingState: Equatable {
    case opening
    case searching
    case oneMatch(BusinessRegistryMatch)
    case several([BusinessRegistryMatch], selected: BusinessRegistryMatch?)
    case none(manual: Bool)
    case unavailable
    case confirmed(String)
}


enum BusinessIdentityProvenance: String, Decodable, Equatable {
    case registryVerified = "registry_verified"
    case ownerConfirmed = "owner_confirmed"
    case ownerProvided = "owner_provided"

    var words: String {
        switch self {
        case .registryVerified: return "Registry verified"
        case .ownerConfirmed: return "You confirmed"
        case .ownerProvided: return "You provided"
        }
    }
}

struct BusinessIdentity: Decodable, Equatable {
    let enteredName, legalName, operatingName, registrationNumber: String?
    let registrationType, legalStructure, jurisdiction, registrationStatus: String?
    let source, sourceRecordId, registryVerifiedAt, ownerConfirmedAt: String?
    let provenance: [String: BusinessIdentityProvenance]

    enum CodingKeys: String, CodingKey {
        case enteredName = "entered_name", legalName = "legal_name", operatingName = "operating_name"
        case registrationNumber = "registration_number", registrationType = "registration_type"
        case legalStructure = "legal_structure", jurisdiction, registrationStatus = "registration_status"
        case source, sourceRecordId = "source_record_id", registryVerifiedAt = "registry_verified_at"
        case ownerConfirmedAt = "owner_confirmed_at", provenance
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        func string(_ key: CodingKeys) -> String? { (try? c.decodeIfPresent(String.self, forKey: key))?.nilIfBlank }
        enteredName = string(.enteredName); legalName = string(.legalName); operatingName = string(.operatingName)
        registrationNumber = string(.registrationNumber); registrationType = string(.registrationType)
        legalStructure = string(.legalStructure); jurisdiction = string(.jurisdiction)
        registrationStatus = string(.registrationStatus); source = string(.source)
        sourceRecordId = string(.sourceRecordId); registryVerifiedAt = string(.registryVerifiedAt)
        ownerConfirmedAt = string(.ownerConfirmedAt)
        provenance = (try? c.decodeIfPresent([String: BusinessIdentityProvenance].self, forKey: .provenance)) ?? [:]
    }
}

struct BusinessIdentityKnowledge: Decodable, Equatable {
    let stage: KnowledgeStage?
    let next: [String]

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        stage = try? c.decodeIfPresent(KnowledgeStage.self, forKey: .stage)
        next = (try? c.decodeIfPresent([String].self, forKey: .next)) ?? []
    }
    enum CodingKeys: String, CodingKey { case stage, next }
}

struct BusinessIdentityCurrentResponse: Decodable, Equatable {
    let ok: Bool
    let identity: BusinessIdentity?
    let knowledge: BusinessIdentityKnowledge
    let canEdit: Bool
    let attribution: String?
    let error: String?

    enum CodingKeys: String, CodingKey { case ok, identity, knowledge, canEdit = "can_edit", attribution, error, message }
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = (try? c.decodeIfPresent(Bool.self, forKey: .ok)) ?? false
        identity = try? c.decodeIfPresent(BusinessIdentity.self, forKey: .identity)
        knowledge = (try? c.decodeIfPresent(BusinessIdentityKnowledge.self, forKey: .knowledge))
            ?? BusinessIdentityKnowledge(stage: nil, next: [])
        canEdit = (try? c.decodeIfPresent(Bool.self, forKey: .canEdit)) ?? false
        attribution = try? c.decodeIfPresent(String.self, forKey: .attribution)
        error = (try? c.decodeIfPresent(String.self, forKey: .error))
            ?? (try? c.decodeIfPresent(String.self, forKey: .message))
    }
}

extension BusinessIdentityKnowledge {
    init(stage: KnowledgeStage?, next: [String]) { self.stage = stage; self.next = next }
}

struct BusinessRegistryMatch: Decodable, Identifiable, Equatable {
    let recordId, legalName: String
    let operatingNames: [String]
    let registrationNumber, registrationType, legalStructure, status, jurisdiction, registeredOn: String?
    let match: String?
    var id: String { recordId }
    static let manualID = "__manual__"

    enum CodingKeys: String, CodingKey {
        case recordId = "record_id", legalName = "legal_name", operatingNames = "operating_names"
        case registrationNumber = "registration_number", registrationType = "registration_type"
        case legalStructure = "legal_structure", status, jurisdiction, registeredOn = "registered_on", match
    }
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        recordId = (try? c.decode(String.self, forKey: .recordId)) ?? UUID().uuidString
        legalName = (try? c.decode(String.self, forKey: .legalName)) ?? "Registered business"
        operatingNames = ((try? c.decodeIfPresent([String].self, forKey: .operatingNames)) ?? []).filter { !$0.isEmpty }
        registrationNumber = try? c.decodeIfPresent(String.self, forKey: .registrationNumber)
        registrationType = try? c.decodeIfPresent(String.self, forKey: .registrationType)
        legalStructure = try? c.decodeIfPresent(String.self, forKey: .legalStructure)
        status = try? c.decodeIfPresent(String.self, forKey: .status)
        jurisdiction = try? c.decodeIfPresent(String.self, forKey: .jurisdiction)
        registeredOn = try? c.decodeIfPresent(String.self, forKey: .registeredOn)
        match = try? c.decodeIfPresent(String.self, forKey: .match)
    }
}

enum BusinessRegistryStatus: String, Decodable { case matches, none, unavailable, rateLimited = "rate_limited" }

struct BusinessIdentitySearchResponse: Decodable {
    let ok: Bool
    let status: BusinessRegistryStatus
    let matches: [BusinessRegistryMatch]
    let attribution, error: String?
    enum CodingKeys: String, CodingKey { case ok, status, matches, attribution, error, message }
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = (try? c.decodeIfPresent(Bool.self, forKey: .ok)) ?? false
        status = (try? c.decodeIfPresent(BusinessRegistryStatus.self, forKey: .status)) ?? .unavailable
        matches = Array(((try? c.decodeIfPresent([BusinessRegistryMatch].self, forKey: .matches)) ?? []).prefix(5))
        attribution = try? c.decodeIfPresent(String.self, forKey: .attribution)
        error = (try? c.decodeIfPresent(String.self, forKey: .error)) ?? (try? c.decodeIfPresent(String.self, forKey: .message))
    }
}

enum BusinessContactField: String, Decodable, CaseIterable { case website, phone, address }
enum BusinessContactSource: String, Decodable { case yourSettings = "your_settings", googlePlaces = "google_places" }

struct BusinessContactSuggestion: Decodable, Identifiable, Equatable {
    let field: BusinessContactField
    let value: String
    let source: BusinessContactSource
    let status: String
    var id: BusinessContactField { field }
}

enum BusinessContactsGoogleStatus: String, Decodable { case notConfigured = "not_configured", ok, unavailable }

struct BusinessContactsResponse: Decodable, Equatable {
    let ok: Bool
    let contacts: [BusinessContactSuggestion]
    let google: BusinessContactsGoogleStatus

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = (try? c.decode(Bool.self, forKey: .ok)) ?? false
        contacts = (try? c.decode([BusinessContactSuggestion].self, forKey: .contacts)) ?? []
        google = (try? c.decode(BusinessContactsGoogleStatus.self, forKey: .google)) ?? .notConfigured
    }
    enum CodingKeys: String, CodingKey { case ok, contacts, google }
}

enum BusinessContactsState: Equatable { case idle, loading, available([BusinessContactSuggestion]), missing, failed }

private extension String {
    var nilIfBlank: String? { trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? nil : self }
}
