//
//  ItFontRegistry.swift
//  MowologyCRM
//
//  Registers Inter (400/500/600, SIL Open Font License — licence text ships as the Inter-OFL data asset)
//  from the asset catalog at launch. Fonts live in Assets.xcassets as data assets so the Xcode project's
//  resource list never changes. If registration fails, Font.custom falls back to the system font and
//  the failure is logged; nothing else depends on it.
//

#if canImport(UIKit)
import UIKit
import CoreText
import os

enum ItFontRegistry {
    static let names = ["Inter-Regular", "Inter-Medium", "Inter-SemiBold"]
    private static let log = Logger(subsystem: "ca.mowology.crm", category: "ittai-fonts")
    private(set) static var registered: [String] = []

    /// Safe to call more than once.
    static func registerAll() {
        guard registered.isEmpty else { return }
        for name in names {
            guard let asset = NSDataAsset(name: name),
                  let provider = CGDataProvider(data: asset.data as CFData),
                  let font = CGFont(provider) else {
                log.error("Ittai font missing from the asset catalog: \(name, privacy: .public)")
                continue
            }
            var error: Unmanaged<CFError>?
            if CTFontManagerRegisterGraphicsFont(font, &error) || UIFont(name: name, size: 12) != nil {
                registered.append(name)
            } else {
                log.error("Ittai font failed to register: \(name, privacy: .public) \(String(describing: error?.takeRetainedValue()), privacy: .public)")
            }
        }
    }
}
#endif
