//
//  KeychainStore.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import Foundation
import Security
import LocalAuthentication

/// Thin wrapper around the iOS Keychain for storing small string secrets
/// (JWT tokens, serialised user JSON). All methods are static and synchronous —
/// Keychain access on the main thread is fine for small items.
enum KeychainStore {

    // MARK: - Save (non-biometric)

    /// Saves (or overwrites) a UTF-8 string value for the given key.
    /// - Returns: `true` if the operation succeeded.
    @discardableResult
    static func save(key: String, value: String) -> Bool {
        guard let data = value.data(using: .utf8) else { return false }

        // Delete any existing item first so we can do a clean add.
        delete(key: key)

        let query: [String: Any] = [
            kSecClass as String:            kSecClassGenericPassword,
            kSecAttrAccount as String:      key,
            kSecAttrService as String:      bundleIdentifier,
            kSecValueData as String:        data,
            kSecAttrAccessible as String:   kSecAttrAccessibleAfterFirstUnlock
        ]

        let status = SecItemAdd(query as CFDictionary, nil)
        return status == errSecSuccess
    }

    // MARK: - Save (biometric-protected)

    /// Saves a value protected by `.biometryCurrentSet`. Reading the item later
    /// will require a successful biometric evaluation. The item is invalidated
    /// automatically if the user adds or removes a fingerprint / face.
    @discardableResult
    static func saveBiometric(key: String, value: String) -> Bool {
        guard let data = value.data(using: .utf8) else { return false }

        delete(key: key)

        var accessError: Unmanaged<CFError>?
        guard let access = SecAccessControlCreateWithFlags(
            nil,
            kSecAttrAccessibleWhenPasscodeSetThisDeviceOnly,
            .biometryCurrentSet,
            &accessError
        ) else {
            return false
        }

        let query: [String: Any] = [
            kSecClass as String:            kSecClassGenericPassword,
            kSecAttrAccount as String:      key,
            kSecAttrService as String:      bundleIdentifier,
            kSecValueData as String:        data,
            kSecAttrAccessControl as String: access
        ]

        let status = SecItemAdd(query as CFDictionary, nil)
        return status == errSecSuccess
    }

    // MARK: - Load

    /// Loads a non-biometric stored string for the given key, or `nil` if not found.
    static func load(key: String) -> String? {
        let query: [String: Any] = [
            kSecClass as String:            kSecClassGenericPassword,
            kSecAttrAccount as String:      key,
            kSecAttrService as String:      bundleIdentifier,
            kSecMatchLimit as String:       kSecMatchLimitOne,
            kSecReturnData as String:       true
        ]

        var result: AnyObject?
        let status = SecItemCopyMatching(query as CFDictionary, &result)

        guard status == errSecSuccess,
              let data = result as? Data,
              let value = String(data: data, encoding: .utf8)
        else { return nil }

        return value
    }

    /// Loads a biometric-protected string using an already-authenticated `LAContext`.
    /// Pass the context returned from `BiometricAuth.authenticate(reason:)` so the
    /// user is not prompted a second time.
    static func loadBiometric(key: String, context: LAContext) -> String? {
        let query: [String: Any] = [
            kSecClass as String:                    kSecClassGenericPassword,
            kSecAttrAccount as String:              key,
            kSecAttrService as String:              bundleIdentifier,
            kSecMatchLimit as String:               kSecMatchLimitOne,
            kSecReturnData as String:               true,
            kSecUseAuthenticationContext as String: context
        ]

        var result: AnyObject?
        let status = SecItemCopyMatching(query as CFDictionary, &result)

        guard status == errSecSuccess,
              let data = result as? Data,
              let value = String(data: data, encoding: .utf8)
        else { return nil }

        return value
    }

    // MARK: - Delete

    /// Removes the item for the given key. Silently succeeds if the key does
    /// not exist.
    @discardableResult
    static func delete(key: String) -> Bool {
        let query: [String: Any] = [
            kSecClass as String:        kSecClassGenericPassword,
            kSecAttrAccount as String:  key,
            kSecAttrService as String:  bundleIdentifier
        ]
        let status = SecItemDelete(query as CFDictionary)
        return status == errSecSuccess || status == errSecItemNotFound
    }

    // MARK: - Private

    private static var bundleIdentifier: String {
        Bundle.main.bundleIdentifier ?? "ca.mowology.crm"
    }
}
