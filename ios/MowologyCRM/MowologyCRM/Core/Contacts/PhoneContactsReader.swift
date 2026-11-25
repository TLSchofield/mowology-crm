import Contacts
import Foundation

enum PhoneContactsAuthorization { case notDetermined, authorized, denied, restricted, other }

protocol PhoneContactsProviding {
    func authorizationStatus() -> PhoneContactsAuthorization
    func requestAccess() async throws -> Bool
    func contacts() async throws -> [PhoneContact]
}

struct PhoneContactsReader: PhoneContactsProviding {
    func authorizationStatus() -> PhoneContactsAuthorization {
        switch CNContactStore.authorizationStatus(for: .contacts) {
        case .notDetermined: return .notDetermined
        case .authorized: return .authorized
        case .denied: return .denied
        case .restricted: return .restricted
        @unknown default: return .other
        }
    }

    func requestAccess() async throws -> Bool {
        try await withCheckedThrowingContinuation { continuation in
            CNContactStore().requestAccess(for: .contacts) { granted, error in
                if let error { continuation.resume(throwing: error) }
                else { continuation.resume(returning: granted) }
            }
        }
    }

    func contacts() async throws -> [PhoneContact] {
        try await Task.detached(priority: .userInitiated) {
            let keys: [CNKeyDescriptor] = [CNContactGivenNameKey, CNContactFamilyNameKey, CNContactOrganizationNameKey,
                CNContactEmailAddressesKey, CNContactPhoneNumbersKey, CNContactPostalAddressesKey] as [CNKeyDescriptor]
            let request = CNContactFetchRequest(keysToFetch: keys)
            var values: [PhoneContact] = []
            try CNContactStore().enumerateContacts(with: request) { contact, _ in
                let emails = contact.emailAddresses.map { String($0.value) }
                let phones = contact.phoneNumbers.map { item in
                    let localized = item.label.map(CNLabeledValue<NSString>.localizedString(forLabel:)) ?? "other"
                    return PhoneContact.Phone(label: Self.wireLabel(localized), number: item.value.stringValue)
                }
                guard !contact.givenName.isEmpty || !contact.familyName.isEmpty || !contact.organizationName.isEmpty || !emails.isEmpty || !phones.isEmpty else { return }
                let postal = contact.postalAddresses.first?.value
                let address = postal.map { PhoneContact.Address(street: $0.street.replacingOccurrences(of: "\n", with: ", "), city: $0.city, province: $0.state, postal: $0.postalCode) }
                values.append(PhoneContact(id: contact.identifier, first: contact.givenName, last: contact.familyName,
                                           company: contact.organizationName, emails: emails, phones: phones, address: address))
            }
            return values.sorted { PhoneContactsLogic.displayName($0).localizedStandardCompare(PhoneContactsLogic.displayName($1)) == .orderedAscending }
        }.value
    }

    private static func wireLabel(_ localized: String) -> String {
        let value = localized.lowercased()
        return ["mobile", "iphone", "home", "work", "main"].contains(value) ? value : "other"
    }
}
