/// Supplies the extension-safe subset of Ittai v1.2 colors needed to keep the clocked-in Live Activity on-brand while it remains visible above a backgrounded app, inspired by Jobber's persistent status.

import SwiftUI

enum LiveActivityToken {
    case brand
    case stateWorking
    case stateIdle
    case surface
    case text
    case meta
}

enum LiveActivityTokens {
    static func color(_ token: LiveActivityToken, scheme: ColorScheme) -> Color {
        let value: UInt32
        switch (token, scheme) {
        case (.brand, .light): value = 0x233A5E
        case (.brand, .dark): value = 0xA9B9DA
        case (.stateWorking, .light): value = 0x2A557E
        case (.stateWorking, .dark): value = 0x8DB3DE
        case (.stateIdle, .light): value = 0x48534C
        case (.stateIdle, .dark): value = 0xAFB9B3
        case (.surface, .light): value = 0xFFFFFF
        case (.surface, .dark): value = 0x161E1A
        case (.text, .light): value = 0x131D17
        case (.text, .dark): value = 0xE9EEEA
        case (.meta, .light): value = 0x48534C
        case (.meta, .dark): value = 0xAFB9B3
        @unknown default: value = 0x131D17
        }

        return Color(
            red: Double((value >> 16) & 255) / 255,
            green: Double((value >> 8) & 255) / 255,
            blue: Double(value & 255) / 255
        )
    }
}
